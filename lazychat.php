<?php
/**
 * Plugin Name: LazyChat
 * Plugin URI: https://app.lazychat.io
 * Description: Connect your WooCommerce store with LazyChat's AI-powered customer support platform. Automatically sync products and orders via webhooks.
 * Version: 1.3.32
 * Author: LazyChat
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lazychat
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 6.0
 * WC tested up to: 8.5
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('LAZYCHAT_VERSION', '1.3.32');
define('LAZYCHAT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LAZYCHAT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LAZYCHAT_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Check if WooCommerce is active
 */
if (!function_exists('is_plugin_active')) {
    include_once(ABSPATH . 'wp-admin/includes/plugin.php');
}

if (!is_plugin_active('woocommerce/woocommerce.php') && !function_exists('WC')) {
    add_action('admin_notices', 'lazychat_woocommerce_missing_notice');
    return;
}

/**
 * Display notice if WooCommerce is not active
 */
function lazychat_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php esc_html_e('LazyChat requires WooCommerce to be installed and activated.', 'lazychat'); ?></p>
    </div>
    <?php
}

/**
 * Declare HPOS compatibility
 */
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Create logs database table
 */
function lazychat_create_logs_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'lazychat_logs';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        timestamp datetime NOT NULL,
        log_type varchar(20) NOT NULL DEFAULT 'info',
        event_type varchar(50) DEFAULT NULL,
        entity_type varchar(20) DEFAULT NULL,
        entity_id varchar(50) DEFAULT NULL,
        message text NOT NULL,
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_timestamp (timestamp),
        KEY idx_entity (entity_type, entity_id),
        KEY idx_event_type (event_type)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Store the database version
    update_option('lazychat_db_version', '1.0.0');
}

/**
 * Plugin activation hook
 */
function lazychat_activate() {
    // Create logs table
    lazychat_create_logs_table();
    
    // Set default options
    if (get_option('lazychat_enable_products') === false) {
        update_option('lazychat_enable_products', 'Yes');
    }
    if (get_option('lazychat_enable_orders') === false) {
        update_option('lazychat_enable_orders', 'No');
    }
    if (get_option('lazychat_enable_connection_test') === false) {
        update_option('lazychat_enable_connection_test', 'Yes');
    }
    if (get_option('lazychat_enable_debug_logging') === false) {
        update_option('lazychat_enable_debug_logging', 'Yes');
    }
    
    // Schedule automatic log cleanup (runs daily to check for logs older than 15 days)
    if (!wp_next_scheduled('lazychat_cleanup_old_logs')) {
        wp_schedule_event(time(), 'daily', 'lazychat_cleanup_old_logs');
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'lazychat_activate');

/**
 * Plugin deactivation hook
 */
function lazychat_deactivate() {
    // Clear scheduled log cleanup event
    $timestamp = wp_next_scheduled('lazychat_cleanup_old_logs');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'lazychat_cleanup_old_logs');
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'lazychat_deactivate');

/**
 * Cleanup old logs (older than 15 days)
 * Runs daily via WordPress cron
 */
function lazychat_cleanup_old_logs() {
    global $wpdb;
    
    // Calculate date 15 days ago (using gmdate for timezone-safe handling)
    $delete_before = gmdate('Y-m-d H:i:s', strtotime('-15 days'));
    
    // Delete logs older than 15 days
    $table_name = $wpdb->prefix . 'lazychat_logs';
    $deleted = $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM `{$wpdb->prefix}lazychat_logs` WHERE timestamp < %s",
            $delete_before
        )
    );
    
    // Log the cleanup action if WP_DEBUG is enabled
    if (defined('WP_DEBUG') && WP_DEBUG && $deleted > 0) {
        error_log(sprintf('[LazyChat] Automatic log cleanup: Deleted %d logs older than 15 days', $deleted));
    }
    
    // Return the number of deleted rows (useful for testing)
    return $deleted;
}
add_action('lazychat_cleanup_old_logs', 'lazychat_cleanup_old_logs');

/**
 * Check if REST API is working
 */
