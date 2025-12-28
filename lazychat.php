<?php
/**
 * Plugin Name: LazyChat
 * Plugin URI: https://app.lazychat.io
 * Description: Connect your WooCommerce store with LazyChat's AI-powered customer support platform. Automatically sync products and orders via webhooks.
 * Version: 1.4.7
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
define('LAZYCHAT_VERSION', '1.4.7');
define('LAZYCHAT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LAZYCHAT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LAZYCHAT_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('LAZYCHAT_REST_API_CHECK_CACHE_DURATION', 30 * MINUTE_IN_SECONDS); // Cache REST API check for 30 minutes

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
    // Check cached result first (cache duration set by LAZYCHAT_REST_API_CHECK_CACHE_DURATION constant)
    $cached_result = get_transient('lazychat_rest_api_check');
    if ($cached_result !== false) {
        return $cached_result === 'working';
    }
    
    // First check: Verify permalink structure (Plain permalinks break REST API)
    $permalink_structure = get_option('permalink_structure');
    if (empty($permalink_structure)) {
        // Permalinks are set to Plain - REST API won't work properly
        set_transient('lazychat_rest_api_check', 'not_working', LAZYCHAT_REST_API_CHECK_CACHE_DURATION);
        return false;
    }
    
    // Second check: Make a test request to WordPress REST API
    $rest_url = rest_url();
    $response = wp_remote_get($rest_url, array(
        'timeout' => 10, // Increased from 5 to 10 seconds to match manual test
        'sslverify' => false
    ));
    
    $is_working = false;
    
    if (is_wp_error($response)) {
        // If first attempt fails, retry once to avoid false positives from temporary issues
        $response = wp_remote_get($rest_url, array(
            'timeout' => 10,
            'sslverify' => false
        ));
        
        if (is_wp_error($response)) {
            $is_working = false;
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            $is_working = in_array($response_code, array(200, 401));
        }
    } else {
        $response_code = wp_remote_retrieve_response_code($response);
        // 200 or 401 (unauthorized but API is working) are acceptable
        $is_working = in_array($response_code, array(200, 401));
    }
    
    // Cache the result
    set_transient('lazychat_rest_api_check', $is_working ? 'working' : 'not_working', LAZYCHAT_REST_API_CHECK_CACHE_DURATION);
    
    return $is_working;
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
    
    ?>
    <div class="notice notice-warning is-dismissible lazychat-rest-notice">
        <p>
            <strong><?php esc_html_e('LazyChat:', 'lazychat'); ?></strong> 
            <?php esc_html_e('WordPress REST API is not accessible.', 'lazychat'); ?> 
            <a href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('Go to LazyChat Settings to fix this issue.', 'lazychat'); ?></a>
        </p>
    </div>
    <script>
    jQuery(document).ready(function($) {
        $('.lazychat-rest-notice').on('click', '.notice-dismiss', function() {
            $.post(ajaxurl, {
                action: 'lazychat_dismiss_rest_notice',
                nonce: '<?php echo esc_js(wp_create_nonce('lazychat_dismiss_rest_notice')); ?>'
            });
        });
    });
    </script>
    <?php
}
add_action('admin_notices', 'lazychat_rest_api_error_notice');

/**
 * Handle REST API notice dismissal via AJAX
 */
add_action('wp_ajax_lazychat_dismiss_rest_notice', function() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'lazychat_dismiss_rest_notice')) {
        wp_send_json_error();
    }
    
    // Check permissions
    if (current_user_can('manage_options')) {
        update_option('lazychat_rest_api_notice_dismissed', true);
        // Clear the check cache so it rechecks later
        delete_transient('lazychat_rest_api_check');
        wp_send_json_success();
    }
    wp_send_json_error();
});

/**
 * Clear REST API check cache when permalink structure changes
 */
add_action('update_option_permalink_structure', function() {
    delete_transient('lazychat_rest_api_check');
    delete_option('lazychat_rest_api_notice_dismissed');
});

/**
 * Clear REST API check cache when rewrite rules are flushed
 */
add_action('wp_loaded', function() {
    // Check if this is a permalink save action
    if (isset($_POST['permalink_structure']) && current_user_can('manage_options')) {
        delete_transient('lazychat_rest_api_check');
        delete_option('lazychat_rest_api_notice_dismissed');
    }
}, 999);

/**
 * Check for plugin updates and notify LazyChat
 */
function lazychat_check_plugin_update() {
    $current_version = LAZYCHAT_VERSION;
    $stored_version = get_option('lazychat_plugin_version');
    
    // If version has changed or not set yet
    if ($stored_version !== $current_version) {
        // Update stored version
        update_option('lazychat_plugin_version', $current_version);
        
        // If there was a previous version, this is an update
        if ($stored_version !== false && $stored_version !== $current_version) {
            // Send update notification to LazyChat
            lazychat_send_event_notification('plugin.updated', array(
                'previous_version' => $stored_version,
                'new_version' => $current_version,
                'update_time' => current_time('mysql')
            ));
        } else {
            // First time activation
            lazychat_send_event_notification('plugin.installed', array(
                'version' => $current_version,
                'install_time' => current_time('mysql')
            ));
        }
    }
}

/**
 * Send event notification to LazyChat backend
 * 
 * @param string $event_type The type of event (e.g., 'plugin.updated', 'admin.login', etc.)
 * @param array $event_data Additional data to send with the event
 * @return bool Success status
 */
function lazychat_send_event_notification($event_type, $event_data = array()) {
    // Check if bearer token is set
    $bearer_token = get_option('lazychat_bearer_token');
    if (empty($bearer_token)) {
        error_log('[LazyChat] Cannot send event notification - Bearer token not configured');
        return false;
    }
    
    // Prepare the event payload
    $payload = array(
        'event_type' => $event_type,
        'event_data' => $event_data,
        'site_info' => array(
            'site_url' => get_site_url(),
            'site_name' => get_bloginfo('name'),
            'wordpress_version' => get_bloginfo('version'),
            'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : 'N/A',
            'plugin_version' => LAZYCHAT_VERSION,
            'php_version' => phpversion(),
            'timestamp' => current_time('mysql')
        )
    );
    
    // Get shop ID if available
    $shop_id = get_option('lazychat_selected_shop_id', '');
    
    // API endpoint for event notifications
    $api_url = 'https://app.lazychat.io/api/woocommerce-plugin/events';
    
    // Prepare headers
    $headers = array(
        'Authorization' => 'Bearer ' . $bearer_token,
        'Content-Type' => 'application/json',
        'X-Event-Type' => $event_type,
        'X-Plugin-Version' => LAZYCHAT_VERSION,
        'X-Lazychat-Shop-Id' => $shop_id,
        'X-Event-Timestamp' => time()
    );
    
    // Send the request
    $response = wp_remote_post($api_url, array(
        'body' => wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'headers' => $headers,
        'timeout' => 15,
        'blocking' => true, // TEMPORARY: Changed to blocking for debugging
        'data_format' => 'body'
    ));
    
    // Debug logging
    if (is_wp_error($response)) {
        error_log('[LazyChat] Event notification failed (' . $event_type . '): ' . $response->get_error_message());
        return false;
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    error_log('[LazyChat] Event sent (' . $event_type . ') - Response Code: ' . $response_code . ' - Body: ' . $response_body);
    
    return true;
}

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
    require_once LAZYCHAT_PLUGIN_DIR . 'includes/controllers/class-category-controller.php';
    require_once LAZYCHAT_PLUGIN_DIR . 'includes/controllers/class-attribute-controller.php';
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
    
    // Check for plugin updates
    lazychat_check_plugin_update();
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

