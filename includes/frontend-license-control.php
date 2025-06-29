<?php
/**
 * Frontend License Control Functions
 * 
 * Handles license checking and warnings for representative panel modules
 * 
 * @package Insurance_CRM
 * @author  Anadolu Birlik
 * @since   1.1.4
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if module is accessible for frontend representative panel
 * 
 * @param string $module Module name
 * @return bool True if module is accessible
 */
function insurance_crm_frontend_can_access_module($module) {
    global $insurance_crm_license_manager;
    
    // Admin users always have access
    if (current_user_can('administrator')) {
        return true;
    }
    
    // Check if license manager exists
    if (!$insurance_crm_license_manager) {
        error_log('[LISANS DEBUG] Frontend: No license manager available, allowing access to module: ' . $module);
        return true; // Allow access if license manager is not available
    }
    
    // Check license bypass first - show debug info for admins
    if ($insurance_crm_license_manager->license_api && 
        $insurance_crm_license_manager->license_api->is_license_bypassed()) {
        
        // Log bypass usage for debugging
        error_log('[LISANS DEBUG] Frontend: License bypass is ENABLED - all modules allowed for module: ' . $module);
        
        // Show admin notice if user has admin capabilities
        if (current_user_can('manage_options')) {
            add_action('wp_footer', function() {
                echo '<div style="position: fixed; top: 32px; right: 20px; background: #ff6b6b; color: white; padding: 10px; border-radius: 5px; font-size: 12px; z-index: 9999; box-shadow: 0 2px 10px rgba(0,0,0,0.3);">
                    ⚠️ LİSANS BYPASS AKTIF - Tüm modüller erişilebilir
                </div>';
            });
        }
        
        return true;
    }
    
    // Check basic license validity
    if (!$insurance_crm_license_manager->can_access_data()) {
        error_log('[LISANS DEBUG] Frontend: Basic license access failed for module: ' . $module);
        return false;
    }
    
    // Use the license manager's module check directly (it now handles empty modules properly)
    $is_allowed = $insurance_crm_license_manager->is_module_allowed($module);
    error_log('[LISANS DEBUG] Frontend: Module ' . $module . ' access result: ' . ($is_allowed ? 'allowed' : 'denied'));
    
    return $is_allowed;
}

/**
 * Display inline license warning for frontend modules
 * 
 * @param string $module Module name
 * @param string $module_name Display name for the module
 */
function insurance_crm_display_frontend_license_warning($module, $module_name = '') {
    global $insurance_crm_license_manager;
    
    if (empty($module_name)) {
        $module_name = ucfirst($module);
    }
    
    // Check if bypass mode is enabled (should not happen in this function but just in case)
    $is_bypassed = $insurance_crm_license_manager && 
                   $insurance_crm_license_manager->license_api && 
                   $insurance_crm_license_manager->license_api->is_license_bypassed();
    
    echo '<div class="frontend-license-warning" style="
        background-color: #fff3cd;
        border: 1px solid #ffc107;
        border-radius: 8px;
        padding: 15px 20px;
        margin: 20px 0;
        color: #856404;
        font-size: 14px;
        line-height: 1.5;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    ">';
    
    echo '<div style="display: flex; align-items: center; gap: 10px;">';
    echo '<div style="font-size: 18px;">⚠️</div>';
    echo '<div>';
    echo '<strong>Lisans Uyarısı:</strong> ';
    echo 'Lisansınız bu modülü (' . esc_html($module_name) . ') kapsamıyor.';
    
    // Add debug info for admins
    if (current_user_can('manage_options')) {
        echo '<br><small style="color: #666; font-size: 12px;">';
        echo 'Debug: Modül = ' . esc_html($module);
        if ($is_bypassed) {
            echo ' | Bypass = Aktif (Bu uyarı görünmemeli)';
        }
        echo '</small>';
    }
    
    echo '</div>';
    echo '</div>';
    
    echo '<div style="margin-top: 10px; font-size: 13px;">';
    echo '<a href="' . generate_panel_url('license-management') . '" style="
        color: #856404;
        text-decoration: underline;
        font-weight: bold;
    ">Lisans Yönetimine Git</a>';
    echo '</div>';
    
    echo '</div>';
}

/**
 * Check and display license warning if module is not accessible
 * 
 * @param string $module Module name
 * @param string $module_name Display name for the module
 * @return bool True if module is accessible, false if warning was displayed
 */
function insurance_crm_check_frontend_module_access($module, $module_name = '') {
    if (!insurance_crm_frontend_can_access_module($module)) {
        // Redirect to license restriction page instead of showing inline warning
        $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $restriction_url = add_query_arg(array(
            'view' => 'license-restriction',
            'restriction' => 'module',
            'module' => $module
        ), get_permalink());
        
        // Use JavaScript redirect to avoid headers already sent issues
        echo '<script>window.location.href = "' . esc_url($restriction_url) . '";</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url($restriction_url) . '"></noscript>';
        
        // Also display a quick message in case JS is disabled
        echo '<div style="text-align: center; padding: 20px;">
            <p>Lisansınız bu modülü içermiyor. Yönlendiriliyorsunuz...</p>
            <p><a href="' . esc_url($restriction_url) . '">Buraya tıklayın</a> eğer otomatik yönlendirme çalışmıyorsa.</p>
        </div>';
        
        return false;
    }
    return true;
}

/**
 * Map view names to module names for license checking
 * 
 * @param string $view_name Current view name from $_GET['view']
 * @return string|null Module name or null if no mapping exists
 */
function insurance_crm_get_module_from_view($view_name) {
    $view_to_module_map = array(
        'customers' => 'customers',
        'policies' => 'policies',
        'team_policies' => 'policies',
        'tasks' => 'tasks',
        'team_tasks' => 'tasks',
        'reports' => 'reports',
        'team_reports' => 'reports',
        'offers' => 'quotes', // Frontend uses "offers" but backend uses "quotes"
        'veri_aktar' => 'data_transfer',
        'veri_aktar_facebook' => 'data_transfer',
        'iceri_aktarim' => 'data_transfer',
        'iceri_aktarim_new' => 'data_transfer',
        'import-system' => 'data_transfer'
    );
    
    return isset($view_to_module_map[$view_name]) ? $view_to_module_map[$view_name] : null;
}

/**
 * Get module display name for license warnings
 * 
 * @param string $module Module name
 * @return string Display name
 */
function insurance_crm_get_module_display_name($module) {
    $module_names = array(
        'customers' => 'Müşteriler',
        'policies' => 'Poliçeler',
        'tasks' => 'Görevler',
        'reports' => 'Raporlar',
        'quotes' => 'Teklifler',
        'offers' => 'Teklifler', // For backwards compatibility
        'data_transfer' => 'Veri Aktarımı'
    );
    
    return isset($module_names[$module]) ? $module_names[$module] : ucfirst($module);
}