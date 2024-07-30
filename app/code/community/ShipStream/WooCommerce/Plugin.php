<?php

/**
 * The ShipStream order.increment_id is the WooCommerce shipment.increment_id.
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
     */
    public function hasConnectionConfig()
    {
        return $this->getConfig('api_url') && $this->getConfig('api_login') && $this->getConfig('api_password');
    }

    /**
     * @return array
     * @throws Plugin_Exception
     */
    public function connectionDiagnostics(bool $super = false): array
    {
        $info = $this->_wooCommerceApi('shipstream/v1/info');
        return [
            sprintf('WooCommerce Version: %s', $info['woocommerce_version'] ?? 'undefined'),
            sprintf('WordPress Version: %s', $info['wordpress_version'] ?? 'undefined'),
            sprintf('ShipStream Sync Version: %s', $info['shipstream_sync_version'] ?? 'undefined'),
            sprintf('Service Status: %s', $this->isFulfillmentServiceRegistered() ? '✅ Registered' : '🚨 Not registered')
        ];
    }


    /**
     * Activate the plugin
     *
     * @return string[]
     */
    public function activate(): array
    {
        $warnings = [];
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
        $errors = [];
        try {
            $this->unregisterFulfillmentService();
        } catch (Plugin_Exception $e) {
            $errors[] = $e->getMessage();
        }
        try {
            $this->setState([
                self::STATE_LOCK_ORDER_PULL => NULL,
                self::STATE_ORDER_LAST_SYNC_AT => NULL,
                self::STATE_FULFILLMENT_SERVICE_REGISTERED => NULL,
            ]);
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
        $result = $this->_wooCommerceApi('shipstream/v1/sync_inventory', 'POST');
        if (!$result['success']) {
            throw new Plugin_Exception($result['message']);
        }
    }

    /**
     * Synchronize orders since the configured date
     *
     * @throws Plugin_Exception
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
     */
    public function cronSyncOrders()
    {
        $this->_importOrders();
    }

    /**
     * @return array|string|null
     */
    public function isFulfillmentServiceRegistered()
    {
        return $this->getState(self::STATE_FULFILLMENT_SERVICE_REGISTERED);
    }

    /**
     * Register fulfillment service
     * @throws Plugin_Exception
     */
    public function registerFulfillmentService()
    {
        if ($this->_wooCommerceApi('shipstream/v1/set_config', 'POST', ['path' => 'warehouse_api_url','value' => $this->getCallbackUrl(null)])) {
            $this->setState(self::STATE_FULFILLMENT_SERVICE_REGISTERED, TRUE);
        }
    }

    /**
     * Unregister fulfillment service
     * @throws Plugin_Exception
     */
    public function unregisterFulfillmentService()
    {
        $this->_wooCommerceApi('shipstream/v1/set_config', 'POST', ['path' => 'warehouse_api_url','value' => NULL]);
        $this->setState(self::STATE_FULFILLMENT_SERVICE_REGISTERED, NULL);
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

        $result = $this->call('order.search', [['order_ref' => $orderIncrementId],[], []]);
        if ($result['totalCount'] > 0) {
            $message = sprintf('ShipStream Order # %s was created at %s', $result['results'][0]['unique_id'], $result['results'][0]['created_at']);
            $this->_addComment($orderIncrementId, 'submitted', $message);
            return;
        }

        $wooCommerceOrder = $this->_wooCommerceApi('shipstream/v1/order_shipment/info', 'POST' ,$orderIncrementId);
        $shippingAddress = $this->formatShippingAddress($wooCommerceOrder['shipping_address']);
        $orderItems = $this->getOrderItems($wooCommerceOrder['items']);

        if (empty($orderItems)) {
            return;
        }

        $additionalData = [
            'order_ref' => $wooCommerceOrder['increment_id'],
            'shipping_method' => $this->_getShippingMethod($wooCommerceOrder),
            'source' => 'woocommerce:'.$wooCommerceOrder['increment_id'],
        ];

        $newOrderData = [
            'store' => NULL,
            'items' => $orderItems,
            'address' => $shippingAddress,
            'options' => $additionalData,
            'timestamp' => new \DateTime('now', $this->getTimeZone()),
        ];

        $this->processOrderTransformScript($newOrderData, $wooCommerceOrder, $logPrefix);
        $this->submitOrder($newOrderData, $wooCommerceOrder, $logPrefix);
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
            $this->_wooCommerceApi('shipstream/v1/stock_item/adjust','POST', [$sku, (float)$change['qty_adjust']]);
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

        if (!in_array($clientOrder['status'], ['submitted', 'failed_to_submit'])) {
            throw new Plugin_Exception("Order $clientOrderId status is '{$clientOrder['status']}', expected 'submitted'.");
        }

        $payload = $data->getData();
        $payload['warehouse_name'] = $this->_getWarehouseName($data->getWarehouseId());
        $wooCommerceShipmentId = $this->_wooCommerceApi('shipstream/v1/order_shipment/create_with_tracking','POST', [$clientOrderId, $payload]);

        $this->log(sprintf('Created WooCommerce shipment # %s for order # %s', $wooCommerceShipmentId, $clientOrderId));
    }

    /****************************
     * Internal Event Observers *
     ****************************/

    /**
     * Respond to the delivery committed webhook
     *
     * @param Varien_Object $data
     */
    public function respondDeliveryCommitted(Varien_Object $data)
    {
        $this->addEvent('adjustInventoryEvent', ['stock_adjustments' => $data->getStockAdjustments()]);
    }

    /**
     * Respond to the inventory adjustment webhook
     *
     * @param Varien_Object $data
     */
    public function respondInventoryAdjusted(Varien_Object $data)
    {
        $this->addEvent('adjustInventoryEvent', ['stock_adjustments' => $data->getStockAdjustments()]);
    }

    /**
     * Respond to shipment:packed event, completes the fulfillment
     *
     * @param Varien_Object $data
     */
    public function respondShipmentPacked(Varien_Object $data)
    {
        if ($this->_getWooCommerceShipmentId($data->getSource())) {
            $this->addEvent('shipmentPackedEvent', $data->toArray());
        }
    }

    /**
     * Handle a new order
     *
     * @param Varien_Object $data
     */
    public function handleOrderNew(Varien_Object $data)
    {
        $this->addEvent('importOrderEvent', ['increment_id' => $data->getIncrementId()]);
    }

    /**
     * Handle an updated order
     *
     * @param Varien_Object $data
     */
    public function handleOrderUpdate(Varien_Object $data)
    {
        $this->addEvent('importOrderEvent', ['increment_id' => $data->getIncrementId()]);
    }

    /**
     * @param string $endpoint
     * @param string $method
     * @param array $params
     * @return array
     * @throws Plugin_Exception
     */
    protected function _wooCommerceApi($endpoint, $method = 'POST', $params = [])
    {
        try {
            return $this->getClient()->request($endpoint, $method, $params);
        } catch (Exception $e) {
            throw new Plugin_Exception($e->getMessage());
        }
    }

    /**
     * @return ShipStream_WooCommerce_Client
     */
    protected function getClient()
    {
        if (!$this->_client) {
            $this->_client = new ShipStream_WooCommerce_Client([
                'base_url' => $this->getConfig('api_url'),
                'consumer_key' => $this->getConfig('api_login'),
                'consumer_secret' => $this->getConfig('api_password'),
            ]);
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
        return [
            'full_name' => trim($wooCommerceAddress['firstname'] . ' ' . $wooCommerceAddress['lastname']),
            'company' => $wooCommerceAddress['company'],
            'street1' => $wooCommerceAddress['street'] . ' ' . $wooCommerceAddress['street2'],
            'city' => $wooCommerceAddress['city'],
            'state' => $wooCommerceAddress['region'],
            'postal_code' => $wooCommerceAddress['postcode'],
            'country' => $wooCommerceAddress['country_id'],
            'phone' => $wooCommerceAddress['telephone']
        ];
    }

    /**
     * @param array $wooCommerceItems
     * @return array
     */
    protected function getOrderItems(array $wooCommerceItems): array
    {
        $orderItems = [];
        foreach ($wooCommerceItems as $item) {
            if ($item['parent_item_id']) {
                continue;
            }
            $orderItems[] = [
                'sku' => $item['sku'],
                'name' => $item['name'],
                'quantity' => (int)$item['qty_ordered']
            ];
        }
        return $orderItems;
    }

    /**
     * @param $wooCommerceOrder
     * @return string
     */
    protected function _getShippingMethod($wooCommerceOrder): string
    {
        return $wooCommerceOrder['shipping_method'];
    }

    /**
     * @param array $orderData
     * @param array $wooCommerceOrder
     * @param string $logPrefix
     */
    protected function processOrderTransformScript(array &$orderData, array $wooCommerceOrder, string $logPrefix)
    {
        if ($script = $this->getConfig('order_transform_script')) {
            eval($script);
            $this->log($logPrefix . 'Transform Script: Applied.');
        }
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
            $result = $this->call('order.import', [$newOrderData]);
            if (!$result['success']) {
                throw new Plugin_Exception($result['message']);
            }
            $this->log($logPrefix . sprintf('Order Submitted to ShipStream: Order # %s', $result['unique_id']));
            $this->_addComment($wooCommerceOrder['increment_id'], 'submitted', sprintf('Submitted to ShipStream: Order # %s', $result['unique_id']));
        } catch (Exception $e) {
            $this->_addComment($wooCommerceOrder['increment_id'], 'failed_to_submit', sprintf('Failed to Submit to ShipStream: %s', $e->getMessage()));
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
     */

    protected function _getWarehouseName(int $warehouseId): ?string
    {
        $warehouse = $this->call('warehouse.get', [$warehouseId]);
        return $warehouse['name'] ?? NULL;
    }
}
