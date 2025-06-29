<?php
/**
 * Admin License Management Page - Modernized Version
 * @version 2.0.0
 * @updated 2025-01-06
 * @description Backend license management page with advanced features
 */

// Doğrudan erişime izin verme
if (!defined('ABSPATH')) {
    exit;
}

// Check if this is a restriction redirect - show custom template
if (isset($_GET['restriction'])) {
    $template_path = plugin_dir_path(dirname(dirname(__FILE__))) . 'templates/license-restriction-page.php';
    if (file_exists($template_path)) {
        include $template_path;
        return;
    }
}

// Include license manager
global $insurance_crm_license_manager;

// Process license form submission
$form_result = null;
$debug_info = array();

if (isset($_POST['insurance_crm_license_action']) && isset($_POST['insurance_crm_license_nonce']) && wp_verify_nonce($_POST['insurance_crm_license_nonce'], 'insurance_crm_license')) {
    $action = sanitize_text_field($_POST['insurance_crm_license_action']);
    $license_key = sanitize_text_field($_POST['insurance_crm_license_key']);
    
    if ($action === 'activate' && !empty($license_key)) {
        if ($insurance_crm_license_manager) {
            $debug_info[] = "Lisans yöneticisi yüklendi.";
            
            // Debug mode'u etkinleştir
            $old_debug_mode = get_option('insurance_crm_license_debug_mode', false);
            update_option('insurance_crm_license_debug_mode', true);
            
            $result = $insurance_crm_license_manager->activate_license($license_key);
            
            // Debug mode'u eski haline getir
            update_option('insurance_crm_license_debug_mode', $old_debug_mode);
            
            if ($result['success']) {
                $form_result = array(
                    'success' => true,
                    'message' => $result['message']
                );
                $debug_info[] = "Lisans aktivasyonu başarılı.";
            } else {
                $form_result = array(
                    'success' => false,
                    'message' => $result['message']
                );
                $debug_info[] = "Lisans aktivasyonu başarısız: " . $result['message'];
            }
        } else {
            $form_result = array(
                'success' => false,
                'message' => 'Lisans yöneticisi yüklenemedi.'
            );
            $debug_info[] = "Lisans yöneticisi yüklenemedi.";
        }
    } elseif ($action === 'deactivate') {
        if ($insurance_crm_license_manager) {
            $result = $insurance_crm_license_manager->deactivate_license();
            if ($result['success']) {
                $form_result = array(
                    'success' => true,
                    'message' => 'Lisans başarıyla devre dışı bırakıldı.'
                );
            } else {
                $form_result = array(
                    'success' => false,
                    'message' => 'Lisans devre dışı bırakılamadı: ' . $result['message']
                );
            }
        }
    } elseif ($action === 'check') {
        if ($insurance_crm_license_manager) {
            $insurance_crm_license_manager->perform_license_check();
            $form_result = array(
                'success' => true,
                'message' => 'Lisans durumu güncellendi.'
            );
        }
    } elseif ($action === 'toggle_debug') {
        $debug_mode = isset($_POST['debug_mode']) ? true : false;
        update_option('insurance_crm_license_debug_mode', $debug_mode);
        $form_result = array(
            'success' => true,
            'message' => 'Debug modu ' . ($debug_mode ? 'etkinleştirildi' : 'devre dışı bırakıldı') . '.'
        );

    } elseif ($action === 'clear_cache') {
        // Clear all license-related transients and cache
        delete_transient('insurance_crm_license_check');
        delete_option('insurance_crm_license_last_check');
        wp_cache_delete('insurance_crm_license_data');
        $form_result = array(
            'success' => true,
            'message' => 'Lisans cache temizlendi.'
        );
    } elseif ($action === 'update_server') {
        $server_url = sanitize_url($_POST['license_server_url']);
        if (!empty($server_url)) {
            update_option('insurance_crm_license_server_url', $server_url);
            $form_result = array(
                'success' => true,
                'message' => 'Lisans sunucusu URL\'si güncellendi.'
            );
        } else {
            $form_result = array(
                'success' => false,
                'message' => 'Lütfen geçerli bir URL girin.'
            );
        }
    } elseif ($action === 'test_connection') {
        if ($insurance_crm_license_manager && $insurance_crm_license_manager->license_api) {
            $license_key = get_option('insurance_crm_license_key', '');
            
            // Run comprehensive connection test
            $connection_test = $insurance_crm_license_manager->license_api->test_server_connection();
            
            $test_message = '<strong>Bağlantı Test Sonuçları:</strong><br>';
            
            // Basic connectivity
            if ($connection_test['connectivity']['success']) {
                $test_message .= '✅ Temel bağlantı: ' . $connection_test['connectivity']['message'] . '<br>';
            } else {
                $test_message .= '❌ Temel bağlantı: ' . $connection_test['connectivity']['message'] . '<br>';
            }
            
            // WordPress detection
            $test_message .= ($connection_test['is_wordpress'] ? '✅' : '❌') . ' WordPress API: ' . $connection_test['wordpress_api'] . '<br>';
            
            // Endpoint tests
            $test_message .= '<br><strong>Endpoint Test Sonuçları:</strong><br>';
            foreach ($connection_test['endpoints'] as $endpoint => $result) {
                $status = $result['accessible'] ? '✅' : '❌';
                $test_message .= $status . ' ' . $endpoint . ' (HTTP ' . ($result['code'] ?: 'N/A') . ')<br>';
            }
            
            // License validation test if license key exists
            if (!empty($license_key)) {
                $test_message .= '<br><strong>Lisans Doğrulama Testi:</strong><br>';
                $validation_result = $insurance_crm_license_manager->license_api->validate_license($license_key);
                
                if (is_wp_error($validation_result)) {
                    $test_message .= '❌ Lisans doğrulama başarısız: ' . $validation_result->get_error_message();
                } else {
                    $test_message .= '✅ Lisans doğrulama yanıtı alındı: ' . json_encode($validation_result);
                }
            } else {
                $test_message .= '<br>ℹ️ Lisans doğrulama testi için önce bir lisans anahtarı girin.';
            }
            
            $form_result = array(
                'success' => $connection_test['connectivity']['success'],
                'message' => $test_message
            );
        } else {
            $form_result = array(
                'success' => false,
                'message' => 'Lisans API sınıfı yüklenemedi.'
            );
        }
    }
}

