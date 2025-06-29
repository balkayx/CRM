<?php
/**
 * License Bypass Control Module
 * 
 * Independent bypass control module that can be deleted from server
 * when bypass functionality is not needed.
 * 
 * @package Insurance_CRM
 * @author  Anadolu Birlik
 * @since   1.1.5
 */

// Doğrudan erişime izin verme
if (!defined('ABSPATH')) {
    exit;
}

// Admin yetki kontrolü
if (!current_user_can('manage_options')) {
    wp_die(__('Bu sayfaya erişim yetkiniz yok.'));
}

// Bypass işlemlerini handle et
$bypass_result = null;

if (isset($_POST['bypass_action']) && isset($_POST['bypass_nonce']) && wp_verify_nonce($_POST['bypass_nonce'], 'license_bypass_control')) {
    $action = sanitize_text_field($_POST['bypass_action']);
    
    if ($action === 'toggle_bypass') {
        $bypass_license = isset($_POST['bypass_license']) ? true : false;
        update_option('insurance_crm_bypass_license', $bypass_license);
        $bypass_result = array(
            'success' => true,
            'message' => 'Lisans bypass ' . ($bypass_license ? 'etkinleştirildi' : 'devre dışı bırakıldı') . '.'
        );
    }
}

// Mevcut bypass durumunu al
$current_bypass_status = get_option('insurance_crm_bypass_license', false);

?>

<div class="wrap">
    <h1>🔧 Lisans Bypass Kontrolü</h1>
    
    <div class="bypass-warning-header">
        <div class="notice notice-warning">
            <p><strong>⚠️ DİKKAT:</strong> Bu modül sadece geliştirme ve test amaçlı kullanılmalıdır!</p>
            <p>Bypass modu, tüm lisans kontrollerini devre dışı bırakır ve güvenlik risklerine yol açabilir.</p>
        </div>
    </div>

    <?php if ($bypass_result): ?>
        <div class="notice <?php echo $bypass_result['success'] ? 'notice-success' : 'notice-error'; ?>">
            <p><?php echo esc_html($bypass_result['message']); ?></p>
        </div>
    <?php endif; ?>

    <div class="bypass-control-container">
        <div class="bypass-status-card">
            <h2>Mevcut Bypass Durumu</h2>
            <div class="status-indicator">
                <span class="status-badge <?php echo $current_bypass_status ? 'status-active' : 'status-inactive'; ?>">
                    <?php if ($current_bypass_status): ?>
                        <span class="dashicons dashicons-yes"></span> Bypass AKTİF
                    <?php else: ?>
                        <span class="dashicons dashicons-no"></span> Bypass KAPALI
                    <?php endif; ?>
                </span>
            </div>
            
            <?php if ($current_bypass_status): ?>
                <div class="warning-message">
                    <p><strong>🚨 UYARI:</strong> Lisans bypass şu anda aktif!</p>
                    <p>Tüm modüller ve özellikler lisans kontrolü olmadan erişilebilir durumda.</p>
                </div>
            <?php else: ?>
                <div class="info-message">
                    <p><strong>✅ GÜVENLİ:</strong> Lisans kontrolleri normal şekilde çalışıyor.</p>
                    <p>Tüm modüller lisans durumuna göre kontrol edilmekte.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="bypass-control-card">
            <h2>Bypass Kontrolü</h2>
            
            <form method="post" action="" id="bypass-control-form">
                <?php wp_nonce_field('license_bypass_control', 'bypass_nonce'); ?>
                <input type="hidden" name="bypass_action" value="toggle_bypass" />
                
                <div class="bypass-toggle">
                    <label class="bypass-switch">
                        <input type="checkbox" name="bypass_license" value="1" <?php checked($current_bypass_status, true); ?> />
                        <span class="slider"></span>
                    </label>
                    <span class="bypass-label">
                        Lisans Kontrollerini Bypass Et
                    </span>
                </div>
                
                <div class="bypass-description">
                    <h4>Bu ayar ne yapar?</h4>
                    <ul>
                        <li><strong>Açık:</strong> Tüm lisans kontrolleri devre dışı kalır</li>
                        <li><strong>Kapalı:</strong> Normal lisans kontrolleri çalışır</li>
                    </ul>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="button button-large <?php echo $current_bypass_status ? 'button-secondary' : 'button-primary'; ?>">
                        <?php if ($current_bypass_status): ?>
                            <span class="dashicons dashicons-lock"></span> Bypass'ı Kapat
                        <?php else: ?>
                            <span class="dashicons dashicons-unlock"></span> Bypass'ı Aç
                        <?php endif; ?>
                    </button>
                </div>
            </form>
        </div>

        <div class="bypass-usage-card">
            <h2>Kullanım Önerileri</h2>
            
            <div class="usage-scenarios">
                <div class="scenario">
                    <h4>🧪 Geliştirme Aşaması</h4>
                    <p>Yeni özellikler test edilirken bypass açık bırakılabilir.</p>
                </div>
                
                <div class="scenario">
                    <h4>🔍 Hata Giderme</h4>
                    <p>Lisans ile ilgili sorunları ayıklarken geçici olarak kullanılabilir.</p>
                </div>
                
                <div class="scenario">
                    <h4>🚀 Canlı Ortam</h4>
                    <p><strong>Kesinlikle kapalı</strong> olmalıdır! Bu dosyayı sunucudan silin.</p>
                </div>
            </div>
        </div>

        <div class="bypass-security-card">
            <h2>🔒 Güvenlik Uyarıları</h2>
            
            <div class="security-warnings">
                <div class="warning-item">
                    <span class="dashicons dashicons-warning"></span>
                    <div>
                        <strong>Canlı ortamda kullanmayın:</strong>
                        <p>Bu modül canlı sistemlerde güvenlik riski oluşturur.</p>
                    </div>
                </div>
                
                <div class="warning-item">
                    <span class="dashicons dashicons-trash"></span>
                    <div>
                        <strong>Dosyayı silin:</strong>
                        <p>İhtiyaç kalmadığında bu dosyayı (<code>license-bypass-control.php</code>) sunucudan silin.</p>
                    </div>
                </div>
                
                <div class="warning-item">
                    <span class="dashicons dashicons-visibility"></span>
                    <div>
                        <strong>Sadece yöneticiler:</strong>
                        <p>Bu sayfaya sadece admin kullanıcılar erişebilir.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.bypass-control-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.bypass-warning-header {
    margin-bottom: 20px;
}

