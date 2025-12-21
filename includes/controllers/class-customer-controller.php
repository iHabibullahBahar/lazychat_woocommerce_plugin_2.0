<?php
/**
 * LazyChat Customer Controller
 * Handles customer creation and management
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class LazyChat_Customer_Controller {
    
    /**
     * Create a new customer
     *
     * @param array $data Customer data
     * @return array|\WP_Error
     */
    public static function create_customer($data) {
        try {
            // Validate required fields
            if (empty($data['email'])) {
                return new WP_Error(
                    'missing_email',
                    __('Email address is required.', 'lazychat'),
                    array('status' => 400)
                );
            }
            
            // Validate email format
            if (!is_email($data['email'])) {
                return new WP_Error(
                    'invalid_email',
                    __('Invalid email address.', 'lazychat'),
                    array('status' => 400)
                );
            }
            
            // Check if email already exists
            if (email_exists($data['email'])) {
                $user = get_user_by('email', $data['email']);
                return new WP_Error(
                    'email_exists',
                    /* translators: %d: Customer ID */
                    sprintf(__('Email address already exists. Customer ID: %d', 'lazychat'), $user->ID),
                    array('status' => 409, 'customer_id' => $user->ID)
                );
            }
            
            // Generate username from email if not provided
            $username = !empty($data['username']) ? sanitize_user($data['username']) : sanitize_user(current(explode('@', $data['email'])));
            
            // Make username unique if it already exists
            if (username_exists($username)) {
                $username = $username . '_' . wp_rand(1000, 9999);
            }
            
            // Prepare user data
            $userdata = array(
                'user_login' => $username,
                'user_email' => sanitize_email($data['email']),
                'user_pass' => !empty($data['password']) ? $data['password'] : wp_generate_password(12, true, true),
                'role' => 'customer',
                'show_admin_bar_front' => false
            );
            
            // Add optional fields
            if (!empty($data['first_name'])) {
                $userdata['first_name'] = sanitize_text_field($data['first_name']);
            }
            
            if (!empty($data['last_name'])) {
                $userdata['last_name'] = sanitize_text_field($data['last_name']);
            }
            
            if (!empty($data['display_name'])) {
                $userdata['display_name'] = sanitize_text_field($data['display_name']);
            }
            
            // Create the user
            $customer_id = wp_insert_user($userdata);
            
            if (is_wp_error($customer_id)) {
                return $customer_id;
            }
            
            // Add billing address if provided
            if (!empty($data['billing'])) {
                self::update_customer_billing($customer_id, $data['billing']);
            }
            
            // Add shipping address if provided
            if (!empty($data['shipping'])) {
                self::update_customer_shipping($customer_id, $data['shipping']);
            }
            
            // Add phone number to billing if provided directly
            if (!empty($data['phone'])) {
                update_user_meta($customer_id, 'billing_phone', sanitize_text_field($data['phone']));
            }
            
            // Add custom meta data if provided
            if (!empty($data['meta_data']) && is_array($data['meta_data'])) {
                foreach ($data['meta_data'] as $meta) {
                    if (!empty($meta['key'])) {
                        update_user_meta($customer_id, sanitize_key($meta['key']), sanitize_text_field($meta['value']));
                    }
                }
            }
            
            // Get the created customer
            $customer = new WC_Customer($customer_id);
            
            return array(
                'success' => true,
                /* translators: %d: Customer ID */
                'message' => sprintf(__('Customer created successfully. Customer ID: %d', 'lazychat'), $customer_id),
                'id' => $customer_id,
                'username' => $username,
                'email' => $data['email'],
                'first_name' => $customer->get_first_name(),
                'last_name' => $customer->get_last_name(),
                'display_name' => $customer->get_display_name(),
                'role' => 'customer',
                'billing' => array(
                    'first_name' => $customer->get_billing_first_name(),
                    'last_name' => $customer->get_billing_last_name(),
                    'company' => $customer->get_billing_company(),
                    'address_1' => $customer->get_billing_address_1(),
                    'address_2' => $customer->get_billing_address_2(),
                    'city' => $customer->get_billing_city(),
                    'state' => $customer->get_billing_state(),
                    'postcode' => $customer->get_billing_postcode(),
                    'country' => $customer->get_billing_country(),
                    'email' => $customer->get_billing_email(),
                    'phone' => $customer->get_billing_phone()
                ),
                'shipping' => array(
                    'first_name' => $customer->get_shipping_first_name(),
                    'last_name' => $customer->get_shipping_last_name(),
                    'company' => $customer->get_shipping_company(),
                    'address_1' => $customer->get_shipping_address_1(),
                    'address_2' => $customer->get_shipping_address_2(),
                    'city' => $customer->get_shipping_city(),
                    'state' => $customer->get_shipping_state(),
                    'postcode' => $customer->get_shipping_postcode(),
                    'country' => $customer->get_shipping_country()
                ),
                'date_created' => $customer->get_date_created() ? $customer->get_date_created()->date('Y-m-d H:i:s') : null
            );
            
        } catch (Exception $e) {
            return new WP_Error(
                'customer_creation_failed',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }
    
    /**
     * Update customer billing address
     *
     * @param int $customer_id Customer ID
     * @param array $billing Billing data
     */
    private static function update_customer_billing($customer_id, $billing) {
        $fields = array(
            'first_name', 'last_name', 'company', 'address_1', 'address_2',
            'city', 'state', 'postcode', 'country', 'email', 'phone'
        );
        
        foreach ($fields as $field) {
            if (isset($billing[$field])) {
                update_user_meta($customer_id, 'billing_' . $field, sanitize_text_field($billing[$field]));
            }
        }
    }
    
    /**
     * Update customer shipping address
     *
     * @param int $customer_id Customer ID
     * @param array $shipping Shipping data
     */
    private static function update_customer_shipping($customer_id, $shipping) {
        $fields = array(
            'first_name', 'last_name', 'company', 'address_1', 'address_2',
            'city', 'state', 'postcode', 'country'
        );
        
        foreach ($fields as $field) {
            if (isset($shipping[$field])) {
                update_user_meta($customer_id, 'shipping_' . $field, sanitize_text_field($shipping[$field]));
            }
        }
    }
    
    /**
     * Get customer by ID
     *
     * @param int $customer_id Customer ID
     * @return array|\WP_Error
     */
    public static function get_customer($customer_id) {
        $user = get_user_by('id', $customer_id);
        
        if (!$user) {
            return new WP_Error(
                'customer_not_found',
                __('Customer not found.', 'lazychat'),
                array('status' => 404)
            );
        }
        
        $customer = new WC_Customer($customer_id);
        
        return array(
            'success' => true,
            /* translators: %d: Customer ID */
            'message' => sprintf(__('Customer retrieved successfully. Customer ID: %d', 'lazychat'), $customer_id),
            'id' => $customer_id,
            'username' => $user->user_login,
            'email' => $user->user_email,
            'first_name' => $customer->get_first_name(),
            'last_name' => $customer->get_last_name(),
            'display_name' => $user->display_name,
            'role' => !empty($user->roles) ? $user->roles[0] : '',
            'billing' => array(
                'first_name' => $customer->get_billing_first_name(),
                'last_name' => $customer->get_billing_last_name(),
                'company' => $customer->get_billing_company(),
                'address_1' => $customer->get_billing_address_1(),
                'address_2' => $customer->get_billing_address_2(),
                'city' => $customer->get_billing_city(),
                'state' => $customer->get_billing_state(),
                'postcode' => $customer->get_billing_postcode(),
                'country' => $customer->get_billing_country(),
                'email' => $customer->get_billing_email(),
                'phone' => $customer->get_billing_phone()
            ),
            'shipping' => array(
                'first_name' => $customer->get_shipping_first_name(),
                'last_name' => $customer->get_shipping_last_name(),
                'company' => $customer->get_shipping_company(),
                'address_1' => $customer->get_shipping_address_1(),
                'address_2' => $customer->get_shipping_address_2(),
                'city' => $customer->get_shipping_city(),
                'state' => $customer->get_shipping_state(),
                'postcode' => $customer->get_shipping_postcode(),
                'country' => $customer->get_shipping_country()
            ),
            'date_created' => $customer->get_date_created() ? $customer->get_date_created()->date('Y-m-d H:i:s') : null,
            'orders_count' => $customer->get_order_count(),
            'total_spent' => $customer->get_total_spent()
        );
    }
}
