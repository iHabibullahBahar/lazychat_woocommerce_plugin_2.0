=== LazyChat ===
Contributors: lazychat
Tags: woocommerce, customer support, ai, chatbot, webhook
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.4.15
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WooCommerce store with LazyChat's AI-powered customer support platform. Automatically sync products and orders via webhooks.

== Description ==

LazyChat integrates your WooCommerce store with LazyChat's AI-powered customer support platform, enabling automatic synchronization of products, orders, and customers.

**Features:**

* Automatic product synchronization with LazyChat
* Real-time order updates via webhooks
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

== Changelog ==

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
