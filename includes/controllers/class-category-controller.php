<?php
/**
 * LazyChat Category Controller
 * Handles category business logic for REST API
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class LazyChat_Category_Controller {
    
    /**
     * Get paginated list of product categories
     * 
     * @param array $args Query arguments
     * @return array Categories list with pagination
     */
    public static function get_categories($args) {
        $page = isset($args['page']) ? absint($args['page']) : 1;
        $per_page = isset($args['per_page']) ? absint($args['per_page']) : 10;
        $hide_empty = isset($args['hide_empty']) ? (bool)$args['hide_empty'] : false;
        $orderby = isset($args['orderby']) ? sanitize_text_field($args['orderby']) : 'name';
        $order = isset($args['order']) ? sanitize_text_field($args['order']) : 'ASC';
        
        // Ensure page and per_page are valid
        $page = max(1, $page);
        $per_page = max(1, min(1000, $per_page)); // Min 1, Max 1000
        
        // Calculate offset
        $offset = ($page - 1) * $per_page;
        
        // Build query args for getting categories
        $query_args = array(
            'taxonomy' => 'product_cat',
            'orderby' => $orderby,
            'order' => $order,
            'hide_empty' => $hide_empty,
            'number' => $per_page,
            'offset' => $offset
        );
        
        // Get categories
        $categories = get_terms($query_args);
        
        // Get total count (using get_terms with 'count' => true for WP 5.6+)
        $total_count_args = array(
            'taxonomy' => 'product_cat',
            'hide_empty' => $hide_empty,
            'count' => true
        );
        $total_categories = (int) get_terms($total_count_args);
        
        // Prepare categories data
        $categories_data = array();
        if (!is_wp_error($categories) && !empty($categories)) {
            foreach ($categories as $category) {
                $categories_data[] = array(
                    'id' => $category->term_id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description
                );
            }
        }
        
        // Calculate total pages
        $total_pages = (int) ceil($total_categories / $per_page);
        
        // Prepare response
        return array(
            'page' => $page,
            'per_page' => $per_page,
            'current_page' => $page,
            'total_categories' => $total_categories,
            'total_pages' => $total_pages,
            'categories' => $categories_data
        );
    }
    
    /**
     * Get single category by ID
     * 
     * @param int $category_id Category ID
     * @return array|WP_Error Category data or error
     */
    public static function get_category($category_id) {
        // Validate category ID
        if (empty($category_id) || !is_numeric($category_id)) {
            return new WP_Error(
                'invalid_category_id',
                __('Invalid category ID.', 'lazychat'),
                array('status' => 400)
            );
        }
        
        $category_id = absint($category_id);
        
        // Get category
        $category = get_term($category_id, 'product_cat');
        
        if (is_wp_error($category)) {
            return new WP_Error(
                'category_error',
                $category->get_error_message(),
                array('status' => 404)
            );
        }
        
        if (!$category || !isset($category->term_id)) {
            return new WP_Error(
                'category_not_found',
                __('Category not found.', 'lazychat'),
                array('status' => 404)
            );
        }
        
        // Prepare category data
        $category_data = array(
            'id' => $category->term_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description
        );
        
        return $category_data;
    }
}
