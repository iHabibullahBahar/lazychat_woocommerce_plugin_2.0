<?php
/**
 * LazyChat Admin Settings
 * Handles admin settings page and WordPress Settings API integration
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class LazyChat_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Ensure debug logging is enabled (for existing installations)
        if (get_option('lazychat_enable_debug_logging') === false) {
            update_option('lazychat_enable_debug_logging', 'Yes');
        }
    }
    
    /**
     * Add settings page to WordPress Settings menu
     */
    public function add_settings_page() {
        add_options_page(
            __('LazyChat', 'lazychat'),
            __('LazyChat', 'lazychat'),
            'manage_options',
            'lazychat_settings',
            array($this, 'settings_page_callback')
        );
    }
    
    /**
     * Register settings with WordPress Settings API
     */
    public function register_settings() {
        register_setting('lazychat_settings', 'lazychat_bearer_token', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));
        
        register_setting('lazychat_settings', 'lazychat_enable_products', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'Yes'
        ));
        
        register_setting('lazychat_settings', 'lazychat_enable_orders', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'Yes'
        ));
        
        register_setting('lazychat_settings', 'lazychat_enable_debug_logging', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'No'
        ));
        
        register_setting('lazychat_settings', 'lazychat_plugin_active', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'No'
        ));
    }
    
    /**
     * Enqueue admin styles and scripts
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_lazychat_settings') {
            return;
        }
        
        wp_enqueue_style(
            'lazychat-admin-style', 
            LAZYCHAT_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            LAZYCHAT_VERSION
        );
        
        wp_enqueue_script(
            'lazychat-admin-script', 
            LAZYCHAT_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            LAZYCHAT_VERSION,
            true
        );
        
        // Localize script for AJAX
        wp_localize_script('lazychat-admin-script', 'lazychat_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lazychat_nonce')
        ));
    }
    
    /**
     * Settings page callback
     */
    public function settings_page_callback() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }

        $bearer_token = get_option('lazychat_bearer_token', '');
        $needs_login  = empty($bearer_token);
        ?>
        <div class="wrap">
            <h2 class="heading"><?php esc_html_e('LazyChat', 'lazychat'); ?></h2>
            
            <?php if ($needs_login): ?>
            
            <!-- Service Disclosure Notice -->
            <div class="notice notice-info" style="margin: 20px 0; padding: 15px;">
                <h3 style="margin-top: 0;"><?php esc_html_e('Third-Party Service Notice', 'lazychat'); ?></h3>
                <p><strong><?php esc_html_e('Important Information:', 'lazychat'); ?></strong></p>
                <p><?php echo wp_kses_post(__('This plugin connects to LazyChat\'s external service at <strong>https://app.lazychat.io</strong> to provide AI-powered customer support functionality.', 'lazychat')); ?></p>
                <p><?php esc_html_e('By logging in and using this plugin, you acknowledge and agree that:', 'lazychat'); ?></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php esc_html_e('Product information (names, prices, images, descriptions, SKUs, stock status) will be transmitted to LazyChat\'s servers', 'lazychat'); ?></li>
                    <li><?php esc_html_e('Order information (order numbers, customer details, shipping addresses, order items) will be transmitted to LazyChat\'s servers', 'lazychat'); ?></li>
                    <li><?php esc_html_e('All data transmission uses secure HTTPS connections with bearer token authentication', 'lazychat'); ?></li>
                    <li><?php esc_html_e('This data is used exclusively to enable AI customer support features', 'lazychat'); ?></li>
                </ul>
                <p style="margin-bottom: 0;">
                    <?php esc_html_e('Please review our:', 'lazychat'); ?>
                    <a href="https://app.lazychat.io/legal/terms-and-conditions" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Terms of Service', 'lazychat'); ?></a> |
                    <a href="https://app.lazychat.io/legal/privacy-policy" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Privacy Policy', 'lazychat'); ?></a>
                </p>
            </div>
            
            <div class="lazychat-main-container">
                <div class="lazychat-settings-wrapper">


                    <!-- Login Card -->
                    <div class="lazychat-settings-card">
                        <div class="lazychat-card-header">
                            <h3 class="lazychat-card-title">
                                <span class="dashicons dashicons-admin-users"></span>
                                <?php esc_html_e('LazyChat Login', 'lazychat'); ?>
                            </h3>
                        </div>
                        <div class="lazychat-card-body">
                            <form id="lazychat_login_form">
                                <div class="lazychat-form-field">
                                    <label for="lazychat_login_email" class="lazychat-form-label">
                                        <?php esc_html_e('Email', 'lazychat'); ?>
                                    </label>
                                    <input type="email"
                                           id="lazychat_login_email"
                                           class="lazychat-form-input"
                                           required
                                           autocomplete="email"
                                           placeholder="<?php esc_attr_e('you@example.com', 'lazychat'); ?>">
                                </div>
                                <div class="lazychat-form-field">
                                    <label for="lazychat_login_password" class="lazychat-form-label">
                                        <?php esc_html_e('Password', 'lazychat'); ?>
                                    </label>
                                    <input type="password"
                                           id="lazychat_login_password"
                                           class="lazychat-form-input"
                                           required
                                           autocomplete="current-password"
                                           placeholder="<?php esc_attr_e('••••••••', 'lazychat'); ?>">
                                </div>
                                <div class="lazychat-form-actions">
                                    <button type="submit" class="lazychat-action-btn button button-primary">
                                        <span class="dashicons dashicons-unlock"></span>
                                        <span><?php esc_html_e('Login', 'lazychat'); ?></span>
                                    </button>
                                    <button type="button" id="lazychat_contact_btn" class="lazychat-action-btn button button-secondary">
                                        <span class="dashicons dashicons-phone"></span>
                                        <span><?php esc_html_e('Contact', 'lazychat'); ?></span>
                                    </button>
                                    <span class="spinner lazychat-login-spinner"></span>
                                </div>
                                <div id="lazychat_login_message" class="lazychat-login-message" role="alert" aria-live="polite"></div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Shop Selection Card -->
                    <div id="lazychat_shop_selection" class="lazychat-settings-card lazychat-shop-selection is-hidden" aria-live="polite">
                        <div class="lazychat-card-header">
                            <h3 class="lazychat-card-title">
                                <span class="dashicons dashicons-store"></span>
                                <?php esc_html_e('Select Your Shop', 'lazychat'); ?>
                            </h3>
                        </div>
                        <div class="lazychat-card-body">
                            <p class="lazychat-card-description">
                                <?php esc_html_e('Choose the shop you want to connect to this WooCommerce site.', 'lazychat'); ?>
                            </p>
                            <form id="lazychat_shop_form">
                                <div class="lazychat-shop-list" role="radiogroup" aria-label="<?php esc_attr_e('LazyChat shops', 'lazychat'); ?>"></div>
                                <div class="lazychat-form-actions">
                                    <button type="submit" class="lazychat-action-btn button button-primary">
                                        <span class="dashicons dashicons-admin-links"></span>
                                        <span><?php esc_html_e('Connect Shop', 'lazychat'); ?></span>
                                    </button>
                                    <span class="spinner lazychat-shop-spinner"></span>
                                </div>
                                <div id="lazychat_shop_message" class="lazychat-shop-message" role="alert" aria-live="polite"></div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            </div>
            <?php
                return;
            endif;
            ?>
            
            <form method="post" action="options.php">
                <?php 
                settings_fields('lazychat_settings');
                ?>
                <div class="lazychat-main-container">
                    <div class="lazychat-view-toggle">
                        <button type="button"
                                id="lazychat_view_home"
                                class="lazychat-view-button"
                                title="<?php esc_attr_e('LazyChat Dashboard', 'lazychat'); ?>">
                            <span class="dashicons dashicons-admin-home"></span>
                            <span class="lazychat-view-button-label"><?php esc_html_e('Home', 'lazychat'); ?></span>
                        </button>
                        <button type="button"
                                id="lazychat_view_settings"
                                class="lazychat-view-button is-active"
                                title="<?php esc_attr_e('Settings', 'lazychat'); ?>">
                            <span class="dashicons dashicons-admin-generic"></span>
                            <span class="lazychat-view-button-label"><?php esc_html_e('Settings', 'lazychat'); ?></span>
                        </button>
                    </div>

                    <div id="lazychat_home_container" class="lazychat-view-panel" style="display: none; margin-bottom: 20px;">
                        <iframe src="https://app.lazychat.io/"
                                title="<?php esc_attr_e('LazyChat Dashboard', 'lazychat'); ?>"
                                style="width: 100%; min-height: 700px; border: 1px solid #ddd; border-radius: 8px;"></iframe>
                    </div>

                    <div id="lazychat_settings_container" class="lazychat-view-panel is-active">
                        <div class="lazychat-settings-wrapper">
                            <?php 
                            $selected_shop_name = get_option('lazychat_selected_shop_name', '');
                            if (!empty($selected_shop_name)): 
                            ?>
                            <div class="lazychat-info-card">
                                <div class="lazychat-info-icon">
                                    <span class="dashicons dashicons-store"></span>
                                </div>
                                <div class="lazychat-info-content">
                                    <p class="lazychat-info-label"><?php esc_html_e('Connected Shop', 'lazychat'); ?></p>
                                    <p class="lazychat-info-value"><?php echo esc_html($selected_shop_name); ?></p>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Connection Settings Card - Hidden -->
                            <div class="lazychat-settings-card" style="display: none;">
                                <div class="lazychat-card-header">
                                    <h3 class="lazychat-card-title">
                                        <span class="dashicons dashicons-admin-network"></span>
                                        <?php esc_html_e('Connection Settings', 'lazychat'); ?>
                                    </h3>
                                </div>
                                <div class="lazychat-card-body">
                                    <div class="lazychat-form-field">
                                        <label for="lazychat_bearer_token" class="lazychat-field-label">
                                            <?php esc_html_e('Bearer Token', 'lazychat'); ?>
                                        </label>
                                        <div class="lazychat-input-wrapper">
                                            <input type="password" 
                                                   id="lazychat_bearer_token" 
                                                   name="lazychat_bearer_token" 
                                                   class="lazychat-input"
                                                   value="<?php echo esc_attr(get_option('lazychat_bearer_token')); ?>" 
                                                   placeholder="<?php esc_attr_e('Enter your bearer token', 'lazychat'); ?>" />
                                            <button type="button" 
                                                    id="lazychat_toggle_token_visibility" 
                                                    class="lazychat-toggle-visibility"
                                                    title="<?php esc_attr_e('Show/Hide Token', 'lazychat'); ?>">
                                                <span class="dashicons dashicons-visibility" id="lazychat_token_eye_icon"></span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Webhook Settings Card -->
                            <div class="lazychat-settings-card">
                                <div class="lazychat-card-header">
                                    <h3 class="lazychat-card-title">
                                        <span class="dashicons dashicons-admin-links"></span>
                                        <?php esc_html_e('Webhook Settings', 'lazychat'); ?>
                                    </h3>
                                </div>
                                <div class="lazychat-card-body">
                                    <div class="lazychat-toggle-field">
                                        <div class="lazychat-toggle-info">
                                            <label class="lazychat-toggle-label"><?php esc_html_e('Enable Product Webhooks', 'lazychat'); ?></label>
                                            <p class="lazychat-toggle-description"><?php esc_html_e('Send product updates to LazyChat automatically', 'lazychat'); ?></p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" 
                                                   name="lazychat_enable_products" 
                                                   value="Yes" 
                                                   <?php checked(get_option('lazychat_enable_products'), 'Yes'); ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <div class="lazychat-toggle-field">
                                        <div class="lazychat-toggle-info">
                                            <label class="lazychat-toggle-label"><?php esc_html_e('Enable Order Webhooks', 'lazychat'); ?></label>
                                            <p class="lazychat-toggle-description"><?php esc_html_e('Send order updates to LazyChat automatically', 'lazychat'); ?></p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" 
                                                   name="lazychat_enable_orders" 
                                                   value="Yes" 
                                                   <?php checked(get_option('lazychat_enable_orders'), 'Yes'); ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <!-- Enable Debug Logging - Hidden -->
                            <input type="hidden" 
                                   name="lazychat_enable_debug_logging" 
                                   value="<?php echo esc_attr(get_option('lazychat_enable_debug_logging', 'No')); ?>" />
                            
                            <?php 
                            $is_active = get_option('lazychat_plugin_active') === 'Yes';
                            ?>
                            
                            <!-- Plugin Status Card -->
                            <div class="lazychat-settings-card lazychat-status-card <?php echo $is_active ? 'is-active' : ''; ?>">
                                <div class="lazychat-card-header">
                                    <h3 class="lazychat-card-title">
                                        <span class="dashicons dashicons-<?php echo $is_active ? 'yes-alt' : 'dismiss'; ?>"></span>
                                        <?php esc_html_e('Plugin Status', 'lazychat'); ?>
                                    </h3>
                                </div>
                                <div class="lazychat-card-body">
                                    <div class="lazychat-status-content">
                                        <div class="lazychat-status-info">
                                            <div class="lazychat-status-badge <?php echo $is_active ? 'status-active' : 'status-inactive'; ?>">
                                                <?php echo $is_active ? esc_html__('Active', 'lazychat') : esc_html__('Inactive', 'lazychat'); ?>
                                            </div>
                                            <p class="lazychat-status-description">
                                                <?php if ($is_active): ?>
                                                    <?php esc_html_e('Webhooks are being sent to LazyChat', 'lazychat'); ?>
                                                <?php else: ?>
                                                    <?php esc_html_e('No webhooks will be sent', 'lazychat'); ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <label class="switch lazychat-status-switch">
                                            <input type="checkbox" 
                                                   id="lazychat_plugin_active"
                                                   name="lazychat_plugin_active" 
                                                   value="Yes" 
                                                   <?php checked($is_active); ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Product Sync Card -->
                            <div class="lazychat-settings-card lazychat-sync-card">
                                <div class="lazychat-card-header">
                                    <h3 class="lazychat-card-title">
                                        <span class="dashicons dashicons-update"></span>
                                        <?php esc_html_e('Product Synchronization', 'lazychat'); ?>
                                    </h3>
                                </div>
                                <div class="lazychat-card-body">
                                    <div class="lazychat-sync-content">
                                        <div class="lazychat-sync-info">
                                            <p class="lazychat-card-description">
                                                <?php esc_html_e('Synchronize your WooCommerce products with LazyChat to keep them up to date.', 'lazychat'); ?>
                                            </p>
                                            <button type="button" id="lazychat_sync_products" class="lazychat-sync-button">
                                                <span class="dashicons dashicons-update"></span>
                                                <span><?php esc_html_e('Sync Products', 'lazychat'); ?></span>
                                            </button>
                                        </div>
                                        <div id="lazychat_sync_status" class="lazychat-sync-status"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- REST API Diagnostic Card -->
                            <div class="lazychat-settings-card lazychat-diagnostic-card">
                                <div class="lazychat-card-header">
                                    <h3 class="lazychat-card-title">
                                        <span class="dashicons dashicons-admin-tools"></span>
                                        <?php esc_html_e('REST API Diagnostic', 'lazychat'); ?>
                                    </h3>
                                </div>
                                <div class="lazychat-card-body">
                                    <div class="lazychat-diagnostic-content">
                                        <div class="lazychat-diagnostic-info">
                                            <p class="lazychat-card-description">
                                                <?php esc_html_e('If LazyChat is not receiving data, test and fix REST API connectivity here.', 'lazychat'); ?>
                                            </p>
                                            <div class="lazychat-diagnostic-buttons" style="display: flex; gap: 10px; margin-top: 15px;">
                                                <button type="button" id="lazychat_test_rest_api" class="button button-secondary">
                                                    <span class="dashicons dashicons-yes-alt"></span>
                                                    <span><?php esc_html_e('Test REST API', 'lazychat'); ?></span>
                                                </button>
                                                <button type="button" id="lazychat_fix_rest_api" class="button button-secondary">
                                                    <span class="dashicons dashicons-admin-tools"></span>
                                                    <span><?php esc_html_e('Fix REST API', 'lazychat'); ?></span>
                                                </button>
                                            </div>
                                        </div>
                                        <div id="lazychat_rest_api_status" class="lazychat-rest-api-status" style="margin-top: 15px;"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="lazychat-actions-card">
                                <div class="lazychat-actions-group">
                                    <?php submit_button(__('Save Changes', 'lazychat'), 'primary large', 'submit', false, array('class' => 'lazychat-action-btn')); ?>
                                    <button type="button" id="lazychat_logout" class="button button-secondary large lazychat-action-btn">
                                        <span class="dashicons dashicons-exit"></span>
                                        <?php esc_html_e('Logout', 'lazychat'); ?>
                                    </button>
                                </div>
                                <div id="lazychat_test_results" class="test-results"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
            
            <!-- Version Display -->
            <div class="lazychat-version-display">
                <?php 
                /* translators: %s: plugin version number */
                echo esc_html(sprintf(__('Version %s', 'lazychat'), LAZYCHAT_VERSION)); ?>
            </div>
        </div>
        
        <!-- LazyChat Logout Modal -->
        <div id="lazychat_logout_modal" class="lazychat-modal" style="display: none;">
            <div class="lazychat-modal-overlay"></div>
            <div class="lazychat-modal-content">
                <div class="lazychat-modal-header">
                    <h3><?php esc_html_e('Disconnect from LazyChat', 'lazychat'); ?></h3>
                    <button type="button" class="lazychat-modal-close" aria-label="<?php esc_attr_e('Close', 'lazychat'); ?>">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
                <div class="lazychat-modal-body">
                    <p><?php esc_html_e('How would you like to disconnect from LazyChat?', 'lazychat'); ?></p>
                    <div class="lazychat-logout-options">
                        <button type="button" id="lazychat_logout_only" class="button button-secondary" style="width: 100%; margin-bottom: 10px; text-align: left; padding: 15px;">
                            <strong><?php esc_html_e('Logout Only', 'lazychat'); ?></strong><br>
                            <span style="font-size: 13px; color: #666;"><?php esc_html_e('Clear local WordPress settings only. Does not disconnect from LazyChat server.', 'lazychat'); ?></span>
                        </button>
                        <button type="button" id="lazychat_disconnect_only" class="button button-secondary" style="width: 100%; margin-bottom: 10px; text-align: left; padding: 15px;">
                            <strong><?php esc_html_e('Disconnect Only', 'lazychat'); ?></strong><br>
                            <span style="font-size: 13px; color: #666;"><?php esc_html_e('Disconnect your store from LazyChat but keep all products.', 'lazychat'); ?></span>
                        </button>
                        <button type="button" id="lazychat_disconnect_delete" class="button button-secondary" style="width: 100%; text-align: left; padding: 15px; border-color: #dc3545; color: #dc3545;">
                            <strong><?php esc_html_e('Disconnect & Delete All Products', 'lazychat'); ?></strong><br>
                            <span style="font-size: 13px; color: #666;"><?php esc_html_e('Disconnect and permanently delete all products from LazyChat.', 'lazychat'); ?></span>
                        </button>
                    </div>
                </div>
                <div class="lazychat-modal-footer">
                    <button type="button" class="button lazychat-modal-cancel"><?php esc_html_e('Cancel', 'lazychat'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
    
}

