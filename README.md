=== LazyChat ===
Contributors: lazychat
Tags: woocommerce, customer support, ai, chatbot, webhook
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.4.17
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WooCommerce store with LazyChat's AI-powered customer support platform. Automatically sync products via webhooks.

== Description ==

LazyChat integrates your WooCommerce store with LazyChat's AI-powered customer support platform, enabling automatic synchronization of products, orders, and customers.

**Features:**

* Automatic product synchronization with LazyChat
* Order creation and management via REST API
* Customer data integration
* Secure API connection with bearer token authentication
* Category and attribute management
* Product variant support
* Comprehensive error logging

**How it works:**

1. Install and activate the plugin
2. Navigate to WooCommerce > LazyChat Settings
3. Enter your Shop ID and Bearer Token from LazyChat
4. Connect and start syncing your data

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/lazychat` directory, or install the plugin through the WordPress plugins screen directly
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to WooCommerce > LazyChat Settings to configure the plugin
4. Enter your Shop ID and Bearer Token provided by LazyChat
5. Click "Connect to LazyChat" to establish the connection

== Frequently Asked Questions ==

= What is LazyChat? =

LazyChat is an AI-powered customer support platform that helps WooCommerce store owners provide better customer service through automated responses and product information.

= Do I need a LazyChat account? =

Yes, you need an active LazyChat account to use this plugin. Sign up at https://app.lazychat.io

= Where do I find my Shop ID and Bearer Token? =

You can find your Shop ID and Bearer Token in your LazyChat account dashboard under the WooCommerce integration section.

= Does this plugin work with WooCommerce product variations? =

Yes, the plugin fully supports WooCommerce product variations and attributes.

== External services ==

This plugin connects to LazyChat's external servers to provide AI-powered customer support functionality for your WooCommerce store.

= LazyChat API =

**Service URL:** https://app.lazychat.io

This plugin sends the following data to LazyChat servers:

* **Product information** (names, prices, images, descriptions, SKUs, stock status) - sent when products are created, updated, or deleted via webhooks
* **Store credentials** - sent during authentication to establish secure connection
* **Site information** (WordPress version, WooCommerce version, plugin version) - sent for compatibility and debugging purposes

**When data is transmitted:**

* When you login and connect your WooCommerce store
* When product webhooks are triggered (automatic sync on create/update/delete)
* When you manually sync products from the settings page
* When the plugin settings page is loaded (connection verification)
* When the plugin status is toggled on/off

= LazyChat Serverless Webhooks =

**Service URL:** https://serverless.lazychat.io

Used for real-time webhook delivery for product updates. Receives the same product data as described above.

= Service Provider =

**LazyChat** - AI-powered customer support platform

* [Terms of Service](https://app.lazychat.io/legal/terms-and-conditions)
* [Privacy Policy](https://app.lazychat.io/legal/privacy-policy)

All data transmission uses secure HTTPS connections with bearer token authentication.

== Changelog ==

= 1.4.16 =
* Improved security: sanitized all $_SERVER inputs and enhanced code quality
* Updated external services documentation for WordPress plugin guidelines compliance

= 1.4.10 =
* Fixed rand() to use wp_rand() for WordPress coding standards
* Wrapped error_log() calls with WP_DEBUG checks
* Added translator comments for i18n compliance
* Code cleanup and optimization

= 1.3.39 =
* Fixed nonce sanitization for WordPress.org compliance
* Removed external file dependencies
* Added translator comments for i18n compliance
* Code cleanup and optimization
* Improved error handling

== Upgrade Notice ==

= 1.4.10 =
WordPress coding standards improvements and security enhancements.
