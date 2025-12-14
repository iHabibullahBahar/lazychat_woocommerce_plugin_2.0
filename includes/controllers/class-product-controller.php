<?php
/**
 * LazyChat Product Controller
 * Handles product business logic for REST API
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class LazyChat_Product_Controller {
    
    /**
     * Get paginated list of products
     * 
     * @param array $args Query arguments
     * @return array Products list with pagination
     */
    public static function get_products($args) {
        $page = isset($args['page']) ? absint($args['page']) : 1;
        $per_page = isset($args['per_page']) ? absint($args['per_page']) : 10;
        $status = isset($args['status']) ? sanitize_text_field($args['status']) : 'publish';
        $type = isset($args['type']) ? sanitize_text_field($args['type']) : '';
        
        // Build query args
        $query_args = array(
            'post_type' => 'product',
            'post_status' => $status,
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC'
        );
        
        // Add product type filter if specified
        if (!empty($type)) {
            $query_args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_type',
                    'field' => 'slug',
                    'terms' => $type
                )
            );
        }
        
        // Execute query
        $query = new WP_Query($query_args);
        
        // Prepare products data
        $products = array();
        if ($query->have_posts()) {
            foreach ($query->posts as $post) {
                $product_data = LazyChat_Product_Formatter::prepare_product_data($post->ID);
                if ($product_data) {
                    $products[] = $product_data;
                }
            }
        }
        
        // Calculate total products
        $total_products = $query->found_posts;
        $total_pages = $query->max_num_pages;
        
        // Prepare response
        return array(
            'page' => $page,
            'per_page' => $per_page,
            'current_page' => $page,
            'total_products' => $total_products,
            'total_pages' => $total_pages,
            'products' => $products
        );
    }
    
    /**
     * Get single product by ID
     * 
     * @param int $product_id Product ID
     * @return array|WP_Error Product data or error
     */
    public static function get_product($product_id) {
        // Validate product ID
        if (empty($product_id) || !is_numeric($product_id)) {
            return new WP_Error(
                'invalid_product_id',
                __('Invalid product ID.', 'lazychat'),
                array('status' => 400)
            );
        }
        
        $product_id = absint($product_id);
        
        // Check if product exists
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error(
                'product_not_found',
                __('Product not found.', 'lazychat'),
                array('status' => 404)
            );
        }
        
        // Prepare product data
        $product_data = LazyChat_Product_Formatter::prepare_product_data($product_id);
        
        if (!$product_data) {
            return new WP_Error(
                'product_data_error',
                __('Failed to prepare product data.', 'lazychat'),
                array('status' => 500)
            );
        }
        
        return $product_data;
    }
}
