<?php

use GuzzleHttp\Exception\GuzzleException;

/**
 * The ShipStream order.increment_id is the WooCommerce order_increment_id.
 * The ShipStream order ref (order.ext_order_id) is the WooCommerce order.increment_id.
 */
class ShipStream_WooCommerce_Plugin extends Plugin_Abstract
{
    const DATE_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})$/';
    const DATE_FORMAT = 'Y-m-d H:i:s';

    const STATE_ORDER_LAST_SYNC_AT = 'order_last_sync_at';
    const STATE_LOCK_ORDER_PULL = 'lock_order_pull';
    const STATE_FULFILLMENT_SERVICE_REGISTERED = 'fulfillment_service_registered';

    /** @var ShipStream_WooCommerce_Client */
    protected $_client = NULL;

    /**
     * @return bool
     * @throws Exception
     */
    public function hasConnectionConfig(): bool
    {
        return $this->getConfig('api_url') && $this->getConfig('consumer_key') && $this->getConfig('consumer_secret');
    }

    /**
     * @param bool $super
     * @return array
     * @throws Plugin_Exception
     * @throws Exception
     */
    public function connectionDiagnostics(bool $super = false): array
    {
        $info = $this->_wooCommerceApi('shipstream/v1/info');
        return array(
            sprintf('WooCommerce Version: %s', $info['woocommerce_version'] ?? 'undefined'),
            sprintf('WordPress Version: %s', $info['wordpress_version'] ?? 'undefined'),
            sprintf('ShipStream Sync Version: %s', $info['shipstream_sync_version'] ?? 'undefined'),
            sprintf('Service Status: %s', $this->isFulfillmentServiceRegistered() ? 'Registered' : 'Not registered')
        );
    }


    /**
     * Activate the plugin
     *
     * @return string[]
     */
    public function activate(): array
    {
        $warnings = array();
        try {
            $this->registerFulfillmentService();
        } catch (Plugin_Exception $e) {
            $warnings[] = $e->getMessage();
        } catch (Exception $e) {
        }

        return $warnings;
    }

    /**
     * Deactivate the plugin
     *
     * @return string[]
     */
    public function deactivate(): array
    {
        $errors = array();
        try {
            $this->unregisterFulfillmentService();
        } catch (Plugin_Exception $e) {
            $errors[] = $e->getMessage();
        }

        try {
            $this->setState(
                array(
                self::STATE_LOCK_ORDER_PULL => NULL,
                self::STATE_ORDER_LAST_SYNC_AT => NULL,
                self::STATE_FULFILLMENT_SERVICE_REGISTERED => NULL,
                )
            );
        } catch (Plugin_Exception $e) {
            $errors[] = $e->getMessage();
        }

        return $errors;
    }

    /**
     * @return string[]
     */
    public function reinstall(): array
    {
        return $this->activate();
    }

    /**
     * Trigger an inventory sync from the WooCommerce side which is more atomic
     *
     * @throws Plugin_Exception
     */
    public function syncInventory()
    {

        try {
            $this->_wooCommerceApi('shipstream/v1/sync_inventory');
        } catch (Exception $e) {
            $this->log(sprintf('Cannot Sync Inventory. Error: %s', $e->getMessage()));
            throw new Plugin_Exception($e->getMessage());
        }
    }


    /**
     * Synchronize orders since the configured date
     *
     * @throws Plugin_Exception
     * @throws Exception
     */
    public function syncOrders()
    {
        $since = $this->getConfig('sync_orders_since');
        if ($since && !$this->validateDate($since)) {
            throw new Plugin_Exception('Invalid synchronize orders since date format. Valid format: YYYY-MM-DD.');
        }

        $this->_importOrders($since);
    }

    /**
     * Synchronize orders since the last sync
     * @throws Plugin_Exception
     */
    public function cronSyncOrders()
    {
        $this->_importOrders();
    }

    /**
     * @return array|string|null
     * @throws Exception
     */
    public function isFulfillmentServiceRegistered()
    {
        return $this->getState(self::STATE_FULFILLMENT_SERVICE_REGISTERED);
    }

    /**
     * Register fulfillment service
     * @throws Plugin_Exception
     * @throws Exception
     */
    public function registerFulfillmentService()
    {
        if ($this->_wooCommerceApi('shipstream/v1/set_config', 'POST', array('path' => 'warehouse_api_url','value' => $this->getCallbackUrl(null)))) {
            $this->setState(self::STATE_FULFILLMENT_SERVICE_REGISTERED, TRUE);
        }
    }

    /**
     * Unregister fulfillment service
     * @throws Plugin_Exception
     */
    public function unregisterFulfillmentService()
    {
        $this->_wooCommerceApi('shipstream/v1/set_config', 'POST', array('path' => 'warehouse_api_url','value' => NULL));
        $this->setState(self::STATE_FULFILLMENT_SERVICE_REGISTERED);
    }

    /*****************
     * Event methods *
     *****************/

    /**
     * Import client order
     *
     * @param Varien_Object $data
     * @throws Exception
     */
    public function importOrderEvent(Varien_Object $data)
    {
        $orderIncrementId = $data->getData('increment_id');
        $logPrefix = sprintf('WooCommerce Order # %s: ', $orderIncrementId);

        // Check if order exists locally and if not, create new local order
        $result = $this->call('order.search', array(array('order_ref' => $orderIncrementId),array(), array()));
        if ($result['totalCount'] > 0) {
            // Local order exists, update WooCommerce order status to 'wc-submitted'.
            $message = sprintf('ShipStream Order # %s was created at %s', $result['results'][0]['unique_id'], $result['results'][0]['created_at']);
            $this->_addComment($orderIncrementId, 'wc-submitted', $message);
            return; // Ignore already existing orders
        }

        // Get full client order data
        $wooCommerceOrder = $this->_wooCommerceApi('shipstream/v1/order_shipment/info', 'POST', array('shipment_id' => $orderIncrementId));
        if (empty($wooCommerceOrder)) {
            return;
        }

        // Setup order.create arguments
        $shippingAddress = $this->formatShippingAddress($wooCommerceOrder['shipping_address']);

        // Prepare order and shipment items
        $orderItems = $this->getOrderItems($wooCommerceOrder['items'])[0];
        $skus       = $this->getOrderItems($wooCommerceOrder['items'])[1];
        if (empty($orderItems)) {
            return;
        }

        // Prepare additional order data
        $additionalData = array(
            'order_ref' => $wooCommerceOrder['order_increment_id'],
            'shipping_method' => $this->_getShippingMethod($wooCommerceOrder),
            'source' => 'woocommerce:' . $wooCommerceOrder['order_increment_id'],
        );

        $newOrderData = array(
            'store' => NULL,
            'items' => $orderItems,
            'address' => $shippingAddress,
            'options' => $additionalData,
            'timestamp' => new DateTime('now', $this->getTimeZone()),
        );
        $output = NULL;
       
        // Apply user scripts
        try {
            if ($script = $this->getConfig('filter_script')) {
                // Add product info for use in script
                // API product.search returns an array of products or an empty array in key 'result'.
                $products = $this->call('product.search', array(array('sku' => array('in' => $skus))))['result'];
                foreach ($newOrderData['items'] as &$item) {
                    $item['product'] = NULL;
                    foreach ($products as $product) {
                        if ($product['sku'] == $item['sku']) {
                            $item['product'] = $product;
                            break;
                        }
                    }
                }

                unset($item);

                try {
                    $newOrderData = $this->applyScriptForOrder($script, $newOrderData, array('wooCommerceOrder' => $wooCommerceOrder), $output);
                } catch (Exception $e) {
                    throw new Plugin_Exception('An unexpected error occurred while applying the Order Transform Script.', 102, $e);
                }

                if (!array_key_exists('store', $newOrderData) || empty($newOrderData['items']) || empty($newOrderData['address']) || empty($newOrderData['options'])) {
                    throw new Plugin_Exception('The Order Transform Script did not return the data expected.');
                }

                if (!empty($newOrderData['skip'])) {
                    // do not submit order
                    $this->log($logPrefix . 'Order has been skipped by the Order Transform Script.', self::DEBUG);
                    return;
                }

                foreach ($newOrderData['items'] as $k => $item) {
                    // Remove added product info from items data
                    unset($newOrderData['items'][$k]['product']);

                    if (!empty($item['skip'])) {
                        // Skipping an item
                        $this->log($logPrefix . sprintf('SKU "%s" has been skipped by the Order Transform Script.', $newOrderData['items'][$k]['sku']), self::DEBUG);
                        unset($newOrderData['items'][$k]);
                    }
                }

                if (empty($newOrderData['items'])) {
                    // no items to submit, all were skipped
                    $this->log($logPrefix . 'All SKUs have been skipped by the Order Transform Script.', self::DEBUG);
                    return;
                }
            }
        } catch (Plugin_Exception $e) {
            if (empty($e->getSubjectType())) {
                $e->setSubject('WooCommerce Order', $wooCommerceOrder['order_increment_id']);
            }

            try {
                $message = sprintf('Order could not be submitted due to the following Order Transform Script error: %s', $e->getMessage());
                $this->_addComment($wooCommerceOrder['order_increment_id'], 'wc-failed-to-submit', $message);
            } catch (Exception $ex) {
            }

            throw $e;
        }

        // Submit order
        $this->_lockOrderImport();
        try {
            $result = $this->call('order.create', array($newOrderData['store'], $newOrderData['items'], $newOrderData['address'], $newOrderData['options']));
            $this->log(sprintf('Created %s Order # %s for WooCommerce Order # %s', $this->getAppTitle(), $result['unique_id'], $wooCommerceOrder['order_increment_id']));
            if ($output) {
                if (!Mage::getIsDeveloperMode()) {
                    $output = substr($output, 0, 512);
                }

                try {
                    $this->call('order.comment', array($result['unique_id'], sprintf("Script output from \"Order Transform Script\":\n<pre>%s</pre>", $output)));
                } catch (Exception $e) {
                    $this->log(sprintf('Error saving Order Transform Script output comment on order %s: %s', $result['unique_id'], $e->getMessage()), self::ERR);
                }
            }
        } catch (Plugin_Exception $e) {
            $this->log(sprintf("Failed to submit order: %s\n%s", $e->getMessage(), json_encode($newOrderData)));
            if (empty($e->getSubjectType())) {
                $e->setSubject('WooCommerce Order', $wooCommerceOrder['order_increment_id']);
            }

            $e->setSkipAutoRetry(TRUE); // Do not retry order creations as errors are usually not temporary
            try {
                $message = sprintf('Order could not be submitted due to the following error: %s', $e->getMessage());
                $this->_addComment($wooCommerceOrder['order_increment_id'], 'wc-failed-to-submit', $message);
            } catch (Exception $ex) {
            }

            throw $e;
        } finally {
            $this->_unlockOrderImport();
        }

        // Update WooCommerce order status and add comment
        $this->_addComment($wooCommerceOrder['order_increment_id'], 'wc-submitted', sprintf('Created %s Order # %s', $this->getAppTitle(), $result['unique_id']));
    }

    /**
     * Adjust inventory
     *
     * @param Varien_Object $data
     * @throws Exception
     */
    public function adjustInventoryEvent(Varien_Object $data)
    {
        foreach ($data->getStockAdjustments() as $sku => $change) {
            if (empty($sku) || empty($change['qty_adjust'])) {
                continue;
            }

            $this->_wooCommerceApi('shipstream/v1/stock_item/adjust', 'POST', array($sku, (float)$change['qty_adjust']));
            $this->log(sprintf('Adjusted inventory for the product %s. Adjustment: %.4f.', $sku, $change['qty_adjust']));
        }
    }

    /**
     * Update WooCommerce order from shipment:packed data
     *
     * @param Varien_Object $data
     * @throws Plugin_Exception
     */
   
    public function shipmentPackedEvent(Varien_Object $data)
    {
        $clientOrderId = $this->_getWooCommerceShipmentId($data->getSource());
        $clientOrder = $this->_wooCommerceApi('shipstream/v1/order_shipment/info', 'POST', $clientOrderId);

        if (!in_array($clientOrder['status'], array('wc-submitted', 'wc-failed-to-submit'))) {
            throw new Plugin_Exception("Order $clientOrderId status is '{$clientOrder['status']}', expected 'wc-submitted'.");
        }

        if ($clientOrder['status'] == 'wc-failed-to-submit') {
            $this->log(sprintf('Order # %s was Failed to Submit, but we assume it is ok to complete it anyway.', $clientOrderId));
        }

        $payload = $data->getData();
        $payload['warehouse_name'] = $this->_getWarehouseName($data->getWarehouseId());
        $wooCommerceShipmentId = $this->_wooCommerceApi('shipstream/v1/order_shipment/create_with_tracking', 'POST', array($clientOrderId, $payload));

        $this->log(sprintf('Created WooCommerce shipment # %s for order # %s', $wooCommerceShipmentId, $clientOrderId));
    }


    /****************************
     * Internal Event Observers *
     ****************************/

    /**
     * Respond to the delivery committed webhook
     *
     * @param Varien_Object $data
     * @throws Plugin_Exception
     */
    public function respondDeliveryCommitted(Varien_Object $data)
    {
        $this->addEvent('adjustInventoryEvent', array('stock_adjustments' => $data->getStockAdjustments()));
    }

    /**
     * Respond to the inventory adjustment webhook
     *
     * @param Varien_Object $data
     * @throws Plugin_Exception
     */
    public function respondInventoryAdjusted(Varien_Object $data)
    {
        $this->addEvent('adjustInventoryEvent', array('stock_adjustments' => $data->getStockAdjustments()));
    }

    /**
     * Respond to shipment:packed event, completes the fulfillment
     *
     * @param Varien_Object $data
     * @throws Plugin_Exception
     */
    public function respondShipmentPacked(Varien_Object $data)
    {
        if ($this->_getWooCommerceShipmentId($data->getSource())) {
            $this->addEvent('shipmentPackedEvent', $data->toArray());
        }
    }

   
