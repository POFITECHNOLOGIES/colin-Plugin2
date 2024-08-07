<?php

class ShipStream_Sync_Observer {
    
    public function __construct() {
        add_action('woocommerce_order_status_changed', [$this, 'salesOrderSaveAfter'], 10, 4);
    }

    /**
     * Handle order status change events
     *
     * @param int $order_id Order ID
     * @param string $old_status Previous order status
     * @param string $new_status New order status
     * @param WC_Order $order Order object
     */
    public function salesOrderSaveAfter($order_id, $old_status, $new_status, $order) {
        
        // Check if the order contains only virtual or downloadable products
        $is_virtual = $this->isOrderVirtual($order);

        // If the order is virtual and its status changes to "processing" from "ready to ship" or "submitted", mark it as completed
        if ($is_virtual && $new_status === 'wc-processing' && ($old_status === 'wc-ready-to-ship' || $old_status === 'wc-submitted')) {
            $order->update_status('wc-completed', __('Changed order status to "Completed" as the order is virtual.'));
            return;
        }

        // If real-time sync is enabled and the new status is "Ready to Ship", sync the order with ShipStream
        if ($this->isRealtimeSyncEnabled() && !$is_virtual && $new_status === 'wc-ready-to-ship') {
            try {
                $this->syncOrderToShipStream($order->get_id());
            } catch (Throwable $e) {
                // Handle potential errors gracefully
                error_log($e->getMessage());
            }
        }
    }

    /**
     * Check if an order contains only virtual or downloadable products
     *
     * @param WC_Order $order Order object
     * @return bool True if the order contains only virtual or downloadable products, otherwise false
     */
    protected function isOrderVirtual($order) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product->is_virtual() && !$product->is_downloadable()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if real-time sync is enabled
     *
     * @return bool True if real-time sync is enabled, otherwise false
     */
    protected function isRealtimeSyncEnabled() {
        return get_option('enable_real_time_order_sync', 'no') === 'yes';
    }

    /**
     * Sync order to ShipStream
     *
     * @param int $order_id Order ID
     */
    protected function syncOrderToShipStream($order_id) {
        $order = wc_get_order($order_id);
        $increment_id = $order->get_order_number();
        ShipStream_Sync_Helper_Api::callback('syncOrders', ['increment_id' => $increment_id]);
    }
}

// Initialize the observer
new ShipStream_Sync_Observer();
