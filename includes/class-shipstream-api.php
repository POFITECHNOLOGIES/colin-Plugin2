<?php

class ShipStream_API {

    // Initialize the REST API routes
    public static function init() {
        add_action('rest_api_init', function () {
            // Register the REST API routes
            register_rest_route('shipstream/v1', '/info', array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'get_info'),
                'permission_callback' => array(__CLASS__, 'authenticate'),
            ));
            register_rest_route('shipstream/v1', '/set_config', array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'set_config'),
                'permission_callback' => array(__CLASS__, 'authenticate'),
            ));
            register_rest_route('shipstream/v1', '/sync_inventory', array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'sync_inventory'),
                'permission_callback' => array(__CLASS__, 'authenticate'),
            ));
            register_rest_route('shipstream/v1', '/stock_item/adjust', array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'adjust_stock_item'),
                'permission_callback' => array(__CLASS__, 'authenticate'),
            ));
            register_rest_route('shipstream/v1', '/order_shipment/info', array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'get_order_shipment_info'),
                'permission_callback' => array(__CLASS__, 'authenticate'),
            ));
            register_rest_route('shipstream/v1', '/order_shipment/create_with_tracking', array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'create_order_shipment_with_tracking'),
                'permission_callback' => array(__CLASS__, 'authenticate'),
            ));
            register_rest_route('shipstream/v1', '/order/list', array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'order_list'),
                'permission_callback' => array(__CLASS__, 'authenticate'),
            ));
            register_rest_route('shipstream/v1', '/order/addComment', array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'add_order_comment'),
                'permission_callback' => array(__CLASS__, 'authenticate'),
            ));
        });
    }

    public static function authenticate(WP_REST_Request $request) {
        // Get headers from the request
        $headers = $request->get_headers();

        // Check if the authorization header is set
        if (!isset($headers['authorization'][0])) {
            return false;
        }

        // Get the authorization header value
        $auth_header = $headers['authorization'][0];

        // Check if the authorization method is Basic
        if (strpos($auth_header, 'Basic ') !== 0) {
            return false;
        }

        // Decode the Base64 encoded authorization value
        $auth_value = base64_decode(substr($auth_header, 6));

        // Split the decoded value into consumer key and consumer secret
        list($consumer_key, $consumer_secret) = explode(':', $auth_value);

        // Sanitize and hash the consumer key
        $consumer_key = wc_api_hash(sanitize_text_field($consumer_key));

        // Access the global $wpdb object for database operations
        global $wpdb;

        // Query the database to retrieve the user information based on the hashed consumer key
        $user = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT key_id, user_id, permissions, consumer_key, consumer_secret, nonces FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key = %s",
                $consumer_key
            )
        );

        // Check if the user exists and if the hashed consumer secret matches the stored value
        if (empty($user) || !hash_equals($user->consumer_secret, $consumer_secret)) {
            return false;
        }

        // Authentication successful
        return true;
    }


    // Get information about WordPress, WooCommerce, and plugin versions
    public static function get_info($request) {
        $wc_version = get_option('woocommerce_version', 'N/A');

        $plugin_file_path = WP_PLUGIN_DIR . '/woocommerce-shipstream-sync/woocommerce-shipstream-sync.php';
        if (file_exists($plugin_file_path)) {
            $plugin_data = get_file_data($plugin_file_path, array('Version' => 'Version'), false);
            $shipstream_sync_version = $plugin_data['Version'];
        } else {
            $shipstream_sync_version = 'N/A';
        }

        global $wp_version;

        $result = array(
            'wordpress_version' => $wp_version,
            'woocommerce_version' => $wc_version,
            'shipstream_sync_version' => $shipstream_sync_version,
        );

        return new WP_REST_Response($result, 200);
    }

    // Set configuration values
    public static function set_config($request) {
        $path = $request->get_param('path');
        $value = $request->get_param('value');

        if (empty($path)) {
            return new WP_REST_Response(array('error' => 'Path parameter is required'), 400);
        }

        if ($value === null) {
            return new WP_REST_Response(array('error' => 'Value parameter is required'), 400);
        }

        $option_name = 'shipstream_' . sanitize_key($path);
        $updated = update_option($option_name, $value);

        if ($updated) {
            return new WP_REST_Response(array('success' => 'Configuration updated'), 200);
        } else {
            return new WP_REST_Response(array('error' => 'Failed to update configuration'), 500);
        }
    }

    // Sync inventory
    public static function sync_inventory($request) {
        try {
            ShipStream_Cron::full_inventory_sync(false);
        } catch (Exception $e) {
            error_log($e->getMessage());
            return new WP_REST_Response(array('success' => false, 'message' => $e->getMessage()), 500);
        }
        return new WP_REST_Response(array('success' => true), 200);
    }

    // Adjust stock item quantity
    public static function adjust_stock_item($request) {
        $product_id = $request->get_param('product_id');
        $delta = $request->get_param('delta');

        if (empty($product_id) || $delta === null) {
            return new WP_REST_Response(array('error' => 'Product ID and delta parameters are required'), 400);
        }

        global $wpdb;

        $wpdb->query('START TRANSACTION');

        try {
            self::_lock_stock_items($product_id);

            $product = wc_get_product($product_id);
            if (!$product) {
                $product_id = wc_get_product_id_by_sku($product_id);
                $product = wc_get_product($product_id);
                if (!$product) {
                    throw new Exception('Product does not exist');
                }
            }

            $stock_quantity = $product->get_stock_quantity();
            $new_stock_quantity = $stock_quantity + $delta;
            $product->set_stock_quantity($new_stock_quantity);

            if ($new_stock_quantity > 0 && $product->get_stock_status() === 'outofstock') {
                $product->set_stock_status('instock');
            }

            $product->save();

            $wpdb->query('COMMIT');

            return new WP_REST_Response(array('success' => true), 200);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log($e->getMessage());
            return new WP_REST_Response(array('error' => $e->getMessage()), 500);
        }
    }

    // Lock stock items for update
    protected static function _lock_stock_items($product_id = NULL) {
        global $wpdb;

        if (is_numeric($product_id)) {
            $query = $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $product_id);
        } elseif (is_array($product_id)) {
            $placeholders = implode(', ', array_fill(0, count($product_id), '%d'));
            $query = $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID IN ($placeholders) FOR UPDATE", $product_id);
        } else {
            return;
        }

        $wpdb->query($query);
    }

    // Get order shipment information
    public static function get_order_shipment_info($request) {
        $shipment_id = $request->get_param('shipment_id');
        if (empty($shipment_id)) {
            return new WP_REST_Response(array('error' => 'Shipment ID parameter is required'), 400);
        }

        $order = wc_get_order($shipment_id);
        if (!$order) {
            return new WP_REST_Response(array('error' => 'Shipment does not exist'), 404);
        }

        $result = array();
        $result['order_increment_id'] = $order->get_order_number();
        $result['shipping_address'] = $order->get_address('shipping');
        $result['shipping_method'] = $order->get_shipping_method();
        $result['status'] = $order->get_status();

        $result['items'] = array();
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $item_data = array(
                'product_id' => $product->get_id(),
                'sku' => $product->get_sku(),
                'name' => $product->get_name(),
                'quantity' => $item->get_quantity(),
                'product_type' => $product->get_type(),
            );
            $result['items'][] = $item_data;
        }

        $result['shipping_lines'] = array();
        foreach ($order->get_shipping_methods() as $shipping_item_id => $shipping_item) {
            $shipping_data = array(
                'shipping_method' => $shipping_item->get_method_id(),
                'shipping_description' => $shipping_item->get_method_title(),
            );
            $result['shipping_lines'][] = $shipping_data;
        }

        if (function_exists('wc_st_get_tracking_items')) {
            $tracks = wc_st_get_tracking_items($order->get_id());
            $result['tracks'] = $tracks;
        } else {
            $result['tracks'] = array();
        }

        return new WP_REST_Response($result, 200);
    }

    // Create order shipment with tracking information
    public function create_order_shipment_with_tracking($orderIncrementId, $data) {
        $order = wc_get_order($orderIncrementId);
        if (!$order) {
            throw new Exception('Order not exists');
        }
        if ($order->get_status() !== 'wc-processing') {
            throw new Exception(__('Cannot do shipment for order.', 'woocommerce'));
        }

        $itemsQty = [];
        if ($data['order_status'] !== "wc-complete") {
            $itemsQty = $this->_getShippedItemsQty($order, $data);
            if (sizeof($itemsQty) === 0) {
                throw new Exception(__('Decimal qty is not allowed to ship in WooCommerce', 'woocommerce'));
            }
        }

        $comments = $this->_getCommentsData($order, $data);

        $tracks = [];
        $carriers = $this->_getCarriers($order);

        $carrier = $data['carrier'];
        if (!isset($carriers[$carrier])) {
            $carrier = 'custom';
            $title = $data['service_description'];
        } else {
            $title = $carriers[$carrier];
        }
        foreach ($data['packages'] as $package) {
            foreach ($package['tracking_numbers'] as $trackingNumber) {
                $tracks[] = [
                    'tracking_number' => $trackingNumber,
                    'carrier_code' => $carrier,
                    'title' => $title,
                ];
                $order->add_order_note(sprintf(
                    __('Tracking number %s for carrier %s added.', 'woocommerce'),
                    $trackingNumber,
                    $carrier
                ));
                update_post_meta($order->get_id(), '_tracking_number', $trackingNumber);
                update_post_meta($order->get_id(), '_tracking_provider', $carrier);
                update_post_meta($order->get_id(), '_tracking_provider_title', $title);
            }
        }

        $shipment = new WC_Order_Shipment($orderIncrementId);
        $shipment->set_items_qty($itemsQty);
        $shipment->set_tracking($tracks);
        $shipment->set_comments($comments);
        $shipment->save();

        foreach ($data['items'] as $dataItem) {
            $orderItem = $order->get_item($dataItem['order_item_id']);
            if ($orderItem) {
                $orderItem->update_meta_data('order_item_id', $dataItem['order_item_id']);
                $orderItem->update_meta_data('sku', $dataItem['sku']);
                $orderItem->update_meta_data('quantity', $dataItem['quantity']);
                $orderItem->update_meta_data('package_data', $dataItem['package_data']);
                $orderItem->update_meta_data('lot_data', $dataItem['lot_data']);
                $orderItem->save();
            }
        }

        if ($data['order_status'] === "wc-complete") {
            $order->update_status('wc-completed', __('All items are shipped.', 'woocommerce'));
        }

        $mailer = WC()->mailer();
        $email = $mailer->emails['WC_Email_Customer_Shipment'];
        $email->trigger($orderIncrementId, $shipment, $order);

        return $shipment->get_id();
    }

    /**
     * Retrieve shipped order item quantities from Shipstream shipment packages
     * @param $order
     * @param $data
     * @return array
     */
    protected function _getShippedItemsQty($order, $data)
    {
        $itemShippedQty = [];

        // Get order item reference IDs from shipment data
        foreach ($data['items'] as $dataItem) {
            $orderItem = $order->get_item($dataItem['order_item_id']);
            if ($orderItem) {
                $orderItemId = $orderItem->get_id();
                $orderItemRef = $dataItem['order_item_ref'];
                $itemShippedQty[$orderItemId] = $orderItemRef;
            }
        }

        // Accumulate shipment quantities from Shipstream packages
        foreach ($data['packages'] as $package) {
            foreach ($package['items'] as $item) {
                $orderItemId = $item['order_item_id'];
                if (isset($itemShippedQty[$orderItemId])) {
                    $itemShippedQty[$orderItemId] += floatval($item['order_item_qty']);
                } else {
                    $itemShippedQty[$orderItemId] = floatval($item['order_item_qty']);
                }
            }
        }

        // Adjust quantities for partially shipped items
        foreach ($itemShippedQty as $item_id => $ordered_qty) {
            $fraction = fmod($ordered_qty, 1);
            $wholeNumber = intval($ordered_qty);
            if ($fraction >= 0.9999) {
                $ordered_qty = $wholeNumber + round($fraction);
            } else {
                $ordered_qty = $wholeNumber;
            }
            $itemShippedQty[$item_id] = $ordered_qty;
            if ($itemShippedQty[$item_id] == 0) {
                unset($itemShippedQty[$item_id]);
            }
        }

        return $itemShippedQty;
    }

    /**
     * Prepare shipment comment data from Shipstream shipment packages
     * @param $order
     * @param $data
     * @return string
     */
    protected function _getCommentsData($order, $data)
    {
        $orderComments = [];

        // Get item names & SKUs from WooCommerce order items
        foreach ($order->get_items() as $orderItem) {
            $orderComments[$orderItem->get_sku()] = [
                'sku' => $orderItem->get_sku(),
                'name' => $orderItem->get_name(),
            ];
        }

        // Add lot data of order items
        foreach ($data['items'] as $item) {
            if (isset($orderComments[$item['sku']])) {
                foreach ($item['lot_data'] as $lot_data) {
                    $orderComments[$item['sku']]['lotdata'][] = $lot_data;
                }
            }
        }

        // Add collected data of packages from shipment packages
        foreach ($data['packages'] as $package) {
            $orderItems = [];
            foreach ($package['items'] as $item) {
                $orderItems[$item['order_item_id']] = $item['sku'];
            }

            foreach ($package['package_data'] as $packageData) {
                if (isset($orderItems[$packageData['order_item_id']])) {
                    $sku = $orderItems[$packageData['order_item_id']];
                    $orderComments[$sku]['collected_data'] = [
                        'label' => $packageData['label'],
                        'value' => $packageData['value'],
                    ];
                }
            }
        }

        $comments = array_values($orderComments);
        if (function_exists('yaml_emit')) {
            return yaml_emit($comments);
        } else {
            return json_encode($comments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Retrieve available carriers for the order
     * @param $order
     * @return array
     */
    protected function _getCarriers($order)
    {
        // Placeholder for retrieving carriers for WooCommerce
        return [
            'dhl' => 'DHL',
            'ups' => 'UPS',
            'fedex' => 'FedEx',
            'custom' => 'Custom Carrier',
        ];
    }

    /**
     * List orders based on filters from the request
     * @param $request
     * @return WP_REST_Response
     */
    public static function order_list($request)
    {
        global $wpdb;

        $cols_select = ['id', 'date_updated_gmt'];
        $cols = ['id', 'date_modified'];
        $orders = [];

        $filters = $request->get_param('filters') ? $request->get_param('filters') : [];
        $date_updated_gmt = isset($filters['date_updated_gmt']) ? $filters['date_updated_gmt'] : [];
        $status = isset($filters['status']) ? $filters['status'] : [];

        // Build the base query
        $query = $wpdb->prepare(
            "SELECT " . implode(", ", $cols_select) . " FROM {$wpdb->prefix}wc_orders WHERE type = %s",
            'shop_order'
        );

        // Add date_updated_gmt filter if provided
        if (isset($date_updated_gmt['from']) && isset($date_updated_gmt['to'])) {
            $query .= $wpdb->prepare(
                " AND date_updated_gmt BETWEEN %s AND %s",
                $date_updated_gmt['from'],
                $date_updated_gmt['to']
            );
        }

        // Add status filters if provided
        if (isset($status['in']) && !empty($status['in'])) {
            $status_in = implode(", ", array_map(function($item) use ($wpdb) { return $wpdb->prepare('%s', $item); }, $status['in']));
            $query .= " AND status IN ($status_in)";
        }
        if (isset($status['not in']) && !empty($status['not in'])) {
            $status_not_in = implode(", ", array_map(function($item) use ($wpdb) { return $wpdb->prepare('%s', $item); }, $status['not in']));
            $query .= " AND status NOT IN ($status_not_in)";
        }

        // Execute the query
        $results = $wpdb->get_results($query, ARRAY_A);

        // Handle query errors
        if ($wpdb->last_error) {
            return new WP_REST_Response(['error' => $wpdb->last_error], 500);
        }

        // Prepare the orders data
        foreach ($results as $row) {
            $order_id = $row['id'];
            $order = wc_get_order($order_id);
            if ($order) {
                $order_data = $order->get_data();
                $filtered_data = array_intersect_key($order_data, array_flip($cols));
                $orders[] = $filtered_data;
            }
        }

        return new WP_REST_Response($orders, 200);
    }

    /**
     * Add a comment to an order.
     * @param WP_REST_Request $request The REST request object.
     * @return WP_REST_Response The response object.
     */
    public static function add_order_comment($request)
    {
        $order_id = $request->get_param('order_id');
        $status = $request->get_param('status');
        $comment = $request->get_param('comment');
        $apptitle = $request->get_param('apptitle');
        $shipstreamid = $request->get_param('shipstreamid');

        if (empty($order_id) || empty($comment) || empty($status)) {
            return new WP_REST_Response(['error' => 'Order ID, Status, and Comment are required.'], 400);
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            return new WP_REST_Response(['error' => 'Invalid order ID.'], 404);
        }

        // Check if the order already has a shipstream ID
        $existing_shipstreamid = $order->get_meta('_shipstream_order_ids');
        if ($existing_shipstreamid) {
            return new WP_REST_Response(['error' => 'A new order cannot be created with another order already exists.'], 400);
        }

        // Update order status
        if ($status) {
            $order->update_status(sanitize_text_field($status), 'Order status updated');
        }

        // Add order note
        $comment_id = $order->add_order_note(
            sanitize_text_field($comment),
            false
        );

        if (!$comment_id) {
            return new WP_REST_Response(['error' => 'Failed to add comment to order.'], 500);
        }

        // Add shipstream ID and app title to order meta
        $order->update_meta_data('_shipstream_order_ids', sanitize_text_field($shipstreamid));
        $order->update_meta_data('_apptitle', sanitize_text_field($apptitle));
        $order->save();

        return new WP_REST_Response(['success' => 'Comment added to order.', 'comment_id' => $comment_id], 200);
    }

}

// Initialize the ShipStream API
ShipStream_API::init();
