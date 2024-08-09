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
            register_rest_route('shipstream/v1', '/order/status_update', array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'updateOrderStatus'),
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
    public static function get_info(WP_REST_Request $request) {
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
    public static function set_config(WP_REST_Request $request) {
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
    public static function sync_inventory(WP_REST_Request $request) {
        try {
            ShipStream_Cron::full_inventory_sync(false);
        } catch (Exception $e) {
            error_log($e->getMessage());
            return new WP_REST_Response(array('success' => false, 'message' => $e->getMessage()), 500);
        }
        return new WP_REST_Response(array('success' => true,'message' => 'Inventory synced successfully.'), 200);
    }

    // Adjust stock item quantity
    public static function adjust_stock_item(WP_REST_Request $request) {
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
    public static function get_order_shipment_info(WP_REST_Request $request) {
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

    public static function create_order_shipment_with_tracking($shipment_data) {
        $order_increment_id = $shipment_data['orderIncrementId'];
        $order_data = $shipment_data['data'];

        // Fetch the order using the increment ID
        $order = wc_get_order($order_increment_id);
        if (!$order) {
            return;
        }

        // Add tracking numbers to the order using the Shipment Tracking extension
        foreach ($order_data['packages'] as $package) {
            foreach ($package['tracking_numbers'] as $tracking_number) {
                self::add_tracking_number_to_order($order->get_id(), $tracking_number, $order_data['carrier']);
            }
        }

        // Update the order's line items with shipment info data
        self::update_order_line_items_with_shipment_info($order->get_id(), $order_data);

        // Check if all items are shipped and mark order as completed
        if ($order_data['status'] == 'packed' && $order_data['order_status'] == 'complete') {
            self::maybe_mark_order_as_completed($order->get_id(), $order_data);
        }
    }

    public static function add_tracking_number_to_order($order_id, $tracking_number, $carrier) {
        if (!class_exists('WC_Shipment_Tracking')) {
            return;
        }

        $tracking_provider = WC_Shipment_Tracking::get_providers()[$carrier] ?? $carrier;

        $tracking_data = array(
            'tracking_provider' => $tracking_provider,
            'tracking_number'   => $tracking_number,
            'date_shipped'      => current_time('timestamp'),
        );

        $wc_shipment_tracking = new WC_Shipment_Tracking();
        $wc_shipment_tracking->add_tracking_number($order_id, $tracking_data);
    }

    public static function update_order_line_items_with_shipment_info($order_id, $order_data) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        foreach ($order->get_items() as $item_id => $item) {
            $item_sku = $item->get_product()->get_sku();

            foreach ($order_data['items'] as $shipment_item) {
                if ($shipment_item['sku'] === $item_sku) {
                    wc_update_order_item_meta($item_id, 'order_item_id', $shipment_item['order_item_id']);
                    wc_update_order_item_meta($item_id, 'sku', $shipment_item['sku']);
                    wc_update_order_item_meta($item_id, 'quantity', $shipment_item['quantity']);
                    wc_update_order_item_meta($item_id, 'package_data', json_encode($shipment_item['package_data']));
                    wc_update_order_item_meta($item_id, 'lot_data', json_encode($shipment_item['lot_data']));
                }
            }
        }
    }

    public static function maybe_mark_order_as_completed($order_id, $order_data) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $all_items_shipped = true;

        foreach ($order->get_items() as $item) {
            $item_sku = $item->get_product()->get_sku();
            $order_quantity = $item->get_quantity();

            foreach ($order_data['items'] as $shipment_item) {
                if ($shipment_item['sku'] === $item_sku) {
                    if ((float)$shipment_item['quantity'] < (float)$order_quantity) {
                        $all_items_shipped = false;
                        break 2;
                    }
                }
            }
        }

        if ($all_items_shipped) {
            $order->update_status('completed');
        }
    }

    /**
     * List orders based on filters from the request
     * @param $request
     * @return WP_REST_Response
     */
    public static function order_list(WP_REST_Request $request)
    {
        global $wpdb;

        $cols_select        = ['id', 'date_updated_gmt'];
        $cols               = ['id', 'date_modified'];
        $orders             = [];
        $date_updated_gmt   = [];
        $status             = [];

        if($request->get_param('date_updated_gmt'))
        {
            $date_updated_gmt = $request->get_param('date_updated_gmt');
        }
        if($request->get_param('status'))
        {
            $status = $request->get_param('status');
        }
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
    public static function add_order_comment(WP_REST_Request $request)
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

    /**
     * Update the status of an order and add a comment.
     * 
     * @param WP_REST_Request $request The REST request object.
     * @return WP_REST_Response The response object.
     */
    public static function updateOrderStatus(WP_REST_Request $request)
    {
        // Get parameters from the request
        $shipstream_id = $request->get_param('shipstreamId');
        $order_id = $request->get_param('orderId');
        $status = $request->get_param('status');

        // Check for missing required parameters
        if (empty($shipstream_id) || empty($order_id) || empty($status)) {
            return new WP_REST_Response(['error' => 'Order ID, Status, and Shipment ID are required.'], 400);
        }

        // Get the order by ID
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_REST_Response(['error' => 'Invalid order ID.'], 404);
        }

        // Validate and update the order status
        $sanitized_status = sanitize_text_field($status);
        if (empty($sanitized_status)) {
            return new WP_REST_Response(['error' => 'Invalid status.'], 400);
        }

        // Update order status and add a comment
        $order->update_status($sanitized_status, 'Order status updated by ShipStream');
        $order->delete_meta_data('_shipstream_order_ids');
        $order->save();

        // Return a success response
        return new WP_REST_Response(['success' => 'Status updated successfully for order #' . $order_id], 200);
    }


}

// Initialize the ShipStream API
ShipStream_API::init();
