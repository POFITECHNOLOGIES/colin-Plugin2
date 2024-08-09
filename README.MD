# ShipStream <=> WooCommerce Sync Extension

## Overview

The ShipStream <=> WooCommerce Sync Extension is a comprehensive tool designed to synchronize inventory, orders, and tracking information between your WooCommerce store and the [ShipStream WooCommerce Plugin](https://github.com/ShipStream/plugin-woocommerce). This extension facilitates efficient management of orders and inventory by providing real-time synchronization and integration capabilities. Please note that this extension will not function until the corresponding ShipStream plugin subscription is properly configured.

## Features

This extension adds the following functionalities to your WooCommerce store:

- **Settings Integration:**
  - Adds a new settings page under `WooCommerce > Settings > ShipStream Sync` with options to:
    - **Enable Real-Time Order Sync:** Activate real-time synchronization of orders between WooCommerce and ShipStream.
    - **Send New Shipment Email:** Configure the extension to send notification emails when new shipments are created.

- **New Order Statuses:**
  - Introduces three new order statuses to better track the lifecycle of orders:
    - **Ready to Ship:** Indicates that the order is prepared and ready for shipment.
    - **Failed to Submit:** Indicates an error occurred while trying to submit the order to the warehouse.
    - **Submitted:** Indicates that the order has been successfully submitted to the warehouse but is not yet fully shipped.

- **API Endpoints:**
  - Adds several API endpoints for interacting with ShipStream:
    - `shipstream/v1/info`
    - `shipstream/v1/set_config`
    - `shipstream/v1/sync_inventory`
    - `shipstream/v1/stock_item/adjust`
    - `shipstream/v1/order_shipment/info`
    - `shipstream/v1/order_shipment/create_with_tracking`
    - `shipstream/v1/order/list`
    - `shipstream/v1/order/addComment`

- **Event Observer:**
  - Adds an event observer for `salesOrderSaveAfter` to trigger synchronization of orders when they are saved.

- **Cron Job:**
  - Configures a cron job to perform a full inventory synchronization daily at 02:00, including a short random sleep time to distribute server load.

- **Configuration Storage:**
  - Stores the ShipStream remote URL in the `options` table for easy access and configuration.

## Purpose of New Statuses

The addition of new order statuses provides better visibility into the order processing workflow, ensuring that orders are correctly managed and tracked. The new statuses help distinguish between different stages of order processing:

- **Ready to Ship:** This status can be set manually or programmatically (through custom code) to indicate that an order is ready for shipment. If this status is not needed, you can configure ShipStream to pull orders in the "Processing" status instead.
  
- **Failed to Submit:** This status signals that there was an issue when attempting to submit the order to the warehouse.

- **Submitted:** This status indicates that the order has been successfully submitted to the warehouse, but not yet fully shipped. The order status will automatically advance to "Complete" once the shipment is finalized.

**Note:** You can customize the labels of these statuses, but it is crucial to retain the status codes to maintain integration functionality.

If configured to sync orders with the "Ready to Ship" status, the order status progression will follow this workflow:

![Status State Diagram](https://raw.githubusercontent.com/ShipStream/openmage-sync/master/shipstream-sync.png)

Alternatively, if configured to sync orders with the "Processing" status, the workflow will be:

![Status State Diagram](https://raw.githubusercontent.com/ShipStream/openmage-sync/master/shipstream-sync-processing.png)

Custom workflows can be implemented by adjusting the statuses used for synchronization.

## Installation

The ShipStream Sync Extension can be installed via modman, zip file, or tar file.

### Installation Methods

#### Using modman

```bash
$ modman init
$ modman clone https://github.com/ShipStream/woocommerce-shipstream-sync
```

#### Using a Zip File

1. Download the latest version of the extension from [GitHub](https://github.com/ShipStream/woocommerce-shipstream-sync).
2. Extract the contents of the downloaded file to a clean directory.
3. Move the files from the `wp-content/plugins/woocommerce-shipstream-sync` directory into the root directory of your WordPress installation.

## Setup

After installation, complete the following steps to configure the extension:

1. **Activate the Plugin:**
   - Log in to your WordPress admin panel.
   - Navigate to `Plugins` and activate the ShipStream Sync Extension.

2. **Create a REST API Key:**
   - Go to `WooCommerce > Settings > Advanced > REST API`.
   - Add a new key with the required permissions (Read/Write) and note down the Consumer Key and Consumer Secret.

3. **Configure ShipStream Subscription:**
   - Set up the plugin subscription in ShipStream by providing the necessary API information and configuration settings.

## Configuration

Adjust the settings in `WooCommerce > Settings > ShipStream Sync` according to your requirements. Ensure that the API URL, Consumer Key, and Consumer Secret are correctly configured.

## ShipStream Setup

The ShipStream plugin requires the following information:

- **REST API URL:** The base URL of your WooCommerce site plus `/wp-json/`.
- **REST API Consumer Key and Secret:** The keys generated in the previous step.
- **Order Status for Automatic Import:** Specify the status to use for automatic order import (e.g., "Processing" or "Ready to Ship").

## Customization

Feel free to modify the source code to tailor the extension to your specific needs. For example, if you have multiple fulfillment providers, you may want to add metadata to orders to control automatic import behavior.

## Support

For assistance, please contact us at [help@shipstream.io](mailto:help@shipstream.io). We're here to help with any questions or issues you may have.