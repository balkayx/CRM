<?php
/**
 * License Manager Class
 * 
 * Main license management functionality
 * 
 * @package Insurance_CRM
 * @author  Anadolu Birlik
 * @since   1.1.3
 */

if (!defined('ABSPATH')) {
    exit;
}

class Insurance_CRM_License_Manager {
    
    /**
     * Plugin version
     */
    private $version;
    
    /**
     * License API instance
     */
    public $license_api;
    
    /**
     * Grace period in days
     */
    private $grace_period_days;

    /**
     * Constructor
     * 
     * @param string $version Plugin version
     */
    public function __construct($version) {
        $this->version = $version;
        $this->grace_period_days = 7; // 1 week grace period
        
        // Initialize license API
        if (class_exists('Insurance_CRM_License_API')) {
            $this->license_api = new Insurance_CRM_License_API();
        }
        
        // Hook into WordPress
        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'handle_license_form_submission'));
        add_action('admin_notices', array($this, 'show_license_notices'));
        
        // AJAX handlers
        add_action('wp_ajax_validate_license', array($this, 'ajax_validate_license'));
        add_action('wp_ajax_nopriv_validate_license', array($this, 'ajax_validate_license'));
        add_action('wp_login', array($this, 'validate_license_on_login'), 10, 2);
        
        // Periodic license check (every 60 minutes)
        add_action('insurance_crm_periodic_license_check', array($this, 'perform_periodic_license_check'));
        if (!wp_next_scheduled('insurance_crm_periodic_license_check')) {
            wp_schedule_event(time(), 'insurance_crm_60_minutes', 'insurance_crm_periodic_license_check');
        }
        
        // Daily license logging (every 24 hours)
        add_action('insurance_crm_daily_license_log', array($this, 'perform_daily_license_logging'));
        if (!wp_next_scheduled('insurance_crm_daily_license_log')) {
            wp_schedule_event(time(), 'daily', 'insurance_crm_daily_license_log');
        }
        
        // Add custom cron schedules
        add_filter('cron_schedules', array($this, 'add_custom_cron_schedules'));
    }

    /**
     * Initialize license manager
     */
    public function init() {
        // Check license status if needed
        $this->maybe_check_license_status();
    }

    /**
     * Check if license is valid
     * 
     * @return bool True if license is valid
     */
    public function is_license_valid() {
        // Check if license bypass is enabled
        if ($this->license_api && $this->license_api->is_license_bypassed()) {
            return true;
        }

        $license_key = get_option('insurance_crm_license_key', '');
        if (empty($license_key)) {
            return false;
        }

        $license_status = get_option('insurance_crm_license_status', 'inactive');
        return $license_status === 'active';
    }

    /**
     * Check if license is in grace period
     * 
     * @return bool True if in grace period
     */
    public function is_in_grace_period() {
        $license_status = get_option('insurance_crm_license_status', 'inactive');
        if ($license_status !== 'expired') {
            return false;
        }

        $license_expiry = get_option('insurance_crm_license_expiry', '');
        if (empty($license_expiry)) {
            return false;
        }

        $expiry_date = strtotime($license_expiry);
        $grace_period_end = $expiry_date + ($this->grace_period_days * 24 * 60 * 60);
        
        $is_in_grace = time() <= $grace_period_end;
        
        // Update can_access_data logic to include grace period
        return $is_in_grace;
    }

    /**
     * Get remaining grace period days
     * 
     * @return int Days remaining in grace period
     */
    public function get_grace_period_days_remaining() {
        if (!$this->is_in_grace_period()) {
            return 0;
        }

        $license_expiry = get_option('insurance_crm_license_expiry', '');
        $expiry_date = strtotime($license_expiry);
        $grace_period_end = $expiry_date + ($this->grace_period_days * 24 * 60 * 60);
        
        return ceil(($grace_period_end - time()) / (24 * 60 * 60));
    }

    /**
     * Check if license expires within specified days
     * 
     * @param int $days Number of days to check (default: 3)
     * @return bool True if license expires within specified days
     */
    public function is_license_expiring_soon($days = 3) {
        $license_status = get_option('insurance_crm_license_status', 'inactive');
        
        // Only check for active licenses
        if ($license_status !== 'active') {
            return false;
        }

        $license_expiry = get_option('insurance_crm_license_expiry', '');
        if (empty($license_expiry)) {
            return false;
        }

        $expiry_date = strtotime($license_expiry);
        $current_time = time();
        $days_until_expiry = ceil(($expiry_date - $current_time) / (24 * 60 * 60));
        
        // Check if license expires within the specified days but hasn't expired yet
        return $days_until_expiry <= $days && $days_until_expiry > 0;
    }

    /**
     * Get days remaining until license expires
     * 
     * @return int Days remaining until expiry (0 if expired or no expiry date)
     */
    public function get_days_until_expiry() {
        $license_expiry = get_option('insurance_crm_license_expiry', '');
        if (empty($license_expiry)) {
            return 0;
        }

        $expiry_date = strtotime($license_expiry);
        $current_time = time();
        $days_until_expiry = ceil(($expiry_date - $current_time) / (24 * 60 * 60));
        
        return max(0, $days_until_expiry);
    }

    /**
     * Check if license allows access to data
     * 
     * @return bool True if data access is allowed
     */
    public function can_access_data() {
        // Check if access is explicitly restricted (for expired licenses past grace period)
        if (get_option('insurance_crm_license_access_restricted', false)) {
            return false;
        }
        
        // Allow access for valid licenses or those in grace period
        return $this->is_license_valid() || $this->is_in_grace_period();
    }

    /**
     * Get current user count
     * 
     * @return int Number of active users
     */
    public function get_current_user_count() {
        global $wpdb;
        
        // Sadece aktif temsilcileri say
        $active_representatives = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}insurance_crm_representatives 
            WHERE status = %s
        ", 'active'));
        
        // Sadece CRM kullanıcıları olan admin'leri say
        $admin_users = get_users(array(
            'role' => 'administrator',
            'meta_query' => array(
                array(
                    'key' => 'wp_capabilities',
                    'value' => 'insurance_representative',
                    'compare' => 'LIKE'
                )
            )
        ));
        
        $total_active_users = intval($active_representatives) + count($admin_users);
        
        return $total_active_users;
    }

    /**
     * Check if user limit is exceeded
     * 
     * @return bool True if limit is exceeded
     */
    public function is_user_limit_exceeded() {
        $user_limit = get_option('insurance_crm_license_user_limit', 5);
        $current_users = $this->get_current_user_count();
        
        return $current_users > $user_limit;
    }

    /**
     * Validate license (without saving)
     * 
     * @param string $license_key License key to validate
     * @return bool True if license is valid
     */
    public function validate_license($license_key) {
        if (!$this->license_api) {
            return false;
        }

        $response = $this->license_api->validate_license($license_key);
        
        // Handle WP_Error objects
        if (is_object($response) && get_class($response) === 'WP_Error') {
            return false;
        }
        
        return is_array($response) && $response['status'] === 'active';
    }

    /**
     * Activate license
     * 
     * @param string $license_key License key to activate
     * @return array Result of activation
     */
    public function activate_license($license_key) {
        if (!$this->license_api) {
            return array(
                'success' => false,
                'message' => 'Lisans API sınıfı yüklenemedi'
            );
        }

        $response = $this->license_api->validate_license($license_key);
        
        // Handle WP_Error objects
        if (is_object($response) && get_class($response) === 'WP_Error') {
            // Deactivate any existing license on validation failure
            $this->deactivate_license_completely();
            return array(
                'success' => false,
                'message' => 'Lisans doğrulaması başarısız: ' . $response->get_error_message()
            );
        }
        
        if (is_array($response) && $response['status'] === 'active') {
            // Save license information
            update_option('insurance_crm_license_key', $license_key);
            update_option('insurance_crm_license_status', 'active');
            update_option('insurance_crm_license_type', $response['license_type'] ?? '');
            update_option('insurance_crm_license_package', $response['license_package'] ?? '');
            update_option('insurance_crm_license_type_description', $response['license_type_description'] ?? '');
            update_option('insurance_crm_license_expiry', $response['expires_on'] ?? '');
            update_option('insurance_crm_license_user_limit', $response['user_limit'] ?? 5);
            update_option('insurance_crm_license_modules', $response['modules'] ?? array());
            update_option('insurance_crm_license_access_restricted', false);
            update_option('insurance_crm_license_last_check', current_time('mysql'));
            
            error_log('[LISANS DEBUG] License activated successfully: ' . $license_key);
            
            return array(
                'success' => true,
                'message' => 'Lisans başarıyla etkinleştirildi'
            );
        } else {
            // Deactivate any existing license on validation failure
            $this->deactivate_license_completely();
            
            $message = 'Lisans etkinleştirilemedi - lisans anahtarı geçersiz veya sunucuda bulunamadı';
            if (is_array($response) && isset($response['message'])) {
                $message = $response['message'];
            }
            
            error_log('[LISANS DEBUG] License activation failed: ' . $message);
            
            return array(
                'success' => false,
                'message' => $message
            );
        }
    }

    /**
     * Deactivate license
     * 
     * @return array Result of deactivation
     */
    public function deactivate_license() {
        update_option('insurance_crm_license_status', 'inactive');
        
        return array(
            'success' => true,
            'message' => 'Lisans devre dışı bırakıldı'
        );
    }

    /**
     * Maybe check license status (if not checked recently)
     */
    private function maybe_check_license_status() {
        $last_check = get_option('insurance_crm_license_last_check', '');
        
        // Check every 4 hours
        if (empty($last_check) || 
            strtotime($last_check) < (time() - 4 * 60 * 60)) {
            $this->perform_license_check();
        }
    }

    /**
     * Perform license status check with enhanced validation
     */
    public function perform_license_check() {
        if (!$this->license_api) {
            return;
        }

        $license_key = get_option('insurance_crm_license_key', '');
        if (empty($license_key)) {
            // No license key, deactivate any existing license
            $this->deactivate_license_completely();
            return;
        }

        $response = $this->license_api->check_license_status($license_key);
        
        // Handle WP_Error objects (communication errors)
        if (is_object($response) && get_class($response) === 'WP_Error') {
            error_log('[LISANS DEBUG] License check communication error: ' . $response->get_error_message());
            // Don't update status on communication error, keep last known status
            return; 
        }
        
        // Handle successful server response
        if (is_array($response) && !isset($response['offline'])) {
            $server_status = $response['status'] ?? 'invalid';
            
            // If server says license is invalid, deleted, or expired, deactivate immediately
            if (in_array($server_status, array('invalid', 'deleted', 'not_found', 'inactive'))) {
                error_log('[LISANS DEBUG] Server reported license as invalid/deleted: ' . $server_status);
                $this->deactivate_license_completely();
                return;
            }
            
            // If server says expired, update status but keep some data for grace period
            if ($server_status === 'expired') {
                update_option('insurance_crm_license_status', 'expired');
                if (isset($response['expires_on'])) {
                    update_option('insurance_crm_license_expiry', $response['expires_on']);
                }
                // Check if grace period has ended
                if (!$this->is_in_grace_period()) {
                    error_log('[LISANS DEBUG] License expired and grace period ended');
                    // Don't completely deactivate, but restrict access
                    update_option('insurance_crm_license_access_restricted', true);
                }
            } else {
                // License is active, update all information
                update_option('insurance_crm_license_status', $server_status);
                update_option('insurance_crm_license_access_restricted', false);
                
                if (isset($response['expires_on'])) {
                    update_option('insurance_crm_license_expiry', $response['expires_on']);
                }
                if (isset($response['user_limit'])) {
                    update_option('insurance_crm_license_user_limit', $response['user_limit']);
                }
                if (isset($response['modules'])) {
                    update_option('insurance_crm_license_modules', $response['modules']);
                }
                if (isset($response['license_type'])) {
                    update_option('insurance_crm_license_type', $response['license_type']);
                }
                if (isset($response['license_package'])) {
                    update_option('insurance_crm_license_package', $response['license_package']);
                }
                if (isset($response['license_type_description'])) {
                    update_option('insurance_crm_license_type_description', $response['license_type_description']);
                }
            }
        }
        
        update_option('insurance_crm_license_last_check', current_time('mysql'));
    }

    /**
     * Daily license check (deprecated, use periodic check)
     */
    public function perform_daily_license_check() {
        $this->perform_license_check();
    }
    
    /**
     * Periodic license check (every 4 hours)
     */
    public function perform_periodic_license_check() {
        error_log('[LISANS DEBUG] Performing periodic license check (4-hour interval)');
        $this->perform_license_check();
    }

    /**
     * Completely deactivate license and clear all data
     */
    public function deactivate_license_completely() {
        error_log('[LISANS DEBUG] Completely deactivating license - clearing all data');
        
        update_option('insurance_crm_license_status', 'inactive');
        update_option('insurance_crm_license_key', '');
        update_option('insurance_crm_license_type', '');
        update_option('insurance_crm_license_package', '');
        update_option('insurance_crm_license_type_description', '');
        update_option('insurance_crm_license_expiry', '');
        update_option('insurance_crm_license_user_limit', 5);
        update_option('insurance_crm_license_modules', array());
        update_option('insurance_crm_license_access_restricted', true);
        update_option('insurance_crm_license_last_check', current_time('mysql'));
    }

    /**
     * Add custom cron schedules
     */
    public function add_custom_cron_schedules($schedules) {
        $schedules['insurance_crm_60_minutes'] = array(
            'interval' => 60 * MINUTE_IN_SECONDS,
            'display' => __('Every 60 Minutes (Insurance CRM License Check)')
        );
        return $schedules;
    }

    /**
     * Validate license on user login
     * 
     * @param string $user_login Username
     * @param WP_User $user User object
     */
    public function validate_license_on_login($user_login, $user) {
        // Only check for insurance users
        if (!in_array('insurance_representative', $user->roles) && 
            !in_array('administrator', $user->roles)) {
            return;
        }

        error_log('[LISANS DEBUG] User login detected, performing license validation: ' . $user_login);
        
        // Perform immediate license check on every login
        $this->perform_license_check();
        
        // Get license details for logging
        $license_status = get_option('insurance_crm_license_status', 'inactive');
        $license_key = get_option('insurance_crm_license_key', '');
        $license_expiry = get_option('insurance_crm_license_expiry', '');
        $is_restricted = get_option('insurance_crm_license_access_restricted', false);
        $is_bypassed = $this->license_api ? $this->license_api->is_license_bypassed() : false;
        
        // Log license validation result to database
        $this->log_license_validation_result($user->ID, array(
            'user_login' => $user_login,
            'license_status' => $license_status,
            'license_key_partial' => !empty($license_key) ? substr($license_key, 0, 8) . '...' : 'None',
            'license_expiry' => $license_expiry,
            'is_restricted' => $is_restricted,
            'is_bypassed' => $is_bypassed,
            'validation_time' => current_time('mysql'),
            'ip_address' => $this->get_client_ip()
        ));
        
        // If license is invalid or access is restricted, log them out
        if (!$this->can_access_data()) {
            error_log('[LISANS DEBUG] License check failed on login - Status: ' . $license_status . ', Restricted: ' . ($is_restricted ? 'Yes' : 'No'));
            
            // Allow access only to license management for expired/invalid licenses
            if ($license_status !== 'active' && !$this->is_in_grace_period()) {
                // Don't log them out, but they'll be redirected to license page by access control
                error_log('[LISANS DEBUG] User will be restricted to license management only');
            }
        } else {
            error_log('[LISANS DEBUG] License validation successful for user: ' . $user_login);
        }
    }

    /**
     * Handle license form submission
     */
    public function handle_license_form_submission() {
        if (!isset($_POST['insurance_crm_license_nonce']) || 
            !wp_verify_nonce($_POST['insurance_crm_license_nonce'], 'insurance_crm_license')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $action = sanitize_text_field($_POST['insurance_crm_license_action'] ?? '');
        
        if ($action === 'activate') {
            $license_key = sanitize_text_field($_POST['insurance_crm_license_key'] ?? '');
            $result = $this->activate_license($license_key);
            
            if ($result['success']) {
                add_settings_error('insurance_crm_license', 'license_activated', 
                    $result['message'], 'updated');
            } else {
                add_settings_error('insurance_crm_license', 'license_activation_failed', 
                    $result['message'], 'error');
            }
        } elseif ($action === 'deactivate') {
            $result = $this->deactivate_license();
            add_settings_error('insurance_crm_license', 'license_deactivated', 
                $result['message'], 'updated');
        }
    }

    /**
     * Get license notices for frontend display
     * 
     * @return array Array of notices
     */
    public function get_frontend_license_notices() {
        $notices = array();
        $license_status = get_option('insurance_crm_license_status', 'inactive');
        
        if ($license_status === 'inactive' || $license_status === 'invalid') {
            $notices[] = array(
                'type' => 'warning',
                'message' => '<strong>Insurance CRM:</strong> Lisansınız etkin değil. Lütfen lisansınızı etkinleştirin.'
            );
        } elseif ($license_status === 'expired') {
            if ($this->is_in_grace_period()) {
                $days_remaining = $this->get_grace_period_days_remaining();
                $notices[] = array(
                    'type' => 'error',
                    'message' => '<strong>Insurance CRM:</strong> Lisansınızın süresi dolmuştur. Lütfen ' . $days_remaining . ' gün içinde ödemenizi yaparak yenileyin.'
                );
            } else {
                $notices[] = array(
                    'type' => 'error',
                    'message' => '<strong>Insurance CRM:</strong> Lisansınızın süresi dolmuştur ve ek kullanım süreniz sona ermiştir. Uygulamamızı kullanabilmek için lütfen ödemenizi yapın ve lisansınızı yenileyin.'
                );
            }
        }

        // Check user limit
        if ($this->is_user_limit_exceeded()) {
            $notices[] = array(
                'type' => 'warning',
                'message' => '<strong>Insurance CRM:</strong> Kullanıcı sayısı sınırı aşıldı. Mevcut: ' . $this->get_current_user_count() . ', Limit: ' . get_option('insurance_crm_license_user_limit', 5) . '. Lütfen lisansınızı yükseltin.'
            );
        }

        return $notices;
    }

    /**
     * Show license notices in admin
     */
    public function show_license_notices() {
        // Only show on CRM pages
        if (!isset($_GET['page']) || strpos($_GET['page'], 'insurance-crm') === false) {
            return;
        }

        // Skip on license settings page to avoid duplicate notices
        if ($_GET['page'] === 'insurance-crm-license') {
            return;
        }

        $license_status = get_option('insurance_crm_license_status', 'inactive');
        
        if ($license_status === 'inactive' || $license_status === 'invalid') {
            echo '<div class="notice notice-warning">';
            echo '<p><strong>Insurance CRM:</strong> Lisansınız etkin değil. ';
            echo '<a href="' . admin_url('admin.php?page=insurance-crm-license') . '">Lisans yönetimi sayfasına gidin</a>';
            echo ' ve lisansınızı etkinleştirin.</p>';
            echo '</div>';
        } elseif ($license_status === 'expired') {
            if ($this->is_in_grace_period()) {
                $days_remaining = $this->get_grace_period_days_remaining();
                echo '<div class="notice notice-error">';
                echo '<p><strong>Insurance CRM:</strong> Lisansınızın süresi dolmuştur. ';
                echo 'Ek kullanım süreniz ' . $days_remaining . ' gün. ';
                echo 'Lütfen <a href="' . admin_url('admin.php?page=insurance-crm-license') . '">lisansınızı yenileyin</a>.</p>';
                echo '</div>';
            } else {
                echo '<div class="notice notice-error">';
                echo '<p><strong>Insurance CRM:</strong> Lisansınızın süresi dolmuştur ve ek kullanım süreniz sona ermiştir. ';
                echo 'Uygulamayı kullanabilmek için <a href="' . admin_url('admin.php?page=insurance-crm-license') . '">lisansınızı yenileyin</a>.</p>';
                echo '</div>';
            }
        }

        // Check user limit
        if ($this->is_user_limit_exceeded()) {
            echo '<div class="notice notice-warning">';
            echo '<p><strong>Insurance CRM:</strong> Kullanıcı sayısı sınırı aşıldı. ';
            echo 'Mevcut: ' . $this->get_current_user_count() . ', ';
            echo 'Limit: ' . get_option('insurance_crm_license_user_limit', 5) . '. ';
            echo '<a href="' . admin_url('admin.php?page=insurance-crm-license') . '">Lisansınızı yükseltin</a>.</p>';
            echo '</div>';
        }
    }

    /**
     * Check if module is allowed by license
     * 
     * @param string $module Module name
     * @return bool True if module is allowed
     */
    public function is_module_allowed($module) {
        // If license is bypassed, allow all modules
        if ($this->license_api && $this->license_api->is_license_bypassed()) {
            return true;
        }

        // If no valid license, deny access
        if (!$this->can_access_data()) {
            return false;
        }

        $allowed_modules = get_option('insurance_crm_license_modules', array());
        
        // If no specific modules defined, allow all modules (legacy compatibility)
        // This ensures backward compatibility with existing licenses
        if (empty($allowed_modules)) {
            error_log('[LISANS DEBUG] No specific modules defined in license, allowing access to module: ' . $module);
            return true;
        }

        $is_allowed = in_array($module, $allowed_modules);
        if (!$is_allowed) {
            error_log('[LISANS DEBUG] Module not in allowed list: ' . $module . '. Allowed: ' . implode(', ', $allowed_modules));
        } else {
            error_log('[LISANS DEBUG] Module access granted: ' . $module);
        }
        
        return $is_allowed;
    }

    /**
     * Get module access restriction message
     * 
     * @param string $module Module name
     * @return string Restriction message in Turkish
     */
    public function get_module_restriction_message($module = '') {
        $module_names = array(
            'dashboard' => 'Dashboard',
            'customers' => 'Müşteriler',
            'policies' => 'Poliçeler', 
            'quotes' => 'Teklifler',
            'tasks' => 'Görevler',
            'reports' => 'Raporlar',
            'data_transfer' => 'Veri Aktarımı'
        );
        
        $module_name = isset($module_names[$module]) ? $module_names[$module] : $module;
        
        if (!empty($module)) {
            return sprintf('Bu özellik (%s) için lisansınız bulunmamaktadır. Lütfen lisans sağlayıcınızla iletişime geçin.', $module_name);
        } else {
            return 'Bu özellik için lisansınız bulunmamaktadır. Lütfen lisans sağlayıcınızla iletişime geçin.';
        }
    }

    /**
     * Display module access restriction notice with enhanced template
     * 
     * @param string $module Module name
     * @param bool $die Whether to stop execution (default: false)
     */
    public function show_module_restriction_notice($module = '', $die = false) {
        // Check if we have the custom restriction page template
        $template_path = plugin_dir_path(dirname(__FILE__)) . 'templates/license-restriction-page.php';
        
        if (file_exists($template_path) && !empty($module)) {
            // Store module info for the template
            $_GET['restriction'] = 'module';
            $_GET['module'] = $module;
            
            // Include the custom template
            include $template_path;
        } else {
            // Fallback to the basic restriction display
            $message = $this->get_module_restriction_message($module);
            
            echo '<div class="wrap">';
            echo '<div class="notice notice-error license-restriction-notice">';
            echo '<h2>Modül Erişimi Kısıtlı</h2>';
            echo '<p><strong>Erişim Kısıtlı:</strong> ' . esc_html($message) . '</p>';
            echo '<p><a href="' . admin_url('admin.php?page=insurance-crm-license') . '" class="button button-primary">Lisans Yönetimine Git</a></p>';
            echo '</div>';
            echo '</div>';
        }
        
        if ($die) {
            exit;
        }
    }

    /**
     * Check module access and show restriction if needed
     * 
     * @param string $module Module name
     * @param bool $die Whether to stop execution if restricted (default: true)
     * @return bool True if access allowed, false if restricted
     */
    public function check_module_access($module, $die = true) {
        if ($this->is_module_allowed($module)) {
            return true;
        }
        
        if ($die) {
            $this->show_module_restriction_notice($module, true);
        } else {
            $this->show_module_restriction_notice($module, false);
        }
        
        return false;
    }

    /**
     * Get list of licensed modules
     * 
     * @return array Array of licensed module slugs
     */
    public function get_licensed_modules() {
        return get_option('insurance_crm_license_modules', array());
    }

    /**
     * Get list of all available modules with their display names
     * 
     * @return array Array of module slug => display name
     */
    public function get_all_modules() {
        return array(
            'dashboard' => 'Dashboard',
            'customers' => 'Müşteriler',
            'policies' => 'Poliçeler', 
            'quotes' => 'Teklifler',
            'tasks' => 'Görevler',
            'reports' => 'Raporlar',
            'data_transfer' => 'Veri Aktarımı'
        );
    }

    /**
     * Get module access status for all modules
     * 
     * @return array Array of module => access status
     */
    public function get_module_access_status() {
        $all_modules = $this->get_all_modules();
        $status = array();
        
        foreach ($all_modules as $slug => $name) {
            $status[$slug] = array(
                'name' => $name,
                'allowed' => $this->is_module_allowed($slug),
                'licensed' => in_array($slug, $this->get_licensed_modules())
            );
        }
        
        return $status;
    }

    /**
     * Get license information for display
     * 
     * @return array License information
     */
    public function get_license_info() {
        return array(
            'key' => get_option('insurance_crm_license_key', ''),
            'status' => get_option('insurance_crm_license_status', 'inactive'),
            'type' => get_option('insurance_crm_license_type', ''),
            'package' => get_option('insurance_crm_license_package', ''),
            'type_description' => get_option('insurance_crm_license_type_description', ''),
            'expiry' => get_option('insurance_crm_license_expiry', ''),
            'user_limit' => get_option('insurance_crm_license_user_limit', 5),
            'modules' => get_option('insurance_crm_license_modules', array()),
            'last_check' => get_option('insurance_crm_license_last_check', ''),
            'current_users' => $this->get_current_user_count(),
            'in_grace_period' => $this->is_in_grace_period(),
            'grace_days_remaining' => $this->get_grace_period_days_remaining(),
            'expiring_soon' => $this->is_license_expiring_soon(3),
            'days_until_expiry' => $this->get_days_until_expiry()
        );
    }

    /**
     * AJAX handler for license validation
     */
    public function ajax_validate_license() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'validate_license')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }
        
        // Get license key
        $license_key = isset($_POST['license_key']) ? sanitize_text_field($_POST['license_key']) : '';
        
        if (empty($license_key)) {
            wp_send_json_error(array('message' => 'License key is required'));
            return;
        }
        
        // Validate license
        $validation_result = $this->validate_license($license_key);
        
        if ($validation_result) {
            wp_send_json_success(array('message' => 'License validated successfully'));
        } else {
            wp_send_json_error(array('message' => 'License validation failed'));
        }
    }

    /**
     * Cleanup on plugin deactivation
     */
    public static function deactivation_cleanup() {
        wp_clear_scheduled_hook('insurance_crm_daily_license_check');
        wp_clear_scheduled_hook('insurance_crm_periodic_license_check');
        wp_clear_scheduled_hook('insurance_crm_daily_license_log');
    }
    
    /**
     * Perform daily license logging to debug.log
     */
    public function perform_daily_license_logging() {
        $license_info = $this->get_license_info();
        $timestamp = current_time('Y-m-d H:i:s');
        
        $log_entry = "\n[{$timestamp}] INSURANCE CRM DAILY LICENSE STATUS:\n";
        $log_entry .= "Status: " . $license_info['status'] . "\n";
        $log_entry .= "License Key: " . (!empty($license_info['key']) ? substr($license_info['key'], 0, 8) . '...' : 'None') . "\n";
        $log_entry .= "Type: " . $license_info['type'] . "\n";
        $log_entry .= "Package: " . $license_info['package'] . "\n";
        $log_entry .= "Expiry: " . $license_info['expiry'] . "\n";
        $log_entry .= "User Limit: " . $license_info['user_limit'] . "\n";
        $log_entry .= "Current Users: " . $license_info['current_users'] . "\n";
        $log_entry .= "In Grace Period: " . ($license_info['in_grace_period'] ? 'Yes' : 'No') . "\n";
        if ($license_info['in_grace_period']) {
            $log_entry .= "Grace Days Remaining: " . $license_info['grace_days_remaining'] . "\n";
        }
        $log_entry .= "Last Check: " . $license_info['last_check'] . "\n";
        $log_entry .= "Days Until Expiry: " . $license_info['days_until_expiry'] . "\n";
        $log_entry .= "----------------------------------------\n";
        
        // Write to debug.log
        $debug_log_path = WP_CONTENT_DIR . '/debug.log';
        error_log($log_entry, 3, $debug_log_path);
    }
    
    /**
     * Log license validation result to database
     */
    private function log_license_validation_result($user_id, $validation_data) {
        global $wpdb;
        
        // Create license validation logs table if it doesn't exist
        $this->create_license_logs_table();
        
        $table_name = $wpdb->prefix . 'insurance_license_logs';
        
        $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'user_login' => $validation_data['user_login'],
                'license_status' => $validation_data['license_status'],
                'license_key_partial' => $validation_data['license_key_partial'],
                'license_expiry' => $validation_data['license_expiry'],
                'is_restricted' => $validation_data['is_restricted'] ? 1 : 0,
                'is_bypassed' => $validation_data['is_bypassed'] ? 1 : 0,
                'validation_result' => $validation_data['license_status'] === 'active' ? 'success' : 'failed',
                'ip_address' => $validation_data['ip_address'],
                'created_at' => $validation_data['validation_time']
            ),
            array('%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s')
        );
    }
    
    /**
     * Create license validation logs table
     */
    private function create_license_logs_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'insurance_license_logs';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id int(11) NOT NULL,
            user_login varchar(60) NOT NULL,
            license_status varchar(20) NOT NULL,
            license_key_partial varchar(50) DEFAULT NULL,
            license_expiry datetime DEFAULT NULL,
            is_restricted tinyint(1) DEFAULT 0,
            is_bypassed tinyint(1) DEFAULT 0,
            validation_result varchar(20) NOT NULL,
            ip_address varchar(45) DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY license_status (license_status),
            KEY validation_result (validation_result),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (array_map('trim', explode(',', $_SERVER[$key])) as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Get JavaScript function for client-side module checking with enhanced features
     * 
     * @return string JavaScript function
     */
    public function get_module_check_js() {
        $licensed_modules = $this->get_licensed_modules();
        $js_modules = json_encode($licensed_modules);
        
        return "
        <script>
        window.insuranceCRMLicensedModules = {$js_modules};
        
        function checkModuleAccess(module) {
            return window.insuranceCRMLicensedModules.includes(module);
        }
        
        function showModuleRestriction(module) {
            const moduleNames = {
                'dashboard': 'Dashboard',
                'customers': 'Müşteriler',
                'policies': 'Poliçeler',
                'quotes': 'Teklifler',
                'tasks': 'Görevler',
                'reports': 'Raporlar',
                'data_transfer': 'Veri Aktarımı'
            };
            
            const moduleName = moduleNames[module] || module;
            
            // Use the enhanced module control system if available
            if (window.InsuranceCRMLicenseControl) {
                window.InsuranceCRMLicenseControl.showModuleRestriction(module);
            } else {
                // Fallback to simple alert
                alert('Bu özellik (' + moduleName + ') için lisansınız bulunmamaktadır. Lütfen lisans sağlayıcınızla iletişime geçin.');
            }
            return false;
        }
    
        function requireModuleAccess(module) {
            if (!checkModuleAccess(module)) {
                return showModuleRestriction(module);
            }
            return true;
        }
        
        // Enhanced module validation with server-side check
        function validateModuleAccessAsync(module, callback) {
            if (window.InsuranceCRMLicenseControl) {
                window.InsuranceCRMLicenseControl.canAccess(module, callback);
            } else if (callback) {
                callback(checkModuleAccess(module));
            }
        }
        
        // Auto-initialize enhanced controls if available
        jQuery(document).ready(function() {
            if (window.InsuranceCRMLicenseControl) {
                console.log('Enhanced license module control active');
            } else {
                console.log('Basic license module control active');
            }
        });
        </script>";
    }
}