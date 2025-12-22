<?php
/**
 * LazyChat Attribute Controller
 * Handles product attribute business logic for REST API
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class LazyChat_Attribute_Controller {
    
    /**
     * Get paginated list of product attributes
     * 
     * @param array $args Query arguments
     * @return array Attributes list with pagination
     */
    public static function get_attributes($args) {
        $page = isset($args['page']) ? absint($args['page']) : 1;
        $per_page = isset($args['per_page']) ? absint($args['per_page']) : 10;
        
        // Ensure page and per_page are valid
        $page = max(1, $page);
        $per_page = max(1, min(1000, $per_page)); // Min 1, Max 1000
        
        // Calculate offset
        $offset = ($page - 1) * $per_page;
        
        global $wpdb;
        
        // Get total count of attributes
        $total_attributes = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_attribute_taxonomies"
        );
        
        // Get paginated attributes
        $attributes = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}woocommerce_attribute_taxonomies 
                ORDER BY attribute_name ASC 
                LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );
        
        // Prepare attributes data
        $attributes_data = array();
        if (!empty($attributes)) {
            foreach ($attributes as $attribute) {
                $taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);
                
                // Get attribute terms
                $terms = get_terms(array(
                    'taxonomy' => $taxonomy,
                    'hide_empty' => false
                ));
                
                $options = array();
                if (!is_wp_error($terms) && !empty($terms)) {
                    foreach ($terms as $term) {
                        $options[] = $term->name;
                    }
                }
                
                $attributes_data[] = array(
                    'id' => (int) $attribute->attribute_id,
                    'name' => $attribute->attribute_label,
                    'slug' => $taxonomy,
                    'options' => $options
                );
            }
        }
        
        // Calculate total pages
        $total_pages = ceil($total_attributes / $per_page);
        
        // Prepare response
        return array(
            'page' => $page,
            'per_page' => $per_page,
            'current_page' => $page,
            'total_attributes' => (int) $total_attributes,
            'total_pages' => $total_pages,
            'attributes' => $attributes_data
        );
    }
    
    /**
     * Get single attribute by ID
     * 
     * @param int $attribute_id Attribute ID
     * @return array|WP_Error Attribute data or error
     */
    public static function get_attribute($attribute_id) {
        // Validate attribute ID
        if (empty($attribute_id) || !is_numeric($attribute_id)) {
            return new WP_Error(
                'invalid_attribute_id',
                __('Invalid attribute ID.', 'lazychat'),
                array('status' => 400)
            );
        }
        
        $attribute_id = absint($attribute_id);
        
        global $wpdb;
        
        // Get attribute
        $attribute = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}woocommerce_attribute_taxonomies 
                WHERE attribute_id = %d",
                $attribute_id
            )
        );
        
        if (!$attribute) {
            return new WP_Error(
                'attribute_not_found',
                __('Attribute not found.', 'lazychat'),
                array('status' => 404)
            );
        }
        
        // Get taxonomy name
        $taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);
        
        // Get attribute terms
        $terms = get_terms(array(
            'taxonomy' => $taxonomy,
            'hide_empty' => false
        ));
        
        $terms_data = array();
        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $term) {
                $terms_data[] = array(
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'description' => $term->description,
                    'count' => $term->count
                );
            }
        }
        
        // Prepare attribute data
        $attribute_data = array(
            'id' => (int) $attribute->attribute_id,
            'name' => $attribute->attribute_name,
            'slug' => $taxonomy,
            'type' => $attribute->attribute_type,
            'order_by' => $attribute->attribute_orderby,
            'has_archives' => (bool) $attribute->attribute_public,
            'label' => $attribute->attribute_label,
            'terms' => $terms_data,
            'terms_count' => count($terms_data)
        );
        
        return $attribute_data;
    }
}
