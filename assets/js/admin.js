jQuery(document).ready(function($) {
    'use strict';
    
    // Sync cooldown configuration
    const SYNC_COOLDOWN_MINUTES = 10;
    const SYNC_COOLDOWN_DURATION = SYNC_COOLDOWN_MINUTES * 60 * 1000; // milliseconds
    let cooldownInterval = null;
    
    // Initialize event listeners
    initEventListeners();
    
    // Check sync progress on page load
    if ($('#lazychat_sync_status').length) {
        checkSyncProgressOnLoad();
        checkSyncCooldown();
    }
    
    /**
     * Handle contact button click
     */
    function handleContact() {
        const $button = $('#lazychat_contact_btn');
        const $message = $('#lazychat_login_message');
        const originalText = $button.html();
        
        $button.prop('disabled', true).html('<span class="spinner is-active" style="float: none; margin: 0;"></span>');
        $message.removeClass('notice-success notice-error').empty();

        $.ajax({
            url: lazychat_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lazychat_contact',
                nonce: lazychat_ajax.nonce
            },
            success: function(response) {
                if (response && response.success) {
                    const data = response.data;
                    let phoneNumber = '';
                    
                    // Use formatted number if available, otherwise use whatsapp_number
                    if (data.formatted) {
                        // Remove + sign and any spaces for WhatsApp URL
                        phoneNumber = data.formatted.replace(/\+/g, '').replace(/\s/g, '');
                    } else if (data.whatsapp_number) {
                        phoneNumber = data.whatsapp_number.replace(/\+/g, '').replace(/\s/g, '');
                    }
                    
                    if (phoneNumber) {
                        // Redirect to WhatsApp
                        const whatsappUrl = 'https://wa.me/' + phoneNumber;
                        window.open(whatsappUrl, '_blank');
                        $button.prop('disabled', false).html(originalText);
                    } else {
                        const errorMessage = '❌ No phone number found in contact information.';
                        $message.addClass('notice-error').text(errorMessage);
                        $button.prop('disabled', false).html(originalText);
                    }
                } else {
                    const errorMessage = response && response.data && response.data.message
                        ? response.data.message
                        : '❌ Failed to retrieve contact information. Please try again.';
                    $message.addClass('notice-error').text(errorMessage);
                    $button.prop('disabled', false).html(originalText);
                }
            },
            error: function(xhr, status, error) {
                console.error('Contact Error:', { xhr: xhr, status: status, error: error });
                $message.addClass('notice-error').text('❌ An unexpected error occurred. Please try again.');
                $button.prop('disabled', false).html(originalText);
            }
        });
    }

    /**
     * Initialize all event listeners
     */
    function initEventListeners() {
        // Check connection (main button)
        $('#check_connection').on('click', function() {
            checkConnection();
        });
        
        // Check connection (inline button in warning)
        $('#check_connection_inline').on('click', function() {
            checkConnection();
        });
        
        // Test connection
        $('#test_connection').on('click', function() {
            testConnection();
        });
        
        // Toggle bearer token visibility
        $('#lazychat_toggle_token_visibility').on('click', function() {
            toggleTokenVisibility();
        });

        // Sync products
        $('#lazychat_sync_products').on('click', function() {
            syncProducts();
        });
        
        // Auto-save product webhooks toggle
        $('input[name="lazychat_enable_products"]').on('change', function() {
            autoSaveWebhookToggle($(this));
        });

        // Test REST API
        $('#lazychat_test_rest_api').on('click', function() {
            testRestApi();
        });

        // Fix REST API
        $('#lazychat_fix_rest_api').on('click', function() {
            fixRestApi();
        });

        // Generate WooCommerce API keys
        const $generateKeysBtn = $('#generate_wc_api_keys');
        if ($generateKeysBtn.length) {
            $generateKeysBtn.on('click', function() {
                generateWcApiKeys($generateKeysBtn);
            });
        }

        // Contact button handler
        $('#lazychat_contact_btn').on('click', function() {
            handleContact();
        });

        // Collapsible section toggle
        $('.lazychat-collapsible-header').on('click', function() {
            const $header = $(this);
            const $content = $header.next('.lazychat-collapsible-content');
            const $icon = $header.find('.lazychat-collapse-icon');
            
            $content.slideToggle(300);
            $icon.toggleClass('lazychat-collapsed');
        });

        // Logout modal handlers
        const $logoutBtn = $('#lazychat_logout');
        if ($logoutBtn.length) {
            $logoutBtn.on('click', function() {
                showLogoutModal();
            });
        }

        const $logoutModal = $('#lazychat_logout_modal');
        if ($logoutModal.length) {
            // Close modal handlers
            $logoutModal.find('.lazychat-modal-close, .lazychat-modal-overlay, .lazychat-modal-cancel').on('click', function() {
                hideLogoutModal();
            });

            // Logout only (local logout, no API call)
            $('#lazychat_logout_only').on('click', function() {
                handleLocalLogoutOnly($(this));
            });

            // Disconnect only
            $('#lazychat_disconnect_only').on('click', function() {
                performDisconnect(false);
            });

            // Disconnect and delete all
            $('#lazychat_disconnect_delete').on('click', function() {
                if (confirm('⚠️ Are you sure you want to disconnect and delete ALL products from LazyChat? This action cannot be undone.')) {
                    performDisconnect(true);
                }
            });
        }

        // View toggles
        const $viewHome = $('#lazychat_view_home');
        const $viewSettings = $('#lazychat_view_settings');
        if ($viewHome.length && $viewSettings.length) {
            $viewHome.on('click', function() {
                switchLazychatView('home');
            });
            $viewSettings.on('click', function() {
                switchLazychatView('settings');
            });
        }
        
        // Product webhook test
        $('#test_product_webhook').on('click', function() {
            testWebhook('product');
        });
        
        // Order webhook test
        $('#test_order_webhook').on('click', function() {
            testWebhook('order');
        });
        
        // Handle plugin activation toggle
        $('#lazychat_plugin_active').on('change', function(e) {
            handleActivationToggle(e, $(this));
        });

        // Handle LazyChat login form
        const $loginForm = $('#lazychat_login_form');
        if ($loginForm.length) {
            $loginForm.on('submit', handleLogin);
        }

        // Handle LazyChat shop selection
        const $shopForm = $('#lazychat_shop_form');
        if ($shopForm.length) {
            $shopForm.on('submit', handleShopSelection);
        }
    }
    
    /**
     * Handle LazyChat login submission
     */
    function handleLogin(event) {
        event.preventDefault();

        const $form = $(event.currentTarget);
        const $email = $('#lazychat_login_email');
        const $password = $('#lazychat_login_password');
        const $message = $('#lazychat_login_message');
        const $spinner = $form.find('.lazychat-login-spinner');
        const $submitButton = $form.find('button[type="submit"]');

        const email = $email.val().trim();
        const password = $password.val();

        $message.removeClass('notice-success notice-error').empty();

        if (!email || !password) {
            $message.addClass('notice-error').text('❌ Email and password are required.');
            return;
        }

        if (password.length < 6) {
            $message.addClass('notice-error').text('❌ Password must be at least 6 characters.');
            return;
        }

        $spinner.addClass('is-active');
        $submitButton.prop('disabled', true);

        $.ajax({
            url: lazychat_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lazychat_login',
                nonce: lazychat_ajax.nonce,
                email: email,
                password: password
            },
            success: function(response) {
                if (response && response.success) {
                    const payload = response.data || {};
                    const successMessage = payload.message || '✅ Login successful.';

                    if (payload.token_saved) {
                        $message.addClass('notice-success').text(successMessage);
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                        return;
                    }

                    if (payload.requires_shop_selection && Array.isArray(payload.shops) && payload.shops.length) {
                        $message.addClass('notice-success').text(successMessage || '✅ Login successful. Please select a shop below.');
                        showShopSelection(payload.shops, payload.default_shop_id || null);
                        return;
                    }

                    $message.addClass('notice-success').text(successMessage || '✅ Login successful! Reloading...');
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    const errorMessage = response && response.data && response.data.message
                        ? response.data.message
                        : '❌ Login failed. Please try again.';
                    $message.addClass('notice-error').text(errorMessage);
                }
            },
            error: function(xhr, status, error) {
                console.error('Login Error:', { xhr: xhr, status: status, error: error });
                $message.addClass('notice-error').text('❌ An unexpected error occurred. Please try again.');
            },
            complete: function() {
                $spinner.removeClass('is-active');
                $submitButton.prop('disabled', false);
            }
        });
    }

    /**
     * Render the shop selection UI
     */
    function showShopSelection(shops, defaultShopId) {
        const $loginForm = $('#lazychat_login_form');
        const $shopSection = $('#lazychat_shop_selection');
        const $shopList = $shopSection.find('.lazychat-shop-list');
        const $shopMessage = $('#lazychat_shop_message');

        $loginForm.addClass('is-hidden');
        $shopSection.removeClass('is-hidden');
        $shopMessage.removeClass('notice-success notice-error').empty();
        $shopList.empty();

        shops.forEach(function(shop, index) {
            const radioId = 'lazychat_shop_' + index;
            const $label = $('<label/>', {
                class: 'lazychat-shop-item',
                for: radioId
            });

            const $input = $('<input/>', {
                type: 'radio',
                name: 'lazychat_selected_shop',
                id: radioId,
                value: shop.id
            });

            $input.data('token', shop.auth_token);
            $input.data('name', shop.name);

            const isDefault = defaultShopId && String(shop.id) === String(defaultShopId);
            if (isDefault || (!defaultShopId && index === 0)) {
                $input.prop('checked', true);
            }

            const $name = $('<span/>', {
                class: 'lazychat-shop-name'
            }).text(shop.name || ('Shop #' + shop.id));

            $label.append($input);
            $label.append($name);


            $shopList.append($label);
        });
    }

    /**
     * Handle shop selection submission
     */
    function handleShopSelection(event) {
        event.preventDefault();

        const $form = $(event.currentTarget);
        const $message = $('#lazychat_shop_message');
        const $spinner = $form.find('.lazychat-shop-spinner');
        const $submitButton = $form.find('button[type="submit"]');
        const $selected = $form.find('input[name="lazychat_selected_shop"]:checked');

        $message.removeClass('notice-success notice-error').empty();

        if (!$selected.length) {
            $message.addClass('notice-error').text('❌ Please select a shop to continue.');
            return;
        }

        const shopId = $selected.val();
        const authToken = $selected.data('token');
        const shopName = $selected.data('name');

        if (!authToken) {
            $message.addClass('notice-error').text('❌ The selected shop is missing an authentication token. Please try another shop or contact support.');
            return;
        }

        $spinner.addClass('is-active');
        $submitButton.prop('disabled', true);

        $.ajax({
            url: lazychat_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lazychat_select_shop',
                nonce: lazychat_ajax.nonce,
                shop_id: shopId,
                shop_name: shopName,
                auth_token: authToken
            },
            success: function(response) {
                if (response && response.success) {
                    const payload = response.data || {};
                    const successMessage = payload.message || '✅ Shop connected! Reloading...';
                    let displayMessage = successMessage;

                    if (payload.api_connection) {
                        const apiStatus = payload.api_connection.success ? '✅' : '⚠️';
                        const apiMessage = payload.api_connection.message ? payload.api_connection.message : (payload.api_connection.success ? 'LazyChat store registered successfully.' : 'LazyChat store registration encountered an issue.');
                        displayMessage += '\n' + apiStatus + ' ' + apiMessage;
                    }

                    //$message.addClass('notice-success').text(displayMessage);
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    const errorMessage = response && response.data && response.data.message
                        ? response.data.message
                        : '❌ Failed to connect the selected shop. Please try again.';
                    $message.addClass('notice-error').text(errorMessage);
                }
            },
            error: function(xhr, status, error) {
                console.error('Shop Selection Error:', { xhr: xhr, status: status, error: error });
                $message.addClass('notice-error').text('❌ An unexpected error occurred. Please try again.');
            },
            complete: function() {
                $spinner.removeClass('is-active');
                $submitButton.prop('disabled', false);
            }
        });
    }
    
    /**
     * Handle activation toggle with connection verification
     */
    function handleActivationToggle(e, $toggle) {
        const isChecking = $toggle.prop('checked');
        
        if (!isChecking) {
            // User is deactivating - prevent default and notify LazyChat server
            e.preventDefault();
            $toggle.prop('checked', true); // Keep it checked until we get response
            
            const $results = $('#lazychat_test_results');
            $results.html('<div class="notice notice-info"><p>⏳ Deactivating plugin...</p></div>');
            
            // Notify LazyChat server about deactivation
            notifyPluginToggle($toggle, false, function(success) {
                if (success) {
                    $toggle.prop('checked', false);
                    updatePluginStatusUI(false);
                    $results.html('<div class="notice notice-success"><p>✅ Plugin deactivated successfully!</p></div>');
                } else {
                    // Still allow deactivation even if notification failed
                    $toggle.prop('checked', false);
                    updatePluginStatusUI(false);
                    $results.html('<div class="notice notice-warning"><p>⚠️ Plugin deactivated (notification to LazyChat server failed, but deactivation will proceed).</p></div>');
                }
            });
            
            return false;
        }
        
        // User is trying to activate - prevent default and check connection first
        e.preventDefault();
        $toggle.prop('checked', false);
        
        const $results = $('#lazychat_test_results');
        const bearerToken = $('#lazychat_bearer_token').val();
        
        if (!bearerToken) {
            $results.html('<div class="notice notice-error"><p>❌ Please enter Bearer Token and save settings before activating.</p></div>');
            return false;
        }
        
        // Show checking message
        $results.html('<div class="notice notice-info"><p>⏳ Verifying connection before activation...</p></div>');
        
        // Check connection automatically
        $.ajax({
            url: lazychat_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lazychat_check_connection',
                nonce: lazychat_ajax.nonce,
                bearer_token: bearerToken
            },
            success: function(response) {
                
                if (response.success && response.data.connected && response.data.activated) {
                    // Connection verified - notify LazyChat server about activation
                    $results.html('<div class="notice notice-info"><p>⏳ Connection verified! Activating plugin...</p></div>');
                    
                    notifyPluginToggle($toggle, true, function(success) {
                        if (success) {
                            $toggle.prop('checked', true);
                            updatePluginStatusUI(true);
                            $results.html('<div class="notice notice-success"><p>✅ Plugin activated successfully!</p></div>');
                        } else {
                            $toggle.prop('checked', false);
                            $results.html('<div class="notice notice-error"><p>❌ Failed to notify LazyChat server. Plugin not activated.</p></div>');
                        }
                    });
                } else {
                    // Connection failed
                    $toggle.prop('checked', false);
                    
                    let errorMessage = '❌ Cannot activate plugin.<br><br>';
                    
                    if (response.success && !response.data.connected) {
                        errorMessage += '<strong>Reason:</strong> WooCommerce is not connected from LazyChat dashboard.<br>';
                        errorMessage += '<strong>Action Required:</strong> Please connect your WooCommerce store from the LazyChat dashboard first, then try again.';
                    } else if (response.data && response.data.message) {
                        errorMessage += response.data.message;
                    } else {
                        errorMessage += '<strong>Reason:</strong> Unable to verify connection.<br>';
                        errorMessage += '<strong>Action Required:</strong> Please check your Bearer Token and try again.';
                    }
                    
                    $results.html('<div class="notice notice-error"><p>' + errorMessage + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Auto Connection Check Error:', {xhr: xhr, status: status, error: error});
                $toggle.prop('checked', false);
                $results.html('<div class="notice notice-error"><p>❌ Connection verification failed. Cannot activate plugin.<br><br><strong>Error:</strong> ' + error + '<br><strong>Action Required:</strong> Please check your Bearer Token and internet connection.</p></div>');
            }
        });
        
        return false;
    }
    
    /**
     * Notify LazyChat server about plugin toggle
     */
    function notifyPluginToggle($toggle, enabled, callback) {
        const $toggleContainer = $toggle.closest('.lazychat-toggle-field');
        
        // Add saving indicator
        let $indicator = $toggleContainer.find('.lazychat-saving-indicator');
        if (!$indicator.length) {
            $indicator = $('<span class="lazychat-saving-indicator" style="position: absolute; right: 70px; font-size: 12px; color: #999;">Saving...</span>');
            $toggleContainer.css('position', 'relative');
            $toggleContainer.append($indicator);
        }
        $indicator.show();
        
        $.ajax({
            url: lazychat_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lazychat_toggle_plugin',
                nonce: lazychat_ajax.nonce,
                enabled: enabled
            },
            success: function(response) {
                if (response.success) {
                    $indicator.text('✓ Saved').css('color', '#46b450');
                    setTimeout(function() {
                        $indicator.fadeOut();
                    }, 2000);
                    callback(true);
                } else {
                    $indicator.text('✗ Failed').css('color', '#dc3232');
                    setTimeout(function() {
                        $indicator.fadeOut();
                    }, 3000);
                    console.error('Toggle Plugin Failed:', response.data.message);
                    callback(false);
                }
            },
            error: function(xhr, status, error) {
                $indicator.text('✗ Error').css('color', '#dc3232');
                setTimeout(function() {
                    $indicator.fadeOut();
                }, 3000);
                console.error('Toggle Plugin Error:', {xhr: xhr, status: status, error: error});
                callback(false);
            }
        });
    }
    
    /**
     * Update plugin status UI elements
     */
    function updatePluginStatusUI(isActive) {
        const $statusCard = $('.lazychat-status-card');
        const $statusBadge = $('.lazychat-status-badge');
        const $statusDescription = $('.lazychat-status-description');
        const $headerIcon = $('.lazychat-status-card .lazychat-card-title .dashicons');
        
        if (isActive) {
            $statusCard.addClass('is-active');
            $statusBadge.removeClass('status-inactive').addClass('status-active').text('Active');
            $statusDescription.text('Webhooks are being sent to LazyChat');
            $headerIcon.removeClass('dashicons-dismiss').addClass('dashicons-yes-alt');
        } else {
            $statusCard.removeClass('is-active');
            $statusBadge.removeClass('status-active').addClass('status-inactive').text('Inactive');
            $statusDescription.text('No webhooks will be sent');
            $headerIcon.removeClass('dashicons-yes-alt').addClass('dashicons-dismiss');
        }
    }
    
    /**
     * Check connection with LazyChat dashboard
     */
    function checkConnection() {
        const $button = $('#check_connection');
        const $results = $('#lazychat_test_results');
        const $activeToggle = $('#lazychat_plugin_active');
        
        $button.prop('disabled', true).text('Checking...');
        $results.html('<div class="notice notice-info"><p>⏳ Checking connection with LazyChat dashboard...</p></div>');
        
        const bearerToken = $('#lazychat_bearer_token').val();
        
        if (!bearerToken) {
            $results.html('<div class="notice notice-error"><p>❌ Please enter Bearer Token and save settings first.</p></div>');
            $button.prop('disabled', false).text('Check Connection');
            return;
        }
        
        $.ajax({
            url: lazychat_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lazychat_check_connection',
                nonce: lazychat_ajax.nonce,
                bearer_token: bearerToken
            },
            success: function(response) {
                
                if (response.success) {
                    $results.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                    
                    // Reload page to reflect changes after successful connection
                    if (response.data.connected && response.data.activated) {
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        // Show warning but allow manual activation
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    }
                } else {
                    $results.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                    
                    // Show debug info if available
                    if (response.data.debug) {
                        console.error('Debug Info:', response.data.debug);
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', {xhr: xhr, status: status, error: error});
                $results.html('<div class="notice notice-error"><p>❌ An error occurred while checking the connection. Check console for details.</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false).text('Check Connection');
            }
        });
    }
    
    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    
    /**
     * Toggle bearer token visibility
     */
    function toggleTokenVisibility() {
        const $tokenInput = $('#lazychat_bearer_token');
        const $eyeIcon = $('#lazychat_token_eye_icon');
        const currentType = $tokenInput.attr('type');
        
        if (currentType === 'password') {
            $tokenInput.attr('type', 'text');
            $eyeIcon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
        } else {
            $tokenInput.attr('type', 'password');
            $eyeIcon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
        }
    }

    /**
     * Auto-save webhook toggle
     */
    function autoSaveWebhookToggle($toggle) {
        const isEnabled = $toggle.is(':checked');
        const $toggleContainer = $toggle.closest('.lazychat-toggle-field');
        
        // Add saving indicator
        let $indicator = $toggleContainer.find('.lazychat-saving-indicator');
        if (!$indicator.length) {
            $indicator = $('<span class="lazychat-saving-indicator" style="position: absolute; right: 70px; font-size: 12px; color: #999;">Saving...</span>');
            $toggleContainer.css('position', 'relative');
            $toggleContainer.append($indicator);
        }
        $indicator.show();
        
        $.ajax({
            url: lazychat_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lazychat_save_webhook_setting',
                nonce: lazychat_ajax.nonce,
                enable_products: isEnabled ? 'Yes' : 'No'
            },
            success: function(response) {
                if (response && response.success) {
                    $indicator.text('✓ Saved').css('color', '#46b450');
                    setTimeout(function() {
                        $indicator.fadeOut();
                    }, 2000);
                } else {
                    $indicator.text('✗ Failed').css('color', '#dc3232');
                    setTimeout(function() {
                        $indicator.fadeOut();
                    }, 3000);
                }
            },
            error: function() {
                $indicator.text('✗ Error').css('color', '#dc3232');
                setTimeout(function() {
                    $indicator.fadeOut();
                }, 3000);
            }
        });
    }

    /**
     * Sync products with LazyChat
     */
    function syncProducts() {
        const $button = $('#lazychat_sync_products');
        const $status = $('#lazychat_sync_status');
        const originalText = $button.data('original-text') || $button.text();

        if (!$button.data('original-text')) {
            $button.data('original-text', originalText);
        }
        
        // Check if cooldown is active
        const lastSyncCompletion = localStorage.getItem('lazychat_last_sync_completion');
        if (lastSyncCompletion) {
            const completionTime = parseInt(lastSyncCompletion, 10);
            const currentTime = new Date().getTime();
            const timeSinceCompletion = currentTime - completionTime;
            
            if (timeSinceCompletion < SYNC_COOLDOWN_DURATION) {
                const minutesRemaining = Math.ceil((SYNC_COOLDOWN_DURATION - timeSinceCompletion) / 60000);
                $status.html('<div class="notice notice-warning"><p>⏳ Please wait ' + minutesRemaining + ' minute(s) before syncing again.</p></div>');
                return;
            }
        }

        const bearerToken = $('#lazychat_bearer_token').val();
        const shopId = lazychat_ajax.shop_id;

        if (!bearerToken) {
            console.error('ERROR: Bearer token is missing');
            $status.html('<div class="notice notice-error"><p>❌ Bearer token is missing. Please configure your settings.</p></div>');
            return;
        }

        if (!shopId) {
            console.error('ERROR: Shop ID is missing');
            $status.html('<div class="notice notice-error"><p>❌ Shop ID is missing. Please reconnect your account.</p></div>');
            return;
        }

        // Show shimmer effect and hide button
        $button.prop('disabled', true).html('<span class="lazychat-shimmer">' + originalText + '</span>').hide();
        $status.html('<div class="lazychat-shimmer-container"><div class="lazychat-shimmer-line"></div><div class="lazychat-shimmer-line"></div></div>').show();
        
        // Call AWS API endpoint through WordPress AJAX (server-side) to avoid CORS issues
        $.ajax({
            url: lazychat_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lazychat_sync_products',
                nonce: lazychat_ajax.nonce
            },
            success: function(response) {
                if (response && response.success) {
                    // Show immediate feedback that sync has started
                    $status.html('<div class="lazychat-sync-progress"><div class="lazychat-progress-header"><h4 style="margin: 0 0 10px 0; font-size: 14px; font-weight: 600;">🔄 Initiating product sync...</h4></div><div class="lazychat-shimmer-container"><div class="lazychat-shimmer-line"></div><div class="lazychat-shimmer-line"></div></div></div>').show();
                    
                    // Start polling for progress (button already hidden)
                    startSyncProgressPolling();
                } else {
                    const errorMessage = response && response.data && response.data.message
                        ? response.data.message
                        : '❌ Failed to sync products. Please try again.';
                    console.error('Sync failed:', errorMessage);
                    $status.html('<div class="notice notice-error"><p>' + escapeHtml(errorMessage) + '</p></div>');
                    $button.prop('disabled', false).html($button.data('original-text') || originalText).show();
                }
            },
            error: function(xhr, status, error) {
                console.error('Sync AJAX error:', error, 'Status:', xhr.status);
                $status.html('<div class="notice notice-error"><p>❌ An unexpected error occurred while syncing products. Error: ' + escapeHtml(error) + '</p></div>');
                $button.prop('disabled', false).html($button.data('original-text') || originalText).show();
            }
        });
    }

    /**
     * Poll sync progress
     */
    let syncProgressInterval = null;
    let syncProgressTimeout = null;
    let showLoadingPhase = true;
    let lastProgressData = null;

    function startSyncProgressPolling() {
        // Clear any existing interval/timeout
        if (syncProgressInterval) {
            clearInterval(syncProgressInterval);
        }
        if (syncProgressTimeout) {
            clearTimeout(syncProgressTimeout);
        }

        // Reset state
        showLoadingPhase = true;
        lastProgressData = null;

        // Poll immediately first time
        checkSyncProgress();

        // Then poll every 2 seconds during loading phase
        syncProgressInterval = setInterval(function() {
            checkSyncProgress();
        }, 2000);

        // After 6 seconds, end loading phase and show last data
        syncProgressTimeout = setTimeout(function() {
            showLoadingPhase = false;
            displayLastProgressData();
            // Continue polling every 2 seconds (interval already set above)
        }, 6000);
    }

    function stopSyncProgressPolling() {
        if (syncProgressInterval) {
            clearInterval(syncProgressInterval);
            syncProgressInterval = null;
        }
        if (syncProgressTimeout) {
            clearTimeout(syncProgressTimeout);
            syncProgressTimeout = null;
        }
        showLoadingPhase = false;
        lastProgressData = null;
    }

    function checkSyncProgress() {
        const $button = $('#lazychat_sync_products');
        const $status = $('#lazychat_sync_status');

        $.ajax({
            url: lazychat_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lazychat_sync_progress',
                nonce: lazychat_ajax.nonce
            },
            success: function(response) {
                if (response && response.success) {
                    const data = response.data;
                    
                    // Store last progress data
                    lastProgressData = data;
                    
                    // If in loading phase (first 6 seconds), just store data but don't display yet
                    if (showLoadingPhase) {
                        // Keep showing loading indicator
                        return;
                    }
                    
                    // After loading phase, display the data
                    displayProgressData(data);
                } else {
                    lastProgressData = response;
                    if (!showLoadingPhase) {
                        const errorMessage = response && response.data && response.data.message
                            ? response.data.message
                            : '❌ Failed to get sync progress.';
                        $status.html('<div class="notice notice-error"><p>' + escapeHtml(errorMessage) + '</p></div>');
                        stopSyncProgressPolling();
                        $button.prop('disabled', false).html($button.data('original-text') || 'Sync Products').show();
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('Progress Check Error:', {xhr: xhr, status: status, error: error});
                if (!showLoadingPhase) {
                    $status.html('<div class="notice notice-error"><p>❌ An error occurred while checking sync progress. Error: ' + escapeHtml(error) + '</p></div>');
                    stopSyncProgressPolling();
                    $button.prop('disabled', false).html($button.data('original-text') || 'Sync Products').show();
                }
            }
        });
    }

    function displayLastProgressData() {
        if (!lastProgressData) {
            return;
        }
        
        displayProgressData(lastProgressData);
    }

    function displayProgressData(data) {
        const $button = $('#lazychat_sync_products');
        const $status = $('#lazychat_sync_status');
        
        if (!data.is_syncing && data.status === 'NO_SYNC') {
            // No sync found - never started
            stopSyncProgressPolling();
            $status.html('<div class="notice notice-info"><p>' + escapeHtml(data.message || 'No active sync found.') + '</p></div>');
            $button.prop('disabled', false).html($button.data('original-text') || 'Sync Products').show();
            return;
        }

        if (data.is_syncing && data.sync_status === 'IN_PROGRESS') {
            // Sync is in progress - disable and hide button, continue polling
            displaySyncProgress(data);
            $button.prop('disabled', true).hide();
        } else if (!data.is_syncing && data.sync_status === 'COMPLETED') {
            // Sync completed - stop polling immediately and show completion data
            stopSyncProgressPolling();
            displaySyncComplete(data);
            
            // Store completion timestamp for cooldown
            const completionTime = new Date().getTime();
            localStorage.setItem('lazychat_last_sync_completion', completionTime);
            
            // Enable cooldown
            enableSyncCooldown(completionTime);
        } else {
            // Unknown state
            stopSyncProgressPolling();
            $status.html('<div class="notice notice-info"><p>' + escapeHtml(data.message || 'Sync status unknown.') + '</p></div>');
            $button.prop('disabled', false).html($button.data('original-text') || 'Sync Products').show();
        }
    }

    /**
     * Check sync progress on page load (one-time check)
     */
    function checkSyncProgressOnLoad() {
        const $status = $('#lazychat_sync_status');

        $.ajax({
            url: lazychat_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lazychat_sync_progress',
                nonce: lazychat_ajax.nonce
            },
            success: function(response) {
                if (response && response.success) {
                    const data = response.data;
                    
                    if (!data.is_syncing && data.status === 'NO_SYNC') {
                        // No sync found - show message if available
                        if (data.message) {
                            $status.html('<div class="notice notice-info"><p>' + escapeHtml(data.message) + '</p></div>').show();
                        }
                        return;
                    }

                    if (data.is_syncing && data.sync_status === 'IN_PROGRESS') {
                        // Active sync found - start polling and hide button
                        displaySyncProgress(data);
                        startSyncProgressPolling();
                        $('#lazychat_sync_products').prop('disabled', true).hide();
                    } else if (!data.is_syncing && data.sync_status === 'COMPLETED') {
                        // Show last completed sync info
                        displayLastSyncInfo(data);
                        
                        // Check if sync was completed within cooldown period
                        if (data.last_sync_at) {
                            const completionTimestamp = parseCompletionTimestamp(data.last_sync_at);
                            
                            if (completionTimestamp) {
                                const currentTime = new Date().getTime();
                                const timeSinceCompletion = currentTime - completionTimestamp;
                                
                                if (timeSinceCompletion < SYNC_COOLDOWN_DURATION) {
                                    // Still within cooldown period - store and enable cooldown
                                    localStorage.setItem('lazychat_last_sync_completion', completionTimestamp);
                                    enableSyncCooldown(completionTimestamp);
                                } else {
                                    // Cooldown expired - show button normally
                                    $('#lazychat_sync_products').show();
                                }
                            } else {
                                // Couldn't parse timestamp - show button normally
                                $('#lazychat_sync_products').show();
                            }
                        } else {
                            // No timestamp - show button normally
                            $('#lazychat_sync_products').show();
                        }
                    }
                }
            },
            error: function(xhr, status, error) {
                // Silently fail on page load - don't show error
                console.error('Failed to load sync progress:', error);
            }
        });
    }

    function displayLastSyncInfo(data) {
        const $status = $('#lazychat_sync_status');
        const lastSyncAt = data.last_sync_at || null;
        const message = data.message || null;

        if (!lastSyncAt) {
            // No sync history at all
            return;
        }

        let html = '<div class="notice notice-info">';

        if (message) {
            html += '<p>' + escapeHtml(message) + '</p>';
        }

        if (lastSyncAt) {
            html += '<div style="margin-top: 10px; margin-bottom: 10px; font-size: 12px; color: #666;">';
            html += '<strong>Last completed:</strong> ' + escapeHtml(lastSyncAt);
            html += '</div>';
        }

        html += '</div>';
        $status.html(html).show();
    }

    function displaySyncProgress(data) {
        const $status = $('#lazychat_sync_status');
        const totalProducts = data.total_products || 0;
        const currentPage = data.current_page || 0;
        const totalPages = data.total_pages || 1;
        const progress = Math.round(data.progress || 0);
        const statusText = data.sync_status || 'IN_PROGRESS';
        const estimatedTimeRemaining = data.estimated_time_remaining_seconds || null;

        let html = '<div class="lazychat-sync-progress">';
        html += '<div class="lazychat-progress-header">';
        html += '<h4 style="margin: 0 0 10px 0; font-size: 14px; font-weight: 600;">🔄 ' + escapeHtml(statusText === 'IN_PROGRESS' ? 'Syncing Products...' : statusText) + '</h4>';
        html += '</div>';
        
        html += '<div class="lazychat-progress-bar-container">';
        html += '<div class="lazychat-progress-bar" style="width: ' + progress + '%;"></div>';
        html += '<span class="lazychat-progress-percentage">' + progress + '%</span>';
        html += '</div>';

        html += '<div class="lazychat-progress-stats" style="margin-top: 15px; display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; font-size: 13px;">';
        html += '<div><strong>Total Products:</strong> ' + totalProducts + '</div>';
        html += '<div><strong>Page:</strong> ' + currentPage + ' / ' + totalPages + '</div>';
        if (estimatedTimeRemaining) {
            html += '<div><strong>ETA:</strong> ' + Math.ceil(estimatedTimeRemaining / 60) + ' min</div>';
        }
        html += '</div>';

        if (data.started_at) {
            html += '<div style="margin-top: 10px; font-size: 12px; color: #999;">';
            html += '<strong>Started at:</strong> ' + escapeHtml(data.started_at);
            html += '</div>';
        }

        html += '</div>';
        $status.html(html).show();
    }

    function displaySyncComplete(data) {
        const $status = $('#lazychat_sync_status');
        const message = data.message || 'Product sync has been completed.';
        const lastSyncAt = data.last_sync_at || null;

        let html = '<div class="notice notice-success">';
        html += '<p style="margin: 0 0 10px 0;"><strong>✅ Sync Completed!</strong></p>';
        
        html += '<div style="margin-top: 10px; font-size: 13px;">';
        html += escapeHtml(message);
        html += '</div>';

        if (lastSyncAt) {
            html += '<div style="margin-top: 10px; font-size: 12px; color: #666;">';
            html += '<strong>Completed at:</strong> ' + escapeHtml(lastSyncAt);
            html += '</div>';
        }

        html += '</div>';
        $status.html(html);
    }

    /**
     * Check if sync cooldown is active on page load
     */
    function checkSyncCooldown() {
        const lastSyncCompletion = localStorage.getItem('lazychat_last_sync_completion');
        
        if (!lastSyncCompletion) {
            return;
        }
        
        const completionTime = parseInt(lastSyncCompletion, 10);
        const currentTime = new Date().getTime();
        const timeSinceCompletion = currentTime - completionTime;
        
        if (timeSinceCompletion < SYNC_COOLDOWN_DURATION) {
            // Still in cooldown period
            enableSyncCooldown(completionTime);
        } else {
            // Cooldown expired, clear storage
            localStorage.removeItem('lazychat_last_sync_completion');
        }
    }

    /**
     * Parse completion timestamp from server format (YYYY-MM-DD HH:MM:SS)
     * Returns timestamp in milliseconds or null if parsing fails
     */
    function parseCompletionTimestamp(dateString) {
        if (!dateString) {
            return null;
        }
        
        try {
            // Format: "2025-12-22 05:59:01"
            // Parse as local time (WordPress format)
            const parts = dateString.match(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2}):(\d{2})$/);
            
            if (!parts) {
                console.error('Failed to parse date format:', dateString);
                return null;
            }
            
            const [, year, month, day, hours, minutes, seconds] = parts;
            const timestamp = new Date(
                parseInt(year, 10),
                parseInt(month, 10) - 1, // Month is 0-indexed
                parseInt(day, 10),
                parseInt(hours, 10),
                parseInt(minutes, 10),
                parseInt(seconds, 10)
            ).getTime();
            
            // Verify it's a valid timestamp
            if (isNaN(timestamp)) {
                console.error('Invalid timestamp result for:', dateString);
                return null;
            }
            
            return timestamp;
        } catch (e) {
            console.error('Failed to parse completion timestamp:', e);
            return null;
        }
    }

    /**
     * Enable sync cooldown with countdown timer
     */
    function enableSyncCooldown(completionTime) {
        const $button = $('#lazychat_sync_products');
        
        // Clear any existing cooldown interval
        if (cooldownInterval) {
            clearInterval(cooldownInterval);
        }
        
        function updateCooldownButton() {
            const currentTime = new Date().getTime();
            const timeSinceCompletion = currentTime - completionTime;
            const timeRemaining = SYNC_COOLDOWN_DURATION - timeSinceCompletion;
            
            if (timeRemaining <= 0) {
                // Cooldown expired
                clearInterval(cooldownInterval);
                cooldownInterval = null;
                localStorage.removeItem('lazychat_last_sync_completion');
                $button.prop('disabled', false).html($button.data('original-text') || 'Sync Products').show();
                return;
            }
            
            // Calculate minutes and seconds remaining
            const minutesRemaining = Math.floor(timeRemaining / 60000);
            const secondsRemaining = Math.floor((timeRemaining % 60000) / 1000);
            
            // Format as MM:SS
            const formattedTime = minutesRemaining + ':' + (secondsRemaining < 10 ? '0' : '') + secondsRemaining;
            
            // Update button text with countdown
            $button.prop('disabled', true).html('Sync available in ' + formattedTime).show();
        }
        
        // Update immediately
        updateCooldownButton();
        
        // Update every second
        cooldownInterval = setInterval(updateCooldownButton, 1000);
    }

    /**
     * Format sync messages from array - extract only message text
     * Limits display to last 50 messages to prevent UI issues
     */
    function formatSyncMessages(messageData) {
        if (!messageData) {
            return '';
        }

        if (typeof messageData === 'string') {
            return escapeHtml(messageData);
        }

        if (!Array.isArray(messageData)) {
            return '';
        }

        if (messageData.length === 0) {
            return '';
        }

        const messageTexts = [];
        messageData.forEach(function(msg) {
            if (msg && msg.message) {
                messageTexts.push(escapeHtml(msg.message));
            }
        });

        if (messageTexts.length === 0) {
            return '';
        }

        const maxDisplay = 50;
        const totalMessages = messageTexts.length;
        let displayMessages = messageTexts;
        let showMoreButton = '';

        // If there are more than maxDisplay messages, show only the last maxDisplay
        if (totalMessages > maxDisplay) {
            displayMessages = messageTexts.slice(-maxDisplay);
            const hiddenCount = totalMessages - maxDisplay;
            showMoreButton = '<div style="margin-top: 8px; padding: 8px; background: #e9ecef; border-radius: 4px; text-align: center; font-size: 11px; color: #666;">';
            showMoreButton += 'Showing last ' + maxDisplay + ' of ' + totalMessages + ' messages';
            showMoreButton += '</div>';
        }

        return '<div class="lazychat-messages-container" style="max-height: 200px; overflow-y: auto; padding: 5px 0;">' + 
               displayMessages.join('<br>') + 
               '</div>' + 
               showMoreButton;
    }
    
    /**
     * Test connection to LazyChat API
     */
    function testConnection() {
        const bearerToken = $('#lazychat_bearer_token').val();
        
        if (!bearerToken) {
            showTestResult('error', 'Please fill in Bearer Token first.');
            return;
        }
        
        showTestResult('loading', 'Testing connection...');
        disableTestButtons(true);
        
        $.ajax({
            url: lazychat_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lazychat_test_connection',
                bearer_token: bearerToken,
                nonce: lazychat_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    showTestResult('success', response.data.message);
                } else {
                    showTestResult('error', response.data.message);
                }
            },
            error: function(xhr, status, error) {
                showTestResult('error', 'Connection test failed. Please check your settings. Error: ' + error);
            },
            complete: function() {
                disableTestButtons(false);
            }
        });
    }
    
    /**
     * Test webhook functionality
     */
    function testWebhook(type) {
        const bearerToken = $('#lazychat_bearer_token').val();
        
        if (!bearerToken) {
            showTestResult('error', 'Please fill in Bearer Token first.');
            return;
        }
        
        showTestResult('loading', `Testing ${type} webhook...`);
        disableTestButtons(true);
        
        $.ajax({
            url: lazychat_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lazychat_test_webhook',
                webhook_type: type,
                bearer_token: bearerToken,
                nonce: lazychat_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    showTestResult('success', response.data.message);
                } else {
                    showTestResult('error', response.data.message);
                }
            },
            error: function(xhr, status, error) {
                showTestResult('error', `${type.charAt(0).toUpperCase() + type.slice(1)} webhook test failed. Please check your settings. Error: ${error}`);
            },
            complete: function() {
                disableTestButtons(false);
            }
        });
    }
    
    /**
     * Display test result message
     */
    function showTestResult(type, message) {
        const resultsDiv = $('#lazychat_test_results');
        resultsDiv.removeClass('success error loading').addClass(type);
        resultsDiv.html(message).show();
        
        // Auto-hide success messages after 5 seconds
        if (type === 'success') {
            setTimeout(function() {
                resultsDiv.fadeOut();
            }, 5000);
        }
    }
    
    /**
     * Enable/disable test buttons
     */
    function disableTestButtons(disable) {
        $('.test_buttons .button').prop('disabled', disable);
    }
    
    /**
     * Show logout modal
     */
    function showLogoutModal() {
        const $modal = $('#lazychat_logout_modal');
        if ($modal.length) {
            $modal.fadeIn(200);
            $('body').css('overflow', 'hidden');
        }
    }

    /**
     * Hide logout modal
     */
    function hideLogoutModal() {
        const $modal = $('#lazychat_logout_modal');
        if ($modal.length) {
            $modal.fadeOut(200);
            $('body').css('overflow', '');
        }
    }

    /**
     * Perform disconnect from LazyChat
     */
    function performDisconnect(deleteAll) {
        const $modal = $('#lazychat_logout_modal');
        const $disconnectOnly = $('#lazychat_disconnect_only');
        const $disconnectDelete = $('#lazychat_disconnect_delete');
        const $cancelBtn = $modal.find('.lazychat-modal-cancel');

        // Store original HTML if not already stored
        if (!$disconnectOnly.data('original-html')) {
            $disconnectOnly.data('original-html', $disconnectOnly.html());
        }
        if (!$disconnectDelete.data('original-html')) {
            $disconnectDelete.data('original-html', $disconnectDelete.html());
        }

        // Disable buttons
        $disconnectOnly.prop('disabled', true);
        $disconnectDelete.prop('disabled', true);
        $cancelBtn.prop('disabled', true);

        const actionText = deleteAll ? 'Disconnecting & Deleting...' : 'Disconnecting...';
        if (deleteAll) {
            $disconnectDelete.html('<strong>' + actionText + '</strong>');
        } else {
            $disconnectOnly.html('<strong>' + actionText + '</strong>');
        }

        $.ajax({
            url: lazychat_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lazychat_disconnect',
                nonce: lazychat_ajax.nonce,
                delete_all: deleteAll ? 1 : 0
            },
            success: function(response) {
                if (response && response.success) {
                    // After successful disconnect, perform local logout
                    performLocalLogout();
                } else {
                    const errorMessage = response && response.data && response.data.message
                        ? response.data.message
                        : '❌ Failed to disconnect from LazyChat. Please try again.';
                    alert(errorMessage);
                    
                    // Re-enable buttons
                    $disconnectOnly.prop('disabled', false).html($disconnectOnly.data('original-html'));
                    $disconnectDelete.prop('disabled', false).html($disconnectDelete.data('original-html'));
                    $cancelBtn.prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                alert('❌ An unexpected error occurred while disconnecting. Error: ' + error);
                
                // Re-enable buttons
                $disconnectOnly.prop('disabled', false).html($disconnectOnly.data('original-html'));
                $disconnectDelete.prop('disabled', false).html($disconnectDelete.data('original-html'));
                $cancelBtn.prop('disabled', false);
            }
        });
    }

    /**
     * Handle local logout only (no API disconnect)
     */
    function handleLocalLogoutOnly($button) {
        const $modal = $('#lazychat_logout_modal');
        const $disconnectOnly = $('#lazychat_disconnect_only');
        const $disconnectDelete = $('#lazychat_disconnect_delete');
        const $cancelBtn = $modal.find('.lazychat-modal-cancel');

        // Store original HTML if not already stored
        if (!$button.data('original-html')) {
            $button.data('original-html', $button.html());
        }

        // Disable all buttons
        $button.prop('disabled', true);
        $disconnectOnly.prop('disabled', true);
        $disconnectDelete.prop('disabled', true);
        $cancelBtn.prop('disabled', true);

        $button.html('<strong>Logging out...</strong>');

        $.ajax({
            url: lazychat_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lazychat_logout',
                nonce: lazychat_ajax.nonce
            },
            success: function(response) {
                if (response && response.success) {
                    alert(response.data && response.data.message ? response.data.message : '✅ Local logout successful.');
                    window.location.reload();
                } else {
                    const errorMessage = response && response.data && response.data.message
                        ? response.data.message
                        : '❌ Failed to logout locally. Please try again.';
                    alert(errorMessage);
                    
                    // Re-enable buttons
                    $button.prop('disabled', false).html($button.data('original-html'));
                    $disconnectOnly.prop('disabled', false);
                    $disconnectDelete.prop('disabled', false);
                    $cancelBtn.prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                alert('❌ An unexpected error occurred while logging out. Error: ' + error);
                
                // Re-enable buttons
                $button.prop('disabled', false).html($button.data('original-html'));
                $disconnectOnly.prop('disabled', false);
                $disconnectDelete.prop('disabled', false);
                $cancelBtn.prop('disabled', false);
            }
        });
    }

    /**
     * Perform local logout (clear WordPress options)
     */
    function performLocalLogout() {
        $.ajax({
            url: lazychat_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lazychat_logout',
                nonce: lazychat_ajax.nonce
            },
            success: function(response) {
                if (response && response.success) {
                    alert(response.data && response.data.message ? response.data.message : '✅ Disconnected from LazyChat successfully.');
                    window.location.reload();
                } else {
                    alert('⚠️ Disconnected from LazyChat, but local cleanup failed. Please refresh the page.');
                    window.location.reload();
                }
            },
            error: function() {
                alert('⚠️ Disconnected from LazyChat, but local cleanup failed. Please refresh the page.');
                window.location.reload();
            }
        });
    }

    /**
     * Switch between LazyChat views (home iframe vs settings)
     */
    function switchLazychatView(view) {
        const $homeBtn = $('#lazychat_view_home');
        const $settingsBtn = $('#lazychat_view_settings');
        const $homePanel = $('#lazychat_home_container');
        const $settingsPanel = $('#lazychat_settings_container');

        if (!$homeBtn.length || !$settingsBtn.length || !$homePanel.length || !$settingsPanel.length) {
            return;
        }

        if (view === 'home') {
            $homePanel.show().addClass('is-active');
            $settingsPanel.hide().removeClass('is-active');
            $homeBtn.addClass('is-active');
            $settingsBtn.removeClass('is-active');
        } else {
            $homePanel.hide().removeClass('is-active');
            $settingsPanel.show().addClass('is-active');
            $homeBtn.removeClass('is-active');
            $settingsBtn.addClass('is-active');
        }
    }

    /**
     * Generate WooCommerce REST API keys for LazyChat
     */
    function generateWcApiKeys($button) {
        const $results = $('#lazychat_test_results');
        const originalText = $button.data('original-text') || $button.text();

        if (!$button.data('original-text')) {
            $button.data('original-text', originalText);
        }

        $button.prop('disabled', true).text('Generating...');
        $results.html('<div class="notice notice-info"><p>⏳ Generating WooCommerce API keys...</p></div>').show();

        $.ajax({
            url: lazychat_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lazychat_generate_wc_api_keys',
                nonce: lazychat_ajax.nonce
            },
            success: function(response) {
                if (response && response.success && response.data) {
                    const data = response.data;
                    let message = data.message ? data.message : '✅ WooCommerce API keys generated successfully.';

                    if (data.consumer_key && data.consumer_secret) {
                        message += '<br><br><strong>Consumer Key:</strong> ' + escapeHtml(data.consumer_key) +
                                   '<br><strong>Consumer Secret:</strong> ' + escapeHtml(data.consumer_secret);
                    }

                    if (data.description) {
                        message += '<br><strong>Description:</strong> ' + escapeHtml(data.description);
                    }

                    if (data.last_access) {
                        message += '<br><strong>Last Access:</strong> ' + escapeHtml(data.last_access);
                    }

                    if (data.api_connection) {
                        const apiStatus = data.api_connection.success ? '✅' : '⚠️';
                        const apiMessage = data.api_connection.message ? escapeHtml(data.api_connection.message) : (data.api_connection.success ? 'LazyChat store registered successfully.' : 'LazyChat store registration encountered an issue.');
                        message += '<br><strong>Store Registration:</strong> ' + apiStatus + ' ' + apiMessage;
                    }

                    $results.html('<div class="notice notice-success"><p>' + message + '</p></div>');
                } else {
                    const errorMessage = response && response.data && response.data.message
                        ? response.data.message
                        : '❌ Failed to generate WooCommerce API keys. Please try again.';
                    $results.html('<div class="notice notice-error"><p>' + errorMessage + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                $results.html('<div class="notice notice-error"><p>❌ An unexpected error occurred while generating API keys.<br><strong>Error:</strong> ' + escapeHtml(error) + '</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false).text($button.data('original-text') || originalText);
            }
        });
    }

    /**
     * Test REST API connectivity
     */
    function testRestApi() {
        const $button = $('#lazychat_test_rest_api');
        const $status = $('#lazychat_rest_api_status');
        const originalText = $button.html();
        
        $button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Testing...');
        $status.html('<div class="notice notice-info"><p>🔍 Testing REST API connectivity...</p></div>');

        $.ajax({
            url: lazychat_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lazychat_test_rest_api',
                nonce: lazychat_ajax.nonce
            },
            success: function(response) {
                if (response && response.success && response.data) {
                    const data = response.data;
                    const tests = data.tests;
                    let html = '<div class="notice notice-' + (data.overall_status ? 'success' : 'warning') + '">';
                    html += '<h4>' + (data.overall_status ? '✅ REST API is Working' : '⚠️ REST API Issues Detected') + '</h4>';
                    
                    // WordPress REST API Test
                    html += '<p><strong>WordPress REST API:</strong> ';
                    html += tests.rest_api.status ? '✅ ' : '❌ ';
                    html += escapeHtml(tests.rest_api.message) + '</p>';
                    
                    // LazyChat Endpoints Test
                    html += '<p><strong>LazyChat Endpoints:</strong> ';
                    html += tests.lazychat_endpoint.status ? '✅ ' : '❌ ';
                    html += escapeHtml(tests.lazychat_endpoint.message) + '</p>';
                    
                    // Permalink Test
                    html += '<p><strong>Permalink Structure:</strong> ';
                    html += tests.permalink.status ? '✅ ' : '❌ ';
                    html += escapeHtml(tests.permalink.message) + '</p>';
                    
                    if (!data.overall_status) {
                        html += '<br><p><strong>Recommended Action:</strong> Click the "Fix REST API" button below to automatically resolve common issues.</p>';
                    }
                    
                    html += '</div>';
                    $status.html(html);
                } else {
                    const errorMessage = response && response.data && response.data.message
                        ? response.data.message
                        : 'Failed to test REST API. Please try again.';
                    $status.html('<div class="notice notice-error"><p>❌ ' + escapeHtml(errorMessage) + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                $status.html('<div class="notice notice-error"><p>❌ An unexpected error occurred while testing REST API.<br><strong>Error:</strong> ' + escapeHtml(error) + '</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false).html(originalText);
            }
        });
    }

    /**
     * Fix REST API issues
     */
    function fixRestApi() {
        const $button = $('#lazychat_fix_rest_api');
        const $status = $('#lazychat_rest_api_status');
        const originalText = $button.html();
        
        if (!confirm('This will flush rewrite rules and may update your permalink structure. Continue?')) {
            return;
        }
        
        $button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Fixing...');
        $status.html('<div class="notice notice-info"><p>🔧 Attempting to fix REST API issues...</p></div>');

        $.ajax({
            url: lazychat_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lazychat_fix_rest_api',
                nonce: lazychat_ajax.nonce
            },
            success: function(response) {
                if (response && response.success && response.data) {
                    const data = response.data;
                    let html = '<div class="notice notice-' + (data.working ? 'success' : 'warning') + '">';
                    html += '<h4>' + escapeHtml(data.message) + '</h4>';
                    
                    if (data.actions && data.actions.length > 0) {
                        html += '<p><strong>Actions taken:</strong></p><ul style="list-style: disc; margin-left: 20px;">';
                        data.actions.forEach(function(action) {
                            html += '<li>' + escapeHtml(action) + '</li>';
                        });
                        html += '</ul>';
                    }
                    
                    if (data.next_steps && data.next_steps.length > 0) {
                        html += '<p><strong>Next steps:</strong></p><ul style="list-style: disc; margin-left: 20px;">';
                        data.next_steps.forEach(function(step) {
                            html += '<li>' + escapeHtml(step) + '</li>';
                        });
                        html += '</ul>';
                    }
                    
                    if (data.working) {
                        html += '<br><p>You can verify the fix by clicking "Test REST API" again.</p>';
                    }
                    
                    html += '</div>';
                    $status.html(html);
                } else {
                    const errorMessage = response && response.data && response.data.message
                        ? response.data.message
                        : 'Failed to fix REST API. Please try again.';
                    $status.html('<div class="notice notice-error"><p>❌ ' + escapeHtml(errorMessage) + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                $status.html('<div class="notice notice-error"><p>❌ An unexpected error occurred while fixing REST API.<br><strong>Error:</strong> ' + escapeHtml(error) + '</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false).html(originalText);
            }
        });
    }
    
});

