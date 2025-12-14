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
    private $aws_webhook_url = 'https://d0nhymc226.execute-api.ap-southeast-1.amazonaws.com/Prod/webhooks/woocommerce';
    //private $aws_webhook_url = 'https://app.lazychat.io/api/woocommerce-plugin/test';
    private $bearer_token;
    private $enable_products;
    private $enable_orders;
    private $enable_debug;
    
    public function __construct() {
        // Load settings
        $this->bearer_token = get_option('lazychat_bearer_token');
        $this->enable_products = get_option('lazychat_enable_products') === 'Yes';
        $this->enable_orders = get_option('lazychat_enable_orders') === 'Yes';
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
        } elseif (stripos($message, 'Order Created') !== false) {
            $event_type = 'order.created';
        } elseif (stripos($message, 'Order Updated') !== false) {
            $event_type = 'order.updated';
        } elseif (stripos($message, 'Order Status Changed') !== false) {
            $event_type = 'order.status_changed';
        } elseif (stripos($message, 'Order Deleted') !== false) {
            $event_type = 'order.deleted';
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
            } elseif (isset($data['order_id'])) {
                $entity_type = 'order';
                $entity_id = $data['order_id'];
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
        if (defined('WP_DEBUG') && WP_DEBUG) {
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
        }
        
        // Order webhooks
        if ($this->enable_orders) {
            add_action('woocommerce_new_order', array($this, 'send_order_created'), 10, 1);
            add_action('woocommerce_update_order', array($this, 'send_order_updated'), 10, 1);
            add_action('woocommerce_order_status_changed', array($this, 'send_order_status_changed'), 10, 4);
            add_action('woocommerce_before_delete_order', array($this, 'send_order_deleted'), 10, 1);
        }
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
            'version' => LAZYCHAT_VERSION,
            'version_check' => version_compare(LAZYCHAT_VERSION, '1.2.0', '>') ? 'USE_AWS' : 'USE_OLD'
        ));
        
        // Send to AWS webhook for versions > 1.2.0, otherwise use old endpoint
        if (version_compare(LAZYCHAT_VERSION, '1.2.0', '>')) {
            // Use new AWS webhook with simplified data
            $product_data_simplified = LazyChat_Product_Formatter::prepare_product_data($product_id);
            if ($product_data_simplified) {
                $this->send_aws_webhook($product_data_simplified, 'product/create');
            } else {
                $this->log('Failed to prepare product data for AWS webhook', array('product_id' => $product_id));
            }
        } else {
            // Use old endpoint with full data for versions <= 1.2.0
            $product_data = $this->prepare_product_data($product_id);
            if ($product_data) {
                $this->send_webhook('/products/create', $product_data, 'product.created');
            } else {
                $this->log('Failed to prepare product data', array('product_id' => $product_id));
            }
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
            'version' => LAZYCHAT_VERSION,
            'version_check' => version_compare(LAZYCHAT_VERSION, '1.2.0', '>') ? 'USE_AWS' : 'USE_OLD'
        ));
        
        // Get current product data
        $product_data = $this->prepare_product_data($product_id);
        if (!$product_data) {
            $this->log('Failed to prepare product data', array('product_id' => $product_id));
            return;
        }
        
        // Extract only the fields we care about for comparison
        $current_tracked_data = $this->extract_tracked_fields($product_data);
        
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
        
        // Send to AWS webhook for versions > 1.2.0, otherwise use old endpoint
        if (version_compare(LAZYCHAT_VERSION, '1.2.0', '>')) {
            // Use new AWS webhook with simplified data
            $product_data_simplified = LazyChat_Product_Formatter::prepare_product_data($product_id);
            if ($product_data_simplified) {
                $this->send_aws_webhook($product_data_simplified, 'product/update');
            }
        } else {
            // Use old endpoint with full data for versions <= 1.2.0
            $this->send_webhook('/products/update', $product_data, 'product.updated');
        }
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
        
        // Send to AWS webhook for versions > 1.2.0, otherwise use old endpoint
        if (version_compare(LAZYCHAT_VERSION, '1.2.0', '>')) {
            // Use new AWS webhook
            $this->send_aws_webhook($product_data, 'product/delete');
        } else {
            // Use old endpoint for versions <= 1.2.0
            $this->send_webhook('/products/delete', $product_data, 'product.deleted');
        }
    }
    
    /**
     * Send order created webhook
     */
    public function send_order_created($order_id) {
        // Check if plugin is active
        if (get_option('lazychat_plugin_active') !== 'Yes') {
            $this->log('Skipping webhook - Plugin is not active', array('order_id' => $order_id));
            return;
        }
        
        // Skip if order already has LazyChat Invoice Number
        if ($this->has_lazychat_invoice($order_id)) {
            $this->log('Skipping order webhook - LazyChat Invoice Number already exists', array('order_id' => $order_id));
            return;
        }
        
        $this->log('Order Created Event Triggered', array('order_id' => $order_id));
        $order_data = $this->prepare_order_data($order_id);
        if ($order_data) {
            $this->send_webhook('/orders/create', $order_data, 'order.created');
        } else {
            $this->log('Failed to prepare order data', array('order_id' => $order_id));
        }
    }
    
    /**
     * Send order updated webhook
     */
    public function send_order_updated($order_id) {
        // Check if plugin is active
        if (get_option('lazychat_plugin_active') !== 'Yes') {
            $this->log('Skipping webhook - Plugin is not active', array('order_id' => $order_id));
            return;
        }
        
        // Skip if order already has LazyChat Invoice Number
        if ($this->has_lazychat_invoice($order_id)) {
            $this->log('Skipping order webhook - LazyChat Invoice Number already exists', array('order_id' => $order_id));
            return;
        }
        
        $this->log('Order Updated Event Triggered', array('order_id' => $order_id));
        $order_data = $this->prepare_order_data($order_id);
        if ($order_data) {
            $this->send_webhook('/orders/update', $order_data, 'order.updated');
        } else {
            $this->log('Failed to prepare order data', array('order_id' => $order_id));
        }
    }
    
    /**
     * Send order status changed webhook
     */
    public function send_order_status_changed($order_id, $old_status, $new_status, $order) {
        // Check if plugin is active
        if (get_option('lazychat_plugin_active') !== 'Yes') {
            $this->log('Skipping webhook - Plugin is not active', array('order_id' => $order_id));
            return;
        }
        
        // Skip if order already has LazyChat Invoice Number
        if ($this->has_lazychat_invoice($order_id)) {
            $this->log('Skipping order webhook - LazyChat Invoice Number already exists', array('order_id' => $order_id));
            return;
        }
        
        $this->log('Order Status Changed Event Triggered', array(
            'order_id' => $order_id,
            'old_status' => $old_status,
            'new_status' => $new_status
        ));
        
        $order_data = $this->prepare_order_data($order_id);
        if ($order_data) {
            $order_data['old_status'] = $old_status;
            $order_data['new_status'] = $new_status;
            $this->send_webhook('/orders/update', $order_data, 'order.status_changed');
        } else {
            $this->log('Failed to prepare order data', array('order_id' => $order_id));
        }
    }
    
    /**
     * Send order deleted webhook
     */
    public function send_order_deleted($order_id) {
        // Check if plugin is active
        if (get_option('lazychat_plugin_active') !== 'Yes') {
            $this->log('Skipping webhook - Plugin is not active', array('order_id' => $order_id));
            return;
        }
        
        $this->log('Order Deleted Event Triggered', array('order_id' => $order_id));
        $order_data = array(
            'id' => $order_id,
            'timestamp' => current_time('mysql')
        );
    
 
        // Use old endpoint for versions <= 1.2.0
        $this->send_webhook('/orders/delete', $order_data, 'order.deleted');
    }
    
    /**
     * Prepare product data for webhook (WooCommerce REST API v2 format)
     */
    private function prepare_product_data($product_id) {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return false;
        }
        
        // Build data in WooCommerce REST API v2 format
        $data = array(
            'id' => $product_id,
            'name' => $product->get_name(),
            'slug' => $product->get_slug(),
            'permalink' => get_permalink($product_id),
            'date_created' => $this->format_date($product->get_date_created(), false),
            'date_created_gmt' => $this->format_date($product->get_date_created(), true),
            'date_modified' => $this->format_date($product->get_date_modified(), false),
            'date_modified_gmt' => $this->format_date($product->get_date_modified(), true),
            'type' => $product->get_type(),
            'status' => $product->get_status(),
            'featured' => $product->get_featured(),
            'catalog_visibility' => $product->get_catalog_visibility(),
            'description' => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'sku' => $product->get_sku(),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'date_on_sale_from' => $this->format_date($product->get_date_on_sale_from(), false),
            'date_on_sale_from_gmt' => $this->format_date($product->get_date_on_sale_from(), true),
            'date_on_sale_to' => $this->format_date($product->get_date_on_sale_to(), false),
            'date_on_sale_to_gmt' => $this->format_date($product->get_date_on_sale_to(), true),
            'on_sale' => $product->is_on_sale(),
            'purchasable' => $product->is_purchasable(),
            'total_sales' => $product->get_total_sales(),
            'virtual' => $product->get_virtual(),
            'downloadable' => $product->get_downloadable(),
            'downloads' => $this->prepare_downloads($product),
            'download_limit' => $product->get_download_limit(),
            'download_expiry' => $product->get_download_expiry(),
            'external_url' => $product->get_type() === 'external' ? $product->get_product_url() : null,
            'button_text' => $product->get_type() === 'external' ? $product->get_button_text() : null,
            'tax_status' => $product->get_tax_status(),
            'tax_class' => $product->get_tax_class(),
            'manage_stock' => $product->get_manage_stock(),
            'stock_quantity' => $product->get_stock_quantity(),
            'in_stock' => $product->is_in_stock(),
            'backorders' => $product->get_backorders(),
            'backorders_allowed' => $product->backorders_allowed(),
            'backordered' => $product->is_on_backorder(),
            'sold_individually' => $product->get_sold_individually(),
            'weight' => $product->get_weight(),
            'dimensions' => array(
                'length' => $product->get_length(),
                'width' => $product->get_width(),
                'height' => $product->get_height()
            ),
            'shipping_required' => $product->needs_shipping(),
            'shipping_taxable' => $product->is_shipping_taxable(),
            'shipping_class' => $product->get_shipping_class(),
            'shipping_class_id' => $product->get_shipping_class_id(),
            'reviews_allowed' => $product->get_reviews_allowed(),
            'average_rating' => number_format((float) $product->get_average_rating(), 2, '.', ''),
            'rating_count' => $product->get_rating_count(),
            'upsell_ids' => $product->get_upsell_ids(),
            'cross_sell_ids' => $product->get_cross_sell_ids(),
            'parent_id' => $product->get_parent_id(),
            'purchase_note' => $product->get_purchase_note(),
            'categories' => $this->prepare_categories($product),
            'tags' => $this->prepare_tags($product),
            'images' => $this->prepare_images($product),
            'attributes' => $this->prepare_attributes($product),
            'default_attributes' => $this->prepare_default_attributes($product),
            'variations' => $product->is_type('variable') ? $product->get_children() : array(),
            'grouped_products' => $product->is_type('grouped') ? $product->get_children() : array(),
            'menu_order' => $product->get_menu_order(),
            'price_html' => $product->get_price_html(),
            'related_ids' => wc_get_related_products($product_id, 4),
            'meta_data' => $this->prepare_meta_data($product),
            'stock_status' => $product->get_stock_status()
        );
        
        // Add brands if taxonomy exists
        $brands = wp_get_post_terms($product_id, 'product_brand', array('fields' => 'all'));
        $data['brands'] = !is_wp_error($brands) ? array_map(function($brand) {
            return array(
                'id' => $brand->term_id,
                'name' => $brand->name,
                'slug' => $brand->slug
            );
        }, $brands) : array();
        
        return $data;
    }
    
    /**
     * Format date for API response
     */
    private function format_date($date, $gmt = false) {
        if (!$date) {
            return null;
        }
        
        if ($gmt) {
            return gmdate('Y-m-d\TH:i:s', $date->getTimestamp());
        }
        
        return $date->date('Y-m-d\TH:i:s');
    }
    
    /**
     * Prepare product images
     */
    private function prepare_images($product) {
        $images = array();
        $image_id = $product->get_image_id();
        
        if ($image_id) {
            $images[] = $this->prepare_image($image_id, 0);
        }
        
        $gallery_ids = $product->get_gallery_image_ids();
        foreach ($gallery_ids as $position => $gallery_id) {
            $images[] = $this->prepare_image($gallery_id, $position + 1);
        }
        
        return $images;
    }
    
    /**
     * Prepare single image data
     */
    private function prepare_image($image_id, $position = 0) {
        $attachment = get_post($image_id);
        
        return array(
            'id' => $image_id,
            'date_created' => $attachment ? $this->format_date(new WC_DateTime($attachment->post_date), false) : null,
            'date_created_gmt' => $attachment ? $this->format_date(new WC_DateTime($attachment->post_date_gmt), true) : null,
            'date_modified' => $attachment ? $this->format_date(new WC_DateTime($attachment->post_modified), false) : null,
            'date_modified_gmt' => $attachment ? $this->format_date(new WC_DateTime($attachment->post_modified_gmt), true) : null,
            'src' => wp_get_attachment_url($image_id),
            'name' => get_the_title($image_id),
            'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true),
            'position' => $position
        );
    }
    
    /**
     * Prepare product categories
     */
    private function prepare_categories($product) {
        $categories = array();
        $category_ids = $product->get_category_ids();
        
        foreach ($category_ids as $cat_id) {
            $category = get_term($cat_id, 'product_cat');
            if ($category && !is_wp_error($category)) {
                $categories[] = array(
                    'id' => $category->term_id,
                    'name' => $category->name,
                    'slug' => $category->slug
                );
            }
        }
        
        return $categories;
    }
    
    /**
     * Prepare product tags
     */
    private function prepare_tags($product) {
        $tags = array();
        $tag_ids = $product->get_tag_ids();
        
        foreach ($tag_ids as $tag_id) {
            $tag = get_term($tag_id, 'product_tag');
            if ($tag && !is_wp_error($tag)) {
                $tags[] = array(
                    'id' => $tag->term_id,
                    'name' => $tag->name,
                    'slug' => $tag->slug
                );
            }
        }
        
        return $tags;
    }
    
    /**
     * Prepare product attributes
     */
    private function prepare_attributes($product) {
        $attributes = array();
        
        foreach ($product->get_attributes() as $attribute) {
            $attributes[] = array(
                'id' => $attribute->is_taxonomy() ? wc_attribute_taxonomy_id_by_name($attribute->get_name()) : 0,
                'name' => wc_attribute_label($attribute->get_name()),
                'slug' => $attribute->get_name(),
                'position' => $attribute->get_position(),
                'visible' => $attribute->get_visible(),
                'variation' => $attribute->get_variation(),
                'options' => $attribute->is_taxonomy() ? 
                    wp_get_post_terms($product->get_id(), $attribute->get_name(), array('fields' => 'names')) : 
                    $attribute->get_options()
            );
        }
        
        return $attributes;
    }
    
    /**
     * Prepare default attributes
     */
    private function prepare_default_attributes($product) {
        $default_attributes = array();
        
        if ($product->is_type('variable')) {
            foreach ($product->get_default_attributes() as $key => $value) {
                $taxonomy = wc_attribute_taxonomy_name($key);
                $default_attributes[] = array(
                    'id' => wc_attribute_taxonomy_id_by_name($taxonomy),
                    'name' => wc_attribute_label($taxonomy),
                    'option' => $value
                );
            }
        }
        
        return $default_attributes;
    }
    
    /**
     * Prepare product downloads
     */
    private function prepare_downloads($product) {
        $downloads = array();
        
        if ($product->is_downloadable()) {
            foreach ($product->get_downloads() as $download_id => $download) {
                $downloads[] = array(
                    'id' => $download_id,
                    'name' => $download->get_name(),
                    'file' => $download->get_file()
                );
            }
        }
        
        return $downloads;
    }
    
    /**
     * Prepare meta data
     */
    private function prepare_meta_data($product) {
        $meta_data = array();
        
        foreach ($product->get_meta_data() as $meta) {
            $meta_data[] = array(
                'id' => $meta->id,
                'key' => $meta->key,
                'value' => $meta->value
            );
        }
        
        return $meta_data;
    }
    
    /**
     * Convert DateTime objects to ISO 8601 strings recursively
     */
    private function convert_dates_to_string($data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if ($value instanceof WC_DateTime) {
                    $data[$key] = $value->date('c'); // ISO 8601 format
                } elseif (is_array($value)) {
                    $data[$key] = $this->convert_dates_to_string($value);
                }
            }
        }
        return $data;
    }
    
    /**
     * Prepare order data for webhook (WooCommerce REST API v2 format)
     */
    private function prepare_order_data($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return false;
        }
        
        // Build data in WooCommerce REST API v2 format
        $data = array(
            'id' => $order_id,
            'parent_id' => $order->get_parent_id(),
            'number' => $order->get_order_number(),
            'order_key' => $order->get_order_key(),
            'created_via' => $order->get_created_via(),
            'version' => $order->get_version(),
            'status' => $order->get_status(),
            'currency' => $order->get_currency(),
            'date_created' => $this->format_date($order->get_date_created(), false),
            'date_created_gmt' => $this->format_date($order->get_date_created(), true),
            'date_modified' => $this->format_date($order->get_date_modified(), false),
            'date_modified_gmt' => $this->format_date($order->get_date_modified(), true),
            'discount_total' => $order->get_discount_total(),
            'discount_tax' => $order->get_discount_tax(),
            'shipping_total' => $order->get_shipping_total(),
            'shipping_tax' => $order->get_shipping_tax(),
            'cart_tax' => $order->get_cart_tax(),
            'total' => $order->get_total(),
            'total_tax' => $order->get_total_tax(),
            'prices_include_tax' => $order->get_prices_include_tax(),
            'customer_id' => $order->get_customer_id(),
            'customer_ip_address' => $order->get_customer_ip_address(),
            'customer_user_agent' => $order->get_customer_user_agent(),
            'customer_note' => $order->get_customer_note(),
            'billing' => array(
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'company' => $order->get_billing_company(),
                'address_1' => $order->get_billing_address_1(),
                'address_2' => $order->get_billing_address_2(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'postcode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone()
            ),
            'shipping' => array(
                'first_name' => $order->get_shipping_first_name(),
                'last_name' => $order->get_shipping_last_name(),
                'company' => $order->get_shipping_company(),
                'address_1' => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2(),
                'city' => $order->get_shipping_city(),
                'state' => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'country' => $order->get_shipping_country()
            ),
            'payment_method' => $order->get_payment_method(),
            'payment_method_title' => $order->get_payment_method_title(),
            'transaction_id' => $order->get_transaction_id(),
            'date_paid' => $this->format_date($order->get_date_paid(), false),
            'date_paid_gmt' => $this->format_date($order->get_date_paid(), true),
            'date_completed' => $this->format_date($order->get_date_completed(), false),
            'date_completed_gmt' => $this->format_date($order->get_date_completed(), true),
            'cart_hash' => $order->get_cart_hash(),
            'meta_data' => $this->prepare_order_meta_data($order),
            'line_items' => $this->prepare_line_items($order),
            'tax_lines' => $this->prepare_tax_lines($order),
            'shipping_lines' => $this->prepare_shipping_lines($order),
            'fee_lines' => $this->prepare_fee_lines($order),
            'coupon_lines' => $this->prepare_coupon_lines($order),
            'refunds' => $this->prepare_refunds($order),
            'set_paid' => $order->is_paid()
        );
        
        return $data;
    }
    
    /**
     * Prepare order line items
     */
    private function prepare_line_items($order) {
        $line_items = array();
        
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            
            $line_items[] = array(
                'id' => $item_id,
                'name' => $item->get_name(),
                'product_id' => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'quantity' => $item->get_quantity(),
                'tax_class' => $item->get_tax_class(),
                'subtotal' => $item->get_subtotal(),
                'subtotal_tax' => $item->get_subtotal_tax(),
                'total' => $item->get_total(),
                'total_tax' => $item->get_total_tax(),
                'taxes' => $this->prepare_item_taxes($item),
                'meta_data' => $this->prepare_item_meta_data($item),
                'sku' => $product ? $product->get_sku() : null,
                'price' => $product ? $product->get_price() : 0
            );
        }
        
        return $line_items;
    }
    
    /**
     * Prepare tax lines
     */
    private function prepare_tax_lines($order) {
        $tax_lines = array();
        
        foreach ($order->get_taxes() as $item_id => $item) {
            $tax_lines[] = array(
                'id' => $item_id,
                'rate_code' => $item->get_rate_code(),
                'rate_id' => $item->get_rate_id(),
                'label' => $item->get_label(),
                'compound' => $item->get_compound(),
                'tax_total' => $item->get_tax_total(),
                'shipping_tax_total' => $item->get_shipping_tax_total(),
                'meta_data' => $this->prepare_item_meta_data($item)
            );
        }
        
        return $tax_lines;
    }
    
    /**
     * Prepare shipping lines
     */
    private function prepare_shipping_lines($order) {
        $shipping_lines = array();
        
        foreach ($order->get_shipping_methods() as $item_id => $item) {
            $shipping_lines[] = array(
                'id' => $item_id,
                'method_title' => $item->get_method_title(),
                'method_id' => $item->get_method_id(),
                'total' => $item->get_total(),
                'total_tax' => $item->get_total_tax(),
                'taxes' => $this->prepare_item_taxes($item),
                'meta_data' => $this->prepare_item_meta_data($item)
            );
        }
        
        return $shipping_lines;
    }
    
    /**
     * Prepare fee lines
     */
    private function prepare_fee_lines($order) {
        $fee_lines = array();
        
        foreach ($order->get_fees() as $item_id => $item) {
            $fee_lines[] = array(
                'id' => $item_id,
                'name' => $item->get_name(),
                'tax_class' => $item->get_tax_class(),
                'tax_status' => $item->get_tax_status(),
                'total' => $item->get_total(),
                'total_tax' => $item->get_total_tax(),
                'taxes' => $this->prepare_item_taxes($item),
                'meta_data' => $this->prepare_item_meta_data($item)
            );
        }
        
        return $fee_lines;
    }
    
    /**
     * Prepare coupon lines
     */
    private function prepare_coupon_lines($order) {
        $coupon_lines = array();
        
        foreach ($order->get_coupons() as $item_id => $item) {
            $coupon_lines[] = array(
                'id' => $item_id,
                'code' => $item->get_code(),
                'discount' => $item->get_discount(),
                'discount_tax' => $item->get_discount_tax(),
                'meta_data' => $this->prepare_item_meta_data($item)
            );
        }
        
        return $coupon_lines;
    }
    
    /**
     * Prepare refunds
     */
    private function prepare_refunds($order) {
        $refunds = array();
        
        foreach ($order->get_refunds() as $refund) {
            $refunds[] = array(
                'id' => $refund->get_id(),
                'reason' => $refund->get_reason(),
                'total' => '-' . $refund->get_amount()
            );
        }
        
        return $refunds;
    }
    
    /**
     * Prepare item taxes
     */
    private function prepare_item_taxes($item) {
        $taxes = array();
        
        foreach ($item->get_taxes() as $tax_key => $tax) {
            $taxes[] = array(
                'id' => isset($tax['id']) ? $tax['id'] : 0,
                'total' => isset($tax['total']) ? $tax['total'] : '',
                'subtotal' => isset($tax['subtotal']) ? $tax['subtotal'] : ''
            );
        }
        
        return $taxes;
    }
    
    /**
     * Prepare item meta data
     */
    private function prepare_item_meta_data($item) {
        $meta_data = array();
        
        foreach ($item->get_meta_data() as $meta) {
            $meta_data[] = array(
                'id' => $meta->id,
                'key' => $meta->key,
                'value' => $meta->value
            );
        }
        
        return $meta_data;
    }
    
    /**
     * Prepare order meta data
     */
    private function prepare_order_meta_data($order) {
        $meta_data = array();
        
        foreach ($order->get_meta_data() as $meta) {
            $meta_data[] = array(
                'id' => $meta->id,
                'key' => $meta->key,
                'value' => $meta->value
            );
        }
        
        return $meta_data;
    }
    
    /**
     * Send webhook to LazyChat API
     */
    private function send_webhook($endpoint, $data, $event) {
        // Check if API credentials are set
        if (empty($this->bearer_token)) {
            $error_msg = 'Bearer Token not configured';
            error_log('LazyChat: ' . $error_msg);
            $this->log('Webhook Error: ' . $error_msg, array('event' => $event));
            return false;
        }
        
        $url = rtrim($this->api_url, '/') . $endpoint;
        
        // Prepare payload with proper JSON encoding
        $payload = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        $this->log('Preparing to send webhook', array(
            'event' => $event,
            'endpoint' => $endpoint,
            'url' => $url,
            'data_size' => strlen($payload) . ' bytes'
        ));
        
        // Get shop ID
        $shop_id = get_option('lazychat_selected_shop_id', '');
        
        $headers = array(
            'Authorization' => 'Bearer ' . $this->bearer_token,
            'Content-Type' => 'application/json',
            'X-Webhook-Timestamp' => time(),
            'X-Webhook-Event' => $event,
            'X-Shop-Id' => $shop_id,

        );
        
        $args = array(
            'body' => $payload,
            'headers' => $headers,
            'timeout' => 15,
            'method' => 'POST',
            'blocking' => false, // Non-blocking request for better performance
            'data_format' => 'body' // Send raw body, don't re-encode
        );
        
        $this->log('Sending webhook request', array(
            'url' => $url,
            'event' => $event,
            'timestamp' => $headers['X-Webhook-Timestamp']
        ));
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            error_log('LazyChat Webhook Error (' . $event . '): ' . $error_msg);
            $this->log('Webhook Request Failed', array(
                'event' => $event,
                'url' => $url,
                'error' => $error_msg
            ));
            return false;
        }
        
        $this->log('Webhook sent successfully', array(
            'event' => $event,
            'url' => $url,
            'response_code' => wp_remote_retrieve_response_code($response)
        ));
        
        return true;
    }
    
    /**
     * Send webhook to AWS API endpoint
     */
    private function send_aws_webhook($data, $event) {
        // Check if API credentials are set
        
        if (empty($this->bearer_token)) {
            $error_msg = 'Bearer Token not configured';
            error_log('LazyChat: ' . $error_msg);
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
            'X-Woocommerce-Event-Id' => substr(md5(uniqid(rand(), true)), 0, 10),
            'X-Lazychat-Shop-Id' => $shop_id,
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
            error_log('LazyChat AWS Webhook Error (' . $event . '): ' . $error_msg);
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
     * @param string $entity_type Type of entity (product, order, customer, etc.)
     * @param int $entity_id ID of the entity
     * @param array $debug_info Additional debug information
     * @return bool Success status
     */
    public function send_debug($entity_type, $entity_id, $debug_info = array()) {
        return $this->send_debug_data($entity_type, $entity_id, $debug_info);
    }
    
    /**
     * Check if order already has LazyChat Invoice Number
     */
    private function has_lazychat_invoice($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return false;
        }
        
        // Check for LazyChat Invoice Number in order meta data
        $invoice_number = $order->get_meta('LazyChat Invoice Number', true);
        
        // Also check common variations of the meta key
        if (empty($invoice_number)) {
            $invoice_number = $order->get_meta('lazychat_invoice_number', true);
        }
        
        return !empty($invoice_number);
    }
}

