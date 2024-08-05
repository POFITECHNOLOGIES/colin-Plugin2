# ShipStream Plugin for WooCommerce

## Overview

The ShipStream Plugin for WooCommerce is a powerful tool designed to integrate WooCommerce stores with the ShipStream ecosystem. This plugin facilitates seamless communication with the [ShipStream WooCommerce Sync Extension](https://github.com/ShipStream/woocommerce-shipstream-sync.git) and operates within the [ShipStream Merchant Plugin Middleware](https://github.com/ShipStream/middleware) environment, enabling efficient order management, inventory synchronization, and fulfillment operations.

## Prerequisites

Before installing and configuring the ShipStream Plugin, ensure you have the following:

1. **WooCommerce Installation:** A functioning WooCommerce setup where you can install and configure plugins.
2. **ShipStream Middleware:** The ShipStream Merchant Plugin Middleware must be installed and operational. Follow the instructions provided in the [ShipStream Middleware documentation](https://github.com/ShipStream/middleware) for setup.

## Development Environment Installation

To set up the development environment for the ShipStream Plugin, follow these steps:

1. **Install the Middleware Environment:**
   Follow the detailed instructions provided in the [ShipStream Middleware installation guide](https://github.com/ShipStream/middleware). This will set up the necessary infrastructure for the ShipStream Plugin.

2. **Initialize the Project Directory:**
   Open your terminal and navigate to the root directory of your project. Run the following command to initialize the directory for module management:
   ```bash
   $ bin/modman init
   ```

3. **Clone the ShipStream Plugin Repository:**
   Use the command below to clone the ShipStream WooCommerce Plugin repository into your project directory:
   ```bash
   $ bin/modman clone https://github.com/ShipStream/plugin-woocommerce.git
   ```

## Configuration

After installing the plugin, you'll need to configure it to work with your WooCommerce store and the ShipStream Middleware. This involves creating a REST API key in WooCommerce and setting it in the plugin configuration.

### WooCommerce API Setup

1. **Generate API Keys:**
   - Log in to your WooCommerce admin panel.
   - Navigate to `WooCommerce` > `Settings` > `Advanced` > `REST API`.
   - Click `Add Key` and fill in the description, select a user, and set permissions to `Read/Write`.
   - Click `Generate API Key` and save the generated `Consumer Key` and `Consumer Secret`.

2. **Configure the Plugin:**
   You need to provide these API keys in the plugin's configuration file.

### Example Configuration

**Sample `local.xml` file for the ShipStream Middleware environment:**

```xml
<?xml version="1.0"?>
<config>
    <default>
        <middleware>
            <!-- Middleware-specific settings -->
        </middleware>
        <plugin>
            <ShipStream_WooCommerce>
                <api_url>https://example.com/wp-json/</api_url>
                <api_login>shipstream</api_login>
                <api_password>###</api_password>
                <auto_fulfill_status>wc-ready-to-ship</auto_fulfill_status>
                <auto_fulfill_custom/>
                <shipping_method_config>
                    [
                        {"shipping_method":"cheapest_GROUND","field":"shipping_method","operator":"=","pattern":"flat_rate"},
                        {"shipping_method":"fedex_FEDEX_2_DAY","field":"shipping_description","operator":"=","pattern":"Expedited"}
                    ]
                </shipping_method_config>
                <sync_orders_since>###</sync_orders_since>
            </ShipStream_WooCommerce>
        </plugin>
    </default>
</config>
```

### Configuration Details

- **`<api_url>`:** The base URL for the WooCommerce REST API endpoint. Ensure this URL is accessible and correctly points to your WooCommerce store's API.
- **`<api_login>`** and **`<api_password>`:** The REST API keys generated in WooCommerce. Replace `###` with the actual keys.
- **`<auto_fulfill_status>`:** The status used to filter orders that are ready for fulfillment. Orders with this status will be processed for automatic fulfillment.
- **`<auto_fulfill_custom>`:** Custom settings or parameters for automatic fulfillment, if applicable. Leave empty if not used.
- **`<shipping_method_config>`:** A JSON array defining shipping method mappings between WooCommerce and ShipStream. Adjust based on your shipping methods and descriptions.
- **`<sync_orders_since>`:** The date from which orders should be synchronized. Use the format `YYYY-MM-DD` to specify the last update date for orders.

### Notes

1. **Order Fulfillment Status:** Ensure that the `<auto_fulfill_status>` value matches the status used in your WooCommerce setup for orders that are ready to be shipped.
2. **Synchronization Date:** The `<sync_orders_since>` value should be set to the last synchronization date to ensure that only new or updated orders are processed.

## Troubleshooting

If you encounter issues with the plugin:

1. **Check Logs:** Review logs in both WooCommerce and ShipStream Middleware for error messages or warnings.
2. **Verify API Credentials:** Ensure that the API credentials are correctly entered and have appropriate permissions.
3. **Consult Documentation:** Refer to the [ShipStream Middleware documentation](https://github.com/ShipStream/middleware) and [ShipStream WooCommerce Sync Extension documentation](https://github.com/ShipStream/woocommerce-shipstream-sync.git) for additional configuration details and troubleshooting tips.

## Contribution

Contributions to the ShipStream Plugin are welcome. If you have suggestions, improvements, or bug fixes, please submit a pull request or open an issue in the [GitHub repository](https://github.com/ShipStream/plugin-woocommerce).
