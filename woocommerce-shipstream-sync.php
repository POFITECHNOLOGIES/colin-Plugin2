<?php
/*
Plugin Name: WooCommerce ShipStream Sync
Description: Sync WooCommerce with ShipStream.
Version: 1.0.0
Author: Sivanathan T
*/

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Include required files.
require_once plugin_dir_path(__FILE__) . 'includes/functions.php';
include_once plugin_dir_path(__FILE__) . 'includes/class-shipstream-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-shipstream-cron.php';
include_once(plugin_dir_path(__FILE__) . 'includes/class-shipstream-sync.php');
require_once plugin_dir_path(__FILE__) . 'includes/class-shipstream-sync-observer.php';

// Initialize the observer.
new ShipStream_Sync_Observer();

// Initialize the plugin.
add_action('plugins_loaded', array('ShipStream_Sync', 'init'));

// Schedule activation and deactivation hooks.
register_activation_hook(__FILE__, 'shipstream_sync_activate');
register_deactivation_hook(__FILE__, 'shipstream_sync_deactivate');

/**
 * Activation hook function.
 */
function shipstream_sync_activate() {
    // Schedule cron job on activation.
    if (!wp_next_scheduled('shipstream_full_inventory_sync')) {
        $timestamp = strtotime('02:00:00');
        if (time() > $timestamp) {
            $timestamp = strtotime('tomorrow 02:00:00');
        }
        wp_schedule_event($timestamp, 'daily', 'shipstream_full_inventory_sync');
    }
}

/**
 * Deactivation hook function.
 */
function shipstream_sync_deactivate() {
    // Clear scheduled cron job on deactivation.
    wp_clear_scheduled_hook('shipstream_full_inventory_sync');
}
?>
