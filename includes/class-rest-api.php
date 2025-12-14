<?php
/**
 * LazyChat REST API Handler
 * Handles REST API endpoints for product data
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class LazyChat_REST_API {
    
    /**
     * API namespace
     */
    private $namespace = 'lazychat/v1';
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Log that routes are being registered
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[LazyChat] Registering REST API routes for namespace: ' . $this->namespace);
        }
        
        // Products endpoint with pagination
        register_rest_route($this->namespace, '/products', array(
            'methods' => 'POST',
            'callback' => array($this, 'get_products'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'page' => array(
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0;
                    }
                ),
                'per_page' => array(
                    'default' => 10,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0 && $param <= 100;
                    }
                ),
                'status' => array(
                    'default' => 'publish',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function($param) {
                        return in_array($param, array('publish', 'draft', 'pending', 'private', 'any'));
                    }
                ),
                'type' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function($param) {
                        if (empty($param)) {
                            return true;
                        }
                        return in_array($param, array('simple', 'variable', 'grouped', 'external'));
                    }
                ),
                'consumer_key' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'consumer_secret' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
            )
        ));
        
        // Single product endpoint
        register_rest_route($this->namespace, '/products/details', array(
            'methods' => 'POST',
            'callback' => array($this, 'get_product'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'product_id' => array(
                    'required' => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0;
                    }
                ),
                'consumer_key' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'consumer_secret' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
            )
        ));
        
        // Create order endpoint
        register_rest_route($this->namespace, '/orders/create', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_order'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'consumer_key' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'consumer_secret' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
            )
        ));
        
        // List orders endpoint
        register_rest_route($this->namespace, '/orders/list', array(
            'methods' => 'POST',
            'callback' => array($this, 'list_orders'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'page' => array(
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0;
                    }
                ),
                'per_page' => array(
                    'default' => 10,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0 && $param <= 100;
                    }
                ),
                'status' => array(
                    'default' => 'any',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'orderby' => array(
                    'default' => 'date',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'order' => array(
                    'default' => 'DESC',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function($param) {
                        return in_array(strtoupper($param), array('ASC', 'DESC'));
                    }
                ),
                'consumer_key' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'consumer_secret' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
            )
        ));
        
        // Get order details endpoint
        register_rest_route($this->namespace, '/orders/details', array(
            'methods' => 'POST',
            'callback' => array($this, 'get_order'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'order_id' => array(
                    'required' => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0;
                    }
                ),
                'consumer_key' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'consumer_secret' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
            )
        ));
        
        // Update order endpoint
        register_rest_route($this->namespace, '/orders/update', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_order'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'consumer_key' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'consumer_secret' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
            )
        ));
        
        // Create customer endpoint
        register_rest_route($this->namespace, '/customers/create', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_customer'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'consumer_key' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'consumer_secret' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
            )
        ));
        
        // Get customer endpoint
        register_rest_route($this->namespace, '/customers/details', array(
            'methods' => 'POST',
            'callback' => array($this, 'get_customer'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'customer_id' => array(
                    'required' => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0;
                    }
                ),
                'consumer_key' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'consumer_secret' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
            )
        ));
        
        // Get all available order statuses/phases
        register_rest_route($this->namespace, '/orders/statuses', array(
            'methods' => 'POST',
            'callback' => array($this, 'get_order_statuses'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'consumer_key' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'consumer_secret' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
            )
        ));
        
        // Test connection endpoint
        register_rest_route($this->namespace, '/test-connection', array(
            'methods' => 'POST',
            'callback' => array($this, 'test_connection'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'consumer_key' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'consumer_secret' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
            )
        ));
    }
    
    /**
     * Get authorization header from various sources
     * Handles different server configurations (Apache, Nginx, etc.)
     */
    private function get_authorization_header($request) {
        // Try WordPress REST API method first
        $auth_header = $request->get_header('authorization');
        if (!empty($auth_header)) {
            return $auth_header;
        }
        
        // Check Apache-specific authorization header
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return $_SERVER['HTTP_AUTHORIZATION'];
        }
        
        // Check redirect header (some Apache configs)
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        
        // Check PHP_AUTH_USER and PHP_AUTH_PW for Basic Auth
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            return 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $_SERVER['PHP_AUTH_PW']);
        }
        
        // Check for Authorization in getallheaders() if available
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            // Headers can be case-insensitive
            foreach ($headers as $name => $value) {
                if (strtolower($name) === 'authorization') {
                    return $value;
                }
            }
        }
        
        // Last resort: Check if token is sent in the request body or query parameter
        // This allows customers to use the API without server configuration
        $body = $request->get_json_params();
        if (!empty($body['bearer_token'])) {
            return 'Bearer ' . $body['bearer_token'];
        }
        
        $query_token = $request->get_param('bearer_token');
        if (!empty($query_token)) {
            return 'Bearer ' . $query_token;
        }
        
        return null;
    }
    
    /**
     * Check API permissions
     * Supports both Bearer Token and WooCommerce Consumer Key/Secret authentication
     */
    public function check_permission($request) {
        // Try WooCommerce Consumer Key/Secret authentication first
        // Check both query parameters and JSON body
        $consumer_key = $request->get_param('consumer_key');
        $consumer_secret = $request->get_param('consumer_secret');
        
        // If not in params, check JSON body
        if (empty($consumer_key) || empty($consumer_secret)) {
            $body = $request->get_json_params();
            if (!empty($body['consumer_key'])) {
                $consumer_key = $body['consumer_key'];
            }
            if (!empty($body['consumer_secret'])) {
                $consumer_secret = $body['consumer_secret'];
            }
        }
        
        // If consumer key and secret are provided, use WooCommerce authentication
        if (!empty($consumer_key) && !empty($consumer_secret)) {
            return $this->authenticate_woocommerce($consumer_key, $consumer_secret);
        }
        
        // Fall back to Bearer Token authentication
        $auth_header = $this->get_authorization_header($request);
        
        if (!empty($auth_header)) {
            // Extract token from "Bearer TOKEN" format
            $token = str_replace('Bearer ', '', $auth_header);
            
            // Get stored bearer token
            $stored_token = get_option('lazychat_bearer_token');
            
            if (empty($stored_token)) {
                return new WP_Error(
                    'rest_forbidden',
                    __('API not configured. Please set bearer token in plugin settings.', 'lazychat'),
                    array('status' => 401)
                );
            }
            
            // Verify token matches
            if ($token !== $stored_token) {
                return new WP_Error(
                    'rest_forbidden',
                    __('Invalid bearer token.', 'lazychat'),
                    array('status' => 403)
                );
            }
            
            // Check if plugin is active
            if (get_option('lazychat_plugin_active') !== 'Yes') {
                return new WP_Error(
                    'rest_forbidden',
                    __('Plugin is not active.', 'lazychat'),
                    array('status' => 403)
                );
            }
            
            return true;
        }
        
        // No authentication provided
        return new WP_Error(
            'rest_forbidden',
            __('Authentication required. Please provide either Bearer Token or WooCommerce Consumer Key/Secret.', 'lazychat'),
            array('status' => 401)
        );
    }
    
    /**
     * Authenticate using WooCommerce Consumer Key and Secret
     */
    private function authenticate_woocommerce($consumer_key, $consumer_secret) {
        global $wpdb;
        
        // Get WooCommerce API keys from database
        $key = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT key_id, user_id, permissions, consumer_key, consumer_secret, nonces 
                FROM {$wpdb->prefix}woocommerce_api_keys 
                WHERE consumer_key = %s",
                wc_api_hash($consumer_key)
            )
        );
        
        // Check if key exists
        if (empty($key)) {
            return new WP_Error(
                'woocommerce_rest_authentication_error',
                __('Consumer key is invalid.', 'lazychat'),
                array('status' => 401)
            );
        }
        
        // Validate consumer secret
        if (!hash_equals($key->consumer_secret, $consumer_secret)) {
            return new WP_Error(
                'woocommerce_rest_authentication_error',
                __('Consumer secret is invalid.', 'lazychat'),
                array('status' => 401)
            );
        }
        
        // Check if key has read or read/write permissions
        if (!in_array($key->permissions, array('read', 'write', 'read_write'))) {
            return new WP_Error(
                'woocommerce_rest_authentication_error',
                __('API key does not have read permissions.', 'lazychat'),
                array('status' => 403)
            );
        }
        
        // Check if plugin is active
        if (get_option('lazychat_plugin_active') !== 'Yes') {
            return new WP_Error(
                'rest_forbidden',
                __('Plugin is not active.', 'lazychat'),
                array('status' => 403)
            );
        }
        
        return true;
    }
    
    /**
     * Get products with pagination
     */
    public function get_products($request) {
        $body = $request->get_json_params();
        if (empty($body)) {
            $body = array();
        }
        
        $args = array(
            'page' => isset($body['page']) ? absint($body['page']) : 1,
            'per_page' => isset($body['per_page']) ? absint($body['per_page']) : 10,
            'status' => isset($body['status']) ? sanitize_text_field($body['status']) : 'publish',
            'type' => isset($body['type']) ? sanitize_text_field($body['type']) : ''
        );
        
        // Get products from controller
        $result = LazyChat_Product_Controller::get_products($args);
        
        return rest_ensure_response($result);
    }
    
    /**
     * Get single product
     */
    public function get_product($request) {
        $body = $request->get_json_params();
        
        if (empty($body) || !isset($body['product_id'])) {
            return new WP_Error(
                'missing_product_id',
                __('Product ID is required.', 'lazychat'),
                array('status' => 400)
            );
        }
        
        $product_id = absint($body['product_id']);
        
        // Get product from controller
        $product = LazyChat_Product_Controller::get_product($product_id);
        
        // Check if product retrieval failed
        if (is_wp_error($product)) {
            return $product;
        }
        
        return rest_ensure_response($product);
    }
    
    /**
     * Create a new order
     */
    public function create_order($request) {
        // Get JSON body
        $body = $request->get_json_params();
        
        if (empty($body)) {
            return new WP_Error(
                'rest_invalid_request',
                __('Invalid request body.', 'lazychat'),
                array('status' => 400)
            );
        }
        
        // Use the order controller class to create the order
        $order = LazyChat_Order_Controller::create_order($body);
        
        // Check if order creation failed
        if (is_wp_error($order)) {
            return $order;
        }
        
        // Prepare and return response
        $response_data = LazyChat_Order_Controller::prepare_order_response($order);
        
        return rest_ensure_response($response_data);
    }
    
    /**
     * List orders with pagination
     */
    public function list_orders($request) {
        $body = $request->get_json_params();
        if (empty($body)) {
            $body = array();
        }
        
        $page = isset($body['page']) ? absint($body['page']) : 1;
        $per_page = isset($body['per_page']) ? absint($body['per_page']) : 10;
        $status = isset($body['status']) ? sanitize_text_field($body['status']) : 'any';
        $orderby = isset($body['orderby']) ? sanitize_text_field($body['orderby']) : 'date';
        $order = isset($body['order']) ? sanitize_text_field($body['order']) : 'DESC';
        
        // Build query args
        $args = array(
            'limit' => $per_page,
            'page' => $page,
            'status' => $status,
            'orderby' => $orderby,
            'order' => $order,
        );
        
        // Get orders from controller
        $result = LazyChat_Order_Controller::get_orders($args);
        
        return rest_ensure_response($result);
    }
    
    /**
     * Get single order details
     */
    public function get_order($request) {
        $body = $request->get_json_params();
        
        if (empty($body) || !isset($body['order_id'])) {
            return new WP_Error(
                'missing_order_id',
                __('Order ID is required.', 'lazychat'),
                array('status' => 400)
            );
        }
        
        $order_id = absint($body['order_id']);
        
        // Get order from controller
        $order = LazyChat_Order_Controller::get_order($order_id);
        
        // Check if order retrieval failed
        if (is_wp_error($order)) {
            return $order;
        }
        
        // Prepare and return response
        $response_data = LazyChat_Order_Controller::prepare_order_response($order);
        
        return rest_ensure_response($response_data);
    }
    
    /**
     * Update order
     */
    public function update_order($request) {
        $data = $request->get_json_params();
        
        // Check if order_id is provided
        if (!isset($data['order_id'])) {
            return new WP_Error(
                'missing_order_id',
                __('Order ID is required.', 'lazychat'),
                array('status' => 400)
            );
        }
        
        $order_id = absint($data['order_id']);
        
        // Update order using controller
        $order = LazyChat_Order_Controller::update_order($order_id, $data);
        
        // Check if update failed
        if (is_wp_error($order)) {
            return $order;
        }
        
        // Prepare and return response
        $response_data = LazyChat_Order_Controller::prepare_order_response($order);
        
        return rest_ensure_response($response_data);
    }
    
    /**
     * Create a new customer
     */
    public function create_customer($request) {
        $data = $request->get_json_params();
        
        if (empty($data)) {
            $data = $request->get_body_params();
        }
        
        // Create customer using controller
        $customer = LazyChat_Customer_Controller::create_customer($data);
        
        // Check if customer creation failed
        if (is_wp_error($customer)) {
            return $customer;
        }
        
        return rest_ensure_response($customer);
    }
    
    /**
     * Get customer by ID
     */
    public function get_customer($request) {
        $body = $request->get_json_params();
        
        if (empty($body) || !isset($body['customer_id'])) {
            return new WP_Error(
                'missing_customer_id',
                __('Customer ID is required.', 'lazychat'),
                array('status' => 400)
            );
        }
        
        $customer_id = absint($body['customer_id']);
        
        // Get customer from controller
        $customer = LazyChat_Customer_Controller::get_customer($customer_id);
        
        // Check if customer retrieval failed
        if (is_wp_error($customer)) {
            return $customer;
        }
        
        return rest_ensure_response($customer);
    }
    
    /**
     * Get all available order statuses/phases
     */
    public function get_order_statuses($request) {
        // Get all WooCommerce order statuses
        $statuses = wc_get_order_statuses();
        
        // Get default order status
        $default_status = apply_filters('woocommerce_default_order_status', 'pending');
        
        // Format statuses for API response
        $formatted_statuses = array();
        foreach ($statuses as $slug => $name) {
            // Remove 'wc-' prefix from slug
            $clean_slug = 'wc-' === substr($slug, 0, 3) ? substr($slug, 3) : $slug;
            $formatted_statuses[] = array(
                'slug' => $clean_slug,
                'name' => $name,
                'full_slug' => $slug,
                'is_default' => ($clean_slug === $default_status)
            );
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'statuses' => $formatted_statuses,
            'total' => count($formatted_statuses)
        ));
    }
    
    /**
     * Test connection endpoint
     */
    public function test_connection($request) {
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Connection successful',
            'timestamp' => current_time('mysql'),
            'wordpress_version' => get_bloginfo('version'),
            'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : 'Not installed',
            'plugin_version' => LAZYCHAT_VERSION,
        ));
    }
}