.bypass-status-card,
.bypass-control-card,
.bypass-usage-card,
.bypass-security-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.bypass-status-card {
    grid-column: 1 / -1;
}

.status-indicator {
    text-align: center;
    margin: 20px 0;
}

.status-badge {
    display: inline-block;
    padding: 15px 25px;
    border-radius: 50px;
    font-size: 18px;
    font-weight: bold;
    color: white;
}

.status-badge.status-active {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52);
    animation: pulse 2s infinite;
}

.status-badge.status-inactive {
    background: linear-gradient(135deg, #51cf66, #69db7c);
}

@keyframes pulse {
    0% { box-shadow: 0 0 0 0 rgba(255, 107, 107, 0.7); }
    70% { box-shadow: 0 0 0 10px rgba(255, 107, 107, 0); }
    100% { box-shadow: 0 0 0 0 rgba(255, 107, 107, 0); }
}

.warning-message {
    background: #fff3cd;
    border-left: 4px solid #ffc107;
    padding: 15px;
    margin-top: 15px;
    border-radius: 4px;
}

.info-message {
    background: #d1e7dd;
    border-left: 4px solid #0f5132;
    padding: 15px;
    margin-top: 15px;
    border-radius: 4px;
}

.bypass-toggle {
    display: flex;
    align-items: center;
    gap: 15px;
    margin: 20px 0;
}

.bypass-switch {
    position: relative;
    display: inline-block;
    width: 60px;
    height: 34px;
}

.bypass-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 34px;
}

.slider:before {
    position: absolute;
    content: "";
    height: 26px;
    width: 26px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}

input:checked + .slider {
    background-color: #ff6b6b;
}

input:checked + .slider:before {
    transform: translateX(26px);
}

.bypass-label {
    font-size: 16px;
    font-weight: 600;
}

.bypass-description {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 4px;
    margin: 20px 0;
}

.bypass-description h4 {
    margin-top: 0;
    color: #495057;
}

.bypass-description ul {
    margin: 10px 0 0 20px;
}

.form-actions {
    text-align: center;
    margin-top: 20px;
}

.usage-scenarios,
.security-warnings {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.scenario {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 4px;
    border-left: 4px solid #0073aa;
}

.scenario h4 {
    margin: 0 0 10px 0;
    color: #0073aa;
}

.warning-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 15px;
    background: #fff3cd;
    border-radius: 4px;
    border-left: 4px solid #ffc107;
}

.warning-item .dashicons {
    color: #856404;
    font-size: 20px;
    margin-top: 2px;
}

.warning-item strong {
    color: #856404;
}

.warning-item p {
    margin: 5px 0 0 0;
    color: #856404;
}

/* Responsive */
@media (max-width: 768px) {
    .bypass-control-container {
        grid-template-columns: 1fr;
    }
    
    .bypass-toggle {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('bypass-control-form');
    const checkbox = form.querySelector('input[name="bypass_license"]');
    
    form.addEventListener('submit', function(e) {
        const isEnabling = checkbox.checked;
        
        if (isEnabling) {
            const confirmed = confirm(
                '⚠️ UYARI: Lisans bypass\'ını etkinleştirmek istediğinizden emin misiniz?\n\n' +
                'Bu işlem tüm lisans kontrollerini devre dışı bırakacaktır.\n\n' +
                'Sadece geliştirme/test ortamında kullanılmalıdır!'
            );
            
            if (!confirmed) {
                e.preventDefault();
                return false;
            }
        }
    });
});
</script>