function lazychat_check_rest_api() {
    // Make a test request to WordPress REST API
    $rest_url = rest_url();
    $response = wp_remote_get($rest_url, array(
        'timeout' => 5,
        'sslverify' => false
    ));
    
    if (is_wp_error($response)) {
        return false;
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    // 200 or 401 (unauthorized but API is working) are acceptable
    return in_array($response_code, array(200, 401));
}

/**
 * Display REST API error notice
 */
function lazychat_rest_api_error_notice() {
    // Only show to admins
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Check if notice has been dismissed
    if (get_option('lazychat_rest_api_notice_dismissed')) {
        return;
    }
    
    // Check if REST API is working
    if (lazychat_check_rest_api()) {
        // REST API is working, remove dismissed flag if it exists
        delete_option('lazychat_rest_api_notice_dismissed');
        return;
    }
    
    $settings_url = admin_url('options-general.php?page=lazychat_settings');
    $permalink_url = admin_url('options-permalink.php');
    $dismiss_url = add_query_arg('lazychat_dismiss_rest_notice', '1');
    
    ?>
    <div class="notice notice-error is-dismissible" style="position: relative;">
        <p><strong><?php esc_html_e('LazyChat: REST API Not Working', 'lazychat'); ?></strong></p>
        <p><?php esc_html_e('The WordPress REST API is not accessible on your site. This will prevent LazyChat from functioning properly.', 'lazychat'); ?></p>
        <p><?php esc_html_e('Common solutions:', 'lazychat'); ?></p>
        <ol>
            <li><?php esc_html_e('Make sure your permalinks are NOT set to "Plain". Visit', 'lazychat'); ?> <a href="<?php echo esc_url($permalink_url); ?>"><?php esc_html_e('Permalink Settings', 'lazychat'); ?></a> <?php esc_html_e('and select any option except "Plain", then click "Save Changes".', 'lazychat'); ?></li>
            <li><?php esc_html_e('Click the "Fix REST API" button in', 'lazychat'); ?> <a href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('LazyChat Settings', 'lazychat'); ?></a></li>
            <li><?php esc_html_e('Contact your hosting provider if the issue persists - they may have REST API disabled.', 'lazychat'); ?></li>
        </ol>
        <p><a href="<?php echo esc_url($dismiss_url); ?>" class="button"><?php esc_html_e('Dismiss this notice', 'lazychat'); ?></a></p>
    </div>
    <?php
}
add_action('admin_notices', 'lazychat_rest_api_error_notice');

/**
 * Handle REST API notice dismissal
 */
function lazychat_handle_rest_api_notice_dismissal() {
    if (isset($_GET['lazychat_dismiss_rest_notice']) && current_user_can('manage_options')) {
        update_option('lazychat_rest_api_notice_dismissed', true);
        wp_safe_redirect(remove_query_arg('lazychat_dismiss_rest_notice'));
        exit;
    }
}
add_action('admin_init', 'lazychat_handle_rest_api_notice_dismissal');

/**
 * Initialize the plugin
 */
function lazychat_init() {
    // Load required files
    require_once LAZYCHAT_PLUGIN_DIR . 'includes/class-error-logger.php';
    require_once LAZYCHAT_PLUGIN_DIR . 'includes/formatters/class-product-formatter.php';
    require_once LAZYCHAT_PLUGIN_DIR . 'includes/controllers/class-product-controller.php';
    require_once LAZYCHAT_PLUGIN_DIR . 'includes/controllers/class-order-controller.php';
    require_once LAZYCHAT_PLUGIN_DIR . 'includes/controllers/class-customer-controller.php';
    require_once LAZYCHAT_PLUGIN_DIR . 'includes/class-admin.php';
    require_once LAZYCHAT_PLUGIN_DIR . 'includes/class-webhook-sender.php';
    require_once LAZYCHAT_PLUGIN_DIR . 'includes/class-ajax-handlers.php';
    require_once LAZYCHAT_PLUGIN_DIR . 'includes/class-rest-api.php';
    
    // Initialize classes
    if (is_admin()) {
        new LazyChat_Admin();
    }
    new LazyChat_Webhook_Sender();
    new LazyChat_Ajax_Handlers();
    new LazyChat_REST_API();
}
add_action('plugins_loaded', 'lazychat_init');

/**
 * Add settings link on plugin page
 */
function lazychat_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=lazychat_settings') . '">' . __('Settings', 'lazychat') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . LAZYCHAT_PLUGIN_BASENAME, 'lazychat_add_settings_link');

/**
 * Add plugin meta links
 */
function lazychat_add_plugin_meta_links($links, $file) {
    if ($file === LAZYCHAT_PLUGIN_BASENAME) {
        $links[] = '<a href="https://app.lazychat.io" target="_blank">' . __('Visit Website', 'lazychat') . '</a>';
        $links[] = '<a href="mailto:support@lazychat.io">' . __('Support', 'lazychat') . '</a>';
    }
    return $links;
}
add_filter('plugin_row_meta', 'lazychat_add_plugin_meta_links', 10, 2);