// Get license information
$license_info = array();
if ($insurance_crm_license_manager) {
    $license_info = $insurance_crm_license_manager->get_license_info();
} else {
    // Fallback - get from options directly
    $license_info = array(
        'key' => get_option('insurance_crm_license_key', ''),
        'status' => get_option('insurance_crm_license_status', 'inactive'),
        'type' => get_option('insurance_crm_license_type', ''),
        'package' => get_option('insurance_crm_license_package', ''),
        'type_description' => get_option('insurance_crm_license_type_description', ''),
        'expiry' => get_option('insurance_crm_license_expiry', ''),
        'user_limit' => get_option('insurance_crm_license_user_limit', 5),
        'modules' => get_option('insurance_crm_license_modules', array()),
        'last_check' => get_option('insurance_crm_license_last_check', ''),
        'current_users' => 0,
        'in_grace_period' => false,
        'grace_days_remaining' => 0
    );
}

// Get current user count
global $wpdb;
$active_users_count = $wpdb->get_var($wpdb->prepare("
    SELECT COUNT(DISTINCT r.user_id) 
    FROM {$wpdb->prefix}insurance_crm_representatives r 
    INNER JOIN {$wpdb->users} u ON r.user_id = u.ID 
    WHERE r.status = %s
", 'active')) ?: 0;

$license_info['current_users'] = $active_users_count;

// Prepare display data
$license_key = $license_info['key'];
$license_status = $license_info['status'];
$license_type = $license_info['type'];
$license_package = $license_info['package'];
$license_type_description = $license_info['type_description'];
$license_expiry = $license_info['expiry'];

// Determine status text and class
$status_text = 'Etkin Değil';
$status_class = 'invalid';

if ($license_status === 'active') {
    $status_text = 'Etkin';
    $status_class = 'valid';
} elseif ($license_status === 'expired') {
    if ($license_info['in_grace_period']) {
        $status_text = 'Süresi Dolmuş (Ek Kullanım Süresi)';
        $status_class = 'grace-period';
    } else {
        $status_text = 'Süresi Dolmuş';
        $status_class = 'expired';
    }
} elseif ($license_status === 'invalid') {
    $status_text = 'Geçersiz';
    $status_class = 'invalid';
}

// Check if access is restricted
$is_access_restricted = get_option('insurance_crm_license_access_restricted', false);
if ($is_access_restricted && $license_status !== 'active') {
    $status_text .= ' (Erişim Kısıtlı)';
    $status_class = 'restricted';
}

// Format expiry date
$expiry_date = '';
if (!empty($license_expiry)) {
    $expiry_date = date_i18n(get_option('date_format'), strtotime($license_expiry));
}

// Helper function to get license type display name
function get_license_type_display_name($license_type, $license_type_description = '') {
    // Prefer server-provided description over hardcoded mapping
    if (!empty($license_type_description)) {
        return $license_type_description;
    }
    
    // Fallback to hardcoded mapping if server doesn't provide description
    $type_map = array(
        'monthly' => 'Aylık',
        'yearly' => 'Yıllık', 
        'lifetime' => 'Ömürlük',
        'trial' => 'Deneme'
    );
    
    return isset($type_map[$license_type]) ? $type_map[$license_type] : 'Bilinmiyor';
}

// Helper function to get license package display name
function get_license_package_display_name($license_package) {
    if (empty($license_package)) {
        return 'Standart';
    }
    
    $package_map = array(
        'basic' => 'Temel Paket',
        'standard' => 'Standart Paket',
        'premium' => 'Premium Paket',
        'enterprise' => 'Kurumsal Paket',
        'unlimited' => 'Sınırsız Paket'
    );
    
    return isset($package_map[$license_package]) ? $package_map[$license_package] : ucfirst($license_package);
}

// Calculate days left
$days_left = '';
if ($license_status === 'active' && in_array($license_type, array('monthly', 'yearly', 'trial')) && !empty($license_expiry)) {
    $days_left = ceil((strtotime($license_expiry) - time()) / 86400);
    if ($days_left < 0) {
        $days_left = 0;
    }
}
?>

<style>
    .admin-license-management-container {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        padding: 20px 30px;
        max-width: 1200px;
        margin: 20px 0;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    
    .admin-license-header {
        margin-bottom: 30px;
        border-bottom: 1px solid #e0e0e0;
        padding-bottom: 15px;
    }
    
    .admin-license-header h1 {
        font-size: 24px;
        color: #333;
        margin-bottom: 5px;
        font-weight: 600;
    }
    
    .admin-license-header p {
        font-size: 14px;
        color: #666;
        margin: 0;
    }
    
    .admin-license-status-card {
        display: flex;
        align-items: center;
        gap: 20px;
        padding: 20px;
        margin-bottom: 30px;
        border-radius: 8px;
        border: 1px solid #ddd;
    }
    
    .admin-license-status-card.active {
        background: linear-gradient(135deg, #e8f5e9, #f1f8e9);
        border-color: #4caf50;
    }
    
    .admin-license-status-card.invalid,
    .admin-license-status-card.expired {
        background: linear-gradient(135deg, #ffebee, #fce4ec);
        border-color: #f44336;
    }
    
    .admin-license-status-card.grace-period {
        background: linear-gradient(135deg, #fff3e0, #fef7e0);
        border-color: #ff9800;
    }
    
    .admin-license-icon {
        font-size: 48px;
        min-width: 60px;
        text-align: center;
    }
    
    .admin-license-details h4 {
        margin: 0 0 8px 0;
        font-size: 18px;
        color: #333;
        font-weight: 600;
    }
    
    .admin-license-details p {
        margin: 4px 0;
        color: #555;
        font-size: 14px;
    }
    
    .admin-license-form-section {
        background: #f9f9f9;
        border-radius: 6px;
        padding: 20px;
        border: 1px solid #eee;
        margin-bottom: 20px;
    }
    
    .admin-license-form-section h2 {
        margin-top: 0;
        font-size: 18px;
        color: #333;
        margin-bottom: 15px;
        font-weight: 600;
        border-bottom: 1px solid #ddd;
        padding-bottom: 10px;
    }
    
    .admin-form-row {
        margin-bottom: 15px;
    }
    
    .admin-form-row label {
        display: block;
        margin-bottom: 5px;
        font-weight: 500;
        font-size: 14px;
        color: #444;
    }
    
    .admin-form-input {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
        font-family: inherit;
    }
    
    .admin-form-input:focus {
        border-color: #0073aa;
        outline: none;
        box-shadow: 0 0 0 2px rgba(0, 115, 170, 0.2);
    }
    
    .admin-form-actions {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
    }
    
    .admin-btn {
        padding: 8px 15px;
        border-radius: 4px;
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        border: 1px solid;
        transition: all 0.2s ease;
        text-decoration: none;
        display: inline-block;
        text-align: center;
    }
    
    .admin-btn-primary {
        background-color: #0073aa;
        color: white;
        border-color: #0073aa;
    }
    
    .admin-btn-primary:hover:not(:disabled) {
        background-color: #005a87;
        border-color: #005a87;
        color: white;
        text-decoration: none;
    }
    
    .admin-btn-secondary {
        background-color: #f7f7f7;
        color: #555;
        border-color: #ccc;
    }
    
    .admin-btn-secondary:hover {
        background-color: #e0e0e0;
        color: #333;
        text-decoration: none;
    }
    
    .admin-btn-danger {
        background-color: #d63638;
        color: white;
        border-color: #d63638;
    }
    
    .admin-btn-danger:hover {
        background-color: #b32d2e;
        border-color: #b32d2e;
        color: white;
        text-decoration: none;
    }
    
    .admin-notification {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 4px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .admin-notification.success {
        background-color: #e8f5e9;
        border-left: 4px solid #4caf50;
        color: #2e7d32;
    }
    
    .admin-notification.error {
        background-color: #ffebee;
        border-left: 4px solid #f44336;
        color: #c62828;
    }
    
    .admin-license-info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    
    .admin-license-info-item {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 6px;
        border-left: 4px solid #0073aa;
    }
    
    .admin-license-info-item h4 {
        margin: 0 0 8px 0;
        color: #333;
        font-size: 14px;
        font-weight: 600;
    }
    
    .admin-license-info-item p {
        margin: 0;
        color: #555;
        font-size: 14px;
    }
    
    .admin-license-status-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .admin-license-status-badge.valid {
        background-color: #e8f5e9;
        color: #2e7d32;
        border: 1px solid #4caf50;
    }
    
    .admin-license-status-badge.invalid,
    .admin-license-status-badge.restricted {
        background-color: #ffebee;
        color: #c62828;
        border: 1px solid #f44336;
    }
    
    .admin-license-status-badge.expired {
        background-color: #fff3e0;
        color: #e65100;
        border: 1px solid #ff9800;
    }
    
    .admin-license-status-badge.grace-period {
        background-color: #fff8e1;
        color: #f57c00;
        border: 1px solid #ffc107;
    }
    
    .admin-user-limit-warning {
        color: #f44336;
        font-weight: bold;
    }
    
    .admin-grace-period-warning {
        color: #ff9800;
        font-weight: bold;
    }
    
    .admin-access-restriction-warning {
        background: linear-gradient(135deg, #ffebee, #fff3e0);
        border: 2px solid #f44336;
        border-radius: 8px;
        padding: 20px;
        display: flex;
        align-items: flex-start;
        gap: 15px;
        color: #c62828;
    }
    
    .admin-access-restriction-warning i {
        font-size: 24px;
        color: #f44336;
        margin-top: 2px;
    }
    
    .admin-access-restriction-warning h4 {
        margin: 0 0 10px 0;
        color: #c62828;
        font-size: 16px;
        font-weight: 600;
    }
    
    .admin-access-restriction-warning p {
        margin: 0 0 10px 0;
        color: #c62828;
        line-height: 1.5;
    }
    
    .admin-access-restriction-warning p:last-child {
        margin-bottom: 0;
    }
    
    /* Advanced Sections Tabs */
    .admin-advanced-sections-tabs {
        margin-top: 20px;
    }
    
    .admin-tab-headers {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-bottom: 20px;
    }
    
    .admin-tab-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 15px;
        background: #f8f9fa;
        border: 1px solid #ddd;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.3s ease;
        font-size: 14px;
        font-weight: 600;
        color: #666;
    }
    
    .admin-tab-header:hover {
        background: #e9ecef;
        border-color: #0073aa;
        color: #0073aa;
    }
    
    .admin-tab-header.active {
        background: #0073aa;
        color: white;
        border-color: #0073aa;
    }
    
    .admin-tab-arrow {
        transition: transform 0.3s ease;
        font-size: 12px;
    }
    
    .admin-tab-header.active .admin-tab-arrow {
        transform: rotate(180deg);
    }
    
    .admin-tab-content {
        padding: 20px;
        border: 1px solid #ddd;
        border-radius: 6px;
        background: white;
        margin-bottom: 15px;
        animation: slideDown 0.3s ease;
    }
    
    @keyframes slideDown {
        from {
            opacity: 0;
            max-height: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            max-height: 1000px;
            transform: translateY(0);
        }
    }
    
    .admin-tab-content[style*="display: none"] {
        display: none !important;
    }
    
    /* Modules Grid Styles */
    .admin-modules-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
        margin-top: 15px;
    }
    
    .admin-module-item {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 15px;
        border-radius: 8px;
        border: 2px solid;
        transition: all 0.3s ease;
    }
    
    .admin-module-item.module-licensed {
        background: linear-gradient(135deg, #e8f5e9, #f1f8e9);
        border-color: #4caf50;
        color: #2e7d32;
    }
    
    .admin-module-item.module-unlicensed {
        background: linear-gradient(135deg, #ffebee, #fce4ec);
        border-color: #f44336;
        color: #c62828;
    }
    
    .admin-module-icon {
        font-size: 24px;
        min-width: 30px;
        text-align: center;
    }
    
    .admin-module-details h4 {
        margin: 0 0 5px 0;
        font-size: 16px;
        font-weight: 600;
    }
    
    .admin-module-status {
        font-size: 12px;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .admin-module-warning {
        background: #fff3e0;
        border: 2px solid #ff9800;
        border-radius: 8px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        color: #e65100;
        margin-top: 15px;
    }
    
    .admin-module-warning i {
        font-size: 24px;
        color: #ff9800;
    }
    
    .admin-module-warning p {
        margin: 0;
        font-size: 14px;
        line-height: 1.5;
    }
    
    @media (max-width: 768px) {
        .admin-license-management-container {
            padding: 15px;
            margin: 10px 0;
        }
        
        .admin-license-status-card {
            flex-direction: column;
            text-align: center;
            padding: 15px;
        }
        
        .admin-license-info-grid {
            grid-template-columns: 1fr;
        }
        
        .admin-form-actions {
            flex-direction: column;
        }
        
        .admin-btn {
            width: 100%;
        }
    }
</style>

<div class="wrap">
    <?php if ($form_result): ?>
    <div class="admin-notification <?php echo $form_result['success'] ? 'success' : 'error'; ?>">
        <span class="dashicons dashicons-<?php echo $form_result['success'] ? 'yes-alt' : 'warning'; ?>"></span>
        <div style="flex: 1;">
            <?php 
            // Check if message contains HTML
            if (strpos($form_result['message'], '<') !== false) {
                echo $form_result['message']; // Allow HTML for detailed test results
            } else {
                echo esc_html($form_result['message']); // Escape plain text
            }
            ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="admin-license-management-container">
        <div class="admin-license-header">
            <h1><span class="dashicons dashicons-admin-network"></span> Insurance CRM Lisans Yönetimi</h1>
            <p>Insurance CRM lisans bilgilerinizi buradan yönetebilir ve durumunuzu kontrol edebilirsiniz.</p>
        </div>
        
        <!-- License Status Display -->
        <?php if ($license_status === 'active'): ?>
            <div class="admin-license-status-card active">
                <div class="admin-license-icon">✅</div>
                <div class="admin-license-details">
                    <h4>Lisans Aktif</h4>
                    <p class="license-type">
                        <?php 
                        echo get_license_type_display_name($license_type, $license_type_description);
                        if (!empty($license_package)) {
                            echo ' - ' . get_license_package_display_name($license_package);
                        }
                        ?>
                    </p>
                    <?php if (!empty($license_type_description)): ?>
                        <p class="license-description" style="color: #666; font-size: 13px;">
                            <?php echo esc_html($license_type_description); ?>
                        </p>
                    <?php endif; ?>
                    <?php if (in_array($license_type, array('monthly', 'yearly', 'trial')) && !empty($expiry_date)): ?>
                        <p class="expiry-info">
                            Bitiş Tarihi: <?php echo $expiry_date; ?> 
                            (<?php echo $days_left; ?> gün kaldı)
                        </p>
                    <?php endif; ?>
                    <p class="user-info">
                        Kullanıcı Sayısı: <?php echo $license_info['current_users']; ?> / <?php echo $license_info['user_limit']; ?>
                        <?php if ($license_info['current_users'] > $license_info['user_limit']): ?>
                            <span class="admin-user-limit-warning">(Limit Aşıldı)</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        <?php elseif ($license_status === 'expired' && $license_info['in_grace_period']): ?>
            <div class="admin-license-status-card grace-period">
                <div class="admin-license-icon">⏰</div>
                <div class="admin-license-details">
                    <h4>Lisans Süresi Dolmuş - Ek Kullanım Süresi</h4>
                    <p class="license-type">
                        <?php 
                        echo get_license_type_display_name($license_type, $license_type_description);
                        if (!empty($license_package)) {
                            echo ' - ' . get_license_package_display_name($license_package);
                        }
                        echo ' - Süre Dolmuş';
                        ?>
                    </p>
                    <?php if (!empty($license_type_description)): ?>
                        <p class="license-description" style="color: #666; font-size: 13px;">
                            <?php echo esc_html($license_type_description); ?>
                        </p>
                    <?php endif; ?>
                    <p class="admin-grace-period-warning">
                        Kalan Süre: <?php echo $license_info['grace_days_remaining']; ?> gün
                    </p>
                    <p>Lütfen ödemenizi yaparak lisansınızı yenileyin.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="admin-license-status-card invalid">
                <div class="admin-license-icon">❌</div>
                <div class="admin-license-details">
                    <h4>Lisans Aktif Değil</h4>
                    <p>Sistem kullanımı için geçerli bir lisans anahtarı girin.</p>
                    <?php if ($license_status === 'expired'): ?>
                        <p>Lisansınızın süresi dolmuştur ve ek kullanım süreniz sona ermiştir.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- License Key Input Form -->
        <div class="admin-license-form-section">
            <h2><span class="dashicons dashicons-admin-network"></span> Lisans Anahtarı</h2>
            
            <form method="post" action="">
                <?php wp_nonce_field('insurance_crm_license', 'insurance_crm_license_nonce'); ?>
                
                <div class="admin-form-row">
                    <label for="insurance_crm_license_key">Lisans Anahtarı</label>
                    <input type="text" id="insurance_crm_license_key" name="insurance_crm_license_key" class="admin-form-input large-text" 
                           value="<?php echo esc_attr($license_key); ?>" 
                           placeholder="Lisans anahtarınızı buraya girin..." />
                </div>
                
                <div class="admin-form-actions">
                    <input type="hidden" name="insurance_crm_license_action" value="activate" />
                    <input type="submit" class="admin-btn admin-btn-primary" value="Lisans Anahtarını Kaydet ve Doğrula" />
                    
                    <?php if (!empty($license_key)): ?>
                        <button type="submit" class="admin-btn admin-btn-secondary" onclick="this.form.elements['insurance_crm_license_action'].value='check';">
                            <span class="dashicons dashicons-update"></span> Lisans Durumunu Kontrol Et
                        </button>
                        
                        <button type="submit" class="admin-btn admin-btn-danger" 
                               onclick="this.form.elements['insurance_crm_license_action'].value='deactivate'; return confirm('Lisansı devre dışı bırakmak istediğinizden emin misiniz?');">
                            <span class="dashicons dashicons-dismiss"></span> Lisansı Devre Dışı Bırak
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        
        <!-- License Information -->
        <?php if (!empty($license_key)): ?>
        <div class="admin-license-form-section">
            <h2><span class="dashicons dashicons-info"></span> Lisans Bilgileri</h2>
            
            <div class="admin-license-info-grid">
                <div class="admin-license-info-item">
                    <h4>Lisans Durumu</h4>
                    <p><span class="admin-license-status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span></p>
                </div>
                
                <div class="admin-license-info-item">
                    <h4>Lisans Türü</h4>
                    <p>
                        <?php 
                        echo get_license_type_display_name($license_type, $license_type_description);
                        ?>
                    </p>
                </div>
                
                <?php if (!empty($license_package)): ?>
                <div class="admin-license-info-item">
                    <h4>Lisans Paketi</h4>
                    <p><?php echo get_license_package_display_name($license_package); ?></p>
                </div>
                <?php endif; ?>
                
                <div class="admin-license-info-item">
                    <h4>Kullanıcı Limiti</h4>
                    <p>
                        <?php echo $license_info['current_users']; ?> / <?php echo $license_info['user_limit']; ?>
                        <?php if ($license_info['current_users'] > $license_info['user_limit']): ?>
                            <span class="admin-user-limit-warning">(Limit Aşıldı)</span>
                        <?php endif; ?>
                    </p>
                </div>
                
                <?php if (!empty($expiry_date) && $license_type !== 'lifetime'): ?>
                <div class="admin-license-info-item">
                    <h4>Lisans Süresi</h4>
                    <p>
                        <?php 
                        if ($license_type === 'monthly') {
                            echo 'Aylık abonelik - ';
                        } elseif ($license_type === 'yearly') {
                            echo 'Yıllık abonelik - ';
                        } elseif ($license_type === 'trial') {
                            echo 'Deneme süresi - ';
                        }
                        echo 'Bitiş: ' . $expiry_date;
                        if ($days_left > 0) {
                            echo ' (' . $days_left . ' gün kaldı)';
                        }
                        ?>
                    </p>
                </div>
                <?php elseif ($license_type === 'lifetime'): ?>
                <div class="admin-license-info-item">
                    <h4>Lisans Süresi</h4>
                    <p>Ömürlük lisans - Süresiz kullanım</p>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($license_info['last_check'])): ?>
                <div class="admin-license-info-item">
                    <h4>Son Kontrol</h4>
                    <p><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($license_info['last_check'])); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Licensed Modules Display -->
        <div class="admin-license-form-section">
            <h2><span class="dashicons dashicons-admin-plugins"></span> Lisanslı Modüller</h2>
            
            <?php
            // Get licensed modules
            $licensed_modules = $license_info['modules'];
            $all_modules = array(
                'dashboard' => 'Dashboard',
                'customers' => 'Müşteriler',
                'policies' => 'Poliçeler', 
                'quotes' => 'Teklifler',
                'tasks' => 'Görevler',
                'reports' => 'Raporlar',
                'data_transfer' => 'Veri Aktarımı'
            );
            ?>
            
            <div class="admin-modules-grid">
                <?php foreach ($all_modules as $module_slug => $module_name): ?>
                    <?php 
                    $is_licensed = !empty($licensed_modules) && in_array($module_slug, $licensed_modules);
                    $module_class = $is_licensed ? 'module-licensed' : 'module-unlicensed';
                    $icon_class = $is_licensed ? 'dashicons-yes-alt' : 'dashicons-dismiss';
                    $status_text = $is_licensed ? 'Lisanslı' : 'Lisans Yok';
                    ?>
                    <div class="admin-module-item <?php echo $module_class; ?>">
                        <div class="admin-module-icon">
                            <span class="dashicons <?php echo $icon_class; ?>"></span>
                        </div>
                        <div class="admin-module-details">
                            <h4><?php echo esc_html($module_name); ?></h4>
                            <span class="admin-module-status"><?php echo $status_text; ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if (empty($licensed_modules)): ?>
                <div class="admin-module-warning">
                    <span class="dashicons dashicons-warning"></span>
                    <p>Herhangi bir modül lisansı bulunamadı. Lütfen lisans anahtarınızı kontrol edin veya lisans sağlayıcınızla iletişime geçin.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($is_access_restricted): ?>
        <div class="admin-license-form-section">
            <div class="admin-access-restriction-warning">
                <span class="dashicons dashicons-warning"></span>
                <div>
                    <h4>Erişim Kısıtlı</h4>
                    <p>Lisansınızın süresi dolmuş ve ek kullanım süreniz sona ermiştir. Şu anda sadece bu lisans yönetimi sayfasına erişebilirsiniz.</p>
                    <p><strong>CRM özelliklerini kullanabilmek için lütfen lisansınızı yenileyin veya geçerli bir lisans anahtarı girin.</strong></p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        
        <!-- Help and Contact -->
        <div class="admin-license-form-section">
            <h2><span class="dashicons dashicons-sos"></span> Yardım ve İletişim</h2>
            
            <div class="admin-license-info-grid">
                <div class="admin-license-info-item">
                    <h4>Lisans Satın Alma</h4>
                    <p>Yeni lisans satın almak için: <a href="https://www.balkay.net/crm" target="_blank">www.balkay.net/crm</a></p>
                </div>
                
                <div class="admin-license-info-item">
                    <h4>Teknik Destek</h4>
                    <p>Lisans sorunları için <a href="<?php echo admin_url('admin.php?page=insurance-crm'); ?>">CRM Paneli</a>'ne gidin.</p>
                </div>
                
                <div class="admin-license-info-item">
                    <h4>Ödeme Sorunları</h4>
                    <p>Ödeme ve faturalandırma: <a href="mailto:info@balkay.net">info@balkay.net</a></p>
                </div>
            </div>
        </div>
        
        <!-- Advanced Sections - Collapsible Tabs -->
        <div class="admin-license-form-section">
            <div class="admin-advanced-sections-tabs">
                <!-- Tab Headers -->
                <div class="admin-tab-headers">
                    <button type="button" class="admin-tab-header" data-tab="debug-tools">
                        <span><span class="dashicons dashicons-admin-tools"></span> Geliştirici Araçları</span>
                        <span class="dashicons dashicons-arrow-down admin-tab-arrow"></span>
                    </button>
                    <button type="button" class="admin-tab-header" data-tab="server-settings">
                        <span><span class="dashicons dashicons-admin-site"></span> Sunucu Ayarları</span>
                        <span class="dashicons dashicons-arrow-down admin-tab-arrow"></span>
                    </button>
                    <button type="button" class="admin-tab-header" data-tab="advanced-tools">
                        <span><span class="dashicons dashicons-admin-generic"></span> Gelişmiş Araçlar</span>
                        <span class="dashicons dashicons-arrow-down admin-tab-arrow"></span>
                    </button>
                </div>
                
                <!-- Debug Tools Tab Content -->
                <div class="admin-tab-content" id="debug-tools" style="display: none;">
                    <div class="admin-license-info-grid">
                        <div class="admin-license-info-item">
                            <h4>Debug Modu</h4>
                            <p>Lisans sunucusu ile iletişim loglarını görüntüler.</p>
                            <form method="post" action="" style="margin-top: 10px;">
                                <?php wp_nonce_field('insurance_crm_license', 'insurance_crm_license_nonce'); ?>
                                <input type="hidden" name="insurance_crm_license_action" value="toggle_debug" />
                                <label>
                                    <input type="checkbox" name="debug_mode" value="1" <?php checked(get_option('insurance_crm_license_debug_mode', false), true); ?> />
                                    Debug Modunu Etkinleştir
                                </label>
                                <br><br>
                                <button type="submit" class="admin-btn admin-btn-secondary">
                                    <span class="dashicons dashicons-<?php echo get_option('insurance_crm_license_debug_mode', false) ? 'visibility' : 'hidden'; ?>"></span>
                                    Debug Ayarını Kaydet
                                </button>
                            </form>
                        </div>
                        
                        <div class="admin-license-info-item">
                            <h4>Bağlantı Testi</h4>
                            <p>Lisans sunucusu ile bağlantıyı test eder.</p>
                            <form method="post" action="" style="margin-top: 10px;">
                                <?php wp_nonce_field('insurance_crm_license', 'insurance_crm_license_nonce'); ?>
                                <input type="hidden" name="insurance_crm_license_action" value="test_connection" />
                                <button type="submit" class="admin-btn admin-btn-secondary">
                                    <span class="dashicons dashicons-admin-site-alt3"></span> Bağlantıyı Test Et
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <?php if (!empty($debug_info)): ?>
                    <div class="admin-license-info-item" style="margin-top: 15px;">
                        <h4>Debug Bilgileri</h4>
                        <ul style="margin: 10px 0; padding-left: 20px;">
                            <?php foreach ($debug_info as $info): ?>
                                <li><?php echo esc_html($info); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (get_option('insurance_crm_license_debug_mode', false)): ?>
                    <div class="admin-license-info-item" style="margin-top: 15px; background: #fff3cd; border-left-color: #ffc107;">
                        <h4>⚠️ Debug Modu Aktif</h4>
                        <p>Tüm lisans iletişim logları WordPress error.log dosyasında saklanmaktadır. 
                        Bu mod sadece sorun giderme amaçlı kullanılmalıdır.</p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Server Settings Tab Content -->
                <div class="admin-tab-content" id="server-settings" style="display: none;">
                    <form method="post" action="">
                        <?php wp_nonce_field('insurance_crm_license', 'insurance_crm_license_nonce'); ?>
                        <input type="hidden" name="insurance_crm_license_action" value="update_server" />
                        
                        <div class="admin-form-row">
                            <label for="license_server_url">Lisans Sunucusu URL'si</label>
                            <input type="url" id="license_server_url" name="license_server_url" class="admin-form-input large-text" 
                                   value="<?php echo esc_attr(get_option('insurance_crm_license_server_url', 'https://balkay.net/crm')); ?>" 
                                   placeholder="https://balkay.net/crm" />
                            <small style="color: #666; font-size: 12px; margin-top: 5px; display: block;">
                                Bu URL'ye API istekleri gönderilir. Değiştirirken dikkatli olun.
                            </small>
                        </div>
                        
                        <div class="admin-form-actions">
                            <button type="submit" class="admin-btn admin-btn-primary">
                                <span class="dashicons dashicons-admin-generic"></span> Sunucu Ayarlarını Kaydet
                            </button>
                        </div>
                    </form>
                    
                    <div class="admin-license-info-grid" style="margin-top: 20px;">
                        <div class="admin-license-info-item">
                            <h4>API Endpoint'leri</h4>
                            <ul style="font-size: 12px; margin: 5px 0; padding-left: 15px;">
                                <li><code>/api/validate_license</code> - Lisans doğrulama</li>
                                <li><code>/api/license_info</code> - Lisans bilgisi</li>
                                <li><code>/api/check_status</code> - Durum kontrolü</li>
                            </ul>
                        </div>
                        
                        <div class="admin-license-info-item">
                            <h4>Beklenen Veri Formatı</h4>
                            <pre style="font-size: 11px; background: #f8f9fa; padding: 8px; border-radius: 3px; margin: 5px 0;">
{
  "license_key": "ANAHTAR",
  "domain": "site.com",
  "action": "validate"
}</pre>
                        </div>
                        
                        <div class="admin-license-info-item">
                            <h4>Beklenen Yanıt Formatı</h4>
                            <pre style="font-size: 11px; background: #f8f9fa; padding: 8px; border-radius: 3px; margin: 5px 0;">
{
  "status": "active",
  "license_type": "monthly",
  "license_package": "premium",
  "license_type_description": "Premium Aylık Abonelik",
  "expires_on": "2025-02-01",
  "user_limit": 10,
  "message": "Başarılı"
}</pre>
                        </div>
                    </div>
                </div>
                
                <!-- Advanced Tools Tab Content -->
                <div class="admin-tab-content" id="advanced-tools" style="display: none;">
                    <div class="admin-license-info-grid">
                        <div class="admin-license-info-item">
                            <h4>Cache Temizleme</h4>
                            <p>Lisans cache verilerini temizle</p>
                            <form method="post" action="" style="margin-top: 10px;">
                                <?php wp_nonce_field('insurance_crm_license', 'insurance_crm_license_nonce'); ?>
                                <input type="hidden" name="insurance_crm_license_action" value="clear_cache" />
                                <button type="submit" class="admin-btn admin-btn-secondary">
                                    <span class="dashicons dashicons-update"></span> Lisans Cache'ini Temizle
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Advanced Sections Tabs Functionality
document.addEventListener('DOMContentLoaded', function() {
    const tabHeaders = document.querySelectorAll('.admin-tab-header');
    const tabContents = document.querySelectorAll('.admin-tab-content');
    
    tabHeaders.forEach(header => {
        header.addEventListener('click', function() {
            const targetTab = this.dataset.tab;
            const targetContent = document.getElementById(targetTab);
            const isActive = this.classList.contains('active');
            
            // Close all tabs first
            tabHeaders.forEach(h => h.classList.remove('active'));
            tabContents.forEach(c => c.style.display = 'none');
            
            // If tab wasn't active, open it
            if (!isActive) {
                this.classList.add('active');
                targetContent.style.display = 'block';
            }
        });
    });
});
</script>

<?php
// Add module access checking JavaScript
if ($insurance_crm_license_manager) {
    echo $insurance_crm_license_manager->get_module_check_js();
}
?>