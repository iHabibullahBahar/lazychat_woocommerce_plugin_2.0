<?php
/**
 * LazyChat Coupon Controller
 * Handles coupon creation, updating, listing, and deletion
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class LazyChat_Coupon_Controller {
    
    /**
     * Create or update a coupon
     *
     * @param array $data Coupon data
     * @return array|WP_Error
     */
    public static function create_or_update_coupon($data) {
        try {
            // Check if this is an update (ID provided)
            $coupon_id = isset($data['id']) ? absint($data['id']) : 0;
            $is_update = $coupon_id > 0;
            
            // Validate required fields for new coupons
            if (!$is_update) {
                if (empty($data['code'])) {
                    return new WP_Error(
                        'missing_coupon_code',
                        __('Coupon code is required.', 'lazychat'),
                        array('status' => 400)
                    );
                }
                
                if (!isset($data['discount_type'])) {
                    return new WP_Error(
                        'missing_discount_type',
                        __('Discount type is required.', 'lazychat'),
                        array('status' => 400)
                    );
                }
                
                if (!isset($data['amount'])) {
                    return new WP_Error(
                        'missing_amount',
                        __('Discount amount is required.', 'lazychat'),
                        array('status' => 400)
                    );
                }
                
                // Check if coupon code already exists
                $existing_coupon_id = wc_get_coupon_id_by_code($data['code']);
                if ($existing_coupon_id) {
                    return new WP_Error(
                        'coupon_already_exists',
                        sprintf(__('Coupon with code "%s" already exists.', 'lazychat'), $data['code']),
                        array('status' => 409, 'existing_coupon_id' => $existing_coupon_id)
                    );
                }
            }
            
            // Create or get existing coupon
            if ($is_update) {
                $coupon = new WC_Coupon($coupon_id);
                if (!$coupon->get_id()) {
                    return new WP_Error(
                        'coupon_not_found',
                        __('Coupon not found.', 'lazychat'),
                        array('status' => 404)
                    );
                }
                
                // Check if coupon was created by LazyChat
                $created_via = get_post_meta($coupon_id, '_created_via', true);
                if ($created_via !== 'lazychat') {
                    return new WP_Error(
                        'coupon_not_editable',
                        __('This coupon was not created by LazyChat and cannot be updated via the API.', 'lazychat'),
                        array('status' => 403)
                    );
                }
            } else {
                $coupon = new WC_Coupon();
                $coupon->set_code(sanitize_text_field($data['code']));
            }
            
            // Set basic properties
            if (isset($data['discount_type'])) {
                $allowed_types = array('percent', 'fixed_cart', 'fixed_product');
                $discount_type = sanitize_text_field($data['discount_type']);
                if (!in_array($discount_type, $allowed_types)) {
                    return new WP_Error(
                        'invalid_discount_type',
                        __('Invalid discount type. Allowed values: percent, fixed_cart, fixed_product', 'lazychat'),
                        array('status' => 400)
                    );
                }
                $coupon->set_discount_type($discount_type);
            }
            
            if (isset($data['amount'])) {
                $amount = wc_format_decimal($data['amount']);
                if ($amount < 0) {
                    return new WP_Error(
                        'invalid_amount',
                        __('Discount amount cannot be negative.', 'lazychat'),
                        array('status' => 400)
                    );
                }
                $coupon->set_amount($amount);
            }
            
            if (isset($data['description'])) {
                $coupon->set_description(sanitize_textarea_field($data['description']));
            }
            
            // Set usage restrictions
            if (isset($data['minimum_amount'])) {
                $coupon->set_minimum_amount(wc_format_decimal($data['minimum_amount']));
            }
            
            if (isset($data['maximum_amount'])) {
                $coupon->set_maximum_amount(wc_format_decimal($data['maximum_amount']));
            }
            
            if (isset($data['individual_use'])) {
                $coupon->set_individual_use((bool) $data['individual_use']);
            }
            
            if (isset($data['product_ids'])) {
                $product_ids = is_array($data['product_ids']) ? array_map('absint', $data['product_ids']) : array();
                $coupon->set_product_ids($product_ids);
            }
            
            if (isset($data['excluded_product_ids'])) {
                $excluded_ids = is_array($data['excluded_product_ids']) ? array_map('absint', $data['excluded_product_ids']) : array();
                $coupon->set_excluded_product_ids($excluded_ids);
            }
            
            if (isset($data['product_categories'])) {
                $categories = is_array($data['product_categories']) ? array_map('absint', $data['product_categories']) : array();
                $coupon->set_product_categories($categories);
            }
            
            if (isset($data['excluded_product_categories'])) {
                $excluded_cats = is_array($data['excluded_product_categories']) ? array_map('absint', $data['excluded_product_categories']) : array();
                $coupon->set_excluded_product_categories($excluded_cats);
            }
            
            if (isset($data['email_restrictions'])) {
                $emails = is_array($data['email_restrictions']) ? $data['email_restrictions'] : array();
                $validated_emails = array();
                foreach ($emails as $email) {
                    $clean_email = sanitize_email($email);
                    if (is_email($clean_email)) {
                        $validated_emails[] = $clean_email;
                    }
                }
                $coupon->set_email_restrictions($validated_emails);
            }
            
            if (isset($data['exclude_sale_items'])) {
                $coupon->set_exclude_sale_items((bool) $data['exclude_sale_items']);
            }
            
            // Set usage limits
            if (isset($data['usage_limit'])) {
                $coupon->set_usage_limit(absint($data['usage_limit']));
            }
            
            if (isset($data['usage_limit_per_user'])) {
                $coupon->set_usage_limit_per_user(absint($data['usage_limit_per_user']));
            }
            
            if (isset($data['limit_usage_to_x_items'])) {
                $coupon->set_limit_usage_to_x_items(absint($data['limit_usage_to_x_items']));
            }
            
            // Set expiry date
            if (isset($data['date_expires'])) {
                if (empty($data['date_expires'])) {
                    $coupon->set_date_expires(null);
                } else {
                    $date_string = sanitize_text_field($data['date_expires']);
                    // Validate Y-m-d format
                    try {
                        $date = DateTime::createFromFormat('Y-m-d', $date_string);
                        if ($date && $date->format('Y-m-d') === $date_string) {
                            $coupon->set_date_expires($date_string);
                        } else {
                            return new WP_Error(
                                'invalid_date_format',
                                __('Expiration date must be in Y-m-d format (e.g., 2024-12-31).', 'lazychat'),
                                array('status' => 400)
                            );
                        }
                    } catch (Exception $e) {
                        return new WP_Error(
                            'invalid_date_format',
                            __('Invalid expiration date format.', 'lazychat'),
                            array('status' => 400)
                        );
                    }
                }
            }
            
            // Set free shipping
            if (isset($data['free_shipping'])) {
                $coupon->set_free_shipping((bool) $data['free_shipping']);
            }
            
            // Save the coupon
            $saved_id = $coupon->save();
            
            if (!$saved_id) {
                return new WP_Error(
                    'coupon_save_failed',
                    __('Failed to save coupon.', 'lazychat'),
                    array('status' => 500)
                );
            }
            
            // Mark as created by LazyChat (only for new coupons)
            if (!$is_update) {
                update_post_meta($saved_id, '_created_via', 'lazychat');
            }
            
            // Prepare response
            $coupon_data = LazyChat_Coupon_Formatter::prepare_coupon_data($saved_id);
            
            return array(
                'success' => true,
                'message' => $is_update 
                    ? sprintf(__('Coupon updated successfully. Coupon ID: %d', 'lazychat'), $saved_id)
                    : sprintf(__('Coupon created successfully. Coupon ID: %d', 'lazychat'), $saved_id),
                'coupon' => $coupon_data
            );
            
        } catch (Exception $e) {
            return new WP_Error(
                'coupon_operation_failed',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }
    
    /**
     * Get list of coupons with pagination
     *
     * @param array $args Query arguments
     * @return array
     */
    public static function get_coupons($args = array()) {
        $defaults = array(
            'page' => 1,
            'per_page' => 10,
            'status' => 'all', // all, active, expired, used_up
            'is_lazychat_coupon' => false, // Filter by LazyChat coupons only
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // Ensure page and per_page are valid
        $page = max(1, absint($args['page']));
        $per_page = max(1, min(1000, absint($args['per_page'])));
        
        // Build WP_Query args
        $query_args = array(
            'post_type' => 'shop_coupon',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC'
        );
        
        // Filter by LazyChat coupons only if requested
        if ($args['is_lazychat_coupon']) {
            $query_args['meta_query'] = array(
                array(
                    'key' => '_created_via',
                    'value' => 'lazychat',
                    'compare' => '='
                )
            );
        }
        
        // Execute query
        $query = new WP_Query($query_args);
        
        // Prepare coupons data
        $coupons = array();
        if ($query->have_posts()) {
            foreach ($query->posts as $post) {
                $coupon_data = LazyChat_Coupon_Formatter::prepare_coupon_data($post->ID);
                
                // Filter by status if specified
                if ($args['status'] !== 'all') {
                    if ($coupon_data['status'] !== $args['status']) {
                        continue;
                    }
                }
                
                if ($coupon_data) {
                    $coupons[] = $coupon_data;
                }
            }
        }
        
        // Note: When filtering by status, total_coupons may be higher than actual results
        // because status is calculated dynamically, not stored in database
        $total_coupons = $query->found_posts;
        $total_pages = $query->max_num_pages;
        
        // If status filter is active, adjust totals to actual filtered count
        if ($args['status'] !== 'all') {
            $total_coupons = count($coupons);
            $total_pages = ($per_page > 0) ? ceil($total_coupons / $per_page) : 1;
        }
        
        // Prepare response
        return array(
            'page' => $page,
            'per_page' => $per_page,
            'total_coupons' => $total_coupons,
            'total_pages' => $total_pages,
            'coupons' => $coupons
        );
    }
    
    /**
     * Delete a coupon
     *
     * @param int $coupon_id Coupon ID
     * @return array|WP_Error
     */
    public static function delete_coupon($coupon_id) {
        // Validate coupon ID
        if (empty($coupon_id) || !is_numeric($coupon_id)) {
            return new WP_Error(
                'invalid_coupon_id',
                __('Invalid coupon ID.', 'lazychat'),
                array('status' => 400)
            );
        }
        
        $coupon_id = absint($coupon_id);
        
        // Check if coupon exists
        $coupon = new WC_Coupon($coupon_id);
        if (!$coupon->get_id()) {
            return new WP_Error(
                'coupon_not_found',
                __('Coupon not found.', 'lazychat'),
                array('status' => 404)
            );
        }
        
        // Check if coupon was created by LazyChat
        $created_via = get_post_meta($coupon_id, '_created_via', true);
        if ($created_via !== 'lazychat') {
            return new WP_Error(
                'coupon_not_deletable',
                __('This coupon was not created by LazyChat and cannot be deleted via the API.', 'lazychat'),
                array('status' => 403)
            );
        }
        
        $coupon_code = $coupon->get_code();
        
        // Delete the coupon (force delete, not trash)
        $deleted = wp_delete_post($coupon_id, true);
        
        if (!$deleted) {
            return new WP_Error(
                'coupon_delete_failed',
                __('Failed to delete coupon.', 'lazychat'),
                array('status' => 500)
            );
        }
        
        return array(
            'success' => true,
            'message' => sprintf(__('Coupon "%s" deleted successfully.', 'lazychat'), $coupon_code),
            'coupon_id' => $coupon_id,
            'coupon_code' => $coupon_code
        );
    }
}
