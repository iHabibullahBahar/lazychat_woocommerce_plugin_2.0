<?php
/**
 * LazyChat Webhook Sender
 * Handles sending webhooks to LazyChat API
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class LazyChat_Webhook_Sender {
    
    private $api_url = 'https://app.lazychat.io/api/woocommerce-plugin';
    private $aws_webhook_url = 'https://serverless.lazychat.io/webhooks/woocommerce';
    private $bearer_token;
    private $enable_products;
    private $enable_debug;
    
    public function __construct() {
        // Load settings
        $this->bearer_token = get_option('lazychat_bearer_token');
        $this->enable_products = get_option('lazychat_enable_products') === 'Yes';
        $this->enable_debug = get_option('lazychat_enable_debug_logging') === 'Yes';
        
        // Register hooks
        $this->register_hooks();
    }
    
    /**
     * Log debug messages - Only stores ERROR logs in database
     */
    private function log($message, $data = null) {
        global $wpdb;
        
        // Determine log type based on message content
        $log_type = 'info';
        if (stripos($message, 'error') !== false || stripos($message, 'failed') !== false) {
            $log_type = 'error';
        } elseif (stripos($message, 'success') !== false) {
            $log_type = 'success';
        }
        
        // Only store ERROR logs in database (ignore success/info if not in debug mode)
        if ($log_type !== 'error' && !$this->enable_debug) {
            return;
        }
        
        // Extract event type from message
        $event_type = null;
        if (stripos($message, 'Product Created') !== false) {
            $event_type = 'product.created';
        } elseif (stripos($message, 'Product Updated') !== false) {
            $event_type = 'product.updated';
        } elseif (stripos($message, 'Product Deleted') !== false) {
            $event_type = 'product.deleted';
        } elseif (stripos($message, 'Webhook') !== false) {
            $event_type = 'webhook';
        }
        
        // Extract entity type and ID from data
        $entity_type = null;
        $entity_id = null;
        if (is_array($data)) {
            if (isset($data['product_id'])) {
                $entity_type = 'product';
                $entity_id = $data['product_id'];
            }
            
            // Don't log full bearer token for security
            if (isset($data['bearer_token'])) {
                $data['bearer_token'] = substr($data['bearer_token'], 0, 10) . '...';
            }
        }
        
        // Prepare full log message
        $full_message = $message;
        if ($data !== null) {
            $full_message .= ' | Data: ' . wp_json_encode($data);
        }
        
        // Insert into database
        $table_name = $wpdb->prefix . 'lazychat_logs';
        $wpdb->insert(
            $table_name,
            array(
                'timestamp' => current_time('mysql'),
                'log_type' => $log_type,
                'event_type' => $event_type,
                'entity_type' => $entity_type,
                'entity_id' => $entity_id,
                'message' => $full_message
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        // Send ERROR logs to LazyChat API using shared log_error function
        if ($log_type === 'error') {
            // Prepare enriched data with webhook context
            $enriched_data = is_array($data) ? $data : array();
            $enriched_data['entity_type'] = $entity_type;
            $enriched_data['entity_id'] = $entity_id;
            
            // Use the centralized error logging function
            LazyChat_Error_Logger::log_error($message, $enriched_data, $event_type);
        }
        
        // Also log to error_log for backward compatibility during debugging
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging when WP_DEBUG is enabled
            error_log('[LazyChat] ' . $full_message);
        }
    }
    
    /**
     * Register WordPress and WooCommerce hooks
     */
    private function register_hooks() {
        // Product webhooks
        if ($this->enable_products) {
            add_action('woocommerce_new_product', array($this, 'send_product_created'), 10, 1);
            add_action('woocommerce_update_product', array($this, 'send_product_updated'), 10, 1);
            add_action('woocommerce_before_delete_product', array($this, 'send_product_deleted'), 10, 1);
            // Also hook into trash action - this fires when user clicks "Trash" (not just permanent delete)
            add_action('wp_trash_post', array($this, 'send_product_trashed'), 10, 1);
        }
    }
    
    /**
     * Send product deleted webhook when product is trashed
     * This is triggered when user clicks "Trash" in admin
     */
    public function send_product_trashed($post_id) {
        // Only process WooCommerce products
        if (get_post_type($post_id) !== 'product') {
            return;
        }
        
        // Send the delete webhook
        $this->send_product_deleted($post_id);
    }
    
    /**
     * Send product created webhook
     */
    public function send_product_created($product_id) {
        // Check if plugin is active
        if (get_option('lazychat_plugin_active') !== 'Yes') {
            $this->log('Skipping webhook - Plugin is not active', array('product_id' => $product_id));
            return;
        }
        
        $this->log('Product Created Event Triggered', array(
            'product_id' => $product_id,
            'version' => LAZYCHAT_VERSION
        ));
        
        // Use AWS webhook with simplified data
        $product_data_simplified = LazyChat_Product_Formatter::prepare_product_data($product_id);
        if ($product_data_simplified) {
            $this->send_aws_webhook($product_data_simplified, 'product/create');
        } else {
            $this->log('Failed to prepare product data for AWS webhook', array('product_id' => $product_id));
        }
    }
    
    /**
     * Send product updated webhook
     */
    public function send_product_updated($product_id) {
        // Check if plugin is active
        if (get_option('lazychat_plugin_active') !== 'Yes') {
            $this->log('Skipping webhook - Plugin is not active', array('product_id' => $product_id));
            return;
        }
        
        $this->log('Product Updated Event Triggered', array(
            'product_id' => $product_id,
            'version' => LAZYCHAT_VERSION
        ));
        
        // Get current product data using formatter
        $product_data_simplified = LazyChat_Product_Formatter::prepare_product_data($product_id);
        if (!$product_data_simplified) {
            $this->log('Failed to prepare product data', array('product_id' => $product_id));
            return;
        }
        
        // Extract only the fields we care about for comparison
        $current_tracked_data = $this->extract_tracked_fields($product_data_simplified);
        
        // Check if any tracked field has changed
        $transient_key = 'lazychat_product_tracked_' . $product_id;
        $previous_tracked_data = get_transient($transient_key);
        
        $has_tracked_changes = false;
        $changed_fields = array();
        
        if ($previous_tracked_data !== false) {
            // Compare tracked data
            if ($current_tracked_data === $previous_tracked_data) {
                $this->log('Skipping webhook - No changes in tracked fields', array('product_id' => $product_id));
                
                // Send debug data for non-tracked changes
                $this->send_debug_data('product', $product_id, array(
                    'has_tracked_changes' => false,
                    'changed_fields' => array(),
                    'current_tracked_data' => $current_tracked_data,
                    'previous_tracked_data' => $previous_tracked_data,
                    'is_first_track' => false,
                    'webhook_sent' => false,
                    'skip_reason' => 'No changes in tracked fields'
                ));
                return;
            }
            
            // Log what changed for debugging
            $changed_fields = $this->get_changed_fields($previous_tracked_data, $current_tracked_data);
            $has_tracked_changes = true;
            $this->log('Product update detected', array(
                'product_id' => $product_id,
                'changed_fields' => implode(', ', $changed_fields)
            ));
        } else {
            // First time tracking this product
            $has_tracked_changes = true;
        }
        
        // Store current tracked data for future comparison (expires in 24 hours)
        set_transient($transient_key, $current_tracked_data, DAY_IN_SECONDS);
        
        // Send debug data for tracked changes
        $this->send_debug_data('product', $product_id, array(
            'has_tracked_changes' => $has_tracked_changes,
            'changed_fields' => $changed_fields,
            'current_tracked_data' => $current_tracked_data,
            'previous_tracked_data' => $previous_tracked_data !== false ? $previous_tracked_data : null,
            'is_first_track' => $previous_tracked_data === false,
            'webhook_sent' => true
        ));
        
        // Use AWS webhook with simplified data
        $this->send_aws_webhook($product_data_simplified, 'product/update');
    }
    
    /**
     * Extract only the fields we want to track for changes
     */
    private function extract_tracked_fields($product_data) {
        $tracked = array(
            'id' => isset($product_data['id']) ? $product_data['id'] : null,
            'name' => isset($product_data['name']) ? $product_data['name'] : null,
            'permalink' => isset($product_data['permalink']) ? $product_data['permalink'] : null,
            'purchase_note' => isset($product_data['purchase_note']) ? $product_data['purchase_note'] : null,
            'stock_status' => isset($product_data['stock_status']) ? $product_data['stock_status'] : null,
            'stock_quantity' => isset($product_data['stock_quantity']) ? $product_data['stock_quantity'] : null,
            'description' => isset($product_data['description']) ? $product_data['description'] : null,
            'short_description' => isset($product_data['short_description']) ? $product_data['short_description'] : null,
            'sku' => isset($product_data['sku']) ? $product_data['sku'] : null,
            'price' => isset($product_data['price']) ? $product_data['price'] : null,
            'regular_price' => isset($product_data['regular_price']) ? $product_data['regular_price'] : null,
            'sale_price' => isset($product_data['sale_price']) ? $product_data['sale_price'] : null,
            'categories' => isset($product_data['categories']) ? $product_data['categories'] : array(),
            'images' => isset($product_data['images']) ? $product_data['images'] : array(),
            'parent_id' => isset($product_data['parent_id']) ? $product_data['parent_id'] : null,
            'brands' => isset($product_data['brands']) ? $product_data['brands'] : array(),
            'variations' => isset($product_data['variations']) ? $product_data['variations'] : array(),
            'in_stock' => isset($product_data['in_stock']) ? $product_data['in_stock'] : null,
            'purchasable' => isset($product_data['purchasable']) ? $product_data['purchasable'] : null,
            'type' => isset($product_data['type']) ? $product_data['type'] : null,
            'featured' => isset($product_data['featured']) ? $product_data['featured'] : null,
            'status' => isset($product_data['status']) ? $product_data['status'] : null,
        );
        
        // Add variation data if it's a variable product
        if (isset($product_data['type']) && $product_data['type'] === 'variable') {
            $variations_tracked = array();
            if (isset($product_data['variations']) && is_array($product_data['variations'])) {
                foreach ($product_data['variations'] as $variation_id) {
                    $variation = wc_get_product($variation_id);
                    if ($variation) {
                        $variations_tracked[$variation_id] = array(
                            'id' => $variation_id,
                            'stock_status' => $variation->get_stock_status(),
                            'stock_quantity' => $variation->get_stock_quantity(),
                            'sku' => $variation->get_sku(),
                            'price' => $variation->get_price(),
                            'regular_price' => $variation->get_regular_price(),
                            'sale_price' => $variation->get_sale_price(),
                            'image_id' => $variation->get_image_id()
                        );
                    }
                }
            }
            $tracked['variations_data'] = $variations_tracked;
        }
        
        return $tracked;
    }
    
    /**
     * Get list of changed fields between old and new data
     */
    private function get_changed_fields($old_data, $new_data) {
        $changed = array();
        
        foreach ($new_data as $key => $value) {
            if (!isset($old_data[$key])) {
                $changed[] = $key;
            } elseif (is_array($value)) {
                if ($value !== $old_data[$key]) {
                    $changed[] = $key;
                }
            } elseif ($value !== $old_data[$key]) {
                $changed[] = $key;
            }
        }
        
        return $changed;
    }
    
    /**
     * Send product deleted webhook
     */
    public function send_product_deleted($product_id) {
        // Check if plugin is active
        if (get_option('lazychat_plugin_active') !== 'Yes') {
            $this->log('Skipping webhook - Plugin is not active', array('product_id' => $product_id));
            return;
        }
        
        $this->log('Product Deleted Event Triggered', array('product_id' => $product_id));
        $product_data = array(
            'id' => $product_id,
            'timestamp' => current_time('mysql')
        );
        
        // Use AWS webhook
        $this->send_aws_webhook($product_data, 'product/delete');
    }
    

    

    
    /**
     * Send webhook to AWS API endpoint
     */
    private function send_aws_webhook($data, $event) {
        // Check if API credentials are set
        
        if (empty($this->bearer_token)) {
            $error_msg = 'Bearer Token not configured';
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging when WP_DEBUG is enabled
                error_log('LazyChat: ' . $error_msg);
            }
            $this->log('Webhook Error: ' . $error_msg, array('event' => $event));
            return false;
        }
        
        $url = $this->aws_webhook_url;
        
        // Wrap the data in a payload object
        $body_data = array(
            'payload' => $data
        );
        
        // Prepare payload with proper JSON encoding
        $payload = wp_json_encode($body_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    

        
        // Get shop ID
        $shop_id = get_option('lazychat_selected_shop_id', '');
        
        $headers = array(
            'Authorization' => 'Bearer ' . $this->bearer_token,
            'Content-Type' => 'application/json',
            'X-Webhook-Event' => $event,
            'X-Woocommerce-Topic' => $event,
            'X-Woocommerce-Event-Id' => substr(md5(uniqid(wp_rand(), true)), 0, 10),
            'X-Lazychat-Shop-Id' => $shop_id,
            'X-Plugin-Version' => defined('LAZYCHAT_VERSION') ? LAZYCHAT_VERSION : '1.0.0',
        );
        
        $args = array(
            'body' => $payload,
            'headers' => $headers,
            'timeout' => 15,
            'method' => 'POST',
            'blocking' => false, // Non-blocking request for better performance
            'data_format' => 'body' // Send raw body, don't re-encode
        );
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging when WP_DEBUG is enabled
                error_log('LazyChat AWS Webhook Error (' . $event . '): ' . $error_msg);
            }
            $this->log('AWS Webhook Request Failed', array(
                'event' => $event,
                'url' => $url,
                'error' => $error_msg
            ));
            return false;
        }
        
        return true;
    }
    
    private function send_debug_data($entity_type, $entity_id, $debug_info = array()) {
        // Check if API credentials are set
        if (empty($this->bearer_token)) {
            return false;
        }
        
        $url = rtrim($this->api_url, '/') . '/debug-data';
        
        // Prepare base debug payload
        $debug_data = array(
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'p_version' => defined('LAZYCHAT_VERSION') ? LAZYCHAT_VERSION : '1.0.0'
        );
        
        // Merge with additional debug info
        $debug_data = array_merge($debug_data, $debug_info);
        
        $payload = wp_json_encode($debug_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        $this->log('Sending debug data', array(
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'debug_info_keys' => array_keys($debug_info)
        ));
        
        $headers = array(
            'Authorization' => 'Bearer ' . $this->bearer_token,
            'Content-Type' => 'application/json',
            'X-Debug-Timestamp' => time(),
            'X-Debug-Entity-Type' => $entity_type,
            'X-Debug-Entity-ID' => $entity_id
        );
        
        $args = array(
            'body' => $payload,
            'headers' => $headers,
            'timeout' => 10,
            'method' => 'POST',
            'blocking' => false, // Non-blocking request for better performance
            'data_format' => 'body'
        );
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            $this->log('Debug data request failed', array(
                'entity_type' => $entity_type,
                'entity_id' => $entity_id,
                'error' => $error_msg
            ));
            return false;
        }
        
        $this->log('Debug data sent successfully', array(
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'url' => $url
        ));
        
        return true;
    }
    
    /**
     * Public helper to send debug data from anywhere
     * 
     * @param string $entity_type Type of entity (product, customer, etc.)
     * @param int $entity_id ID of the entity
     * @param array $debug_info Additional debug information
     * @return bool Success status
     */
    public function send_debug($entity_type, $entity_id, $debug_info = array()) {
        return $this->send_debug_data($entity_type, $entity_id, $debug_info);
    }
    
    /**
     * Send custom event notification to LazyChat backend
     * This allows sending various types of events with custom data
     * 
     * @param string $event_type The type of event (e.g., 'plugin.updated', 'admin.login', 'custom.action')
     * @param array $event_data Additional data to send with the event
     * @return bool Success status
     */
    public function send_event($event_type, $event_data = array()) {
        // Check if bearer token is set
        if (empty($this->bearer_token)) {
            $this->log('Cannot send event - Bearer token not configured', array('event_type' => $event_type));
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
        $url = rtrim($this->api_url, '/') . '/events';
        
        // Prepare headers
        $headers = array(
            'Authorization' => 'Bearer ' . $this->bearer_token,
            'Content-Type' => 'application/json',
            'X-Event-Type' => $event_type,
            'X-Plugin-Version' => LAZYCHAT_VERSION,
            'X-Lazychat-Shop-Id' => $shop_id,
            'X-Event-Timestamp' => time()
        );
        
        // Log the event
        $this->log('Sending event notification', array(
            'event_type' => $event_type,
            'event_data_keys' => array_keys($event_data),
            'url' => $url
        ));
        
        // Send the request
        $response = wp_remote_post($url, array(
            'body' => wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'headers' => $headers,
            'timeout' => 15,
            'blocking' => false, // Non-blocking for better performance
            'data_format' => 'body'
        ));
        
        if (is_wp_error($response)) {
            $this->log('Event notification failed', array(
                'event_type' => $event_type,
                'error' => $response->get_error_message()
            ));
            return false;
        }
        
        $this->log('Event notification sent successfully', array(
            'event_type' => $event_type
        ));
        
        return true;
    }
    
}

