<?php
/**
 * LazyChat Order Controller
 * Handles all order-related operations (create, view, list, cancel, etc.)
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class LazyChat_Order_Controller {
    
    /**
     * Validate and sanitize order status
     * Returns valid status or defaults to 'pending' if invalid
     * 
     * @param string $status The status to validate
     * @return string Valid order status
     */
    private static function validate_order_status($status) {
        // Sanitize the input
        $status = sanitize_text_field($status);
        
        // Remove 'wc-' prefix if present (WooCommerce adds it internally)
        $status = str_replace('wc-', '', $status);
        
        // Get all valid WooCommerce order statuses
        $valid_statuses = wc_get_order_statuses();
        
        // wc_get_order_statuses() returns statuses with 'wc-' prefix as keys
        // Example: array('wc-pending' => 'Pending payment', 'wc-processing' => 'Processing', ...)
        
        // Check if the status (with wc- prefix) exists in valid statuses
        $status_key = 'wc-' . $status;
        
        if (array_key_exists($status_key, $valid_statuses)) {
            return $status; // Return without 'wc-' prefix (set_status adds it internally)
        }
        
        // Status is invalid, default to 'pending'
        return 'pending';
    }
    
    /**
     * Prepare and populate an order with data (shared logic for create and calculate)
     * 
     * @param WC_Order $order Order object to populate
     * @param array $data Order data
     * @param bool $create_customer Whether to create customer if not exists
     * @param bool $for_calculation Whether this is for calculation only (no status change)
     * @return true|WP_Error True on success, WP_Error on failure
     */
    private static function prepare_order($order, $data, $create_customer = false, $for_calculation = false) {
        // Validate line items are provided
        if (empty($data['line_items']) || !is_array($data['line_items'])) {
            return new WP_Error(
                'missing_line_items',
                __('At least one line item is required.', 'lazychat'),
                array('status' => 400)
            );
        }
        
        // Set order status (skip for calculations to prevent stock reduction)
        if (!$for_calculation && isset($data['status'])) {
            // Validate status and fallback to 'pending' if invalid
            $validated_status = self::validate_order_status($data['status']);
            $order->set_status($validated_status);
        }
        
        // Set customer
        $customer_id = self::resolve_customer($data, $create_customer);
        if ($customer_id > 0) {
            $order->set_customer_id($customer_id);
        }
        
        // Set addresses
        if (isset($data['billing'])) {
            self::set_billing_address($order, $data['billing']);
        }
        if (isset($data['shipping'])) {
            self::set_shipping_address($order, $data['shipping']);
        }
        
        // Add line items
        $line_items_data = $data['line_items'];
        $line_items_result = self::add_line_items($order, $line_items_data);
        if (is_wp_error($line_items_result)) {
            return $line_items_result;
        }
        
        // Add shipping, fees, coupons
        if (isset($data['shipping_lines']) && is_array($data['shipping_lines'])) {
            self::add_shipping_lines($order, $data['shipping_lines']);
        }
        if (isset($data['fee_lines']) && is_array($data['fee_lines'])) {
            self::add_fee_lines($order, $data['fee_lines']);
        }
        if (isset($data['coupon_lines']) && is_array($data['coupon_lines'])) {
            $coupon_result = self::add_coupon_lines($order, $data['coupon_lines']);
            if (is_wp_error($coupon_result)) {
                return $coupon_result;
            }
        }
        
        // Set payment and order details
        self::set_order_details($order, $data);
        
        // Calculate totals
        $order->calculate_totals();
        
        // Apply price fallbacks
        if (!empty($line_items_data)) {
            self::apply_price_fallbacks($order, $line_items_data);
        }
        
        return true;
    }
    
    /**
     * Resolve customer ID from data
     * 
     * @param array $data Order data
     * @param bool $create_if_missing Whether to create customer if not exists
     * @return int Customer ID (0 for guest)
     */
    private static function resolve_customer($data, $create_if_missing = false) {
        $customer_id = 0;
        
        if (isset($data['customer_id'])) {
            $customer_id = absint($data['customer_id']);
            if ($customer_id > 0) {
                $customer = new WC_Customer($customer_id);
                if (!$customer->get_id()) {
                    $customer_id = 0;
                }
            }
        } elseif (isset($data['billing']['email'])) {
            $email = sanitize_email($data['billing']['email']);
            if (is_email($email)) {
                $user = get_user_by('email', $email);
                if ($user) {
                    $customer_id = $user->ID;
                } elseif ($create_if_missing) {
                    $customer_data = array(
                        'email' => $email,
                        'billing' => isset($data['billing']) ? $data['billing'] : array(),
                        'shipping' => isset($data['shipping']) ? $data['shipping'] : array(),
                        'first_name' => isset($data['billing']['first_name']) ? $data['billing']['first_name'] : '',
                        'last_name' => isset($data['billing']['last_name']) ? $data['billing']['last_name'] : '',
                        'phone' => isset($data['billing']['phone']) ? $data['billing']['phone'] : ''
                    );
                    
                    $customer_result = LazyChat_Customer_Controller::create_customer($customer_data);
                    if (!is_wp_error($customer_result) && isset($customer_result['id'])) {
                        $customer_id = $customer_result['id'];
                    }
                }
            }
        }
        
        return $customer_id;
    }
    
    /**
     * Set order details (payment, meta, currency, etc.)
     * 
     * @param WC_Order $order Order object
     * @param array $data Order data
     */
    private static function set_order_details($order, $data) {
        if (isset($data['payment_method'])) {
            $order->set_payment_method(sanitize_text_field($data['payment_method']));
        }
        if (isset($data['payment_method_title'])) {
            $order->set_payment_method_title(sanitize_text_field($data['payment_method_title']));
        }
        if (isset($data['transaction_id'])) {
            $order->set_transaction_id(sanitize_text_field($data['transaction_id']));
        }
        if (isset($data['customer_note'])) {
            $order->set_customer_note(sanitize_textarea_field($data['customer_note']));
        }
        if (isset($data['meta_data']) && is_array($data['meta_data'])) {
            self::add_meta_data($order, $data['meta_data']);
        }
        if (isset($data['currency'])) {
            $order->set_currency(sanitize_text_field($data['currency']));
        }
        if (isset($data['prices_include_tax'])) {
            $order->set_prices_include_tax((bool) $data['prices_include_tax']);
        }
    }
    
    /**
     * Create a new WooCommerce order
     * 
     * @param array $data Order data
     * @return WC_Order|WP_Error Order object on success, WP_Error on failure
     */
    public static function create_order($data) {
        if (empty($data)) {
            return new WP_Error(
                'invalid_order_data',
                __('Invalid order data.', 'lazychat'),
                array('status' => 400)
            );
        }
        

        
        try {
            $order = wc_create_order();
            
            if (is_wp_error($order)) {
                return new WP_Error(
                    'order_creation_failed',
                    __('Failed to create order.', 'lazychat'),
                    array('status' => 500)
                );
            }
            
            // Prepare order with all data (create customer = true, for calculation = false)
            $prepare_result = self::prepare_order($order, $data, true, false);
            if (is_wp_error($prepare_result)) {
                $order->delete(true);
                return $prepare_result;
            }
            
            // Set order source
            $order->set_created_via('lazychat');
            
            // Save the order
            $order_id = $order->save();
            
            if (!$order_id) {
                // Delete the order if save failed
                $order->delete(true);
                return new WP_Error(
                    'order_save_failed',
                    __('Failed to save order.', 'lazychat'),
                    array('status' => 500)
                );
            }
            
            // Set order date if provided
            if (isset($data['date_created']) && !empty($data['date_created'])) {
                // WooCommerce accepts timestamp, DateTime object, or date string
                // No sanitization needed as set_date_created handles it internally
                $order->set_date_created($data['date_created']);
                $order->save();
            }
            
            // Reduce stock levels if order status requires it
            // Stock will be reduced based on WooCommerce settings and order status
            // Typical statuses that trigger stock reduction: 'processing', 'completed', 'on-hold'
            if (in_array($order->get_status(), array('processing', 'completed', 'on-hold'))) {
                // Check if stock has already been reduced
                if (!$order->get_meta('_order_stock_reduced', true)) {
                    wc_reduce_stock_levels($order->get_id());
                }
            }
            
            return $order;
            
        } catch (Exception $e) {
            // Clean up: delete the order if it was created
            if (isset($order) && is_object($order) && method_exists($order, 'get_id') && $order->get_id()) {
                $order->delete(true);
            }
            
            return new WP_Error(
                'order_creation_exception',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }
    
    /**
     * Calculate order totals without creating a permanent order
     * This function simulates order creation to get accurate pricing calculations
     * 
     * @param array $data Order data (same format as create_order)
     * @return array|WP_Error Array with calculated totals on success, WP_Error on failure
     */
    public static function calculate_order_totals($data) {
        if (empty($data)) {
            return new WP_Error(
                'invalid_order_data',
                __('Invalid order data.', 'lazychat'),
                array('status' => 400)
            );
        }
        

        
        $temp_order = null;
        
        try {
            // Prevent stock reduction during calculation
            add_filter('woocommerce_can_reduce_order_stock', '__return_false', 999);
            add_filter('woocommerce_payment_complete_reduce_order_stock', '__return_false', 999);
            
            $temp_order = wc_create_order();
            
            if (is_wp_error($temp_order)) {
                // Remove filters
                remove_filter('woocommerce_can_reduce_order_stock', '__return_false', 999);
                remove_filter('woocommerce_payment_complete_reduce_order_stock', '__return_false', 999);
                
                return new WP_Error(
                    'calculation_failed',
                    __('Failed to initialize calculation.', 'lazychat'),
                    array('status' => 500)
                );
            }
            
            // Prepare order with all data (don't create customer, for calculation = true)
            $prepare_result = self::prepare_order($temp_order, $data, false, true);
            if (is_wp_error($prepare_result)) {
                $temp_order->delete(true);
                // Remove filters
                remove_filter('woocommerce_can_reduce_order_stock', '__return_false', 999);
                remove_filter('woocommerce_payment_complete_reduce_order_stock', '__return_false', 999);
                return $prepare_result;
            }
            
            // Extract calculated totals
            $calculated_totals = self::extract_order_totals($temp_order);
            
            // Delete the temporary order
            $temp_order->delete(true);
            
            // Remove filters
            remove_filter('woocommerce_can_reduce_order_stock', '__return_false', 999);
            remove_filter('woocommerce_payment_complete_reduce_order_stock', '__return_false', 999);
            
            return $calculated_totals;
            
        } catch (Exception $e) {
            // Clean up: delete the temp order if it was created
            if (isset($temp_order) && is_object($temp_order) && method_exists($temp_order, 'get_id') && $temp_order->get_id()) {
                $temp_order->delete(true);
            }
            
            // Remove filters
            remove_filter('woocommerce_can_reduce_order_stock', '__return_false', 999);
            remove_filter('woocommerce_payment_complete_reduce_order_stock', '__return_false', 999);
            
            return new WP_Error(
                'calculation_exception',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }
    
    /**
     * Extract order totals and breakdown from an order object
     * 
     * @param WC_Order $order Order object
     * @return array Calculated totals and breakdown
     */
    private static function extract_order_totals($order) {
        $totals = array(
            'subtotal' => $order->get_subtotal(),
            'discount_total' => $order->get_discount_total(),
            'shipping_total' => $order->get_shipping_total(),
            'fee_total' => 0,
            'tax_total' => $order->get_total_tax(),
            'total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'line_items' => array(),
            'shipping_lines' => array(),
            'fee_lines' => array(),
            'coupon_lines' => array()
        );
        
        // Calculate total fees
        foreach ($order->get_fees() as $fee) {
            $totals['fee_total'] += floatval($fee->get_total());
        }
        
        // Get line items breakdown
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            if ($product) {
                $totals['line_items'][] = array(
                    'name' => $item->get_name(),
                    'product_id' => $item->get_product_id(),
                    'variation_id' => $item->get_variation_id(),
                    'quantity' => $item->get_quantity(),
                    'subtotal' => $item->get_subtotal(),
                    'total' => $item->get_total(),
                    'tax' => $item->get_total_tax(),
                    'price_per_item' => $item->get_quantity() > 0 ? round($item->get_total() / $item->get_quantity(), 2) : 0
                );
            }
        }
        
        // Get shipping lines breakdown
        foreach ($order->get_shipping_methods() as $shipping) {
            $totals['shipping_lines'][] = array(
                'method_id' => $shipping->get_method_id(),
                'method_title' => $shipping->get_method_title(),
                'total' => $shipping->get_total(),
                'total_tax' => $shipping->get_total_tax()
            );
        }
        
        // Get fee lines breakdown
        foreach ($order->get_fees() as $fee) {
            $totals['fee_lines'][] = array(
                'name' => $fee->get_name(),
                'total' => $fee->get_total(),
                'total_tax' => $fee->get_total_tax()
            );
        }
        
        // Get coupon lines breakdown
        foreach ($order->get_coupons() as $coupon) {
            $totals['coupon_lines'][] = array(
                'code' => $coupon->get_code(),
                'discount' => $coupon->get_discount(),
                'discount_tax' => $coupon->get_discount_tax()
            );
        }
        
        return $totals;
    }
    
    /**
     * Set billing address for order
     */
    private static function set_billing_address($order, $billing) {
        if (isset($billing['first_name'])) $order->set_billing_first_name(sanitize_text_field($billing['first_name']));
        if (isset($billing['last_name'])) $order->set_billing_last_name(sanitize_text_field($billing['last_name']));
        if (isset($billing['company'])) $order->set_billing_company(sanitize_text_field($billing['company']));
        if (isset($billing['address_1'])) $order->set_billing_address_1(sanitize_text_field($billing['address_1']));
        if (isset($billing['address_2'])) $order->set_billing_address_2(sanitize_text_field($billing['address_2']));
        if (isset($billing['city'])) $order->set_billing_city(sanitize_text_field($billing['city']));
        if (isset($billing['state'])) $order->set_billing_state(sanitize_text_field($billing['state']));
        if (isset($billing['postcode'])) $order->set_billing_postcode(sanitize_text_field($billing['postcode']));
        if (isset($billing['country'])) $order->set_billing_country(sanitize_text_field($billing['country']));
        if (isset($billing['email'])) $order->set_billing_email(sanitize_email($billing['email']));
        if (isset($billing['phone'])) $order->set_billing_phone(sanitize_text_field($billing['phone']));
    }
    
    /**
     * Set shipping address for order
     */
    private static function set_shipping_address($order, $shipping) {
        if (isset($shipping['first_name'])) $order->set_shipping_first_name(sanitize_text_field($shipping['first_name']));
        if (isset($shipping['last_name'])) $order->set_shipping_last_name(sanitize_text_field($shipping['last_name']));
        if (isset($shipping['company'])) $order->set_shipping_company(sanitize_text_field($shipping['company']));
        if (isset($shipping['address_1'])) $order->set_shipping_address_1(sanitize_text_field($shipping['address_1']));
        if (isset($shipping['address_2'])) $order->set_shipping_address_2(sanitize_text_field($shipping['address_2']));
        if (isset($shipping['city'])) $order->set_shipping_city(sanitize_text_field($shipping['city']));
        if (isset($shipping['state'])) $order->set_shipping_state(sanitize_text_field($shipping['state']));
        if (isset($shipping['postcode'])) $order->set_shipping_postcode(sanitize_text_field($shipping['postcode']));
        if (isset($shipping['country'])) $order->set_shipping_country(sanitize_text_field($shipping['country']));
    }
    
    /**
     * Add line items to order
     * 
     * @return true|WP_Error True on success, WP_Error if any validation fails
     */
    private static function add_line_items($order, $line_items) {
        $errors = array();
        
        foreach ($line_items as $index => $item) {
            $product_id = isset($item['product_id']) ? absint($item['product_id']) : 0;
            $quantity = isset($item['quantity']) ? absint($item['quantity']) : 1;
            $variation_id = isset($item['variation_id']) ? absint($item['variation_id']) : 0;
            
            // Validate product_id
            if ($product_id <= 0) {
                $errors[] = array(
                    'line_item' => $index + 1,
                    'error' => 'missing_product_id',
                    'message' => __('Product ID is required.', 'lazychat')
                );
                continue;
            }
            
            // Validate quantity
            if ($quantity <= 0) {
                $errors[] = array(
                    'line_item' => $index + 1,
                    'product_id' => $product_id,
                    'error' => 'invalid_quantity',
                    'message' => __('Quantity must be at least 1.', 'lazychat')
                );
                continue;
            }
            
            // Get the product (variation takes priority)
            $product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);
            
            // The actual ID being used (variation ID if it's a variation, otherwise product ID)
            $actual_product_id = $variation_id > 0 ? $variation_id : $product_id;
            
            // Check if product exists
            if (!$product) {
                $errors[] = array(
                    'line_item' => $index + 1,
                    'product_id' => $actual_product_id,
                    'error' => 'product_not_found',
                    'message' => __('Product not found.', 'lazychat')
                );
                continue;
            }
            
            // Get product name for error messages
            $product_name = $product->get_name();
            
            // Check if product is purchasable
            if (!$product->is_purchasable()) {
                $errors[] = array(
                    'line_item' => $index + 1,
                    'product_id' => $actual_product_id,
                    'product_name' => $product_name,
                    'error' => 'not_purchasable',
                    'message' => __('Product is not purchasable.', 'lazychat')
                );
                continue;
            }
            
            // Check stock status (only if managing stock)
            if ($product->managing_stock()) {
                if (!$product->has_enough_stock($quantity)) {
                    $errors[] = array(
                        'line_item' => $index + 1,
                        'product_id' => $actual_product_id,
                        'product_name' => $product_name,
                        'error' => 'insufficient_stock',
                        'message' => __('Insufficient stock.', 'lazychat'),
                        'stock_available' => $product->get_stock_quantity(),
                        'stock_requested' => $quantity
                    );
                    continue;
                }
            } elseif ($product->get_stock_status() === 'outofstock') {
                $errors[] = array(
                    'line_item' => $index + 1,
                    'product_id' => $actual_product_id,
                    'product_name' => $product_name,
                    'error' => 'out_of_stock',
                    'message' => __('Product is out of stock.', 'lazychat')
                );
                continue;
            }
            
            // Validate custom price if provided (BEFORE adding product)
            if (isset($item['price']) && is_numeric($item['price'])) {
                $custom_price = floatval($item['price']);
                
                // Validate price is not negative
                if ($custom_price < 0) {
                    $errors[] = array(
                        'line_item' => $index + 1,
                        'product_id' => $actual_product_id,
                        'product_name' => $product_name,
                        'error' => 'invalid_price',
                        'message' => __('Price cannot be negative.', 'lazychat')
                    );
                    continue;
                }
            }
            
            // Prepare args for adding product
            $args = array();
            
            // Set variation attributes if it's a variation
            if ($variation_id > 0 && $product->is_type('variation')) {
                $variation_attributes = $product->get_variation_attributes();
                if (!empty($variation_attributes)) {
                    $args['variation'] = $variation_attributes;
                }
            }
            
            // Add product to order
            $item_id = $order->add_product($product, $quantity, $args);
            
            if (!$item_id) {
                $errors[] = array(
                    'line_item' => $index + 1,
                    'product_id' => $actual_product_id,
                    'product_name' => $product_name,
                    'error' => 'failed_to_add',
                    'message' => __('Failed to add product to order.', 'lazychat')
                );
                continue;
            }
            
            // Get the order item object
            $order_item = $order->get_item($item_id);
            
            // Apply custom price if provided (already validated above)
            if (isset($item['price']) && is_numeric($item['price'])) {
                $custom_price = floatval($item['price']);
                
                $order_item->set_subtotal($custom_price * $quantity);
                $order_item->set_total($custom_price * $quantity);
                
                // Mark that custom price was used
                wc_add_order_item_meta($item_id, '_lazychat_custom_price', 'yes', true);
                wc_add_order_item_meta($item_id, '_lazychat_custom_price_value', $custom_price, true);
            }
            
            // Add custom meta data if provided
            if (isset($item['meta_data']) && is_array($item['meta_data'])) {
                foreach ($item['meta_data'] as $meta) {
                    if (isset($meta['key']) && isset($meta['value'])) {
                        $key = sanitize_text_field($meta['key']);
                        $value = $meta['value'];
                        
                        // Only sanitize if it's a string, preserve arrays and objects
                        if (is_string($value)) {
                            $value = sanitize_text_field($value);
                        }
                        
                        wc_add_order_item_meta($item_id, $key, $value);
                    }
                }
            }
        }
        
        // If there were any errors, return WP_Error to prevent order creation
        if (!empty($errors)) {
            return new WP_Error(
                'line_items_validation_failed',
                __('Order cannot be created due to the following issues.', 'lazychat'),
                array(
                    'status' => 400,
                    'errors' => $errors
                )
            );
        }
        
        return true;
    }
    
    /**
     * Apply price fallbacks to order items after calculate_totals()
     * This ensures manual prices aren't overridden by WooCommerce calculations
     */
    private static function apply_price_fallbacks($order, $line_items_data) {
        $order_items = $order->get_items();
        $item_index = 0;
        
        foreach ($order_items as $item_id => $order_item) {
            // Match order item with original line item data by index
            if (isset($line_items_data[$item_index])) {
                $product = $order_item->get_product();
                
                if ($product) {
                    $quantity = $order_item->get_quantity();
                    $product_price = $product->get_price();
                    
                    // Skip if custom price was already set
                    $custom_price_used = wc_get_order_item_meta($item_id, '_lazychat_custom_price', true);
                    
                    if ($custom_price_used !== 'yes') {
                        // Check if price fallback is needed
                        if (empty($product_price) || $product_price === '' || $product_price === null) {
                            $fallback_price = $product->get_sale_price();
                            $fallback_source = '';
                            
                            // If sale price is also empty, use regular price
                            if (empty($fallback_price) || $fallback_price === '' || $fallback_price === null) {
                                $fallback_price = $product->get_regular_price();
                                $fallback_source = 'regular_price';
                            } else {
                                $fallback_source = 'sale_price';
                            }
                            
                            // If we have a valid fallback price, apply it to the order item
                            if (!empty($fallback_price) && is_numeric($fallback_price)) {
                                $fallback_price = floatval($fallback_price);
                                
                                // Get current totals to check if discount was applied
                                $current_item_total = floatval($order_item->get_total());
                                $current_item_subtotal = floatval($order_item->get_subtotal());
                                
                                // Set the new subtotal
                                $order_item->set_subtotal($fallback_price * $quantity);
                                
                                // Calculate new total preserving discount ratio if discount was applied
                                if ($current_item_subtotal > 0 && $current_item_total < $current_item_subtotal) {
                                    // Discount was applied, preserve the ratio
                                    $discount_ratio = $current_item_total / $current_item_subtotal;
                                    $new_total = ($fallback_price * $quantity) * $discount_ratio;
                                    $order_item->set_total($new_total);
                                } else {
                                    // No discount, use full price
                                    $order_item->set_total($fallback_price * $quantity);
                                }
                                
                                $order_item->save();
                                
                                // Store hidden metadata about price fallback
                                wc_add_order_item_meta($item_id, '_lazychat_price_fallback', 'yes', true);
                                wc_add_order_item_meta($item_id, '_lazychat_fallback_source', $fallback_source, true);
                                wc_add_order_item_meta($item_id, '_lazychat_fallback_value', $fallback_price, true);
                                wc_add_order_item_meta($item_id, '_lazychat_original_price', 'null', true);
                            }
                        }
                    }
                }
            }
            $item_index++;
        }
        
        // Manually update order totals without recalculating item prices
        // Sum up all line item totals
        $items_total = 0;
        $items_subtotal = 0;
        foreach ($order->get_items() as $item) {
            $items_total += floatval($item->get_total());
            $items_subtotal += floatval($item->get_subtotal());
        }
        
        // Calculate final order total including all components
        // Note: $items_total already includes discounts (coupons are applied to line items)
        $total = $items_total;
        $total += floatval($order->get_shipping_total());
        $total += floatval($order->get_total_tax());
        
        // Add fees
        foreach ($order->get_fees() as $fee) {
            $total += floatval($fee->get_total());
        }
        
        // Do NOT subtract discounts here - they're already applied to line item totals
        // The discount_total is just a reference value showing how much was discounted
        
        // Set the final total
        $order->set_total(max(0, $total)); // Ensure total is never negative
    }
    
    /**
     * Add shipping lines to order
     */
    private static function add_shipping_lines($order, $shipping_lines) {
        foreach ($shipping_lines as $shipping_line) {
            $shipping_item = new WC_Order_Item_Shipping();
            
            if (isset($shipping_line['method_id'])) {
                $shipping_item->set_method_id(sanitize_text_field($shipping_line['method_id']));
            }
            if (isset($shipping_line['method_title'])) {
                $shipping_item->set_method_title(sanitize_text_field($shipping_line['method_title']));
            }
            if (isset($shipping_line['total']) && is_numeric($shipping_line['total'])) {
                $shipping_item->set_total(wc_format_decimal($shipping_line['total']));
            }
            
            $order->add_item($shipping_item);
        }
    }
    
    /**
     * Add fee lines to order
     */
    private static function add_fee_lines($order, $fee_lines) {
        foreach ($fee_lines as $fee_line) {
            $fee_item = new WC_Order_Item_Fee();
            
            if (isset($fee_line['name'])) {
                $fee_item->set_name(sanitize_text_field($fee_line['name']));
            }
            if (isset($fee_line['total']) && is_numeric($fee_line['total'])) {
                $fee_item->set_total(wc_format_decimal($fee_line['total']));
            }
            if (isset($fee_line['tax_status'])) {
                $fee_item->set_tax_status(sanitize_text_field($fee_line['tax_status']));
            }
            
            $order->add_item($fee_item);
        }
    }
    
    /**
     * Add coupon lines to order
     * 
     * @return true|WP_Error True on success, WP_Error if any coupon is invalid
     */
    private static function add_coupon_lines($order, $coupon_lines) {
        $errors = array();
        
        foreach ($coupon_lines as $index => $coupon_line) {
            if (!isset($coupon_line['code'])) {
                $errors[] = array(
                    'coupon_line' => $index + 1,
                    'error' => 'missing_coupon_code',
                    'message' => __('Coupon code is required.', 'lazychat')
                );
                continue;
            }
            
            $coupon_code = sanitize_text_field($coupon_line['code']);
            
            // Check if coupon exists
            $coupon = new WC_Coupon($coupon_code);
            
            if (!$coupon->get_id()) {
                $errors[] = array(
                    'coupon_line' => $index + 1,
                    'coupon_code' => $coupon_code,
                    'error' => 'coupon_not_found',
                    'message' => __('Coupon not found.', 'lazychat')
                );
                continue;
            }
            
            // Check if coupon is valid
            $is_valid = $coupon->is_valid();
            
            if (is_wp_error($is_valid)) {
                $errors[] = array(
                    'coupon_line' => $index + 1,
                    'coupon_code' => $coupon_code,
                    'error' => 'coupon_invalid',
                    'message' => $is_valid->get_error_message(),
                    'error_code' => $is_valid->get_error_code()
                );
                continue;
            }
            
            // Apply the coupon to the order
            $result = $order->apply_coupon($coupon_code);
            
            if (is_wp_error($result)) {
                $errors[] = array(
                    'coupon_line' => $index + 1,
                    'coupon_code' => $coupon_code,
                    'error' => 'coupon_apply_failed',
                    'message' => $result->get_error_message(),
                    'error_code' => $result->get_error_code()
                );
                continue;
            }
        }
        
        // If there were any errors, return WP_Error to prevent order creation
        if (!empty($errors)) {
            return new WP_Error(
                'coupon_validation_failed',
                __('Order cannot be created due to coupon issues.', 'lazychat'),
                array(
                    'status' => 400,
                    'errors' => $errors
                )
            );
        }
        
        return true;
    }
    
    /**
     * Add meta data to order
     */
    private static function add_meta_data($order, $meta_data) {
        foreach ($meta_data as $meta) {
            if (isset($meta['key']) && isset($meta['value'])) {
                $key = sanitize_text_field($meta['key']);
                $value = $meta['value'];
                
                // Only sanitize if it's a string, preserve arrays and objects
                if (is_string($value)) {
                    $value = sanitize_text_field($value);
                }
                
                $order->add_meta_data($key, $value, true);
            }
        }
    }
    
    /**
     * Prepare order response data
     */
    public static function prepare_order_response($order) {
        // Get all order meta data
        $all_order_meta = $order->get_meta_data();
        $order_meta_data = array();
        $order_secure_meta = array();
        
        foreach ($all_order_meta as $meta_obj) {
            $meta_key = $meta_obj->key;
            $meta_value = $meta_obj->value;
            
            // Skip internal WooCommerce meta
            if (in_array($meta_key, array('_order_key', '_cart_hash', '_order_stock_reduced', '_download_permissions_granted', '_recorded_sales', '_recorded_coupon_usage_counts', '_new_order_email_sent'))) {
                continue;
            }
            
            // Separate secure (underscore prefix) from general meta
            if (strpos($meta_key, '_') === 0) {
                // Secure/hidden meta
                $order_secure_meta[] = array(
                    'key' => $meta_key,
                    'value' => $meta_value,
                );
            } else {
                // Public/general meta
                $order_meta_data[] = array(
                    'key' => $meta_key,
                    'value' => $meta_value,
                );
            }
        }
        
        $response = array(
            'id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'status' => $order->get_status(),
            'total' => $order->get_total(),
            'subtotal' => $order->get_subtotal(),
            'total_tax' => $order->get_total_tax(),
            'shipping_total' => $order->get_shipping_total(),
            'discount_total' => $order->get_discount_total(),
            'currency' => $order->get_currency(),
            'date_created' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d\TH:i:s') : null,
            'date_modified' => $order->get_date_modified() ? $order->get_date_modified()->date('Y-m-d\TH:i:s') : null,
            'customer_id' => $order->get_customer_id(),
            'created_via' => $order->get_created_via(),
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
                'phone' => $order->get_billing_phone(),
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
                'country' => $order->get_shipping_country(),
            ),
            'payment_method' => $order->get_payment_method(),
            'payment_method_title' => $order->get_payment_method_title(),
            'transaction_id' => $order->get_transaction_id(),
            'customer_note' => $order->get_customer_note(),
            'line_items' => self::prepare_line_items($order),
            'shipping_lines' => self::prepare_shipping_lines($order),
            'fee_lines' => self::prepare_fee_lines($order),
            'coupon_lines' => self::prepare_coupon_lines($order),
        );
        
        // Add order metadata if exists
        if (!empty($order_meta_data)) {
            $response['meta_data'] = $order_meta_data;
        }
        if (!empty($order_secure_meta)) {
            $response['secure_meta'] = $order_secure_meta;
        }
        
        return $response;
    }
    
    /**
     * Prepare line items with product details
     */
    private static function prepare_line_items($order) {
        $line_items = array();
        
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            
            if (!$product) {
                continue;
            }
            
            $line_item_data = array(
                'id' => $item_id,
                'name' => $item->get_name(),
                'product_id' => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'quantity' => $item->get_quantity(),
                'subtotal' => $item->get_subtotal(),
                'total' => $item->get_total(),
                'tax' => $item->get_total_tax(),
                'sku' => $product->get_sku(),
                'price' => $product->get_price(),
                'regular_price' => $product->get_regular_price(),
                'sale_price' => $product->get_sale_price(),
                'product_type' => $product->get_type(),
                'stock_status' => $product->get_stock_status(),
                'stock_quantity' => $product->get_stock_quantity(),
                'image' => wp_get_attachment_url($product->get_image_id()),
                'permalink' => get_permalink($product->get_id()),
            );
            
            // Add custom price information if it exists
            $custom_price_used = wc_get_order_item_meta($item_id, '_lazychat_custom_price', true);
            if ($custom_price_used === 'yes') {
                $line_item_data['custom_price'] = array(
                    'used' => true,
                    'value' => wc_get_order_item_meta($item_id, '_lazychat_custom_price_value', true),
                );
            }
            
            // Add price fallback information if it exists
            $price_fallback = wc_get_order_item_meta($item_id, '_lazychat_price_fallback', true);
            if ($price_fallback === 'yes') {
                $line_item_data['price_fallback'] = array(
                    'used' => true,
                    'source' => wc_get_order_item_meta($item_id, '_lazychat_fallback_source', true),
                    'value' => wc_get_order_item_meta($item_id, '_lazychat_fallback_value', true),
                    'original_price' => wc_get_order_item_meta($item_id, '_lazychat_original_price', true),
                );
            }
            
            // Add all item metadata (both secure and general)
            $all_meta = $item->get_meta_data();
            $meta_data = array();
            $secure_meta = array();
            
            foreach ($all_meta as $meta_obj) {
                $meta_key = $meta_obj->key;
                $meta_value = $meta_obj->value;
                
                // Skip internal WooCommerce meta and already included fallback/custom price meta
                if (in_array($meta_key, array('_qty', '_tax_class', '_product_id', '_variation_id', '_line_subtotal', '_line_total', '_line_tax', '_line_subtotal_tax', '_lazychat_price_fallback', '_lazychat_fallback_source', '_lazychat_fallback_value', '_lazychat_original_price', '_lazychat_custom_price', '_lazychat_custom_price_value'))) {
                    continue;
                }
                
                // Separate secure (underscore prefix) from general meta
                if (strpos($meta_key, '_') === 0) {
                    // Secure/hidden meta
                    $secure_meta[] = array(
                        'key' => $meta_key,
                        'value' => $meta_value,
                    );
                } else {
                    // Public/general meta
                    $meta_data[] = array(
                        'key' => $meta_key,
                        'value' => $meta_value,
                    );
                }
            }
            
            if (!empty($meta_data)) {
                $line_item_data['meta_data'] = $meta_data;
            }
            if (!empty($secure_meta)) {
                $line_item_data['secure_meta'] = $secure_meta;
            }
            
            // Add variation attributes directly if this is a variation product
            if ($product->get_type() === 'variation' && $item->get_variation_id()) {
                $variation_attributes = array();
                
                foreach ($product->get_variation_attributes() as $attribute_name => $attribute_value) {
                    $taxonomy = str_replace('attribute_', '', $attribute_name);
                    
                    // Get the attribute label
                    if (taxonomy_exists($taxonomy)) {
                        $term = get_term_by('slug', $attribute_value, $taxonomy);
                        $attribute_label = wc_attribute_label($taxonomy);
                        $attribute_value_display = $term ? $term->name : $attribute_value;
                    } else {
                        $attribute_label = wc_attribute_label($taxonomy);
                        $attribute_value_display = $attribute_value;
                    }
                    
                    $variation_attributes[] = array(
                        'name' => $attribute_label,
                        'value' => $attribute_value_display,
                    );
                }
                
                $line_item_data['variation_attributes'] = $variation_attributes;
                $line_item_data['parent_product_id'] = $product->get_parent_id();
            }
            
            $line_items[] = $line_item_data;
        }
        return $line_items;
    }
    
    /**
     * Prepare shipping lines
     */
    private static function prepare_shipping_lines($order) {
        $shipping_lines = array();
        
        foreach ($order->get_shipping_methods() as $item_id => $item) {
            $shipping_lines[] = array(
                'id' => $item_id,
                'method_id' => $item->get_method_id(),
                'method_title' => $item->get_method_title(),
                'total' => $item->get_total(),
                'total_tax' => $item->get_total_tax(),
            );
        }
        
        return $shipping_lines;
    }
    
    /**
     * Prepare fee lines
     */
    private static function prepare_fee_lines($order) {
        $fee_lines = array();
        
        foreach ($order->get_fees() as $item_id => $item) {
            $fee_lines[] = array(
                'id' => $item_id,
                'name' => $item->get_name(),
                'total' => $item->get_total(),
                'total_tax' => $item->get_total_tax(),
                'tax_status' => $item->get_tax_status(),
            );
        }
        
        return $fee_lines;
    }
    
    /**
     * Prepare coupon lines
     */
    private static function prepare_coupon_lines($order) {
        $coupon_lines = array();
        
        foreach ($order->get_coupons() as $item_id => $item) {
            $coupon_lines[] = array(
                'id' => $item_id,
                'code' => $item->get_code(),
                'discount' => $item->get_discount(),
                'discount_tax' => $item->get_discount_tax(),
            );
        }
        
        return $coupon_lines;
    }
    
    /**
     * Get a single order by ID
     * 
     * @param int $order_id Order ID
     * @return WC_Order|WP_Error Order object on success, WP_Error on failure
     */
    public static function get_order($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error(
                'order_not_found',
                __('Order not found.', 'lazychat'),
                array('status' => 404)
            );
        }
        
        return $order;
    }
    
    /**
     * Get list of orders with pagination
     * 
     * @param array $args Query arguments
     * @return array Orders list with pagination info
     */
    public static function get_orders($args = array()) {
        $defaults = array(
            'limit' => 10,
            'page' => 1,
            'status' => 'any',
            'orderby' => 'date',
            'order' => 'DESC',
            'lazychat_only' => false,
        );
        
        $args = wp_parse_args($args, $defaults);
        
// Build query with pagination
    $query_args = array(
        'limit' => absint($args['limit']),
        'page' => absint($args['page']),
        'orderby' => sanitize_text_field($args['orderby']),
        'order' => sanitize_text_field($args['order']),
        'paginate' => true, // This efficiently returns both orders and total count
    );
    
    // Add status filter if not 'any'
    if ($args['status'] !== 'any') {
        $query_args['status'] = sanitize_text_field($args['status']);
    }
    
    // Filter by LazyChat orders only if requested
    if (!empty($args['lazychat_only']) && $args['lazychat_only'] === true) {
        $query_args['created_via'] = 'lazychat';
    }
    
    // Get orders with pagination info in one efficient query
    $result = wc_get_orders($query_args);
    $orders = $result->orders;
    $total_orders = $result->total;
    $total_pages = $result->max_num_pages;
        
        // Prepare response
        $orders_data = array();
        foreach ($orders as $order) {
            $orders_data[] = self::prepare_order_response($order);
        }
        
        return array(
            'orders' => $orders_data,
            'pagination' => array(
                'page' => absint($args['page']),
                'per_page' => absint($args['limit']),
                'total_orders' => $total_orders,
                'total_pages' => $total_pages,
            ),
        );
    }
    
    /**
     * Update order status
     * 
     * @param int $order_id Order ID
     * @param string $new_status New order status
     * @param string $note Optional note to add
     * @return WC_Order|WP_Error Order object on success, WP_Error on failure
     */
    public static function update_order_status($order_id, $new_status, $note = '') {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error(
                'order_not_found',
                __('Order not found.', 'lazychat'),
                array('status' => 404)
            );
        }
        
        // Validate status
        $valid_statuses = array_keys(wc_get_order_statuses());
        $new_status = 'wc-' === substr($new_status, 0, 3) ? substr($new_status, 3) : $new_status;
        
        if (!in_array('wc-' . $new_status, $valid_statuses)) {
            return new WP_Error(
                'invalid_status',
                __('Invalid order status.', 'lazychat'),
                array('status' => 400)
            );
        }
        
        // Update status
        $order->update_status($new_status, $note);
        
        return $order;
    }
    
    /**
     * Cancel an order
     * 
     * @param int $order_id Order ID
     * @param string $note Optional cancellation note
     * @return WC_Order|WP_Error Order object on success, WP_Error on failure
     */
    public static function cancel_order($order_id, $note = '') {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error(
                'order_not_found',
                __('Order not found.', 'lazychat'),
                array('status' => 404)
            );
        }
        
        // Check if order can be cancelled
        if (!$order->has_status(array('pending', 'on-hold', 'processing'))) {
            return new WP_Error(
                'cannot_cancel_order',
                __('Order cannot be cancelled.', 'lazychat'),
                array('status' => 400)
            );
        }
        
        // Cancel order
        $order->update_status('cancelled', $note);
        
        return $order;
    }
    
    /**
     * Delete an order
     * 
     * @param int $order_id Order ID
     * @param bool $force_delete Whether to force delete (bypass trash)
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function delete_order($order_id, $force_delete = false) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error(
                'order_not_found',
                __('Order not found.', 'lazychat'),
                array('status' => 404)
            );
        }
        
        $result = $order->delete($force_delete);
        
        if (!$result) {
            return new WP_Error(
                'order_delete_failed',
                __('Failed to delete order.', 'lazychat'),
                array('status' => 500)
            );
        }
        
        return true;
    }
    
    /**
     * Add a note to an order
     * 
     * @param int $order_id Order ID
     * @param string $note Note content
     * @param bool $is_customer_note Whether it's a customer note
     * @return int|WP_Error Note ID on success, WP_Error on failure
     */
    public static function add_order_note($order_id, $note, $is_customer_note = false) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error(
                'order_not_found',
                __('Order not found.', 'lazychat'),
                array('status' => 404)
            );
        }
        
        if (empty($note)) {
            return new WP_Error(
                'empty_note',
                __('Note content is required.', 'lazychat'),
                array('status' => 400)
            );
        }
        
        $note_id = $order->add_order_note(
            sanitize_textarea_field($note),
            $is_customer_note ? 1 : 0,
            false
        );
        
        return $note_id;
    }
    
    /**
     * Get order notes
     * 
     * @param int $order_id Order ID
     * @return array|WP_Error Array of notes on success, WP_Error on failure
     */
    public static function get_order_notes($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error(
                'order_not_found',
                __('Order not found.', 'lazychat'),
                array('status' => 404)
            );
        }
        
        $notes = wc_get_order_notes(array(
            'order_id' => $order_id,
        ));
        
        return $notes;
    }
    
    /**
     * Update order
     * 
     * @param int $order_id Order ID
     * @param array $data Order data to update
     * @return WC_Order|WP_Error Order object on success, WP_Error on failure
     */
    public static function update_order($order_id, $data) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error(
                'order_not_found',
                __('Order not found.', 'lazychat'),
                array('status' => 404)
            );
        }
        
        // Check if order was created via LazyChat
        if ($order->get_created_via() !== 'lazychat') {
            return new WP_Error(
                'order_update_forbidden',
                __('Only orders created via LazyChat can be updated through this API.', 'lazychat'),
                array('status' => 403)
            );
        }
        
        try {
            // Update order status if provided
            if (isset($data['status'])) {
                $valid_statuses = array('pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed');
                $new_status = sanitize_text_field($data['status']);
                
                if (in_array($new_status, $valid_statuses)) {
                    $order->set_status($new_status);
                } else {
                    return new WP_Error(
                        'invalid_status',
                        __('Invalid order status provided.', 'lazychat'),
                        array('status' => 400)
                    );
                }
            }
            
            // Update billing address if provided
            if (isset($data['billing'])) {
                self::set_billing_address($order, $data['billing']);
            }
            
            // Update shipping address if provided
            if (isset($data['shipping'])) {
                self::set_shipping_address($order, $data['shipping']);
            }
            
            // Update payment method
            if (isset($data['payment_method'])) {
                $order->set_payment_method(sanitize_text_field($data['payment_method']));
            }
            if (isset($data['payment_method_title'])) {
                $order->set_payment_method_title(sanitize_text_field($data['payment_method_title']));
            }
            
            // Update transaction ID
            if (isset($data['transaction_id'])) {
                $order->set_transaction_id(sanitize_text_field($data['transaction_id']));
            }
            
            // Update customer note
            if (isset($data['customer_note'])) {
                $order->set_customer_note(sanitize_textarea_field($data['customer_note']));
            }
            
            // Update meta data
            if (isset($data['meta_data']) && is_array($data['meta_data'])) {
                self::add_meta_data($order, $data['meta_data']);
            }
            
            // Save order
            $order->save();
            
            return $order;
            
        } catch (Exception $e) {
            return new WP_Error(
                'order_update_exception',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }
}
