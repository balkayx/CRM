<?php
/**
 * Frontend License Restriction Page Template
 * 
 * Modern and user-friendly restriction page for representative panel
 * 
 * @package Insurance_CRM
 * @author  Anadolu Birlik
 * @since   1.1.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// Ensure user is logged in
if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/temsilci-girisi/'));
    exit;
}

$user = wp_get_current_user();
if (!in_array('insurance_representative', (array)$user->roles)) {
    wp_safe_redirect(home_url());
    exit;
}

// Get restriction details from URL parameters
$restriction_type = isset($_GET['restriction']) ? sanitize_text_field($_GET['restriction']) : 'module';
$module = isset($_GET['module']) ? sanitize_text_field($_GET['module']) : '';

// Get license info
global $insurance_crm_license_manager;
$license_info = $insurance_crm_license_manager ? $insurance_crm_license_manager->get_license_info() : array();

// Determine restriction content based on type and available information
$page_title = 'Erişim Kısıtlaması';
$main_message = '';
$sub_message = '';
$icon_class = 'fas fa-lock';
$container_class = 'license-restriction-general';

if ($restriction_type === 'module' && !empty($module)) {
    // Module restriction
    $module_names = array(
        'dashboard' => 'Dashboard',
        'customers' => 'Müşteriler',
        'policies' => 'Poliçeler',
        'offers' => 'Teklifler',
        'tasks' => 'Görevler',
        'reports' => 'Raporlar',
        'veri_aktar' => 'Veri Aktarımı',
        'veri_aktar_facebook' => 'Facebook Veri Aktarımı',
        'iceri_aktarim' => 'İçeri Aktarım',
        'import-system' => 'İmport Sistemi'
    );
    
    $module_name = isset($module_names[$module]) ? $module_names[$module] : $module;
    $page_title = 'Modül Erişimi Kısıtlı';
    $main_message = sprintf('Lisansınız bu modülü (%s) içermiyor.', $module_name);
    $sub_message = 'Lütfen lisansınızı güncelleyin veya uygun modülleri içeren bir lisans satın alın.';
    $icon_class = 'fas fa-puzzle-piece';
    $container_class = 'license-restriction-module';
} elseif ($restriction_type === 'data') {
    // Data access restriction
    $icon_class = 'fas fa-database';
    $container_class = 'license-restriction-data';
    
    if (!empty($license_info) && $license_info['status'] === 'expired' && !$license_info['in_grace_period']) {
        $page_title = 'Lisans Süresi Dolmuş';
        $main_message = 'Lisansınızın süresi dolmuştur ve ek kullanım süreniz sona ermiştir.';
        $sub_message = 'Uygulamamızı kullanabilmek için lütfen ödemenizi yapın ve lisansınızı yenileyin.';
    } elseif (!empty($license_info) && $license_info['status'] === 'expired' && $license_info['in_grace_period']) {
        $page_title = 'Lisans Süresi Dolmuş - Geçiş Dönemi';
        $main_message = sprintf('Lisansınızın süresi dolmuştur. %d gün geçiş süreniz kalmıştır.', 
                               $license_info['grace_days_remaining']);
        $sub_message = 'Lütfen en kısa sürede ödemenizi yaparak lisansınızı yenileyin.';
        $icon_class = 'fas fa-clock';
        $container_class = 'license-restriction-grace';
    } else {
        $page_title = 'Lisans Gerekli';
        $main_message = 'Bu verilere erişebilmek için geçerli bir lisansa ihtiyacınız var.';
        $sub_message = 'Lütfen geçerli bir lisans anahtarı girin veya mevcut lisansınızı yenileyin.';
    }
} elseif ($restriction_type === 'user_limit') {
    // User limit restriction
    $page_title = 'Kullanıcı Limiti Aşıldı';
    $main_message = 'Kullanıcı sayısı limiti aşıldı. Yeni kullanıcı ekleyemezsiniz.';
    $sub_message = sprintf('Mevcut: %d / %d kullanıcı. Lütfen lisansınızı yükseltin.',
                          $license_info['current_users'] ?? 0, $license_info['user_limit'] ?? 5);
    $icon_class = 'fas fa-users';
    $container_class = 'license-restriction-user-limit';
}

// Generate contact and support information
$support_links = array(
    'license_page' => array(
        'url' => generate_panel_url('license-management'),
        'text' => 'Lisans Yönetimine Git',
        'class' => 'button-primary'
    ),
    'dashboard' => array(
        'url' => generate_panel_url('dashboard'),
        'text' => 'Ana Sayfaya Dön',
        'class' => 'button-secondary'
    )
);

?>

<div class="main-content">
    <div class="license-restriction-container <?php echo esc_attr($container_class); ?>">
        <div class="restriction-header">
            <div class="restriction-icon">
                <i class="<?php echo esc_attr($icon_class); ?>"></i>
            </div>
            <h1 class="restriction-title"><?php echo esc_html($page_title); ?></h1>
        </div>
        
        <div class="restriction-content">
            <div class="restriction-main-message">
                <p><?php echo esc_html($main_message); ?></p>
            </div>
            
            <?php if (!empty($sub_message)): ?>
            <div class="restriction-sub-message">
                <p><?php echo esc_html($sub_message); ?></p>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($license_info)): ?>
            <div class="license-status-info">
                <h3>Lisans Durumu</h3>
                <div class="license-details">
                    <div class="license-detail-item">
                        <span class="label">Durum:</span>
                        <span class="value status-<?php echo esc_attr($license_info['status']); ?>">
                            <?php 
                            $status_map = array(
                                'active' => 'Aktif',
                                'expired' => 'Süresi Dolmuş',
                                'invalid' => 'Geçersiz',
                                'inactive' => 'Etkin Değil'
                            );
                            echo esc_html($status_map[$license_info['status']] ?? 'Bilinmiyor');
                            ?>
                        </span>
                    </div>
                    
                    <?php if (!empty($license_info['expiry'])): ?>
                    <div class="license-detail-item">
                        <span class="label">Son Geçerlilik:</span>
                        <span class="value"><?php echo esc_html(date('d.m.Y H:i', strtotime($license_info['expiry']))); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($license_info['user_limit'])): ?>
                    <div class="license-detail-item">
                        <span class="label">Kullanıcı Limiti:</span>
                        <span class="value">
                            <?php echo esc_html($license_info['current_users'] ?? 0); ?> / 
                            <?php echo esc_html($license_info['user_limit']); ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($license_info['modules']) && is_array($license_info['modules'])): ?>
                    <div class="license-detail-item">
                        <span class="label">İzinli Modüller:</span>
                        <span class="value">
                            <?php 
                            $module_names = array(
                                'dashboard' => 'Dashboard',
                                'customers' => 'Müşteriler',
                                'policies' => 'Poliçeler',
                                'offers' => 'Teklifler',
                                'tasks' => 'Görevler',
                                'reports' => 'Raporlar',
                                'data_transfer' => 'Veri Aktarımı'
                            );
                            
                            $licensed_module_names = array();
                            foreach ($license_info['modules'] as $mod) {
                                $licensed_module_names[] = $module_names[$mod] ?? $mod;
                            }
                            
                            echo esc_html(implode(', ', $licensed_module_names));
                            ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="restriction-actions">
                <?php foreach ($support_links as $link): ?>
                <a href="<?php echo esc_url($link['url']); ?>" 
                   class="button <?php echo esc_attr($link['class']); ?>">
                    <?php echo esc_html($link['text']); ?>
                </a>
                <?php endforeach; ?>
            </div>
            
            <?php if ($restriction_type === 'module'): ?>
            <div class="upgrade-suggestions">
                <h3>Lisans Yükseltme Önerileri</h3>
                <ul>
                    <li>Daha fazla modüle erişim için lisansınızı yükseltin</li>
                    <li>Tam özellik setine sahip olmak için enterprise lisansı edinin</li>
                    <li>Özel gereksinimleriniz için özelleştirilmiş bir lisans paketi talep edin</li>
                </ul>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="restriction-footer">
            <p class="help-text">
                Sorularınız için: 
                <a href="mailto:info@balkay.net">info@balkay.net</a>
            </p>
        </div>
    </div>
</div>

<style>
.license-restriction-container {
    max-width: 800px;
    margin: 40px auto;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.restriction-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 40px 30px;
    text-align: center;
    position: relative;
}

.license-restriction-module .restriction-header {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
}

.license-restriction-data .restriction-header {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
}

.license-restriction-grace .restriction-header {
    background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
}

.license-restriction-user-limit .restriction-header {
    background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
}

.restriction-icon {
    font-size: 48px;
    margin-bottom: 20px;
    opacity: 0.9;
}

.restriction-title {
    margin: 0;
    font-size: 28px;
    font-weight: 600;
    color: white !important;
}

.restriction-content {
    padding: 40px 30px;
}

.restriction-main-message {
    font-size: 18px;
    line-height: 1.6;
    color: #333;
    margin-bottom: 20px;
    text-align: center;
}

.restriction-sub-message {
    font-size: 16px;
    color: #666;
    margin-bottom: 30px;
    text-align: center;
    font-style: italic;
}

.license-status-info {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 25px;
    margin-bottom: 30px;
}

.license-status-info h3 {
    margin: 0 0 20px 0;
    color: #333;
    font-size: 18px;
    border-bottom: 2px solid #e9ecef;
    padding-bottom: 10px;
}

.license-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
}

.license-detail-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid #e9ecef;
}

.license-detail-item:last-child {
    border-bottom: none;
}

.license-detail-item .label {
    font-weight: 600;
    color: #495057;
}

.license-detail-item .value {
    color: #333;
}

.status-active {
    color: #28a745;
    font-weight: bold;
}

.status-expired {
    color: #dc3545;
    font-weight: bold;
}

.status-invalid {
    color: #fd7e14;
    font-weight: bold;
}

.status-inactive {
    color: #6c757d;
    font-weight: bold;
}

.restriction-actions {
    text-align: center;
    margin-bottom: 30px;
}

.restriction-actions .button {
    margin: 0 10px 10px 0;
    padding: 12px 24px;
    font-size: 16px;
    border-radius: 6px;
    text-decoration: none;
    display: inline-block;
}

.button-primary {
    background: #0073aa;
    color: white;
    border: none;
}

.button-primary:hover {
    background: #005a87;
    color: white;
}

.button-secondary {
    background: #f8f9fa;
    color: #495057;
    border: 1px solid #dee2e6;
}

.button-secondary:hover {
    background: #e9ecef;
    color: #495057;
}

.upgrade-suggestions {
    background: #e3f2fd;
    border-left: 4px solid #2196f3;
    padding: 20px;
    border-radius: 0 8px 8px 0;
    margin-bottom: 30px;
}

.upgrade-suggestions h3 {
    margin: 0 0 15px 0;
    color: #1976d2;
    font-size: 16px;
}

.upgrade-suggestions ul {
    margin: 0;
    padding-left: 20px;
}

.upgrade-suggestions li {
    margin-bottom: 8px;
    color: #333;
}

.restriction-footer {
    background: #f8f9fa;
    padding: 20px 30px;
    text-align: center;
    border-top: 1px solid #e9ecef;
}

.help-text {
    margin: 0;
    color: #6c757d;
    font-size: 14px;
}

.help-text a {
    color: #007cba;
    text-decoration: none;
}

.help-text a:hover {
    text-decoration: underline;
}

/* Responsive design */
@media (max-width: 768px) {
    .license-restriction-container {
        margin: 20px;
        border-radius: 8px;
    }
    
    .restriction-header {
        padding: 30px 20px;
    }
    
    .restriction-title {
        font-size: 24px;
    }
    
    .restriction-content {
        padding: 30px 20px;
    }
    
    .license-details {
        grid-template-columns: 1fr;
    }
    
    .license-detail-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 5px;
    }
    
    .restriction-actions .button {
        display: block;
        margin: 10px 0;
        width: 100%;
        text-align: center;
    }
}

/* Animation */
.license-restriction-container {
    animation: fadeInUp 0.5s ease-out;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add some interactive behavior
    const detailItems = document.querySelectorAll('.license-detail-item');
    detailItems.forEach(function(item) {
        item.addEventListener('mouseenter', function() {
            this.style.backgroundColor = '#f8f9fa';
        });
        item.addEventListener('mouseleave', function() {
            this.style.backgroundColor = 'transparent';
        });
    });
    
    // Auto-refresh check after 30 seconds on license page
    if (window.location.href.indexOf('license-management') > -1) {
        setTimeout(function() {
            if (confirm('Lisans durumunu yeniden kontrol etmek ister misiniz?')) {
                window.location.reload();
            }
        }, 30000);
    }
});
</script>