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
            // Create new order
            $order = wc_create_order();
            
            if (is_wp_error($order)) {
                return new WP_Error(
                    'order_creation_failed',
                    __('Failed to create order.', 'lazychat'),
                    array('status' => 500)
                );
            }
            
            // Set order status
            if (isset($data['status'])) {
                $order->set_status(sanitize_text_field($data['status']));
            }
            
            // Check if email matches an existing customer and set customer_id
            $customer_id = 0;
            if (isset($data['customer_id'])) {
                // Use provided customer_id if available
                $customer_id = absint($data['customer_id']);
            } elseif (isset($data['billing']['email'])) {
                // Check if email matches an existing customer
                $email = sanitize_email($data['billing']['email']);
                if (is_email($email)) {
                    $user = get_user_by('email', $email);
                    if ($user) {
                        // Customer exists
                        $customer_id = $user->ID;
                    } else {
                        // Customer doesn't exist, create new customer
                        $customer_data = array(
                            'email' => $email,
                            'billing' => isset($data['billing']) ? $data['billing'] : array(),
                            'shipping' => isset($data['shipping']) ? $data['shipping'] : array()
                        );
                        
                        // Add first and last name if available
                        if (isset($data['billing']['first_name'])) {
                            $customer_data['first_name'] = $data['billing']['first_name'];
                        }
                        if (isset($data['billing']['last_name'])) {
                            $customer_data['last_name'] = $data['billing']['last_name'];
                        }
                        if (isset($data['billing']['phone'])) {
                            $customer_data['phone'] = $data['billing']['phone'];
                        }
                        
                        // Create the customer
                        $customer_result = LazyChat_Customer_Controller::create_customer($customer_data);
                        
                        // If customer creation successful, use the new customer ID
                        if (!is_wp_error($customer_result) && isset($customer_result['id'])) {
                            $customer_id = $customer_result['id'];
                        }
                        // If customer creation fails, order will be created as guest order (customer_id = 0)
                    }
                }
            }
            
            // Set customer ID if found or provided
            if ($customer_id > 0) {
                $order->set_customer_id($customer_id);
            }
            
            // Set billing address
            if (isset($data['billing'])) {
                self::set_billing_address($order, $data['billing']);
            }
            
            // Set shipping address
            if (isset($data['shipping'])) {
                self::set_shipping_address($order, $data['shipping']);
            }
            
            // Add line items (products)
            if (isset($data['line_items']) && is_array($data['line_items'])) {
                self::add_line_items($order, $data['line_items']);
            }
            
            // Add shipping lines
            if (isset($data['shipping_lines']) && is_array($data['shipping_lines'])) {
                self::add_shipping_lines($order, $data['shipping_lines']);
            }
            
            // Add fee lines
            if (isset($data['fee_lines']) && is_array($data['fee_lines'])) {
                self::add_fee_lines($order, $data['fee_lines']);
            }
            
            // Add coupon lines
            if (isset($data['coupon_lines']) && is_array($data['coupon_lines'])) {
                self::add_coupon_lines($order, $data['coupon_lines']);
            }
            
            // Set payment method
            if (isset($data['payment_method'])) {
                $order->set_payment_method(sanitize_text_field($data['payment_method']));
            }
            if (isset($data['payment_method_title'])) {
                $order->set_payment_method_title(sanitize_text_field($data['payment_method_title']));
            }
            
            // Set transaction ID
            if (isset($data['transaction_id'])) {
                $order->set_transaction_id(sanitize_text_field($data['transaction_id']));
            }
            
            // Set customer note
            if (isset($data['customer_note'])) {
                $order->set_customer_note(sanitize_textarea_field($data['customer_note']));
            }
            
            // Add meta data
            if (isset($data['meta_data']) && is_array($data['meta_data'])) {
                self::add_meta_data($order, $data['meta_data']);
            }
            
            // Set order source to LazyChat using WooCommerce's standard field
            $order->set_created_via('lazychat');
            
            // Set currency
            if (isset($data['currency'])) {
                $order->set_currency(sanitize_text_field($data['currency']));
            }
            
            // Set prices include tax
            if (isset($data['prices_include_tax'])) {
                $order->set_prices_include_tax((bool) $data['prices_include_tax']);
            }
            
            // Calculate totals
            $order->calculate_totals();
            
            // Save the order
            $order_id = $order->save();
            
            if (!$order_id) {
                return new WP_Error(
                    'order_save_failed',
                    __('Failed to save order.', 'lazychat'),
                    array('status' => 500)
                );
            }
            
            // Set order date if provided
            if (isset($data['date_created'])) {
                $order->set_date_created(sanitize_text_field($data['date_created']));
                $order->save();
            }
            
            return $order;
            
        } catch (Exception $e) {
            return new WP_Error(
                'order_creation_exception',
                $e->getMessage(),
                array('status' => 500)
            );
        }
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
     */
    private static function add_line_items($order, $line_items) {
        foreach ($line_items as $item) {
            $product_id = isset($item['product_id']) ? absint($item['product_id']) : 0;
            $quantity = isset($item['quantity']) ? absint($item['quantity']) : 1;
            $variation_id = isset($item['variation_id']) ? absint($item['variation_id']) : 0;
            
            if ($product_id > 0) {
                $product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);
                
                if ($product) {
                    // Smart price fallback: handle products with null price
                    $product_price = $product->get_price();
                    $price_fallback_used = false;
                    $fallback_source = '';
                    $fallback_value = '';
                    
                    // If price is null or empty, fall back to sale_price or regular_price
                    if (empty($product_price) || $product_price === '' || $product_price === null) {
                        $fallback_price = $product->get_sale_price();
                        
                        // If sale price is also empty, use regular price
                        if (empty($fallback_price) || $fallback_price === '' || $fallback_price === null) {
                            $fallback_price = $product->get_regular_price();
                            $fallback_source = 'regular_price';
                        } else {
                            $fallback_source = 'sale_price';
                        }
                        
                        // If we have a valid fallback price, set it on the product temporarily
                        if (!empty($fallback_price) && is_numeric($fallback_price)) {
                            $product->set_price($fallback_price);
                            $price_fallback_used = true;
                            $fallback_value = $fallback_price;
                        }
                    }
                    
                    $item_id = $order->add_product($product, $quantity);
                    
                    // Store hidden metadata if price fallback was used (underscore prefix hides from frontend)
                    if ($item_id && $price_fallback_used) {
                        wc_add_order_item_meta($item_id, '_lazychat_price_fallback', 'yes', true);
                        wc_add_order_item_meta($item_id, '_lazychat_fallback_source', $fallback_source, true);
                        wc_add_order_item_meta($item_id, '_lazychat_fallback_value', $fallback_value, true);
                        wc_add_order_item_meta($item_id, '_lazychat_original_price', 'null', true);
                    }
                    
                    // Add custom meta data if provided
                    if ($item_id && isset($item['meta_data']) && is_array($item['meta_data'])) {
                        foreach ($item['meta_data'] as $meta) {
                            if (isset($meta['key']) && isset($meta['value'])) {
                                wc_add_order_item_meta($item_id, sanitize_text_field($meta['key']), sanitize_text_field($meta['value']));
                            }
                        }
                    }
                }
            }
        }
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
            if (isset($shipping_line['total'])) {
                $shipping_item->set_total(sanitize_text_field($shipping_line['total']));
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
            if (isset($fee_line['total'])) {
                $fee_item->set_total(sanitize_text_field($fee_line['total']));
            }
            if (isset($fee_line['tax_status'])) {
                $fee_item->set_tax_status(sanitize_text_field($fee_line['tax_status']));
            }
            
            $order->add_item($fee_item);
        }
    }
    
    /**
     * Add coupon lines to order
     */
    private static function add_coupon_lines($order, $coupon_lines) {
        foreach ($coupon_lines as $coupon_line) {
            if (isset($coupon_line['code'])) {
                $order->apply_coupon(sanitize_text_field($coupon_line['code']));
            }
        }
    }
    
    /**
     * Add meta data to order
     */
    private static function add_meta_data($order, $meta_data) {
        foreach ($meta_data as $meta) {
            if (isset($meta['key']) && isset($meta['value'])) {
                $order->add_meta_data(sanitize_text_field($meta['key']), sanitize_text_field($meta['value']), true);
            }
        }
    }
    
    /**
     * Prepare order response data
     */
    public static function prepare_order_response($order) {
        return array(
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