/************************
     * Callbacks (<routes>) *
     ************************/

    /**
     * Inventory with order import lock request handler
     *
     * @param array $query
     * @return string
     */
    public function inventoryWithLock(array $query): string
    {
        $result = $skus = array();
        try {
            $this->_lockOrderImport();
            $rows = $this->call('inventory.list', empty($query['sku']) ? NULL : strval($query['sku']));
            foreach ($rows as $row) {
                $qtyAdvertised = intval($row['qty_advertised']);
                $qtyBackOrdered = intval($row['qty_backordered']);
                $skus[$row['sku']] = $qtyAdvertised > 0 ? $qtyAdvertised : -$qtyBackOrdered;
            }

            $result['skus'] = $skus;
        } catch (Plugin_Exception $e) {
            $result['errors'] = $e->getMessage();
        } catch (Exception $e) {
            $result['errors'] = 'An unexpected error occurred while retrieving the inventory.';
        }

        return json_encode($result);
    }

    /**
     * @throws Exception
     */
    public function unlockOrderImport(): bool
    {
        $this->_unlockOrderImport();
        return TRUE;
    }

    /**
     * @throws Plugin_Exception|Exception
     */
    public function lockOrderImport(): bool
    {
        $this->_lockOrderImport();
        return TRUE;
    }

    /**
     * Callback to import an order.
     *
     * @param array $query
     * @return string|true
     */
    public function syncOrder(array $query)
    {
        if (isset($query['increment_id'])) {
            try {
                $this->addEvent('importOrderEvent', array('increment_id' => $query['increment_id']));
                return TRUE;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        } else {
            $error = 'Invalid query.';
        }

        return json_encode(array('errors' => $error));
    }

    /*********************
     * Protected methods *
     *********************/

    /**
     * Import orders
     *
     * @param string|null $from
     *
     * @throws Plugin_Exception
     * @throws Exception
     */
    protected function _importOrders(string $from = NULL)
    {
        // Do not import orders while inventory is being synced
        $state = $this->getState(self::STATE_LOCK_ORDER_PULL, TRUE);
        if (! empty($state['value']) && $state['value'] == 'locked') {
            $this->log(sprintf('%s','Order import is currently locked. Please unlock it to proceed with importing orders.'));
        }

        $now = time();
        $limit = 100;
        if (is_null($from)) {
            $from = $this->getConfig(self::STATE_ORDER_LAST_SYNC_AT);
            if (empty($from)) {
                $from = date(self::DATE_FORMAT, $now - (86400*5)); // Go back up to 5 days
            }
        } else {
            $from .= ' 00:00:00';
        }

        $to = date(self::DATE_FORMAT, $now);

        // Order statuses for which orders should be automatically fulfilled
        $status = $this->getConfig('auto_fulfill_status');
        if ($status === 'custom') {
            $statuses = $this->getConfig('auto_fulfill_custom');
            $statuses = preg_split('/\s*,\s*/', trim($statuses), -1, PREG_SPLIT_NO_EMPTY);
            if (! is_array($statuses)) {
                $statuses = $statuses ? array($statuses) : array();
            }

            // Sanitize - map "Ready To Ship" to "ready_to_ship"
            $statuses = array_map(
                function($status) {
                return strtolower(str_replace(' ', '_', $status));
                }, $statuses
            );
        } else if ($status && $status !== '-') {
            $statuses = array(strtolower(str_replace(' ', '_', $status)));
        } else {
            $statuses = NULL;
        }

        // Automatic fulfillment. When a new order is found in the specified statuses,
        // an order should be created on the ShipStream side and status updated to Submitted.
        if ($statuses) {
            do {
                $updatedAtMin = $from;
                $updatedAtMax = $to;
                $filters = array(
                    'date_updated_gmt' => array('from' => $updatedAtMin, 'to' => $updatedAtMax),
                    'status' => array('in' => $statuses),
                );
                $data = $this->_wooCommerceApi('shipstream/v1/order/list', 'POST', $filters);
                foreach ($data as $orderData) {
                    if (strcmp($orderData['date_modified']['date'], $updatedAtMin) > 0) {
                        $updatedAtMin = date('c', strtotime($orderData['date_modified']['date'])+1);
                    }

                    $this->addEvent('importOrderEvent', array('increment_id' => $orderData['id']));
                    $this->log(sprintf('Queued import for order %s', $orderData['id']));
                }
            } while (count($data) == $limit && strcmp($updatedAtMin, $updatedAtMax) < 0);
            $this->setState(self::STATE_ORDER_LAST_SYNC_AT, $updatedAtMax);
        }
    }

    /**
     * Set flag that prevents client Woocommerce orders from being imported
     *
     * @return bool
     * @throws Exception
     */
    protected function _lockOrderImport(): bool
    {
        $seconds = 0;
        do {
            $state = $this->getState(self::STATE_LOCK_ORDER_PULL, TRUE);
            if (empty($state['value']) || empty($state['date_updated_gmt']) || $state['value'] == 'unlocked') {
                if ($this->setState(self::STATE_LOCK_ORDER_PULL, 'locked')) {
                    return TRUE;
                }
            }

            $now = new DateTime(date('Y-m-d H:i:s', time()));
            $updatedAt = new DateTime($state['date_updated_gmt']);
            $interval = $now->diff($updatedAt);
            // Consider the lock to be stale if it is older than 1 minute
            if ($interval->i >= 1) {
                if ($this->setState(self::STATE_LOCK_ORDER_PULL, 'locked')) {
                    return TRUE;
                }
            }

            sleep(1);
            $seconds++;
        } while($seconds < 20);

        throw new Plugin_Exception('Cannot lock order importing.');
    }

    /**
     * Unlock order importing
     *
     * @return void
     */
    protected function _unlockOrderImport()
    {
        try {
            $this->setState(self::STATE_LOCK_ORDER_PULL, 'unlocked');
        } catch (Exception $e) {
            $this->log(sprintf('Cannot unlock order importing. Error: %s', $e->getMessage()));
        }
    }


    /**
     * Method is originally used for mapping Shopify shipping_lines to ShipStream shipping.
     * Reused as is for WooCommerce.
     *
     * Map Shopify shipping method
     *
     * @param array $data
     * @return string
     * @throws Plugin_Exception
     * @throws Exception
     */
    protected function _getShippingMethod(array $data): string
    {
        $shippingLines = $data['shipping_lines'];
        if (empty($shippingLines)) {
            $shippingLines = array(array('shipping_description' => 'unknown', 'shipping_method' => 'unknown'));
        }

        // Extract shipping method
        $_shippingMethod = NULL;
        $rules = $this->getConfig('shipping_method_config');
        $rules = json_decode($rules, TRUE);
        $rules = empty($rules) ? array() : $rules;

        foreach ($shippingLines as $shippingLine) {
            if ($_shippingMethod === NULL) {
                $_shippingMethod = $shippingLine['shipping_method'] ?? NULL;
            }

            foreach ($rules as $rule) {
                if (count($rule) != 4) {
                    throw new Plugin_Exception('Invalid shipping method rule.');
                }

                foreach (array('shipping_method', 'field', 'operator', 'pattern') as $field) {
                    if (empty($rule[$field])) {
                        throw new Plugin_Exception('Invalid shipping method rule.');
                    }
                }

                list($shippingMethod, $field, $operator, $pattern) = array(
                    $rule['shipping_method'], $rule['field'], $rule['operator'], $rule['pattern']
                );
                $compareValue = empty($shippingLine[$field]) ? '' : $shippingLine[$field];
                if ($operator == '=~') {
                    if (@preg_match('/^'.$pattern.'$/i', NULL, $matches) === FALSE && $matches === NULL) {
                        throw new Plugin_Exception('Invalid RegEx expression after "=~" operator', NULL, NULL, 'Get shipping method');
                    }

                    if (preg_match('/^'.$pattern.'$/i', $compareValue)) {
                        $_shippingMethod = $shippingMethod;
                        break 2;
                    }
                }
                else {
                    $pattern = str_replace(array('"', "'"), '', $pattern);
                    if ($operator == '=' && $compareValue == $pattern) {
                        $_shippingMethod = $shippingMethod;
                        break 2;
                    }
                    else {
                        if ($operator == '!=' && $compareValue != $pattern) {
                            $_shippingMethod = $shippingMethod;
                            break 2;
                        }
                    }
                }
            }
        }

        if (empty($_shippingMethod)) {
            throw new Plugin_Exception('Cannot identify shipping method.', NULL, NULL, 'Get shipping method');
        }

        return $_shippingMethod;
    }


    protected function _checkItem($productType): bool
    {
        return ($productType && $productType == 'simple');
    }

    /**
     * Update WooCommerce order status and add comment.
     *
     * @param string $orderIncrementId
     * @param string $orderStatus
     * @param string $comment
     * @param string $appTitle
     * @param string $shipstreamId
     * @return void
     */
    protected function _addComment(string $orderIncrementId, string $orderStatus, string $comment = '', string $appTitle = '', string $shipstreamId = '')
    {
        try {
            $comment_data = array(
                'order_id'      => $orderIncrementId,
                'status'        => $orderStatus,
                'comment'       => $comment,
                'apptitle'      => $appTitle,
                'shipstreamid'  => $shipstreamId,
            );
            $this->_wooCommerceApi('shipstream/v1/order/addComment', 'POST', $comment_data);
            $message = sprintf('Status of order # %s was changed to %s in merchant site, comment: %s', $orderIncrementId, $orderStatus, $comment);
            $this->log($message);
        } catch (Throwable $e) {
            $message = sprintf('Order status could not be changed in merchant site due to the following error: %s', $e->getMessage());
            $this->log($message, self::ERR);
        }
    }

    /**
     * @param string $endpoint
     * @param string $method
     * @param array $params
     *
     * @return mixed|void
     * @throws Plugin_Exception
     */
    protected function _wooCommerceApi(string $endpoint, string $method = 'POST', array $params = array())
    {
        try {
            return $this->getClient()->request($endpoint, $method, $params);
        } catch (Exception $e) {
            throw new Plugin_Exception($e->getMessage());
        } catch (GuzzleException $e) {
        }

    }

    /**
     * @return ShipStream_WooCommerce_Client
     * @throws Exception
     */
    protected function getClient(): ShipStream_WooCommerce_Client
    {
        if (!$this->_client) {
            $this->_client = new ShipStream_WooCommerce_Client(
                array(
                'base_url' => $this->getConfig('api_url'),
                'consumer_key' => $this->getConfig('consumer_key'),
                'consumer_secret' => $this->getConfig('consumer_secret'),
                )
            );
        }

        return $this->_client;
    }

    protected function validateDate(string $date): bool
    {
        return preg_match(self::DATE_PATTERN, $date);
    }

    /**
     * @param array $wooCommerceAddress
     * @return array
     */
    protected function formatShippingAddress(array $wooCommerceAddress): array
    {
        return array(
            'firstname' => isset($wooCommerceAddress['first_name']) ? $wooCommerceAddress['first_name'] : '',
            'lastname' => isset($wooCommerceAddress['last_name']) ? $wooCommerceAddress['last_name'] : '',
            'company' => isset($wooCommerceAddress['company']) ? $wooCommerceAddress['company'] : '',
            'street1' => isset($wooCommerceAddress['address_1']) ? $wooCommerceAddress['address_1'] . ' ' . (isset($wooCommerceAddress['address_2']) ? $wooCommerceAddress['address_2'] : '') : '',
            'city' => isset($wooCommerceAddress['city']) ? $wooCommerceAddress['city'] : '',
            'region' => isset($wooCommerceAddress['state']) ? $wooCommerceAddress['state'] : '',
            'postcode' => isset($wooCommerceAddress['postcode']) ? $wooCommerceAddress['postcode'] : '',
            'country' => isset($wooCommerceAddress['country']) ? $wooCommerceAddress['country'] : '',
            'telephone' => isset($wooCommerceAddress['phone']) ? $wooCommerceAddress['phone'] : ''
        );
    }


    /**
     * @param array $wooCommerceItems
     * @return array
     */
    protected function getOrderItems(array $wooCommerceItems): array
    {
        $orderItems = array();
        $skus       = array();
        foreach ($wooCommerceItems as $item) {
            if ($this->_checkItem($item['product_type'])) {
                continue;
            }
            $orderItems[] = array(
                'sku' => $item['sku'],
                'name' => $item['name'],
                'qty' => (int)$item['quantity']
            );
            $skus[] = $item['sku'];
        }

        return array($orderItems,$skus);
    }

    /**
     * @param array $newOrderData
     * @param array $wooCommerceOrder
     * @param string $logPrefix
     * @throws Plugin_Exception
     */
    protected function submitOrder(array $newOrderData, array $wooCommerceOrder, string $logPrefix)
    {
        $this->log($logPrefix . 'Submitting Order...');
        try {
            $result = $this->call('order.import', array($newOrderData));
            if (!$result['success']) {
                throw new Plugin_Exception($result['message']);
            }

            $this->log($logPrefix . sprintf('Order Submitted to ShipStream: Order # %s', $result['unique_id']));
            $this->_addComment($wooCommerceOrder['order_increment_id'], 'wc-submitted', sprintf('Submitted to ShipStream: Order # %s - %s', $this->getAppTitle(), $result['unique_id']));
        } catch (Exception $e) {
            $this->_addComment($wooCommerceOrder['order_increment_id'], 'wc-failed-to-submit', sprintf('Failed to Submit to ShipStream: %s', $this->getAppTitle()));
            throw new Plugin_Exception($e->getMessage());
        }
    }

    /**
     * @param string $source
     * @return string|null
     */
    protected function _getWooCommerceShipmentId(string $source): ?string
    {
        if (strpos($source, 'woocommerce_shipment:') === 0) {
            return substr($source, strlen('woocommerce_shipment:'));
        }

        return NULL;
    }

    /**
     * @param int $warehouseId
     * @return string|null
     * @throws Plugin_Exception
     */

    protected function _getWarehouseName(int $warehouseId): ?string
    {
        $warehouse = $this->call('warehouse.get', array($warehouseId));
        return $warehouse['name'] ?? NULL;
    }
}
