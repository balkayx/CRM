/**
 * Insurance CRM License Module Control
 * 
 * Frontend JavaScript controls for module access and DOM manipulation
 * 
 * @package Insurance_CRM
 * @author  Anadolu Birlik
 * @since   1.1.4
 */

(function($) {
    'use strict';

    /**
     * License Module Control Object
     */
    window.InsuranceCRMLicenseControl = {
        
        // Configuration
        config: {
            ajaxUrl: insurance_crm_ajax.ajax_url || '/wp-admin/admin-ajax.php',
            nonce: insurance_crm_ajax.nonce || '',
            checkInterval: 300000, // 5 minutes
            retryAttempts: 3,
            retryDelay: 2000
        },
        
        // Cache for module access results
        moduleAccessCache: {},
        cacheExpiry: 300000, // 5 minutes
        
        // Module display names
        moduleNames: {
            'dashboard': 'Dashboard',
            'customers': 'Müşteriler',
            'policies': 'Poliçeler',
            'quotes': 'Teklifler',
            'tasks': 'Görevler',
            'reports': 'Raporlar',
            'data_transfer': 'Veri Aktarımı'
        },
        
        // Restricted elements selectors for each module
        moduleSelectors: {
            'customers': [
                'a[href*="insurance-crm-customers"]',
                '.customer-related-action',
                '[data-module="customers"]'
            ],
            'policies': [
                'a[href*="insurance-crm-policies"]', 
                '.policy-related-action',
                '[data-module="policies"]'
            ],
            'quotes': [
                'a[href*="insurance-crm-quotes"]',
                '.quote-related-action', 
                '[data-module="quotes"]'
            ],
            'tasks': [
                'a[href*="insurance-crm-tasks"]',
                '.task-related-action',
                '[data-module="tasks"]'
            ],
            'reports': [
                'a[href*="insurance-crm-reports"]',
                '.report-related-action',
                '[data-module="reports"]'
            ],
            'data_transfer': [
                'a[href*="insurance-crm-data-transfer"]',
                '.data-transfer-action',
                '[data-module="data_transfer"]'
            ]
        },
        
        /**
         * Initialize the module control system
         */
        init: function() {
            this.bindEvents();
            this.checkPageModules();
            this.setupPeriodicCheck();
            this.setupAjaxErrorHandling();
            console.log('Insurance CRM License Module Control initialized');
        },
        
        /**
         * Bind events
         */
        bindEvents: function() {
            var self = this;
            
            // Bind click events for module-restricted elements
            $(document).on('click', '[data-module]', function(e) {
                var module = $(this).data('module');
                if (!self.checkModuleAccessSync(module)) {
                    e.preventDefault();
                    self.showModuleRestriction(module);
                    return false;
                }
            });
            
            // Bind form submission events
            $(document).on('submit', 'form[data-module]', function(e) {
                var module = $(this).data('module');
                if (!self.checkModuleAccessSync(module)) {
                    e.preventDefault();
                    self.showModuleRestriction(module);
                    return false;
                }
            });
            
            // Bind AJAX error events
            $(document).ajaxError(function(event, xhr, settings, error) {
                if (xhr.responseJSON && xhr.responseJSON.license_error) {
                    self.handleLicenseError(xhr.responseJSON);
                }
            });
        },
        
        /**
         * Check module access synchronously from cache
         */
        checkModuleAccessSync: function(module) {
            var cached = this.getFromCache(module);
            if (cached !== null) {
                return cached.hasAccess;
            }
            
            // If not in cache, assume no access for safety
            // and trigger async check
            this.checkModuleAccess(module);
            return false;
        },
        
        /**
         * Check module access via AJAX
         */
        checkModuleAccess: function(module, callback, attempt) {
            var self = this;
            attempt = attempt || 1;
            
            // Check cache first
            var cached = this.getFromCache(module);
            if (cached !== null && callback) {
                callback(cached.hasAccess, cached.data);
                return;
            }
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'check_module_access',
                    module: module,
                    nonce: this.config.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var hasAccess = response.data.has_access;
                        self.setCache(module, hasAccess, response.data);
                        
                        if (callback) {
                            callback(hasAccess, response.data);
                        }
                        
                        // Update DOM based on access
                        self.updateModuleDOM(module, hasAccess);
                    } else {
                        console.error('Module access check failed:', response.data);
                        if (callback) {
                            callback(false, null);
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error checking module access:', error);
                    
                    // Retry mechanism
                    if (attempt < self.config.retryAttempts) {
                        setTimeout(function() {
                            self.checkModuleAccess(module, callback, attempt + 1);
                        }, self.config.retryDelay * attempt);
                    } else {
                        // After max retries, assume no access for safety
                        if (callback) {
                            callback(false, null);
                        }
                        self.updateModuleDOM(module, false);
                    }
                }
            });
        },
        
        /**
         * Check all modules on current page
         */
        checkPageModules: function() {
            var self = this;
            var modulesOnPage = this.getModulesOnPage();
            
            modulesOnPage.forEach(function(module) {
                self.checkModuleAccess(module);
            });
        },
        
        /**
         * Get modules present on current page
         */
        getModulesOnPage: function() {
            var modules = [];
            var self = this;
            
            Object.keys(this.moduleSelectors).forEach(function(module) {
                var selectors = self.moduleSelectors[module];
                for (var i = 0; i < selectors.length; i++) {
                    if ($(selectors[i]).length > 0) {
                        modules.push(module);
                        break;
                    }
                }
            });
            
            return modules;
        },
        
        /**
         * Update DOM elements based on module access
         */
        updateModuleDOM: function(module, hasAccess) {
            var selectors = this.moduleSelectors[module];
            if (!selectors) return;
            
            var self = this;
            selectors.forEach(function(selector) {
                var $elements = $(selector);
                
                if (hasAccess) {
                    // Restore access
                    $elements.removeClass('license-restricted')
                            .removeClass('disabled')
                            .removeAttr('disabled')
                            .off('click.license-restriction');
                } else {
                    // Restrict access
                    $elements.addClass('license-restricted')
                            .addClass('disabled')
                            .attr('disabled', true)
                            .on('click.license-restriction', function(e) {
                                e.preventDefault();
                                self.showModuleRestriction(module);
                                return false;
                            });
                    
                    // Add visual indication
                    if (!$elements.find('.license-lock-icon').length) {
                        $elements.append(' <i class="license-lock-icon fas fa-lock" title="Lisans gerekli"></i>');
                    }
                }
            });
        },
        
        /**
         * Show module restriction message
         */
        showModuleRestriction: function(module) {
            var self = this;
            
            this.checkModuleAccess(module, function(hasAccess, data) {
                if (!hasAccess && data && data.restriction_details) {
                    self.displayRestrictionModal(data.restriction_details);
                } else {
                    // Fallback message
                    var moduleName = self.moduleNames[module] || module;
                    self.displaySimpleRestriction(moduleName);
                }
            });
        },
        
        /**
         * Display restriction modal with detailed information
         */
        displayRestrictionModal: function(details) {
            var modalHtml = '<div id="license-restriction-modal" class="license-modal-overlay">' +
                '<div class="license-modal-content">' +
                '<div class="license-modal-header">' +
                '<h2><i class="fas fa-exclamation-triangle"></i> ' + details.title + '</h2>' +
                '<button class="license-modal-close">&times;</button>' +
                '</div>' +
                '<div class="license-modal-body">' +
                '<p class="restriction-message">' + details.message + '</p>';
            
            if (details.upgrade_message) {
                modalHtml += '<p class="upgrade-message">' + details.upgrade_message + '</p>';
            }
            
            modalHtml += '<div class="license-modal-actions">' +
                '<a href="' + details.contact_info.support_url + '" class="button button-primary">' +
                details.contact_info.support_text + '</a>' +
                '<button class="button license-modal-close">Kapat</button>' +
                '</div>' +
                '</div>' +
                '</div>' +
                '</div>';
            
            // Remove existing modal
            $('#license-restriction-modal').remove();
            
            // Add modal to page
            $('body').append(modalHtml);
            
            // Bind close events
            $('.license-modal-close, .license-modal-overlay').on('click', function(e) {
                if (e.target === this) {
                    $('#license-restriction-modal').fadeOut(300, function() {
                        $(this).remove();
                    });
                }
            });
            
            // Show modal
            $('#license-restriction-modal').fadeIn(300);
        },
        
        /**
         * Display simple restriction alert
         */
        displaySimpleRestriction: function(moduleName) {
            alert('Bu özellik (' + moduleName + ') için lisansınız bulunmamaktadır. Lütfen lisans sağlayıcınızla iletişime geçin.');
        },
        
        /**
         * Setup periodic license checking
         */
        setupPeriodicCheck: function() {
            var self = this;
            
            setInterval(function() {
                self.clearCache();
                self.checkPageModules();
            }, this.config.checkInterval);
        },
        
        /**
         * Setup AJAX error handling for license errors
         */
        setupAjaxErrorHandling: function() {
            var self = this;
            
            // Global AJAX error handler
            $(document).ajaxComplete(function(event, xhr, settings) {
                if (xhr.responseJSON && xhr.responseJSON.license_error) {
                    self.handleLicenseError(xhr.responseJSON);
                }
            });
        },
        
        /**
         * Handle license errors from AJAX responses
         */
        handleLicenseError: function(errorData) {
            console.warn('License error detected:', errorData);
            
            // Clear all cache
            this.clearCache();
            
            // Show error message
            if (errorData.message) {
                this.showLicenseErrorNotice(errorData.message, errorData.redirect_url);
            }
            
            // Optionally redirect
            if (errorData.redirect_url && confirm('Lisans sorunu tespit edildi. Lisans sayfasına yönlendirilmek ister misiniz?')) {
                window.location.href = errorData.redirect_url;
            }
        },
        
        /**
         * Show license error notice
         */
        showLicenseErrorNotice: function(message, redirectUrl) {
            var noticeHtml = '<div class="notice notice-error license-error-notice is-dismissible">' +
                '<p><strong>Lisans Hatası:</strong> ' + message + '</p>';
            
            if (redirectUrl) {
                noticeHtml += '<p><a href="' + redirectUrl + '" class="button button-primary">Lisans Ayarlarına Git</a></p>';
            }
            
            noticeHtml += '</div>';
            
            // Remove existing notices
            $('.license-error-notice').remove();
            
            // Add to page
            if ($('.wrap h1').length) {
                $('.wrap h1').after(noticeHtml);
            } else {
                $('body').prepend(noticeHtml);
            }
            
            // Auto-dismiss after 10 seconds
            setTimeout(function() {
                $('.license-error-notice').fadeOut();
            }, 10000);
        },
        
        /**
         * Cache management
         */
        setCache: function(module, hasAccess, data) {
            this.moduleAccessCache[module] = {
                hasAccess: hasAccess,
                data: data,
                timestamp: Date.now()
            };
        },
        
        getFromCache: function(module) {
            var cached = this.moduleAccessCache[module];
            if (!cached) return null;
            
            // Check if cache is expired
            if (Date.now() - cached.timestamp > this.cacheExpiry) {
                delete this.moduleAccessCache[module];
                return null;
            }
            
            return cached;
        },
        
        clearCache: function() {
            this.moduleAccessCache = {};
        },
        
        /**
         * Public API methods
         */
        
        /**
         * Check if user can access a module (public method)
         */
        canAccess: function(module, callback) {
            this.checkModuleAccess(module, callback);
        },
        
        /**
         * Require module access (public method)
         */
        requireAccess: function(module, callback) {
            var self = this;
            this.checkModuleAccess(module, function(hasAccess, data) {
                if (hasAccess) {
                    if (callback) callback(true);
                } else {
                    self.showModuleRestriction(module);
                    if (callback) callback(false);
                }
            });
        },
        
        /**
         * Get accessible modules (public method)
         */
        getAccessibleModules: function(callback) {
            var self = this;
            var modules = Object.keys(this.moduleNames);
            var results = {};
            var completed = 0;
            
            modules.forEach(function(module) {
                self.checkModuleAccess(module, function(hasAccess) {
                    results[module] = hasAccess;
                    completed++;
                    
                    if (completed === modules.length && callback) {
                        callback(results);
                    }
                });
            });
        }
    };

    /**
     * Add CSS styles for restriction indicators
     */
    function addRestrictionStyles() {
        var styles = `
            <style id="license-restriction-styles">
                .license-restricted {
                    opacity: 0.5;
                    cursor: not-allowed !important;
                    pointer-events: none;
                }
                
                .license-restricted:hover {
                    opacity: 0.3;
                }
                
                .license-lock-icon {
                    color: #dc3232;
                    margin-left: 5px;
                    font-size: 12px;
                }
                
                .license-modal-overlay {
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0, 0, 0, 0.7);
                    z-index: 999999;
                    display: none;
                }
                
                .license-modal-content {
                    position: absolute;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: white;
                    border-radius: 8px;
                    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
                    max-width: 500px;
                    width: 90%;
                    max-height: 80vh;
                    overflow-y: auto;
                }
                
                .license-modal-header {
                    padding: 20px 20px 10px;
                    border-bottom: 1px solid #eee;
                    position: relative;
                }
                
                .license-modal-header h2 {
                    margin: 0;
                    color: #d63638;
                    font-size: 18px;
                }
                
                .license-modal-close {
                    position: absolute;
                    top: 15px;
                    right: 20px;
                    background: none;
                    border: none;
                    font-size: 24px;
                    cursor: pointer;
                    color: #666;
                }
                
                .license-modal-body {
                    padding: 20px;
                }
                
                .restriction-message {
                    font-size: 14px;
                    line-height: 1.5;
                    margin-bottom: 15px;
                }
                
                .upgrade-message {
                    font-size: 13px;
                    color: #666;
                    margin-bottom: 20px;
                }
                
                .license-modal-actions {
                    text-align: right;
                }
                
                .license-modal-actions .button {
                    margin-left: 10px;
                }
                
                .license-error-notice {
                    position: fixed;
                    top: 32px;
                    left: 20px;
                    right: 20px;
                    z-index: 999999;
                    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                }
                
                @media (max-width: 782px) {
                    .license-error-notice {
                        top: 46px;
                    }
                }
            </style>
        `;
        
        if (!$('#license-restriction-styles').length) {
            $('head').append(styles);
        }
    }

    /**
     * Initialize when document is ready
     */
    $(document).ready(function() {
        addRestrictionStyles();
        
        // Small delay to ensure all other scripts are loaded
        setTimeout(function() {
            window.InsuranceCRMLicenseControl.init();
        }, 100);
    });

    /**
     * Backward compatibility - expose functions globally
     */
    window.checkModuleAccess = function(module) {
        return window.InsuranceCRMLicenseControl.checkModuleAccessSync(module);
    };
    
    window.requireModuleAccess = function(module) {
        return window.InsuranceCRMLicenseControl.requireAccess(module);
    };
    
    window.showModuleRestriction = function(module) {
        window.InsuranceCRMLicenseControl.showModuleRestriction(module);
    };

})(jQuery);