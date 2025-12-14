<?php
/**
 * LazyChat Uninstall Script
 * 
 * This file runs when the plugin is uninstalled (deleted).
 * It cleans up all plugin data from the database.
 */

// Exit if uninstall not called from WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Delete plugin options and database tables
 */
function lazychat_uninstall_cleanup() {
    global $wpdb;
    
    // Delete all plugin options
    delete_option('lazychat_bearer_token');
    delete_option('lazychat_enable_products');
    delete_option('lazychat_enable_orders');
    delete_option('lazychat_enable_connection_test');
    delete_option('lazychat_enable_debug_logging');
    delete_option('lazychat_plugin_active');
    delete_option('lazychat_connection_verified');
    delete_option('lazychat_db_version');
    delete_option('lazychat_selected_shop_id');
    delete_option('lazychat_selected_shop_name');
    delete_option('lazychat_wc_consumer_key');
    delete_option('lazychat_wc_consumer_secret');
    delete_option('lazychat_wc_last_access');
    
    // Drop logs table
    // Note: Table name is safe as it uses $wpdb->prefix
    $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}lazychat_logs`");
    
    // Delete all LazyChat transients (product tracking cache)
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lazychat_product_tracked_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_lazychat_product_tracked_%'");
    
    // For multisite installations, delete options and tables for all sites
    if (is_multisite()) {
        $blog_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
        $original_blog_id = get_current_blog_id();
        
        foreach ($blog_ids as $blog_id) {
            switch_to_blog($blog_id);
            
            // Delete options for this site
            delete_option('lazychat_bearer_token');
            delete_option('lazychat_enable_products');
            delete_option('lazychat_enable_orders');
            delete_option('lazychat_enable_connection_test');
            delete_option('lazychat_enable_debug_logging');
            delete_option('lazychat_plugin_active');
            delete_option('lazychat_connection_verified');
            delete_option('lazychat_db_version');
            delete_option('lazychat_selected_shop_id');
            delete_option('lazychat_selected_shop_name');
            delete_option('lazychat_wc_consumer_key');
            delete_option('lazychat_wc_consumer_secret');
            delete_option('lazychat_wc_last_access');
            
            // Drop logs table for this site
            // Note: Table name is safe as it uses $wpdb->prefix
            $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}lazychat_logs`");
            
            // Delete all LazyChat transients for this site
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lazychat_product_tracked_%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_lazychat_product_tracked_%'");
        }
        
        switch_to_blog($original_blog_id);
    }
    
    // Clear any cached data
    wp_cache_flush();
}

// Run cleanup
lazychat_uninstall_cleanup();

// Log uninstallation (optional, for debugging)
error_log('LazyChat plugin uninstalled and all data removed.');

