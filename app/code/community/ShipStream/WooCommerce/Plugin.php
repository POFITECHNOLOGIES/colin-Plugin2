<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magento.com for more information.
 *
 *
 * @package   ShipStream_WooCommmerce
 * @copyright Copyright (c) 2024 Magento, Inc. (http://www.magento.com)
 * @license   Open Software License (OSL 3.0)
 */


/**
 * The ShipStream order.increment_id is the WooCommerce
 * shipment.increment_id.
 * The ShipStream order ref (order.ext_order_id) is the
 * WooCommerce order.increment_id.
 */
class ShipStream_WooCommerce_Plugin extends Plugin_Abstract
{
    const DATE_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})$/';
    const DATE_FORMAT = 'Y-m-d H:i:s';

    const STATE_ORDER_LAST_SYNC_AT = 'order_last_sync_at';
    const STATE_LOCK_ORDER_PULL = 'lock_order_pull';
    const STATE_FULFILLMENT_SERVICE_REGISTERED = 'fulfillment_service_registered';

    /**
     * Client variable
     *
     * @var ?ShipStream_WooCommerce_Client
     */
    protected ?ShipStream_WooCommerce_Client $_client = null;

    /**
     * Check the connection config
     *
     * @return bool
     * @throws Exception
     */
    public function hasConnectionConfig(): bool
    {
        return $this->getConfig('api_url')
            && $this->getConfig('api_login')
            && $this->getConfig('api_password');
    }

    /**
     * Debug the connection
     *
     * @param bool $super Global variable
     *
     * @return array
     * @throws Plugin_Exception
     * @throws Exception
     */
    public function connectionDiagnostics(bool $super = false): array
    {
        $info = $this->wooCommerceApi('shipstream/v1/info');
        if ($this->isFulfillmentServiceRegistered()) {
            $serviceStatus = 'Registered';
        } else {
            $serviceStatus = 'Not registered';
        }

        if ($info['woocommerce_version']) {
            $woocommerceVersion = $info['woocommerce_version'];
        } else {
            $woocommerceVersion = 'undefined';
        }

        return array(
            sprintf(
                'WooCommerce Version: %s', $woocommerceVersion
            ),
            sprintf(
                'WordPress Version: %s', $info['wordpress_version'] ?? 'undefined'
            ),
            sprintf(
                'ShipStream Sync Version: %s', $info['shipstream_sync_version'] ?? 'undefined'
            ),
            sprintf(
                'Service Status: %s',
                $serviceStatus
            )
        );
    }


    /**
     * Activate the plugin
     *
     * @return string[]
     * @throws Exception
     */
    public function activate(): array
    {
        $warnings = array();
        try {
            $this->registerFulfillmentService();
        } catch (Plugin_Exception $e) {
            $warnings[] = $e->getMessage();
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
                    self::STATE_LOCK_ORDER_PULL => null,
                    self::STATE_ORDER_LAST_SYNC_AT => null,
                    self::STATE_FULFILLMENT_SERVICE_REGISTERED => null,
                )
            );
        } catch (Plugin_Exception $e) {
            $errors[] = $e->getMessage();
        }

        return $errors;
    }

    /**
     * Reinstall the plugin
     *
     * @return string[]
     * @throws Exception
     */
    public function reinstall(): array
    {
        return $this->activate();
    }

    /**
     * Trigger an inventory sync from the WooCommerce side which is more atomic
     *
     * @return void
     * @throws Plugin_Exception
     */
    public function syncInventory(): void
    {
        $result = $this->wooCommerceApi('shipstream/v1/sync_inventory', 'POST');
        if (isset($result['success'])) {
            if (!$result['success']) {
                throw new Plugin_Exception('Unexpected response format.');
            }
        } else {
            throw new Plugin_Exception('Unexpected response format.');
        }
    }


    /**
     * Synchronize orders since the configured date
     *
     * @return void
     * @throws Plugin_Exception
     * @throws Exception
     */
    public function syncOrders(): void
    {
        $since = $this->getConfig('sync_orders_since');
        if ($since && !$this->validateDate($since)) {
            throw new Plugin_Exception(
                'Invalid synchronize orders since date format. Valid format: YYYY-MM-DD.'
            );
        }

        $this->_importOrders($since);
    }

    /**
     * Synchronize orders since the last sync
     *
     * @return void
     * @throws Exception
     */
    public function cronSyncOrders(): void
    {
        $this->_importOrders();
    }

    /**
     * Check is the service is fulfillment or not
     *
     * @return array|string|null
     * @throws Exception
     */
    public function isFulfillmentServiceRegistered(): array|string|null
    {
        return $this->getState(self::STATE_FULFILLMENT_SERVICE_REGISTERED);
    }

    /**
     * Register fulfillment service
     *
     * @return void
     * @throws Plugin_Exception
     * @throws Exception
     */
    public function registerFulfillmentService(): void
    {
        $apiConfigData = array(
            'path' => 'warehouse_api_url',
            'value' => $this->getCallbackUrl(null)
        );
        if ($this->wooCommerceApi(
            'shipstream/v1/set_config',
            'POST',
            $apiConfigData
        )
        ) {
            $this->setState(self::STATE_FULFILLMENT_SERVICE_REGISTERED, true);
        }
    }

    /**
     * Unregister fulfillment service
     *
     * @return void
     * @throws Plugin_Exception
     */
    public function unregisterFulfillmentService(): void
    {
        $apiConfigData = array('path' => 'warehouse_api_url', 'value' => null);
        $this->wooCommerceApi('shipstream/v1/set_config', 'POST', $apiConfigData);
        $this->setState(self::STATE_FULFILLMENT_SERVICE_REGISTERED);
    }

    /*****************
     * Event methods *
     *****************/

    /**
     * Import client order
     *
     * @param Varien_Object $data Varien object
     *
     * @return void
     * @throws Exception
     */
    public function importOrderEvent(Varien_Object $data): void
    {
        $orderIncrementId = $data->getData('increment_id');
        $logPrefix = sprintf('WooCommerce Order # %s: ', $orderIncrementId);

        if ($this->isOrderExists($orderIncrementId)) {
            return;
        }

        $wooCommerceOrder = $this->getWooCommerceOrder($orderIncrementId);
        if (empty($wooCommerceOrder)) {
            return;
        }

        $newOrderData = $this->prepareNewOrderData($wooCommerceOrder);
        if (empty($newOrderData)) {
            return;
        }

        try {
            $this->applyUserScripts($newOrderData, $wooCommerceOrder, $logPrefix);
            $this->createOrder($newOrderData, $wooCommerceOrder);
            $this->updateWooCommerceOrderStatus($wooCommerceOrder, $orderIncrementId);
        } catch (Exception $e) {
            $this->handleException($e, $wooCommerceOrder);
        }

    }

    /**
     * Checks if the order already exists locally.
     *
     * @param string $orderIncrementId The WooCommerce order increment ID.
     *
     * @return bool true if the order exists, false otherwise.
     * @throws Plugin_Exception
     */
    protected function isOrderExists(string $orderIncrementId): bool
    {
        $result = $this->call(
            'order.search',
            array(
                array('order_ref' => $orderIncrementId), array(), array()
            )
        );
        if ($result['totalCount'] > 0) {
            $uniqueId = $result['results'][0]['unique_id'] ?? '';
            $createdAt = $result['results'][0]['created_at'] ?? '';
            $message = sprintf(
                'ShipStream Order # %s was created at %s',
                $uniqueId,
                $createdAt
            );
            $this->addComment($orderIncrementId, 'wc-submitted', $message);
            return true; // Ignore already existing orders
        }

        return false;
    }

    /**
     * Retrieves the WooCommerce order data.
     *
     * @param string $orderIncrementId The WooCommerce order increment ID.
     *
     * @return array The WooCommerce order data.
     * @throws Plugin_Exception
     */
    protected function getWooCommerceOrder(string $orderIncrementId): array
    {
        $orderId = array('shipment_id' => $orderIncrementId);
        return $this->wooCommerceApi(
            'shipstream/v1/order_shipment/info',
            'POST',
            $orderId
        );
    }

    /**
     * Prepares the new order data for submission.
     *
     * @param array $wooCommerceOrder The WooCommerce order data.
     *
     * @return array|null The new order data or null if preparation fails.
     * @throws Plugin_Exception
     * @throws Exception
     */
    protected function prepareNewOrderData(array $wooCommerceOrder): ?array
    {
        $shippingAddress = $this->formatShippingAddress(
            $wooCommerceOrder['shipping_address']
        );
        $orderItems = $this->getOrderItems($wooCommerceOrder['items']);
        if (empty($orderItems)) {
            return null;
        }

        $additionalData = array(
            'order_ref' => $wooCommerceOrder['order_increment_id'],
            'shipping_method' => $this->getShippingMethod($wooCommerceOrder),
            'source' => 'woocommerce:' . $wooCommerceOrder['order_increment_id'],
        );

        return array(
            'store' => null,
            'items' => $orderItems,
            'address' => $shippingAddress,
            'options' => $additionalData,
            'timestamp' => new DateTime('now', $this->getTimeZone()),
        );
    }

    /**
     * Applies user scripts to the new order data.
     *
     * @param array $newOrderData The new order data.
     * @param array $wooCommerceOrder The WooCommerce order data.
     * @param string $logPrefix The log prefix for this order.
     *
     * @return void
     * @throws Plugin_Exception If an error occurs while applying the script.
     * @throws Exception
     */
    protected function applyUserScripts(
        array  &$newOrderData,
        array  $wooCommerceOrder,
        string $logPrefix
    ): void
    {
        if ($script = $this->getConfig('filter_script')) {
            $this->addProductInfoToItems($newOrderData);

            try {
                $output = null;
                $wooCommerceOrder = array('wooCommerceOrder' => $wooCommerceOrder);
                $newOrderData = $this->applyScriptForOrder(
                    $script,
                    $newOrderData,
                    $wooCommerceOrder,
                    $output
                );
                $this->validateNewOrderData($newOrderData);

                if (!empty($newOrderData['skip'])) {
                    $this->log(
                        $logPrefix . 'Order has been skipped by the Order Transform Script.',
                        self::DEBUG
                    );
                    return;
                }

                $this->removeSkippedItems($newOrderData, $logPrefix);
            } catch (Exception $e) {
                throw new Plugin_Exception(
                    'An unexpected error occurred while applying the Order Transform Script.',
                    102,
                    $e
                );
            }
        }
    }

    /**
     * Adds product information to the order items.
     *
     * @param array $newOrderData The new order data.
     *
     * @return void
     * @throws Plugin_Exception
     */
    protected function addProductInfoToItems(array &$newOrderData): void
    {
        $products = $this->call(
            'product.search',
            array(
                array(
                    'sku' => array('in' => $skus)
                )
            )
        )['result'];
        foreach ($newOrderData['items'] as &$item) {
            $item['product'] = null;
            foreach ($products as $product) {
                if ($product['sku'] == $item['sku']) {
                    $item['product'] = $product;
                    break;
                }
            }
        }

        unset($item);
    }

    /**
     * Validates the new order data after script application.
     *
     * @param array $newOrderData The new order data.
     *
     * @return void
     * @throws Plugin_Exception If the data is not valid.
     */
    protected function validateNewOrderData(array $newOrderData): void
    {
        if (!array_key_exists('store', $newOrderData)
            || empty($newOrderData['items'])
            || empty($newOrderData['address'])
            || empty($newOrderData['options'])
        ) {
            throw new Plugin_Exception(
                'The Order Transform Script did not return the data expected.'
            );
        }
    }

    /**
     * Removes skipped items from the new order data.
     *
     * @param array $newOrderData The new order data.
     * @param string $logPrefix The log prefix for this order.
     *
     * @return void
     */
    protected function removeSkippedItems(array &$newOrderData, string $logPrefix): void
    {
        foreach ($newOrderData['items'] as $k => $item) {
            unset($newOrderData['items'][$k]['product']);

            if (!empty($item['skip'])) {
                $sku = $newOrderData['items'][$k]['sku'] ?? '';
                if ($sku) {
                    $this->log(
                        $logPrefix . sprintf(
                            'SKU "%s" has been skipped by the Order Transform Script.',
                            $sku
                        ),
                        self::DEBUG
                    );
                    unset($newOrderData['items'][$k]);
                }
            }
        }

        if (empty($newOrderData['items'])) {
            $this->log(
                $logPrefix . 'All SKUs have been skipped by the Order Transform Script.',
                self::DEBUG
            );
        }
    }

    /**
     * Submits the new order to the order creation service.
     *
     * @param array $newOrderData The new order data.
     * @param array $wooCommerceOrder The WooCommerce order data.
     *
     * @return void
     * @throws Plugin_Exception If an error occurs while submitting the order.
     * @throws Exception
     */
    protected function createOrder(array $newOrderData, array $wooCommerceOrder): void
    {
        $output = NULL;
        $this->_lockOrderImport();
        try {
            $result = $this->call(
                'order.create',
                array($newOrderData['store'],
                    $newOrderData['items'],
                    $newOrderData['address'],
                    $newOrderData['options']
                )
            );
            $this->log(
                sprintf(
                    'Created %s Order # %s for WooCommerce Order # %s',
                    $this->getAppTitle(),
                    $result['unique_id'],
                    $wooCommerceOrder['order_increment_id']
                )
            );
            $this->addScriptOutputComment($result['unique_id'], $output);
        } catch (Plugin_Exception $e) {
            $this->logOrderSubmissionFailure($e, $wooCommerceOrder, $newOrderData);
            throw $e;
        } finally {
            $this->_unlockOrderImport();
        }
    }

    /**
     * Adds the script output as a comment to the order.
     *
     * @param string $orderId The order ID.
     * @param string $output The script output.
     *
     * @return void
     */
    protected function addScriptOutputComment(string $orderId, string $output): void
    {
        if ($output) {
            if (!Mage::getIsDeveloperMode()) {
                $output = substr($output, 0, 512);
            }

            try {
                $this->call(
                    'order.comment',
                    array(
                        $orderId,
                        sprintf(
                            "Script output from \"Order Transform Script\":\n<pre>%s</pre>",
                            $output
                        )
                    )
                );
            } catch (Exception $e) {
                $this->log(
                    sprintf(
                        'Error saving Order Transform Script output comment on order %s: %s',
                        $orderId,
                        $e->getMessage()
                    ),
                    self::ERR
                );
            }
        }
    }

    /**
     * Logs the failure of order submission.
     *
     * @param Plugin_Exception $e The exception.
     * @param array $wooCommerceOrder Woocommerce order
     * @param array $newOrderData The new order data.
     *
     * @return void
     */
    protected function logOrderSubmissionFailure(
        Plugin_Exception $e,
        array            $wooCommerceOrder,
        array $newOrderData
    ): void
    {
        $this->log(
            sprintf(
                "Failed to submit order: %s\n%s",
                $e->getMessage(),
                json_encode($newOrderData)
            )
        );
        if (empty($e->getSubjectType())) {
            $e->setSubject(
                'WooCommerce Order',
                $wooCommerceOrder['order_increment_id']
            );
        }

        $e->setSkipAutoRetry(true); // Do not retry order creations as errors are usually not temporary
        try {
            $message = sprintf(
                'Order could not be submitted due to the following error: %s',
                $e->getMessage()
            );
            $this->addComment(
                $wooCommerceOrder['order_increment_id'],
                'wc-failed-to-submit',
                $message
            );
        } catch (Exception $ex) {
            $this->log(
                sprintf(
                    'Order could not be submitted due to the following error: %s',
                    $ex->getMessage()
                ),
                self::ERR
            );
        }
    }


    /**
     * Handles exceptions during the import process.
     *
     * @param Exception $e The exception.
     * @param array $wooCommerceOrder The WooCommerce order data.
     *
     * @return void
     * @throws Exception
     */
    protected function handleException(Exception $e, array $wooCommerceOrder): void
    {
        if (empty($e->getSubjectType())) {
            $e->setSubject(
                'WooCommerce Order',
                $wooCommerceOrder['order_increment_id']
            );
        }

        try {
            $message = sprintf(
                'Order could not be submitted due to the following Order Transform Script error: %s',
                $e->getMessage()
            );
            $this->addComment(
                $wooCommerceOrder['order_increment_id'],
                'wc-failed-to-submit',
                $message
            );
        } catch (Exception $ex) {
            $this->log(
                sprintf(
                    'Order could not be submitted due to the following Order Transform Script error: %s',
                    $ex->getMessage()
                ),
                self::ERR
            );
        }

        throw $e;
    }


    /**
     * Updates the status of the WooCommerce order.
     *
     * @param array $wooCommerceOrder The WooCommerce order data.
     * @param $orderIncrementId
     *
     * @return void
     * @throws Plugin_Exception
     */
    protected function updateWooCommerceOrderStatus(array $wooCommerceOrder, $orderIncrementId): void
    {
        $result = $this->call('order.search', [['order_ref' => $orderIncrementId],[], []]);
        $this->addComment(
            $wooCommerceOrder['order_increment_id'],
            'wc-submitted',
            sprintf(
                'Created %s Order # %s',
                $this->getAppTitle(),
                $result['unique_id']
            )
        );
    }


    /**
     * Adjust inventory
     *
     * @param Varien_Object $data Variend data object
     *
     *
     * @throws Exception
     */
    public function adjustInventoryEvent(Varien_Object $data)
    {
        foreach ($data->getStockAdjustments() as $sku => $change) {
            if (empty($sku) || empty($change['qty_adjust'])) {
                continue;
            }

            $this->wooCommerceApi(
                'shipstream/v1/stock_item/adjust',
                'POST',
                array(
                    $sku,
                    (float)$change['qty_adjust']
                )
            );
            $this->log(
                sprintf(
                    'Adjusted inventory for the product %s. Adjustment: %.4f.',
                    $sku,
                    $change['qty_adjust']
                )
            );
        }
    }

    /**
     * Update WooCommerce order from shipment:packed data
     *
     * @param Varien_Object $data Varien Object
     *
     * @return void
     *
     * @throws Plugin_Exception
     */
    public function shipmentPackedEvent(Varien_Object $data): void
    {
        $clientOrderId = $this->getWooCommerceShipmentId($data->getSource());
        $clientOrder = $this->wooCommerceApi('shipstream/v1/order_shipment/info', 'POST', $clientOrderId);

        if (!in_array(
            $clientOrder['status'],
            array('wc-submitted', 'wc-failed-to-submit')
        )
        ) {
            throw new Plugin_Exception(
                "Order $clientOrderId status is '{$clientOrder['status']}', expected 'wc-submitted'."
            );
        }

        $payload = $data->getData();
        $payload['warehouse_name'] = $this->_getWarehouseName(
            $data->getWarehouseId()
        );
        $wooCommerceShipmentId = $this->wooCommerceApi(
            'shipstream/v1/order_shipment/create_with_tracking',
            'POST',
            array(
                $clientOrderId,
                $payload
            )
        );

        $this->log(
            sprintf(
                'Created WooCommerce shipment # %s for order # %s',
                $wooCommerceShipmentId,
                $clientOrderId
            )
        );
    }

    /****************************
     * Internal Event Observers *
     ****************************/

    /**
     * Respond to the delivery committed webhook
     *
     * @param Varien_Object $data Varien Object data
     *
     * @return void
     * @throws Plugin_Exception
     */
    public function respondDeliveryCommitted(Varien_Object $data): void
    {
        $this->addEvent(
            'adjustInventoryEvent',
            array(
                'stock_adjustments' => $data->getStockAdjustments()
            )
        );
    }

    /**
     * Respond to the inventory adjustment webhook
     *
     * @param Varien_Object $data Varien Object data
     *
     * @throws Plugin_Exception
     */
    public function respondInventoryAdjusted(Varien_Object $data)
    {
        $this->addEvent(
            'adjustInventoryEvent',
            array(
                'stock_adjustments' => $data->getStockAdjustments()
            )
        );
    }

    /**
     * Respond to shipment:packed event, completes the fulfillment
     *
     * @param Varien_Object $data Varien Object data
     *
     * @throws Plugin_Exception
     */
    public function respondShipmentPacked(Varien_Object $data)
    {
        if ($this->getWooCommerceShipmentId($data->getSource())) {
            $this->addEvent('shipmentPackedEvent', $data->toArray());
        }
    }


    /**
     * **********************
     * Callbacks (<routes>) *
     * **********************
     */

    /**
     * Inventory with order import lock request handler
     *
     * @param array $query Inventory Data
     *
     * @return string
     */
    public function inventoryWithLock(array $query): string
    {
        $result = $skus = array();
        try {
            $this->_lockOrderImport();
            $rows = $this->call(
                'inventory.list',
                empty($query['sku']) ? null : (string)$query['sku']
            );
            foreach ($rows as $row) {
                $qtyAdvertised = (int)$row['qty_advertised'];
                $qtyBackOrdered = (int)$row['qty_backordered'];
                $skus[$row['sku']] = $qtyAdvertised > 0
                    ? $qtyAdvertised
                    : -$qtyBackOrdered;
            }

            $result['skus'] = $skus;
        } catch (Plugin_Exception $e) {
            $result['errors'] = $e->getMessage();
        } catch (Exception $e) {
            $result['errors'] = 'An unexpected error occurred while retrieving the inventory.' . $e->getMessage();
        }

        return json_encode($result);
    }


    public function unlockOrderImport(): bool
    {
        $this->_unlockOrderImport();
        return TRUE;
    }

    /**
     * Call lock order to import
     *
     * @return bool
     * @throws Plugin_Exception|Exception
     *
     */
    public function lockOrderImport(): bool
    {
        $this->_lockOrderImport();
        return true;
    }

    /**
     * Callback to import an order.
     *
     * @param array $query Order Query data
     *
     * @return string|true
     */
    public function syncOrder(array $query): bool|string
    {
        if (isset($query['increment_id'])) {
            try {
                $this->addEvent(
                    'importOrderEvent',
                    array('increment_id' => $query['increment_id'])
                );
                return true;
            } catch (Exception $e) {
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
     * Import orders within a given date range and statuses.
     *
     * @param string|null $from Date from which to start importing orders.
     *
     * @return void
     * @throws Exception
     */
    protected function _importOrders(string $from = null): void
    {
        if ($this->isOrderImportLocked()) {
            $this->log(
                sprintf(
                    'Order import is currently locked. %s',
                    'Please unlock it to proceed with importing orders.'
                )
            );
        }

        list($from, $to) = $this->getDateRange($from);
        $statuses = $this->getAutoFulfillStatuses();

        if ($statuses) {
            $this->processOrders($from, $to, $statuses);
        }
    }

    /**
     * Check if the order import is locked.
     *
     * @return bool
     * @throws Exception
     */
    protected function isOrderImportLocked(): bool
    {
        $state = $this->getState(self::STATE_LOCK_ORDER_PULL, true);
        return !empty($state['value']) && $state['value'] == 'locked';
    }

    /**
     * Get the date range for importing orders.
     *
     * @param string|null $from Date from which to start importing orders.
     *
     * @return array
     * @throws Exception
     */
    protected function getDateRange(?string $from): array
    {
        $now = strtotime("now");
        $dateTime = new DateTime();
        if ($from == null) {
            $from = $this->getConfig(self::STATE_ORDER_LAST_SYNC_AT);
            if (empty($from)) {
                $from = $dateTime->createFromFormat(
                    self::DATE_FORMAT,
                    $now - (86400 * 5)
                );
            }
        } else {
            $from .= ' 00:00:00';
        }

        $to = date(self::DATE_FORMAT, $now);
        return array($from, $to);
    }

    /**
     * Get the order statuses for automatic fulfillment.
     *
     * @return array|null
     * @throws Exception
     */
    protected function getAutoFulfillStatuses(): ?array
    {
        $status = $this->getConfig('auto_fulfill_status');
        if ($status === 'custom') {
            $statuses = $this->getConfig('auto_fulfill_custom');
            $statuses = preg_split(
                '/\s*,\s*/',
                trim($statuses),
                -1,
                PREG_SPLIT_NO_EMPTY
            );
            if (!is_array($statuses)) {
                $statuses = $statuses ? array($statuses) : array();
            }

            // Sanitize - map "Ready To Ship" to "ready_to_ship"
            $statuses = array_map(
                function ($status) {
                    return strtolower(str_replace(' ', '_', $status));
                },
                $statuses
            );
        } elseif ($status && $status !== '-') {
            $statuses = array(strtolower(str_replace(' ', '_', $status)));
        } else {
            $statuses = null;
        }

        return $statuses;
    }

    /**
     * Process orders within the given date range and statuses.
     *
     * @param string $from Start date for the order import.
     * @param string $to End date for the order import.
     * @param array $statuses Array of order statuses to filter by.
     *
     * @return void
     * @throws Plugin_Exception
     */
    protected function processOrders(string $from, string $to, array $statuses): void
    {
        $limit = 100;
        do {
            $updatedAtMin = $from;
            $updatedAtMax = $to;
            $filters = array(
                'date_updated_gmt' => array(
                    'from' => $updatedAtMin,
                    'to' => $updatedAtMax
                ),
                'status' => array('in' => $statuses),
            );
            $data = $this->wooCommerceApi(
                'shipstream/v1/order/list',
                'POST',
                $filters
            );
            foreach ($data as $orderData) {
                if (strcmp($orderData['date_modified']['date'], $updatedAtMin) > 0) {
                    $updatedAtMin = date(
                        'c',
                        strtotime($orderData['date_modified']['date']) + 1
                    );
                }

                $this->addEvent(
                    'importOrderEvent',
                    array('increment_id' => $orderData['id'])
                );
                $this->log(sprintf('Queued import for order %s', $orderData['id']));
            }
        } while (count($data) == $limit && strcmp($updatedAtMin, $updatedAtMax) < 0);
        $this->setState(self::STATE_ORDER_LAST_SYNC_AT, $updatedAtMax);
    }

    /**
     * Set flag that prevents client Woocommerce orders from being imported
     *
     * @return bool
     * @throws Exception
     */
    public function _lockOrderImport(): bool
    {
        $seconds = 0;
        do {
            $state = $this->getState(self::STATE_LOCK_ORDER_PULL, true);
            if (empty($state['value'])
                || empty($state['date_updated_gmt'])
                || $state['value'] == 'unlocked'
            ) {
                if ($this->setState(self::STATE_LOCK_ORDER_PULL, 'locked')) {
                    return true;
                }
            }

            $now = new DateTime(date('Y-m-d H:i:s', time()));
            $updatedAt = new DateTime($state['date_updated_gmt']);
            $interval = $now->diff($updatedAt);
            // Consider the lock to be stale if it is older than 1 minute
            if ($interval->i >= 1) {
                if ($this->setState(self::STATE_LOCK_ORDER_PULL, 'locked')) {
                    return true;
                }
            }

            sleep(1);
            $seconds++;
        } while ($seconds < 20);

        throw new Plugin_Exception('Cannot lock order importing.');
    }

    /**
     * Unlock order importing
     *
     * @return void
     */
    protected function _unlockOrderImport(): void
    {
        try {
            $this->setState(self::STATE_LOCK_ORDER_PULL, 'unlocked');
        } catch (Exception $e) {
            $this->log(
                sprintf(
                    'Cannot unlock order importing. Error: %s', $e->getMessage()
                )
            );
        }
    }


    /**
     * Extracts the shipping method from the provided data.
     *
     * @param array $data The input data containing shipping lines.
     *
     * @return string The identified shipping method.
     * @throws Plugin_Exception When error in the shipping method rules.
     */
    protected function getShippingMethod(array $data): string
    {
        $shippingLines = $this->getShippingLines($data);
        $rules = $this->getShippingRules();

        return $this->identifyShippingMethod($shippingLines, $rules);
    }

    /**
     * Retrieves the shipping lines from the input data,
     * ensuring there is at least a default value.
     *
     * @param array $data Data containing shipping lines and other information.
     *
     * @return array The shipping lines.
     */
    protected function getShippingLines(array $data): array
    {
        $shippingLines = $data['shipping_lines'];
        if (empty($shippingLines)) {
            $shippingLines = array(
                array(
                    'shipping_description' => 'unknown',
                    'shipping_method' => 'unknown'
                )
            );
        }

        return $shippingLines;
    }

    /**
     * Retrieves and decodes the shipping method rules from configuration.
     *
     * @return array The decoded shipping method rules.
     * @throws Plugin_Exception|Exception If the rules are invalid.
     */
    protected function getShippingRules(): array
    {
        $rules = $this->getConfig('shipping_method_config');
        $rules = json_decode($rules, true);
        return empty($rules) ? array() : $rules;
    }

    /**
     * Identifies the shipping method based on the provided shipping lines and rules.
     *
     * @param array $shippingLines The shipping lines.
     * @param array $rules The shipping method rules.
     *
     * @return string The identified shipping method.
     * @throws Plugin_Exception If a rule is invalid.
     */
    protected function identifyShippingMethod(array $shippingLines, array $rules): string
    {
        $_shippingMethod = null;

        foreach ($shippingLines as $shippingLine) {
            if ($_shippingMethod === null) {
                $_shippingMethod = $shippingLine['shipping_method'] ?? null;
            }

            foreach ($rules as $rule) {
                $this->validateRule($rule);

                list($shippingMethod, $field, $operator, $pattern) = array(
                    $rule['shipping_method'],
                    $rule['field'],
                    $rule['operator'],
                    $rule['pattern']
                );
                $compareValue = '';
                if (!empty($shippingLine[$field])) {
                    $compareValue = $shippingLine[$field];
                }

                if ($this->matchesRule($compareValue, $operator, $pattern)) {
                    $_shippingMethod = $shippingMethod;
                    break 2;
                }
            }
        }

        if (empty($_shippingMethod)) {
            throw new Plugin_Exception(
                'Cannot identify shipping method.',
                null,
                null,
                'Get shipping method'
            );
        }

        return $_shippingMethod;
    }

    /**
     * Validates a shipping method rule.
     *
     * @param array $rule The rule to validate.
     *
     * @return void
     * @throws Plugin_Exception If the rule is invalid.
     */
    protected function validateRule(array $rule): void
    {
        if (count($rule) != 4) {
            throw new Plugin_Exception('Invalid shipping method rule.');
        }

        foreach (array('shipping_method', 'field', 'operator', 'pattern') as $field) {
            if (empty($rule[$field])) {
                throw new Plugin_Exception('Invalid shipping method rule.');
            }
        }
    }

    /**
     * Determines if a shipping line value matches
     * a rule based on the operator and pattern.
     *
     * @param string $compareValue The value to compare.
     * @param string $operator The operator to use for comparison.
     * @param string $pattern The pattern to compare against.
     *
     * @return bool true if the value matches the rule, false otherwise.
     *
     * @throws Plugin_Exception If a RegEx expression is invalid.
     */
    protected function matchesRule(string $compareValue, string $operator, string $pattern): bool
    {
        if ($operator == '=~') {
            if (@preg_match(
                    '/^' . $pattern . '$/i',
                    null,
                    $matches
                ) === false && $matches === null
            ) {
                throw new Plugin_Exception(
                    'Invalid RegEx expression after "=~" operator',
                    null,
                    null,
                    'Get shipping method'
                );
            }

            return preg_match('/^' . $pattern . '$/i', $compareValue);
        } else {
            $pattern = str_replace(array('"', "'"), '', $pattern);
            if ($operator == '=' && $compareValue == $pattern) {
                return true;
            } elseif ($operator == '!=' && $compareValue != $pattern) {
                return true;
            }
        }

        return false;
    }


    /**
     * Check if the item is a simple product
     *
     * @param array $item Order Item
     *
     * @return bool
     */
    protected function _checkItem(array $item): bool
    {
        return (isset($item['product_type']) && $item['product_type'] == 'simple');
    }

    /**
     * Update WooCommerce order status and add comment.
     *
     * @param string $orderIncrementId Order ID
     * @param string $orderStatus Order Status
     * @param string $comment App Title
     * @param string $appTitle App Title
     * @param string $shipstreamId Ship Stream ID
     *
     * @return void
     */
    protected function addComment(
        string $orderIncrementId,
        string $orderStatus,
        string $comment = '',
        string $appTitle = '',
        string $shipstreamId = ''
    ): void
    {
        try {
            $commentData = array(
                'order_id' => $orderIncrementId,
                'status' => $orderStatus,
                'comment' => $comment,
                'apptitle' => $appTitle,
                'shipstreamid' => $shipstreamId,
            );
            $this->wooCommerceApi(
                'shipstream/v1/order/addComment',
                'POST',
                $commentData
            );
            $message = sprintf(
                'Status of order # %s was changed
                to %s in merchant site, comment: %s',
                $orderIncrementId,
                $orderStatus,
                $comment
            );
            $this->log($message);
        } catch (Throwable $e) {
            $message = sprintf(
                'Order status could not be changed in merchant
                site due to the following error: %s',
                $e->getMessage()
            );
            $this->log($message, self::ERR);
        }
    }

    /**
     * Call the woocommerce API
     *
     * @param string $endpoint API end point
     * @param string $method API method
     * @param array $params API params
     *
     * @return array
     *
     * @throws Plugin_Exception
     */
    protected function wooCommerceApi(
        string $endpoint,
        string $method = 'POST',
        array $params = array()
    ): array
    {
        try {
            return $this->getClient()->request($endpoint, $method, $params);
        } catch (Exception $e) {
            throw new Plugin_Exception($e->getMessage());
        }
    }

    /**
     * Get ShipStream_WooCommerce_Client instance
     *
     * @return ShipStream_WooCommerce_Client
     * @throws Exception
     *
     * @throws Plugin_Exception
     */
    protected function getClient(): ShipStream_WooCommerce_Client
    {
        if (!$this->_client) {
            $this->_client = new ShipStream_WooCommerce_Client(
                array(
                    'base_url' => $this->getConfig('api_url'),
                    'consumer_key' => $this->getConfig('api_login'),
                    'consumer_secret' => $this->getConfig('api_password'),
                )
            );
        }

        return $this->_client;
    }

    /**
     * Validate the given date
     *
     * @param string $date Date to validate
     *
     * @return bool
     */
    protected function validateDate(string $date): bool
    {
        return preg_match(self::DATE_PATTERN, $date);
    }

    /**
     * Format the shipping address
     *
     * @param array $wooCommerceAddress Woocommerce shipping address
     *
     * @return array
     */
    protected function formatShippingAddress(array $wooCommerceAddress): array
    {
        $address = $firstName = $lastName = $company = '';
        $city = $state = $postCode = $country = $phone = '';


        if (isset($wooCommerceAddress['address_1'])) {
            $address = $wooCommerceAddress['address_1'];

            if (isset($wooCommerceAddress['address_2'])) {
                $address .= ' ' . $wooCommerceAddress['address_2'];
            }
        }

        if (isset($wooCommerceAddress['first_name'])) {
            $firstName = $wooCommerceAddress['first_name'];
        }

        if (isset($wooCommerceAddress['last_name'])) {
            $lastName = $wooCommerceAddress['last_name'];
        }

        if (isset($wooCommerceAddress['company'])) {
            $company = $wooCommerceAddress['company'];
        }

        if (isset($wooCommerceAddress['city'])) {
            $city = $wooCommerceAddress['city'];
        }

        if (isset($wooCommerceAddress['state'])) {
            $state = $wooCommerceAddress['state'];
        }

        if (isset($wooCommerceAddress['postcode'])) {
            $postCode = $wooCommerceAddress['postcode'];
        }

        if (isset($wooCommerceAddress['country'])) {
            $country = $wooCommerceAddress['country'];
        }

        if (isset($wooCommerceAddress['phone'])) {
            $phone = $wooCommerceAddress['phone'];
        }

        return array(
            'firstname' => $firstName,
            'lastname' => $lastName,
            'company' => $company,
            'street1' => $address,
            'city' => $city,
            'region' => $state,
            'postcode' => $postCode,
            'country' => $country,
            'telephone' => $phone,
        );
    }


    /**
     * Get Order Item
     *
     * @param array $wooCommerceItems Woocommerce Item
     *
     * @return array
     */
    protected function getOrderItems(array $wooCommerceItems): array
    {
        $orderItems = array();
        foreach ($wooCommerceItems as $item) {
            $orderItems[] = array(
                'sku' => $item['sku'],
                'name' => $item['name'],
                'qty' => (int)$item['quantity']
            );
        }

        return $orderItems;
    }

    /**
     * Process the order by script
     *
     * @param array $orderData Order Data
     * @param array $wooCommerceOrder Woocommerce Order
     * @param string $logPrefix Log Prefix
     *
     * @return void
     * @throws Exception
     */
    protected function processOrderTransformScript(
        array  &$orderData,
        array  $wooCommerceOrder,
        string $logPrefix
    ): void
    {
        if ($script = $this->getConfig('order_transform_script')) {
            eval($script);
            $this->log($logPrefix . 'Transform Script: Applied.');
        }
    }

    /**
     * Import Order by order data
     *
     * @param array $newOrderData Order Data
     * @param array $wooCommerceOrder Woocommerce Order
     * @param string $logPrefix Log prefix
     *
     * @return void
     * @throws Plugin_Exception
     *
     */
    protected function submitOrder(
        array  $newOrderData,
        array  $wooCommerceOrder,
        string $logPrefix
    ): void
    {
        $this->log($logPrefix . 'Submitting Order...');
        try {
            $result = $this->call('order.import', array($newOrderData));
            if (!$result['success']) {
                throw new Plugin_Exception($result['message']);
            }

            $this->log(
                $logPrefix . sprintf(
                    'Order Submitted to ShipStream: Order # %s', $result['unique_id']
                )
            );
            $this->addComment(
                $wooCommerceOrder['order_increment_id'],
                'wc-submitted',
                sprintf(
                    'Submitted to ShipStream: Order # %s',
                    $result['unique_id']
                )
            );
        } catch (Exception $e) {
            $this->addComment(
                $wooCommerceOrder['order_increment_id'],
                'wc-failed-to-submit',
                sprintf(
                    'Failed to Submit to ShipStream: %s',
                    $this->getAppTitle()
                )
            );
            throw new Plugin_Exception($e->getMessage());
        }
    }

    /**
     * Get woocommerce shipment ID
     *
     * @param string $source shipment source
     *
     * @return string|null shipment ID or null if not found
     */
    protected function getWooCommerceShipmentId(string $source): ?string
    {
        if (str_starts_with($source, 'woocommerce_shipment:')) {
            return substr($source, strlen('woocommerce_shipment:'));
        }

        return null;
    }


    /**
     * Get warehouse name by ID
     *
     * @param int $warehouseId warehouse ID to get name
     *
     * @return string|null
     * @throws Plugin_Exception
     */
    protected function _getWarehouseName(int $warehouseId): ?string
    {
        $warehouse = $this->call('warehouse.get', array($warehouseId));
        return $warehouse['name'] ?? null;
    }
}
