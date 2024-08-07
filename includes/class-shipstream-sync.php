<?php

class ShipStream_Sync {

    public static function init() {
        // Add custom order statuses.
        add_filter('wc_order_statuses', array(__CLASS__, 'add_custom_order_statuses'));
        add_action('init', array(__CLASS__, 'register_custom_order_statuses'));

        // Add settings.
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        
        // Add WooCommerce settings tab.
        add_filter('woocommerce_settings_tabs_array', array(__CLASS__, 'add_settings_tab'), 50);
        add_action('woocommerce_settings_tabs_shipstream_sync', array(__CLASS__, 'settings_tab'));
        add_action('woocommerce_update_options_shipstream_sync', array(__CLASS__, 'update_settings'));

        // Schedule cron job.
        if (!wp_next_scheduled('shipstream_full_inventory_sync')) {
            $timestamp = strtotime('02:00:00');
            if (time() > $timestamp) {
                $timestamp = strtotime('tomorrow 02:00:00');
            }
            wp_schedule_event($timestamp, 'daily', 'shipstream_full_inventory_sync');
        }
        add_action('shipstream_full_inventory_sync', array(__CLASS__, 'full_inventory_sync'));
        
        // Hook to order save event.
        add_action('save_post_shop_order', array(__CLASS__, 'order_save'), 10, 3);
    }

    public static function add_custom_order_statuses($order_statuses) {
        $order_statuses['wc-ready-to-ship'] = 'Ready to Ship';
        $order_statuses['wc-failed-to-submit'] = 'Failed to Submit';
        $order_statuses['wc-submitted'] = 'Submitted';
        return $order_statuses;
    }

    public static function register_custom_order_statuses() {
        register_post_status('wc-ready-to-ship', array(
            'label' => 'Ready to Ship',
            'public' => true,
            'show_in_admin_status_list' => true,
            'show_in_admin_all_list' => true,
            'label_count' => _n_noop('Ready to Ship <span class="count">(%s)</span>', 'Ready to Ship <span class="count">(%s)</span>')
        ));

        register_post_status('wc-failed-to-submit', array(
            'label' => 'Failed to Submit',
            'public' => true,
            'show_in_admin_status_list' => true,
            'show_in_admin_all_list' => true,
            'label_count' => _n_noop('Failed to Submit <span class="count">(%s)</span>', 'Failed to Submit <span class="count">(%s)</span>')
        ));

        register_post_status('wc-submitted', array(
            'label' => 'Submitted',
            'public' => true,
            'show_in_admin_status_list' => true,
            'show_in_admin_all_list' => true,
            'label_count' => _n_noop('Submitted <span class="count">(%s)</span>', 'Submitted <span class="count">(%s)</span>')
        ));
    }

    public static function register_settings() {
        register_setting('shipstream_sync_options', 'enable_real_time_order_sync');
        register_setting('shipstream_sync_options', 'send_new_shipment_email');

        add_settings_section(
            'shipstream_sync_settings',
            'ShipStream Sync Settings',
            null,
            'shipstream-sync'
        );

        add_settings_field(
            'enable_real_time_order_sync',
            'Enable Real-Time Order Sync',
            array(__CLASS__, 'render_enable_real_time_order_sync'),
            'shipstream-sync',
            'shipstream_sync_settings'
        );

        add_settings_field(
            'send_new_shipment_email',
            'Send New Shipment Email',
            array(__CLASS__, 'render_send_new_shipment_email'),
            'shipstream-sync',
            'shipstream_sync_settings'
        );
    }

    public static function render_enable_real_time_order_sync() {
        $value = get_option('enable_real_time_order_sync', '');
        echo '<input type="checkbox" id="enable_real_time_order_sync" name="enable_real_time_order_sync" value="1"' . checked(1, $value, false) . '/>';
    }

    public static function render_send_new_shipment_email() {
        $value = get_option('send_new_shipment_email', '');
        echo '<input type="checkbox" id="send_new_shipment_email" name="send_new_shipment_email" value="1"' . checked(1, $value, false) . '/>';
    }

    public static function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>ShipStream Sync Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('shipstream_sync_options');
                do_settings_sections('shipstream-sync');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public static function full_inventory_sync() {
        try {
            ShipStream_Cron::full_inventory_sync(false);
        } catch (Exception $e) {
            // Log the exception
            error_log($e->getMessage());
            return new WP_REST_Response(array('success' => false, 'message' => $e->getMessage()), 500);
        }
        return new WP_REST_Response(array('success' => true), 200);
    }

    public static function order_save($post_id, $post, $update) {
        if ($post->post_type != 'shop_order') {
            return;
        }
    }

    // Add WooCommerce settings tab
    public static function add_settings_tab($settings_tabs) {
        $settings_tabs['shipstream_sync'] = 'ShipStream Sync';
        return $settings_tabs;
    }

    public static function settings_tab() {
        woocommerce_admin_fields(self::get_settings());
    }

    public static function update_settings() {
        woocommerce_update_options(self::get_settings());
    }

    public static function get_settings() {
        $settings = array(
            'section_title' => array(
                'name'     => 'ShipStream Sync Settings',
                'type'     => 'title',
                'desc'     => '',
                'id'       => 'shipstream_sync_section_title'
            ),
            'enable_real_time_order_sync' => array(
                'name' => 'Enable Real-Time Order Sync',
                'type' => 'checkbox',
                'desc' => 'Enable real-time order synchronization with ShipStream.',
                'id'   => 'enable_real_time_order_sync'
            ),
            'send_new_shipment_email' => array(
                'name' => 'Send New Shipment Email',
                'type' => 'checkbox',
                'desc' => 'Send an email when a new shipment is created.',
                'id'   => 'send_new_shipment_email'
            ),
            'section_end' => array(
                'type' => 'sectionend',
                'id'   => 'shipstream_sync_section_end'
            )
        );
        return $settings;
    }
}

add_action('plugins_loaded', array('ShipStream_Sync', 'init'));
?>
