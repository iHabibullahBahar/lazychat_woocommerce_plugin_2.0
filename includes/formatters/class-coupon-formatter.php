<?php
/**
 * LazyChat Coupon Formatter
 * Shared coupon data formatter for REST API
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class LazyChat_Coupon_Formatter {
    
    /**
     * Prepare coupon data in simplified format (used by REST API)
     */
    public static function prepare_coupon_data($coupon_id) {
        $coupon = new WC_Coupon($coupon_id);
        
        if (!$coupon->get_id()) {
            return null;
        }
        
        // Get expiry date
        $expiry_date = $coupon->get_date_expires();
        $expiry_formatted = null;
        if ($expiry_date) {
            $expiry_formatted = self::format_date($expiry_date);
        }
        
        // Determine coupon status
        $status = 'active';
        if ($expiry_date && $expiry_date->getTimestamp() < time()) {
            $status = 'expired';
        } elseif ($coupon->get_usage_limit() > 0 && $coupon->get_usage_count() >= $coupon->get_usage_limit()) {
            $status = 'used_up';
        }
        
        // Get created_via metadata
        $created_via = get_post_meta($coupon->get_id(), '_created_via', true);
        
        return array(
            'id' => $coupon->get_id(),
            'code' => $coupon->get_code(),
            'amount' => $coupon->get_amount(),
            'discount_type' => $coupon->get_discount_type(),
            'description' => $coupon->get_description(),
            'status' => $status,
            'created_via' => $created_via ? $created_via : 'unknown',
            
            // Date information
            'date_created' => self::format_date($coupon->get_date_created()),
            'date_modified' => self::format_date($coupon->get_date_modified()),
            'date_expires' => $expiry_formatted,
            
            // Usage restrictions
            'minimum_amount' => $coupon->get_minimum_amount(),
            'maximum_amount' => $coupon->get_maximum_amount(),
            'individual_use' => $coupon->get_individual_use(),
            'product_ids' => $coupon->get_product_ids(),
            'excluded_product_ids' => $coupon->get_excluded_product_ids(),
            'product_categories' => $coupon->get_product_categories(),
            'excluded_product_categories' => $coupon->get_excluded_product_categories(),
            'email_restrictions' => $coupon->get_email_restrictions(),
            'exclude_sale_items' => $coupon->get_exclude_sale_items(),
            
            // Usage limits
            'usage_limit' => $coupon->get_usage_limit(),
            'usage_limit_per_user' => $coupon->get_usage_limit_per_user(),
            'limit_usage_to_x_items' => $coupon->get_limit_usage_to_x_items(),
            
            // Usage tracking
            'usage_count' => $coupon->get_usage_count(),
            'used_by' => $coupon->get_used_by(),
            
            // Advanced options
            'free_shipping' => $coupon->get_free_shipping(),
        );
    }
    
    /**
     * Format date for API response
     */
    public static function format_date($date, $gmt = false) {
        if (!$date) {
            return null;
        }
        
        if (is_numeric($date)) {
            $date = new WC_DateTime("@{$date}");
        }
        
        if ($date instanceof WC_DateTime) {
            return $gmt ? $date->date('Y-m-d\TH:i:s') : gmdate('Y-m-d\TH:i:s', $date->getTimestamp() + $date->getOffset());
        }
        
        return null;
    }
}
