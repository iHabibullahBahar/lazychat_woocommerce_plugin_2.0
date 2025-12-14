<?php
/**
 * LazyChat Product Formatter
 * Shared product data formatter for REST API and Webhooks
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class LazyChat_Product_Formatter {
    
    /**
     * Prepare product data in simplified format (used by REST API)
     */
    public static function prepare_product_data($product_id) {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return false;
        }
        
        // Build data in simplified format
        $data = array(
            'id' => $product_id,
            'name' => $product->get_name(),
            'slug' => $product->get_slug(),
            'permalink' => get_permalink($product_id),
            'date_created' => self::format_date($product->get_date_created(), false),
            'date_modified' => self::format_date($product->get_date_modified(), false),
            'type' => $product->get_type(),
            'status' => $product->get_status(),
            'featured' => $product->get_featured(),
            'description' => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'sku' => $product->get_sku(),
            'price' => $product->get_price() ?: '0',
            'regular_price' => $product->get_regular_price() ?: '0',
            'sale_price' => $product->get_sale_price() ?: '0',
            'date_on_sale_from' => self::format_date($product->get_date_on_sale_from(), false),
            'date_on_sale_to' => self::format_date($product->get_date_on_sale_to(), false),
            'on_sale' => $product->is_on_sale(),
            'purchasable' => $product->is_purchasable(),
            'virtual' => $product->get_virtual(),
            'tax_status' => $product->get_tax_status(),
            'manage_stock' => $product->get_manage_stock(),
            'stock_quantity' => $product->get_stock_quantity(),
            'in_stock' => $product->is_in_stock(),
            'sold_individually' => $product->get_sold_individually(),
            'weight' => $product->get_weight(),
            'shipping_required' => $product->needs_shipping(),
            'parent_id' => $product->get_parent_id(),
            'purchase_note' => $product->get_purchase_note(),
            'categories' => self::prepare_categories($product),
            'tags' => self::prepare_tags($product),
            'images' => self::prepare_images($product),
            'attributes' => self::prepare_attributes($product),
            'default_attributes' => self::prepare_default_attributes($product),
            'variations' => $product->is_type('variable') ? self::prepare_variations($product) : array(),
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
    private static function format_date($date, $gmt = false) {
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
    private static function prepare_images($product) {
        $images = array();
        $image_id = $product->get_image_id();
        
        if ($image_id) {
            $images[] = self::prepare_image($image_id, 0);
        }
        
        $gallery_ids = $product->get_gallery_image_ids();
        foreach ($gallery_ids as $position => $gallery_id) {
            $images[] = self::prepare_image($gallery_id, $position + 1);
        }
        
        return $images;
    }
    
    /**
     * Prepare single image data
     */
    private static function prepare_image($image_id, $position = 0) {
        $attachment = get_post($image_id);
        
        return array(
            'id' => $image_id,
            'src' => wp_get_attachment_url($image_id),
            'name' => get_the_title($image_id),
            'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true)
        );
    }
    
    /**
     * Prepare product categories
     */
    private static function prepare_categories($product) {
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
    private static function prepare_tags($product) {
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
    private static function prepare_attributes($product) {
        $attributes = array();
        
        // Handle variation products differently (they return array of strings)
        if ($product->is_type('variation')) {
            foreach ($product->get_attributes() as $key => $value) {
                $taxonomy = wc_attribute_taxonomy_name($key);
                $attributes[] = array(
                    'id' => $taxonomy ? wc_attribute_taxonomy_id_by_name($taxonomy) : 0,
                    'name' => wc_attribute_label($key),
                    'slug' => $key,
                    'option' => $value
                );
            }
        } else {
            // Handle regular and variable products (they return attribute objects)
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
        }
        
        return $attributes;
    }
    
    /**
     * Prepare default attributes
     */
    private static function prepare_default_attributes($product) {
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
     * Prepare variations data for variable products
     */
    private static function prepare_variations($product) {
        $variations = array();
        
        if (!$product->is_type('variable')) {
            return $variations;
        }
        
        $variation_ids = $product->get_children();
        
        foreach ($variation_ids as $variation_id) {
            $variation = wc_get_product($variation_id);
            
            if (!$variation) {
                continue;
            }
            
            $variation_data = array(
                'id' => $variation_id,
                'date_created' => self::format_date($variation->get_date_created(), false),
                'date_modified' => self::format_date($variation->get_date_modified(), false),
                'description' => $variation->get_description(),
                'permalink' => $variation->get_permalink(),
                'sku' => $variation->get_sku(),
                'price' => $variation->get_price() ?: '0',
                'regular_price' => $variation->get_regular_price() ?: '0',
                'sale_price' => $variation->get_sale_price() ?: '0',
                'date_on_sale_from' => self::format_date($variation->get_date_on_sale_from(), false),
                'date_on_sale_to' => self::format_date($variation->get_date_on_sale_to(), false),
                'on_sale' => $variation->is_on_sale(),
                'status' => $variation->get_status(),
                'purchasable' => $variation->is_purchasable(),
                'virtual' => $variation->get_virtual(),
                'tax_status' => $variation->get_tax_status(),
                'tax_class' => $variation->get_tax_class(),
                'manage_stock' => $variation->get_manage_stock(),
                'stock_quantity' => $variation->get_stock_quantity(),
                'stock_status' => $variation->get_stock_status(),
                'in_stock' => $variation->is_in_stock(),
                'weight' => $variation->get_weight(),
                'image' => self::prepare_variation_image($variation),
                'attributes' => self::prepare_variation_attributes($variation)
            );
            
            $variations[] = $variation_data;
        }
        
        return $variations;
    }
    
    /**
     * Prepare variation image
     */
    private static function prepare_variation_image($variation) {
        $image_id = $variation->get_image_id();
        
        if (!$image_id) {
            return null;
        }
        
        return self::prepare_image($image_id, 0);
    }
    
    /**
     * Prepare variation attributes
     */
    private static function prepare_variation_attributes($variation) {
        $attributes = array();
        
        foreach ($variation->get_variation_attributes() as $attribute_name => $attribute_value) {
            // Remove 'attribute_' prefix
            $attribute_name = str_replace('attribute_', '', $attribute_name);
            
            // Get proper capitalization from taxonomy term if it's a taxonomy attribute
            $option_value = $attribute_value;
            if (taxonomy_exists($attribute_name)) {
                $term = get_term_by('slug', $attribute_value, $attribute_name);
                if ($term && !is_wp_error($term)) {
                    $option_value = $term->name;
                }
            }
            
            $attributes[] = array(
                'id' => wc_attribute_taxonomy_id_by_name($attribute_name),
                'name' => wc_attribute_label($attribute_name),
                'option' => $option_value
            );
        }
        
        return $attributes;
    }
}
