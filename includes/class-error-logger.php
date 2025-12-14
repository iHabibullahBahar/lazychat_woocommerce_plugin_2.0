<?php
/**
 * LazyChat Error Logger
 * 
 * Centralized error logging utility that sends errors to LazyChat API
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class LazyChat_Error_Logger {
    
    /**
     * Log error to LazyChat API and error_log
     * 
     * @param string $message Error message
     * @param array|null $data Additional context data
     * @param string $event_type Event type (e.g., 'login.error', 'webhook.error')
     */
    public static function log_error($message, $data = null, $event_type = 'error') {
        // Prepare full log message
        $full_message = '[LazyChat] ' . $message;
        if ($data !== null) {
            $full_message .= ' | Data: ' . wp_json_encode($data);
        }
        
        // Log to error_log
        error_log($full_message);
        
        // Extract error code from data if available
        $error_code = null;
        if (is_array($data) && isset($data['error_code'])) {
            $error_code = $data['error_code'];
        } elseif (is_array($data) && isset($data['error'])) {
            // Try to extract error code from error message
            $error_code = is_string($data['error']) ? substr($data['error'], 0, 255) : null;
        }
        
        // Get WooCommerce version
        $wc_version = null;
        if (function_exists('WC') && defined('WC_VERSION')) {
            $wc_version = WC_VERSION;
        } elseif (defined('WOOCOMMERCE_VERSION')) {
            $wc_version = WOOCOMMERCE_VERSION;
        }
        
        // Prepare log data for API according to expected format
        $log_data = array(
            'error_message' => $message, // Required
            'error_type' => !empty($event_type) ? substr($event_type, 0, 255) : null,
            'error_code' => !empty($error_code) ? substr($error_code, 0, 255) : null,
            'plugin_version' => defined('LAZYCHAT_VERSION') ? substr(LAZYCHAT_VERSION, 0, 50) : null,
            'woocommerce_version' => !empty($wc_version) ? substr($wc_version, 0, 50) : null,
            'php_version' => !empty(PHP_VERSION) ? substr(PHP_VERSION, 0, 50) : null,
            'wordpress_version' => !empty(get_bloginfo('version')) ? substr(get_bloginfo('version'), 0, 50) : null,
            'url' => home_url(), // Site URL
            'additional_data' => $data, // All contextual data goes here
        );
        
        // Remove null values to keep payload clean
        $log_data = array_filter($log_data, function($value) {
            return $value !== null;
        });
        
        // Get bearer token if available
        $bearer_token = get_option('lazychat_bearer_token');
        
        // Prepare headers
        $headers = array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        );
        
        // Add Bearer token if available
        if (!empty($bearer_token)) {
            $headers['Authorization'] = 'Bearer ' . $bearer_token;
        }
        
        // Send to LazyChat API
        $endpoint = 'https://app.lazychat.io/api/woocommerce-plugin/error-logs';
        
        $response = wp_remote_post($endpoint, array(
            'method' => 'POST',
            'headers' => $headers,
            'body' => wp_json_encode($log_data),
            'timeout' => 10,
            'sslverify' => true,
            'data_format' => 'body',
            'blocking' => false, // Non-blocking to avoid slowing down the main request
        ));
        
        // Log API failure only if blocking (for debugging)
        if (is_wp_error($response) && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[LazyChat] Failed to send error log to API: ' . $response->get_error_message());
        }
    }
}

