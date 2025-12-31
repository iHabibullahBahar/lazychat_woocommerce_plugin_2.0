<?php
/**
 * LazyChat AJAX Handlers
 * Handles AJAX requests for connection testing and webhook testing
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class LazyChat_Ajax_Handlers {
    
    /**
     * Log error to LazyChat API and error_log
     * Wrapper for the shared error logger
     */
    private function log_error($message, $data = null, $event_type = 'error') {
        LazyChat_Error_Logger::log_error($message, $data, $event_type);
    }
    
    public function __construct() {
        add_action('wp_ajax_lazychat_test_connection', array($this, 'test_connection'));
        add_action('wp_ajax_lazychat_check_connection', array($this, 'check_connection'));
        add_action('wp_ajax_lazychat_toggle_plugin', array($this, 'toggle_plugin'));
        add_action('wp_ajax_lazychat_login', array($this, 'login'));
        add_action('wp_ajax_lazychat_select_shop', array($this, 'select_shop'));
        add_action('wp_ajax_lazychat_generate_wc_api_keys', array($this, 'generate_wc_api_keys'));
        add_action('wp_ajax_lazychat_logout', array($this, 'logout'));
        add_action('wp_ajax_lazychat_disconnect', array($this, 'disconnect'));
        add_action('wp_ajax_lazychat_sync_products', array($this, 'sync_products'));
        add_action('wp_ajax_lazychat_sync_progress', array($this, 'sync_progress'));
        add_action('wp_ajax_lazychat_contact', array($this, 'contact'));
        add_action('wp_ajax_lazychat_fix_rest_api', array($this, 'fix_rest_api'));
        add_action('wp_ajax_lazychat_test_rest_api', array($this, 'test_rest_api'));
        add_action('wp_ajax_lazychat_save_webhook_setting', array($this, 'save_webhook_setting'));
    }
    
    /**
     * Logout LazyChat and clear saved tokens
     */
    public function logout() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'lazychat_nonce')) {
            $this->log_error('Logout failed: Security check failed (nonce verification)', array('action' => 'logout'), 'logout.error');
            wp_send_json_error(array('message' => __('Security check failed.', 'lazychat')));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            $this->log_error('Logout failed: Insufficient permissions', array('user_id' => get_current_user_id(), 'action' => 'logout'), 'logout.error');
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'lazychat')));
            return;
        }

        // Clear stored options
        update_option('lazychat_bearer_token', '');
        update_option('lazychat_plugin_active', 'No');
        update_option('lazychat_selected_shop_id', '');
        update_option('lazychat_selected_shop_name', '');
        update_option('lazychat_wc_consumer_key', '');
        update_option('lazychat_wc_consumer_secret', '');
        update_option('lazychat_wc_last_access', '');

        wp_send_json_success(array(
            'message' => __('✅ Logged out from LazyChat successfully.', 'lazychat'),
        ));
    }

    /**
     * Disconnect from LazyChat API
     */
    public function disconnect() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'lazychat_nonce')) {
            $this->log_error('Disconnect failed: Security check failed (nonce verification)', array('action' => 'disconnect'), 'disconnect.error');
            wp_send_json_error(array('message' => __('Security check failed.', 'lazychat')));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            $this->log_error('Disconnect failed: Insufficient permissions', array('user_id' => get_current_user_id(), 'action' => 'disconnect'), 'disconnect.error');
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'lazychat')));
            return;
        }

        $bearer_token = get_option('lazychat_bearer_token');
        if (empty($bearer_token)) {
            $this->log_error('Disconnect: Bearer token missing (already disconnected)', array('action' => 'disconnect'), 'disconnect.info');
            wp_send_json_error(array('message' => __('Bearer token is missing. Already disconnected.', 'lazychat')));
            return;
        }

        $delete_all = isset($_POST['delete_all']) ? (int) $_POST['delete_all'] : 0;
        $delete_all = $delete_all === 1 ? 1 : 0;

        $endpoint = 'https://app.lazychat.io/api/woocommerce-plugin/disconnect';
        $body = array(
            'delete_all' => $delete_all,
        );

        $response = wp_remote_post($endpoint, array(
            'method' => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer ' . $bearer_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'body' => wp_json_encode($body),
            'timeout' => 60,
            'sslverify' => true,
            'data_format' => 'body',
        ));

        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            $this->log_error('Disconnect API failed', array('error' => $error_msg, 'delete_all' => $delete_all), 'disconnect.error');
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %s: error message from the API */
                    __('Failed to disconnect from LazyChat: %s', 'lazychat'),
                    $error_msg
                ),
            ));
            return;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging
            error_log('[LazyChat] Disconnect HTTP ' . $status_code . ' response: ' . substr($response_body, 0, 500));
        }

        if ($status_code >= 200 && $status_code < 300) {
            $message = $delete_all 
                ? __('✅ Disconnected from LazyChat and all products deleted successfully.', 'lazychat')
                : __('✅ Disconnected from LazyChat successfully.', 'lazychat');
            
            if (isset($data['message']) && !empty($data['message'])) {
                $message = $data['message'];
            }

            wp_send_json_success(array(
                'message' => $message,
            ));
        } else {
            $error_message = isset($data['message']) ? $data['message'] : $response_body;
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: 1: HTTP status code, 2: error message */
                    __('LazyChat disconnect failed (HTTP %1$d): %2$s', 'lazychat'),
                    $status_code,
                    esc_html(substr($error_message, 0, 200))
                ),
            ));
        }
    }

    /**
     * Handle LazyChat login to retrieve bearer token
     */
    public function login() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'lazychat_nonce')) {
            $this->log_error('Login failed: Security check failed (nonce verification)', array('action' => 'login'), 'login.error');
            wp_send_json_error(array('message' => __('Security check failed.', 'lazychat')));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            $this->log_error('Login failed: Insufficient permissions', array('user_id' => get_current_user_id(), 'action' => 'login'), 'login.error');
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'lazychat')));
            return;
        }

        $email    = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $password = isset($_POST['password']) ? sanitize_text_field(wp_unslash($_POST['password'])) : '';


        $api_url = 'https://app.lazychat.io/api/woocommerce-plugin/login';

        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body'    => wp_json_encode(array(
                'email'    => $email,
                'password' => $password,
                'site_url' => home_url(),
            )),
            'timeout' => 20,
            'method'  => 'POST',
        );

        $response = wp_remote_post($api_url, $args);

        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            $this->log_error('Login API request failed', array('error' => $error_msg, 'email' => $email), 'login.error');
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %s: error message */
                    __('Login failed: %s', 'lazychat'),
                    $error_msg
                ),
            ));
            return;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code !== 200) {
            // Try to parse JSON response to extract error message
            $error_message = __('Login failed. Please check your credentials and try again.', 'lazychat');
            $api_error_message = null;
            $data = json_decode($response_body, true);
            
            if (is_array($data) && isset($data['message']) && !empty($data['message'])) {
                $api_error_message = sanitize_text_field($data['message']);
                $error_message = $api_error_message;
            } elseif ($response_code === 401) {
                $error_message = __('Invalid email or password. Please try again.', 'lazychat');
            } elseif ($response_code === 422) {
                $error_message = __('Invalid credentials provided. Please check your email and password.', 'lazychat');
            }
            
            // Log error with API error message if available
            $log_message = 'Login API returned error status';
            if ($api_error_message) {
                $log_message .= ': ' . $api_error_message;
            } else {
                $log_message .= ' (HTTP ' . $response_code . ')';
            }
            
            $this->log_error($log_message, array('http_code' => $response_code, 'response' => substr($response_body, 0, 500), 'email' => $email), 'login.error');
            wp_send_json_error(array(
                'message' => $error_message,
            ));
            return;
        }

        $data = json_decode($response_body, true);

        if (!is_array($data) || empty($data['status']) || $data['status'] === 'error') {
            $this->log_error('Login API returned invalid response', array('response_code' => $response_code, 'response_body' => substr($response_body, 0, 500), 'email' => $email), 'login.error');
            wp_send_json_error(array(
                'message' => __('Login response was invalid. Please try again.', 'lazychat'),
            ));
            return;
        }

        $response_message = !empty($data['message']) ? wp_strip_all_tags($data['message']) : __('Login successful.', 'lazychat');
        $payload          = isset($data['data']) && is_array($data['data']) ? $data['data'] : array();
        $shops            = isset($payload['shops']) && is_array($payload['shops']) ? $payload['shops'] : array();

        if (empty($shops)) {
            $this->log_error('Login succeeded but no shops returned', array('email' => $email, 'response_code' => $response_code), 'login.error');
            wp_send_json_error(array(
                'message' => __('No shops were returned for this account. Please verify your LazyChat account setup.', 'lazychat'),
            ));
            return;
        }

        $default_shop_id = isset($payload['default_shop_id']) ? sanitize_text_field((string) $payload['default_shop_id']) : '';

        $sanitized_shops = array();
        foreach ($shops as $shop) {
            if (!is_array($shop)) {
                continue;
            }

            $shop_id    = isset($shop['id']) ? sanitize_text_field((string) $shop['id']) : '';
            $shop_name  = isset($shop['name']) ? sanitize_text_field($shop['name']) : '';
            $auth_token = isset($shop['auth_token']) ? sanitize_textarea_field($shop['auth_token']) : '';
            $is_active  = !empty($shop['is_active']) ? true : false;

            if (empty($shop_id) || empty($auth_token)) {
                continue;
            }

            $sanitized_shops[] = array(
                'id'         => $shop_id,
                'name'       => $shop_name,
                'auth_token' => $auth_token,
                'is_active'  => $is_active,
            );
        }

        if (empty($sanitized_shops)) {
            $this->log_error('Login failed: Unable to parse shop data', array('email' => $email, 'shops_count' => count($shops)), 'login.error');
            wp_send_json_error(array(
                'message' => __('Unable to parse shop data from LazyChat response.', 'lazychat'),
            ));
            return;
        }

        wp_send_json_success(array(
            'message'                   => $response_message,
            'requires_shop_selection'   => true,
            'default_shop_id'           => $default_shop_id,
            'shops'                     => $sanitized_shops,
        ));
    }

    /**
     * Handle shop selection to persist bearer token
     */
    public function select_shop() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'lazychat_nonce')) {
            $this->log_error('Select shop failed: Security check failed (nonce verification)', array('action' => 'select_shop'), 'select_shop.error');
            wp_send_json_error(array('message' => __('Security check failed.', 'lazychat')));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            $this->log_error('Select shop failed: Insufficient permissions', array('user_id' => get_current_user_id(), 'action' => 'select_shop'), 'select_shop.error');
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'lazychat')));
            return;
        }

        $shop_id    = isset($_POST['shop_id']) ? sanitize_text_field(wp_unslash($_POST['shop_id'])) : '';
        $shop_name  = isset($_POST['shop_name']) ? sanitize_text_field(wp_unslash($_POST['shop_name'])) : '';
        $auth_token = isset($_POST['auth_token']) ? sanitize_textarea_field(wp_unslash($_POST['auth_token'])) : '';

        if (empty($shop_id) || empty($auth_token)) {
            $this->log_error('Select shop failed: Invalid shop data', array('has_shop_id' => !empty($shop_id), 'has_auth_token' => !empty($auth_token)), 'select_shop.error');
            wp_send_json_error(array('message' => __('Shop selection is invalid. Please try again.', 'lazychat')));
            return;
        }

        update_option('lazychat_bearer_token', $auth_token);
        update_option('lazychat_selected_shop_id', $shop_id);
        update_option('lazychat_selected_shop_name', $shop_name);
        update_option('lazychat_plugin_active', 'Yes'); // Auto-activate when shop is connected

        $credentials = $this->create_wc_api_credentials();

        if (is_wp_error($credentials)) {
            $this->log_error('Select shop failed: WooCommerce API key generation failed', array('shop_id' => $shop_id, 'error' => $credentials->get_error_message(), 'error_code' => $credentials->get_error_code()), 'select_shop.error');
            wp_send_json_error(array('message' => $credentials->get_error_message()));
            return;
        }

        $api_registration = $this->register_lazychat_store($credentials);

        $success_message = __('✅ Shop connected successfully and token saved.', 'lazychat');
        if (!empty($shop_name)) {
            /* translators: %s: shop name */
            $success_message = sprintf(__('✅ %s connected successfully and token saved.', 'lazychat'), $shop_name);
        }

        wp_send_json_success(array(
            'message'   => $success_message,
            'shop_id'   => $shop_id,
            'shop_name' => $shop_name,
            'api_connection' => $api_registration,
        ));
    }

    /**
     * Test connection to LazyChat API
     */
    public function test_connection() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'lazychat_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'lazychat')));
            return;
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'lazychat')));
            return;
        }
        
        $api_url = 'https://app.lazychat.io/api/woocommerce-plugin';
        $bearer_token = isset($_POST['bearer_token']) ? sanitize_text_field($_POST['bearer_token']) : '';
        
        if (empty($bearer_token)) {
            wp_send_json_error(array('message' => __('Bearer Token is required.', 'lazychat')));
            return;
        }
        
        // Test connection to /test endpoint
        $test_url = rtrim($api_url, '/') . '/test';
        
        $headers = array(
            'Authorization' => 'Bearer ' . $bearer_token,
            'Content-Type' => 'application/json',
            'X-Webhook-Event' => 'test'
        );
        
        $args = array(
            'headers' => $headers,
            'timeout' => 15,
            'method' => 'GET'
        );
        
        $response = wp_remote_request($test_url, $args);
        
        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %s: error message */
                    __('Connection failed: %s', 'lazychat'),
                    $response->get_error_message()
                )
            ));
            return;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code === 200) {
            $data = json_decode($response_body, true);
            if (isset($data['status']) && $data['status']) {
                wp_send_json_success(array(
                    'message' => sprintf(
                        /* translators: %s: API URL */
                        __('✅ Connection successful!<br><strong>URL:</strong> %s<br><strong>Response:</strong> API is responding correctly.', 'lazychat'),
                        esc_html($test_url)
                    )
                ));
            } else {
                wp_send_json_error(array(
                    'message' => sprintf(
                        /* translators: 1: API URL, 2: error message */
                        __('❌ API responded with error<br><strong>URL:</strong> %1$s<br><strong>Error:</strong> %2$s', 'lazychat'),
                        esc_html($test_url),
                        isset($data['message']) ? esc_html($data['message']) : __('Unknown error', 'lazychat')
                    )
                ));
            }
        } else {
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: 1: API URL, 2: HTTP status code, 3: response body */
                    __('❌ Connection failed<br><strong>URL:</strong> %1$s<br><strong>HTTP Code:</strong> %2$d<br><strong>Response:</strong> %3$s', 'lazychat'),
                    esc_html($test_url),
                    $response_code,
                    esc_html($response_body)
                )
            ));
        }
    }
    
    /**
     * Check connection status from LazyChat dashboard
     */
    public function check_connection() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'lazychat_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'lazychat')));
            return;
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'lazychat')));
            return;
        }
        
        $bearer_token = get_option('lazychat_bearer_token');
        
        if (empty($bearer_token)) {
            wp_send_json_error(array(
                'message' => __('❌ Bearer Token is missing. Please enter your Bearer Token and save the settings.', 'lazychat')
            ));
            return;
        }
        
        // Call LazyChat API to check connection
        $api_url = 'https://app.lazychat.io/api/woocommerce-plugin/check-connection';
        
        $body_data = array(
            'shop_url' => home_url(),
            'woocommerce_version' => WC()->version,
            'wordpress_version' => get_bloginfo('version'),
            'bearer_token' => $bearer_token
        );
        
        $response = wp_remote_post($api_url, array(
            'method' => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer ' . $bearer_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => wp_json_encode($body_data),
            'timeout' => 30,
            'sslverify' => true,
            'data_format' => 'body'
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: 1: error message, 2: API URL */
                    __('❌ Connection failed<br><strong>Error:</strong> %1$s<br><strong>URL:</strong> %2$s', 'lazychat'),
                    esc_html($error_message),
                    esc_html($api_url)
                ),
                'debug' => array(
                    'url' => $api_url,
                    'error' => $error_message
                )
            ));
            return;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        // Log for debugging
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging
            error_log('LazyChat Check Connection Response: Code=' . $response_code . ', Body=' . $response_body);
        }
        
        if ($response_code === 200 && isset($data['status']) && $data['status'] === 'success') {
            $is_connected = isset($data['connected']) && $data['connected'] === true;
            
            if ($is_connected) {
                // Activate plugin when connected
                update_option('lazychat_plugin_active', 'Yes');
                
                wp_send_json_success(array(
                    'message' => sprintf(
                        /* translators: %s: API URL */
                        __('✅ Connection verified successfully!<br><strong>Status:</strong> Connected<br><strong>URL:</strong> %s<br><strong>Plugin:</strong> Now Active', 'lazychat'),
                        esc_html($api_url)
                    ),
                    'connected' => true,
                    'activated' => true
                ));
            } else {
                wp_send_json_success(array(
                    'message' => sprintf(
                        /* translators: %s: API URL */
                        __('⚠️ Connection check succeeded but WooCommerce is not connected<br><strong>URL:</strong> %s<br><strong>Action Required:</strong> Please connect WooCommerce from your LazyChat dashboard first.', 'lazychat'),
                        esc_html($api_url)
                    ),
                    'connected' => false,
                    'activated' => false
                ));
            }
        } else {
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: 1: API URL, 2: HTTP status code, 3: error message */
                    __('❌ Connection check failed<br><strong>URL:</strong> %1$s<br><strong>HTTP Code:</strong> %2$d<br><strong>Response:</strong> %3$s', 'lazychat'),
                    esc_html($api_url),
                    $response_code,
                    isset($data['message']) ? esc_html($data['message']) : esc_html(substr($response_body, 0, 200))
                ),
                'debug' => array(
                    'url' => $api_url,
                    'code' => $response_code,
                    'body' => $response_body,
                    'data' => $data
                )
            ));
        }
    }
    
    /**
     * Toggle plugin status (notify LazyChat server)
     */
    public function toggle_plugin() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'lazychat_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'lazychat')));
            return;
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'lazychat')));
            return;
        }
        
        $bearer_token = get_option('lazychat_bearer_token');
        $enabled = isset($_POST['enabled']) ? filter_var($_POST['enabled'], FILTER_VALIDATE_BOOLEAN) : false;
        
        if (empty($bearer_token)) {
            wp_send_json_error(array(
                'message' => __('❌ Bearer Token is missing. Please save your settings first.', 'lazychat')
            ));
            return;
        }
        
        // Call LazyChat API to update plugin status
        $api_url = 'https://app.lazychat.io/api/woocommerce-plugin/toggle-plugin';
        
        $body_data = array(
            'enabled' => $enabled
        );
        
        $response = wp_remote_post($api_url, array(
            'method' => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer ' . $bearer_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => wp_json_encode($body_data),
            'timeout' => 30,
            'sslverify' => true,
            'data_format' => 'body'
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging
                error_log('[LazyChat] Toggle Plugin Failed: ' . $error_message);
            }
            
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %s: error message */
                    __('❌ Failed to notify LazyChat server<br><strong>Error:</strong> %s', 'lazychat'),
                    esc_html($error_message)
                )
            ));
            return;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        // Log for debugging
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging
            error_log('[LazyChat] Toggle Plugin Response: Code=' . $response_code . ', Body=' . $response_body);
        }
        
        if ($response_code === 200 && isset($data['status']) && $data['status'] === 'success') {
            // Save the plugin active status to database
            update_option('lazychat_plugin_active', $enabled ? 'Yes' : 'No');
            
            wp_send_json_success(array(
                'message' => isset($data['message']) ? $data['message'] : __('Plugin status updated successfully.', 'lazychat'),
                'enabled' => isset($data['enabled']) ? $data['enabled'] : $enabled
            ));
        } else {
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: 1: HTTP status code, 2: error message */
                    __('❌ Failed to update plugin status<br><strong>HTTP Code:</strong> %1$d<br><strong>Response:</strong> %2$s', 'lazychat'),
                    $response_code,
                    isset($data['message']) ? esc_html($data['message']) : esc_html(substr($response_body, 0, 200))
                )
            ));
        }
    }

    /**
     * Generate WooCommerce REST API keys for LazyChat usage
     */
    public function generate_wc_api_keys() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'lazychat_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'lazychat')));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions to generate API keys.', 'lazychat')));
            return;
        }

        $credentials = $this->create_wc_api_credentials();

        if (is_wp_error($credentials)) {
            wp_send_json_error(array('message' => $credentials->get_error_message()));
            return;
        }

        $api_registration = $this->register_lazychat_store($credentials);

        wp_send_json_success(array(
            'message' => __('✅ WooCommerce API keys generated successfully. Make sure to copy them now, as the secret will be hidden later.', 'lazychat'),
            'consumer_key' => $credentials['consumer_key'],
            'consumer_secret' => $credentials['consumer_secret'],
            'description' => $credentials['description'],
            'user_id' => $credentials['user_id'],
            'user_email' => $credentials['user_email'],
            'last_access' => $credentials['last_access'],
            'api_connection' => $api_registration,
        ));
    }

    /**
     * Determine default user ID for API key generation.
     *
     * @return int
     */
    private function get_default_api_user_id() {
        $admin_email = get_option('admin_email');

        if (!empty($admin_email)) {
            $admin_user = get_user_by('email', $admin_email);
            if ($admin_user instanceof WP_User) {
                return (int) $admin_user->ID;
            }
        }

        $admin_users = get_users(array(
            'role__in' => array('administrator'),
            'orderby' => 'ID',
            'order' => 'ASC',
            'number' => 1,
        ));

        if (!empty($admin_users)) {
            $first_admin = reset($admin_users);
            if ($first_admin instanceof WP_User) {
                return (int) $first_admin->ID;
            }

            if (is_object($first_admin) && isset($first_admin->ID)) {
                return (int) $first_admin->ID;
            }

            if (is_numeric($first_admin)) {
                return (int) $first_admin;
            }
        }

        $current_user_id = get_current_user_id();

        return $current_user_id ? (int) $current_user_id : 0;
    }

    /**
     * Delete all existing LazyChat API keys from WooCommerce
     */
    private function delete_existing_lazychat_keys() {
        global $wpdb;

        // Search for keys with "LazyChat" or "Lazychat" in the description (case-insensitive)
        // Note: Table name is safe as it uses $wpdb->prefix for WooCommerce core table
        $deleted_count = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$wpdb->prefix}woocommerce_api_keys` WHERE description LIKE %s OR description LIKE %s",
                '%' . $wpdb->esc_like('LazyChat') . '%',
                '%' . $wpdb->esc_like('Lazychat') . '%'
            )
        );

        return $deleted_count !== false ? $deleted_count : 0;
    }

    /**
     * Generate LazyChat API key description with current date/time
     */
    private function get_lazychat_description_with_timestamp() {
        $datetime = current_time('Y-m-d H:i:s');
        return 'LazyChat - ' . $datetime;
    }

    /**
     * Sync products with LazyChat
     */
    public function sync_products() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'lazychat_nonce')) {
            $this->log_error('Sync products failed: Security check failed (nonce verification)', array('action' => 'sync_products'), 'sync_products.error');
            wp_send_json_error(array('message' => __('Security check failed.', 'lazychat')));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            $this->log_error('Sync products failed: Insufficient permissions', array('user_id' => get_current_user_id(), 'action' => 'sync_products'), 'sync_products.error');
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'lazychat')));
            return;
        }

        $bearer_token = get_option('lazychat_bearer_token');
        if (empty($bearer_token)) {
            $this->log_error('Sync products failed: Bearer token missing', array('action' => 'sync_products'), 'sync_products.error');
            wp_send_json_error(array('message' => __('Bearer token is missing. Please login first.', 'lazychat')));
            return;
        }

        $shop_id = get_option('lazychat_selected_shop_id');
        if (empty($shop_id)) {
            $this->log_error('Sync products failed: Shop ID missing', array('action' => 'sync_products'), 'sync_products.error');
            wp_send_json_error(array('message' => __('Shop ID is missing. Please reconnect your account.', 'lazychat')));
            return;
        }

        // Use LazyChat API endpoint
        $endpoint = 'https://app.lazychat.io/api/woocommerce-plugin/sync-products';

        $response = wp_remote_post($endpoint, array(
            'method' => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer ' . $bearer_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Lazychat-Shop-Id' => $shop_id,
                'X-Plugin-Version' => defined('LAZYCHAT_VERSION') ? LAZYCHAT_VERSION : '1.0.0',
            ),
            'timeout' => 600,
            'sslverify' => true,
            'data_format' => 'body',
        ));

        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            $this->log_error('Sync products API request failed', array('error' => $error_msg), 'sync_products.error');
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %s: error message */
                    __('Failed to sync products: %s', 'lazychat'),
                    $error_msg
                ),
            ));
            return;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Intentional debug logging
            error_log('[LazyChat] Sync Products HTTP ' . $status_code . ' response: ' . $response_body);
            error_log('[LazyChat] Sync Products Shop ID: ' . $shop_id . ', Bearer Token: ' . substr($bearer_token, 0, 10) . '...');
            error_log('[LazyChat] Sync Products Decoded Data: ' . print_r($data, true));
        }

        // Check if HTTP status is successful (200-299)
        if ($status_code >= 200 && $status_code < 300) {
            // AWS API might return different response structures, check for common success patterns
            $is_success = false;
            $message = __('✅ Product sync initiated successfully.', 'lazychat');
            
            // Check for 'status' = 'success' (LazyChat API format)
            if (isset($data['status']) && $data['status'] === 'success') {
                $is_success = true;
                if (isset($data['message']) && !empty($data['message'])) {
                    $message = $data['message'];
                }
            }
            // Check for direct 'success' boolean (some APIs use this)
            elseif (isset($data['success']) && $data['success'] === true) {
                $is_success = true;
                if (isset($data['message']) && !empty($data['message'])) {
                    $message = $data['message'];
                }
            }
            // Check for 'message' field with success indicators
            elseif (isset($data['message']) && (
                stripos($data['message'], 'success') !== false || 
                stripos($data['message'], 'completed') !== false ||
                stripos($data['message'], 'initiated') !== false
            )) {
                $is_success = true;
                $message = $data['message'];
            }
            // If HTTP 200 with valid JSON but no clear success indicator, treat as success
            elseif (is_array($data) && !empty($data)) {
                $is_success = true;
                if (isset($data['message'])) {
                    $message = $data['message'];
                }
            }
            // If HTTP 200 but empty or invalid response, still treat as success
            else {
                $is_success = true;
                $message = __('✅ Product sync request sent successfully.', 'lazychat');
            }

            if ($is_success) {
                if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging
                    error_log('[LazyChat] Sync Products: Success! Message: ' . $message);
                }
                wp_send_json_success(array(
                    'message' => $message,
                    'data' => isset($data['data']) ? $data['data'] : $data,
                ));
            } else {
                // Should not reach here, but handle it anyway
                if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging
                    error_log('[LazyChat] Sync Products: HTTP 200 but could not determine success');
                }
                wp_send_json_success(array(
                    'message' => __('✅ Product sync request sent.', 'lazychat'),
                    'data' => $data,
                ));
            }
        } else {
            // HTTP error status (400+, 500+)
            $error_message = isset($data['message']) ? $data['message'] : $response_body;
            $this->log_error('Sync products API returned error status', array('http_code' => $status_code, 'response' => substr($response_body, 0, 500), 'shop_id' => $shop_id), 'sync_products.error');
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: 1: HTTP status code, 2: error message */
                    __('Product sync failed (HTTP %1$d): %2$s', 'lazychat'),
                    $status_code,
                    esc_html(substr($error_message, 0, 200))
                ),
            ));
        }
    }

    /**
     * Get sync progress from LazyChat
     */
    public function sync_progress() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'lazychat_nonce')) {
            $this->log_error('Sync progress failed: Security check failed (nonce verification)', array('action' => 'sync_progress'), 'sync_progress.error');
            wp_send_json_error(array('message' => __('Security check failed.', 'lazychat')));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            $this->log_error('Sync progress failed: Insufficient permissions', array('user_id' => get_current_user_id(), 'action' => 'sync_progress'), 'sync_progress.error');
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'lazychat')));
            return;
        }

        $bearer_token = get_option('lazychat_bearer_token');
        if (empty($bearer_token)) {
            $this->log_error('Sync progress failed: Bearer token missing', array('action' => 'sync_progress'), 'sync_progress.error');
            wp_send_json_error(array('message' => __('Bearer token is missing. Please login first.', 'lazychat')));
            return;
        }

        $shop_id = get_option('lazychat_selected_shop_id');
        if (empty($shop_id)) {
            $this->log_error('Sync progress failed: Shop ID missing', array('action' => 'sync_progress'), 'sync_progress.error');
            wp_send_json_error(array('message' => __('Shop ID is missing. Please reconnect your account.', 'lazychat')));
            return;
        }

        // Use LazyChat base URL for progress endpoint
        $endpoint = 'https://app.lazychat.io/api/woocommerce-plugin/products/sync-progress';

        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging
            error_log('[LazyChat] Fetching sync progress from: ' . $endpoint . ' with Bearer Token: ' . substr($bearer_token, 0, 10) . '...');
        }

        $response = wp_remote_post($endpoint, array(
            'method' => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer ' . $bearer_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'timeout' => 30,
            'sslverify' => true,
            'body' => json_encode(array()),
        ));

        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            $this->log_error('Sync progress API request failed', array('error' => $error_msg), 'sync_progress.error');
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %s: error message */
                    __('Failed to get sync progress: %s', 'lazychat'),
                    $error_msg
                ),
            ));
            return;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if ($status_code >= 200 && $status_code < 300 && isset($data['status']) && $data['status'] === 'success') {
            wp_send_json_success($data['data']);
        } else {
            $error_message = isset($data['message']) ? $data['message'] : $response_body;
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: 1: HTTP status code, 2: error message */
                    __('Failed to get sync progress (HTTP %1$d): %2$s', 'lazychat'),
                    $status_code,
                    esc_html(substr($error_message, 0, 200))
                ),
            ));
        }
    }

    /**
     * Notify LazyChat API about WooCommerce store credentials.
     *
     * @return array
     */
    private function register_lazychat_store($credentials = null) {
        if (is_array($credentials) && isset($credentials['consumer_key'], $credentials['consumer_secret'])) {
            $consumer_key = $credentials['consumer_key'];
            $consumer_secret = $credentials['consumer_secret'];
        } else {
            $consumer_key = get_option('lazychat_wc_consumer_key');
            $consumer_secret = get_option('lazychat_wc_consumer_secret');
        }

        if (empty($consumer_key) || empty($consumer_secret)) {
            return array(
                'success' => false,
                'message' => __('WooCommerce API credentials were not found. Please generate new API keys.', 'lazychat'),
            );
        }

        $bearer_token = get_option('lazychat_bearer_token');

        if (empty($bearer_token)) {
            return array(
                'success' => false,
                'message' => __('Bearer token is missing. Please reconnect LazyChat.', 'lazychat'),
            );
        }

        $endpoint = 'https://app.lazychat.io/api/woocommerce-plugin/store';
        $body = array(
            'woocommerce_store_url' => home_url(),
            'woocommerce_consumer_key' => $consumer_key,
            'woocommerce_consumer_secret' => $consumer_secret,
            'plugin_version' => defined('LAZYCHAT_VERSION') ? LAZYCHAT_VERSION : '1.0.0',
        );

        $response = wp_remote_post($endpoint, array(
            'method' => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer ' . $bearer_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'body' => wp_json_encode($body),
            'timeout' => 30,
            'sslverify' => true,
            'data_format' => 'body',
        ));

        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging
                error_log('[LazyChat] Store registration failed: ' . $response->get_error_message());
            }
            return array(
                'success' => false,
                'message' => sprintf(
                    /* translators: %s: error message */
                    __('Failed to register store with LazyChat: %s', 'lazychat'),
                    $response->get_error_message()
                ),
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging
            error_log('[LazyChat] Store registration HTTP ' . $status_code . ' response: ' . substr($response_body, 0, 500));
        }

        if ($status_code >= 200 && $status_code < 300 && isset($data['status']) && $data['status'] === 'success') {
            return array(
                'success' => true,
                'message' => isset($data['message']) ? $data['message'] : __('Store connected to LazyChat successfully.', 'lazychat'),
            );
        }

        $error_message = isset($data['message']) ? $data['message'] : $response_body;

        return array(
            'success' => false,
            'message' => sprintf(
                /* translators: 1: HTTP status code, 2: error message */
                __('LazyChat store registration failed (HTTP %1$d): %2$s', 'lazychat'),
                $status_code,
                $error_message
            ),
        );
    }
    /**
     * Create WooCommerce REST API credentials using default LazyChat mapping.
     *
     * @return array|\WP_Error
     */
    private function create_wc_api_credentials() {
        if (!function_exists('wc_rand_hash') || !function_exists('wc_api_hash')) {
            return new WP_Error('lazychat_wc_missing', __('WooCommerce functions are unavailable. Please ensure WooCommerce is active.', 'lazychat'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'woocommerce_api_keys';

        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        if ($table_exists !== $table_name) {
            return new WP_Error('lazychat_wc_table_missing', __('WooCommerce API keys table was not found. Please verify your WooCommerce installation.', 'lazychat'));
        }

        // Delete all existing LazyChat API keys before creating a new one
        $deleted_count = $this->delete_existing_lazychat_keys();
        if ($deleted_count > 0 && defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging
            error_log('[LazyChat] Deleted ' . $deleted_count . ' existing LazyChat API key(s) before creating new one.');
        }

        $user_id = $this->get_default_api_user_id();
        if (!$user_id) {
            return new WP_Error('lazychat_no_user', __('Unable to determine a default user for API key generation.', 'lazychat'));
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error('lazychat_user_missing', __('The default user for API key generation could not be found.', 'lazychat'));
        }

        if (!current_user_can('edit_user', $user_id) && get_current_user_id() !== (int) $user_id) {
            return new WP_Error('lazychat_user_permission', __('You do not have permission to assign API keys to the default user.', 'lazychat'));
        }

        // Generate description with current date/time
        $description = $this->get_lazychat_description_with_timestamp();
        $last_access = current_time('mysql');
        $consumer_key = 'ck_' . wc_rand_hash();
        $consumer_secret = 'cs_' . wc_rand_hash();

        $data = array(
            'user_id' => $user_id,
            'description' => $description,
            'permissions' => 'read_write',
            'consumer_key' => wc_api_hash($consumer_key),
            'consumer_secret' => $consumer_secret,
            'truncated_key' => substr($consumer_key, -7),
            'last_access' => $last_access,
        );

        $formats = array('%d', '%s', '%s', '%s', '%s', '%s', '%s');

        $inserted = $wpdb->insert($table_name, $data, $formats);

        if (false === $inserted || 0 === (int) $wpdb->insert_id) {
            return new WP_Error('lazychat_wc_insert_error', __('There was an error generating the WooCommerce API keys. Please try again.', 'lazychat'));
        }

        update_option('lazychat_wc_consumer_key', $consumer_key);
        update_option('lazychat_wc_consumer_secret', $consumer_secret);
        update_option('lazychat_wc_last_access', $last_access);

        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging
            error_log('[LazyChat] Created new WooCommerce API key: ' . $description);
        }

        return array(
            'consumer_key' => $consumer_key,
            'consumer_secret' => $consumer_secret,
            'description' => $description,
            'last_access' => $last_access,
            'user_id' => $user_id,
            'user_email' => $user->user_email,
        );
    }

    /**
     * Get contact information from LazyChat API
     */
    public function contact() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'lazychat_nonce')) {
            $this->log_error('Contact API failed: Security check failed (nonce verification)', array('action' => 'contact'), 'contact.error');
            wp_send_json_error(array('message' => __('Security check failed.', 'lazychat')));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            $this->log_error('Contact API failed: Insufficient permissions', array('user_id' => get_current_user_id(), 'action' => 'contact'), 'contact.error');
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'lazychat')));
            return;
        }

        $endpoint = 'https://app.lazychat.io/api/woocommerce-plugin/contact';

        $response = wp_remote_post($endpoint, array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'timeout' => 30,
            'sslverify' => true,
            'data_format' => 'body',
        ));

        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            $this->log_error('Contact API request failed', array('error' => $error_msg), 'contact.error');
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %s: error message */
                    __('Failed to retrieve contact information: %s', 'lazychat'),
                    $error_msg
                ),
            ));
            return;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if ($status_code >= 200 && $status_code < 300) {
            if (isset($data['status']) && $data['status'] === 'success' && isset($data['data'])) {
                wp_send_json_success($data['data']);
            } else {
                $this->log_error('Contact API returned invalid response format', array('http_code' => $status_code, 'response' => substr($response_body, 0, 500)), 'contact.error');
                wp_send_json_error(array(
                    'message' => __('Invalid response format from contact API.', 'lazychat'),
                ));
            }
        } else {
            $error_message = isset($data['message']) ? $data['message'] : $response_body;
            $this->log_error('Contact API returned error status', array('http_code' => $status_code, 'error' => substr($error_message, 0, 500)), 'contact.error');
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: 1: HTTP status code, 2: error message */
                    __('Contact API failed (HTTP %1$d): %2$s', 'lazychat'),
                    $status_code,
                    esc_html(substr($error_message, 0, 200))
                ),
            ));
        }
    }
    
    /**
     * Test REST API accessibility
     */
    public function test_rest_api() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'lazychat_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'lazychat')));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'lazychat')));
            return;
        }
        
        // Clear cache and dismissal flag before testing to get fresh results
        delete_transient('lazychat_rest_api_check');
        delete_option('lazychat_rest_api_notice_dismissed');
        
        // Send event notification
        if (function_exists('lazychat_send_event_notification')) {
            lazychat_send_event_notification('diagnostic.rest_api_test', array(
                'user_id' => get_current_user_id(),
                'user_email' => wp_get_current_user()->user_email,
                'test_time' => current_time('mysql')
            ));
        }

        // Test 1: Check if REST API root is accessible
        $rest_url = rest_url();
        $response = wp_remote_get($rest_url, array(
            'timeout' => 10,
            'sslverify' => false
        ));
        
        $rest_api_working = false;
        $rest_api_message = '';
        
        if (is_wp_error($response)) {
            $rest_api_message = __('WordPress REST API is not accessible: ', 'lazychat') . $response->get_error_message();
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            if (in_array($response_code, array(200, 401))) {
                $rest_api_working = true;
                $rest_api_message = __('WordPress REST API is working correctly.', 'lazychat');
            } else {
                /* translators: %d: HTTP status code */
                $rest_api_message = sprintf(__('WordPress REST API returned unexpected status code: %d', 'lazychat'), $response_code);
            }
        }

        // Test 2: Check if LazyChat REST API endpoints are registered
        $lazychat_endpoint = rest_url('lazychat/v1/test-connection');
        $lazychat_response = wp_remote_post($lazychat_endpoint, array(
            'timeout' => 10,
            'sslverify' => false,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'bearer_token' => get_option('lazychat_bearer_token', '')
            ))
        ));
        
        $lazychat_endpoint_working = false;
        $lazychat_endpoint_message = '';
        
        if (is_wp_error($lazychat_response)) {
            $lazychat_endpoint_message = __('LazyChat REST API endpoint is not accessible: ', 'lazychat') . $lazychat_response->get_error_message();
        } else {
            $lazychat_response_code = wp_remote_retrieve_response_code($lazychat_response);
            // 200 (success), 401 (unauthorized), 403 (forbidden) all mean the endpoint exists
            if (in_array($lazychat_response_code, array(200, 401, 403))) {
                $lazychat_endpoint_working = true;
                $lazychat_endpoint_message = __('LazyChat REST API endpoints are properly registered.', 'lazychat');
            } else {
                /* translators: %d: HTTP status code */
                $lazychat_endpoint_message = sprintf(__('LazyChat REST API endpoint returned status code: %d', 'lazychat'), $lazychat_response_code);
            }
        }

        // Test 3: Check permalink structure
        $permalink_structure = get_option('permalink_structure');
        $permalink_ok = !empty($permalink_structure);
        $permalink_message = $permalink_ok 
            ? __('Permalink structure is configured correctly.', 'lazychat')
            : __('Permalink structure is set to "Plain" which prevents REST API from working. Please change it in Settings > Permalinks.', 'lazychat');

        // Overall status
        $all_ok = $rest_api_working && $lazychat_endpoint_working && $permalink_ok;
        
        // Cache the new result (including permalink check)
        // Dashboard notice should reflect the overall status including permalinks
        if ($all_ok) {
            set_transient('lazychat_rest_api_check', 'working', LAZYCHAT_REST_API_CHECK_CACHE_DURATION);
        } else {
            set_transient('lazychat_rest_api_check', 'not_working', LAZYCHAT_REST_API_CHECK_CACHE_DURATION);
        }

        wp_send_json_success(array(
            'overall_status' => $all_ok,
            
            'tests' => array(
                'rest_api' => array(
                    'status' => $rest_api_working,
                    'message' => $rest_api_message,
                    'url' => $rest_url
                ),
                'lazychat_endpoint' => array(
                    'status' => $lazychat_endpoint_working,
                    'message' => $lazychat_endpoint_message,
                    'url' => $lazychat_endpoint
                ),
                'permalink' => array(
                    'status' => $permalink_ok,
                    'message' => $permalink_message,
                    'structure' => $permalink_structure
                )
            )
        ));
    }
    
    /**
     * Fix REST API issues
     */
    public function fix_rest_api() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'lazychat_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'lazychat')));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'lazychat')));
            return;
        }

        $actions_taken = array();
        $success = true;

        // Action 1: Flush rewrite rules
        flush_rewrite_rules();
        $actions_taken[] = __('Flushed rewrite rules', 'lazychat');

        // Action 2: Check and update permalink structure if it's plain
        $permalink_structure = get_option('permalink_structure');
        if (empty($permalink_structure)) {
            // Set to post name structure (recommended for REST API)
            // Note: WordPress automatically handles redirects from old ?p=123 URLs to new pretty URLs
            update_option('permalink_structure', '/%postname%/');
            flush_rewrite_rules();
            $actions_taken[] = __('Changed permalink structure from "Plain" to "Post name" (WordPress will auto-redirect old URLs)', 'lazychat');
        }

        // Action 3: Clear any REST API related transients
        delete_transient('rest_api_test');
        delete_transient('lazychat_rest_api_check');
        $actions_taken[] = __('Cleared REST API cache', 'lazychat');

        // Action 4: Remove REST API notice dismissal so it shows again if still broken
        delete_option('lazychat_rest_api_notice_dismissed');
        $actions_taken[] = __('Reset REST API diagnostic notice', 'lazychat');

        // Test if REST API is now working
        $rest_url = rest_url();
        $response = wp_remote_get($rest_url, array(
            'timeout' => 10,
            'sslverify' => false
        ));
        
        if (!is_wp_error($response)) {
            $response_code = wp_remote_retrieve_response_code($response);
            if (in_array($response_code, array(200, 401))) {
                // Cache the successful result so dashboard notice updates immediately
                set_transient('lazychat_rest_api_check', 'working', LAZYCHAT_REST_API_CHECK_CACHE_DURATION);
                
                wp_send_json_success(array(
                    'message' => __('REST API has been fixed successfully!', 'lazychat'),
                    'actions' => $actions_taken,
                    'working' => true
                ));
                return;
            }
        }

        // If we get here, REST API is still not working
        wp_send_json_success(array(
            'message' => __('Attempted to fix REST API, but issues persist. Please contact your hosting provider.', 'lazychat'),
            'actions' => $actions_taken,
            'working' => false,
            'next_steps' => array(
                __('Contact your hosting provider and ask them to enable WordPress REST API', 'lazychat'),
                __('Check if any security plugins are blocking REST API access', 'lazychat'),
                __('Ensure your server supports URL rewriting (.htaccess for Apache, nginx.conf for Nginx)', 'lazychat')
            )
        ));
    }
    
    /**
     * Save webhook setting (auto-save on toggle)
     */
    public function save_webhook_setting() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'lazychat_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'lazychat')));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'lazychat')));
            return;
        }

        // Get and validate the setting
        $enable_products = isset($_POST['enable_products']) ? sanitize_text_field(wp_unslash($_POST['enable_products'])) : 'No';
        
        // Update the option
        update_option('lazychat_enable_products', $enable_products);

        wp_send_json_success(array(
            'message' => __('Webhook setting saved successfully.', 'lazychat'),
            'enable_products' => $enable_products
        ));
    }
}

