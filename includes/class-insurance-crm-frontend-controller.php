<?php
/**
 * Frontend Controller sınıfı
 * Frontend sayfalarının yönetimi için
 */

// Doğrudan erişimi engelle
if (!defined('ABSPATH')) {
    exit;
}

class Insurance_CRM_Frontend_Controller {
    /**
     * Eksik tabloları oluştur
     */
    public static function create_missing_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // İşlemler tablosu
        $table_interactions = $wpdb->prefix . 'insurance_crm_interactions';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_interactions'") != $table_interactions) {
            $sql = "CREATE TABLE $table_interactions (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                representative_id bigint(20) NOT NULL,
                customer_id bigint(20) NOT NULL,
                type varchar(50) NOT NULL,
                notes text NOT NULL,
                interaction_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                KEY representative_id (representative_id),
                KEY customer_id (customer_id)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
        
        // Bildirimler tablosu
        $table_notifications = $wpdb->prefix . 'insurance_crm_notifications';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_notifications'") != $table_notifications) {
            $sql = "CREATE TABLE $table_notifications (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                user_id bigint(20) NOT NULL,
                type varchar(50) NOT NULL,
                title varchar(255) NOT NULL,
                message text NOT NULL,
                related_id bigint(20) DEFAULT 0,
                related_type varchar(50) DEFAULT '',
                is_read tinyint(1) DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                KEY user_id (user_id),
                KEY is_read (is_read)
            ) $charset_collate;";
            
            dbDelta($sql);
        }
    }

    /**
     * Constructor
     */
    public function __construct() {
        // Dashboard shortcode ile sayfayı yönet
        add_shortcode('temsilci_dashboard', array($this, 'render_dashboard_page'));
        
        // Eksik tabloları oluştur
        self::create_missing_tables();
        
        // ChartJS ve diğer scriptleri ekle
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // CRM favicon'u ekle
        add_action('wp_head', array($this, 'add_crm_favicon'));
    }
    
    /**
     * Gerekli scriptleri ekle
     */
    public function enqueue_scripts() {
        // Sadece temsilci paneli sayfasında scriptleri yükle
        if (is_page('temsilci-paneli')) {
            // jQuery UI ekle
            wp_enqueue_script('jquery');
            wp_enqueue_script('jquery-ui-core');
            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_style('jquery-ui-css', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
            
            // ChartJS ekle
            wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js', array('jquery'), '3.9.1', true);
            
            // Dashicons ekleme
            wp_enqueue_style('dashicons');
            
            // Custom CSS ve JS
            wp_enqueue_style('insurance-crm-representative', plugin_dir_url(dirname(__FILE__)) . 'public/css/representative-panel.css', array(), '1.1.3');
            wp_enqueue_script('insurance-crm-representative', plugin_dir_url(dirname(__FILE__)) . 'public/js/representative-panel.js', array('jquery', 'chartjs'), '1.1.3', true);
            
            // AJAX URL ekle
            wp_localize_script('insurance-crm-representative', 'insurance_crm_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('insurance_crm_nonce')
            ));
        }
    }
    
    /**
     * CRM favicon'u ekle
     */
    public function add_crm_favicon() {
        // Sadece CRM sayfalarında favicon ekle
        if (is_page('temsilci-paneli') || is_page('boss-panel') || is_page('temsilci-girisi')) {
            echo "\n    <!-- CRM PWA Icons & Favicon -->\n";
            echo "    <link rel=\"apple-touch-icon\" sizes=\"180x180\" href=\"data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 180 180'><rect width='180' height='180' fill='%233b82f6'/><text x='90' y='110' font-family='Arial' font-size='60' text-anchor='middle' fill='white'>📊</text></svg>\">\n";
            echo "    <link rel=\"icon\" type=\"image/png\" sizes=\"32x32\" href=\"data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' fill='%233b82f6'/><text x='16' y='24' font-family='Arial' font-size='20' text-anchor='middle' fill='white'>📊</text></svg>\">\n";
            echo "    <link rel=\"icon\" type=\"image/svg+xml\" href=\"data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' fill='%233b82f6'/><text x='16' y='24' font-family='Arial' font-size='20' text-anchor='middle' fill='white'>📊</text></svg>\">\n";
        }
    }
    
    /**
     * Dashboard sayfasını render eder
     */
    public function render_dashboard_page() {
        ob_start();
        
        // Kullanıcı giriş yapmamışsa login sayfasına yönlendir
        if (!is_user_logged_in()) {
            wp_redirect(home_url('/temsilci-girisi/'));
            exit;
        }

        // Kullanıcı müşteri temsilcisi değilse ana sayfaya yönlendir
        $user = wp_get_current_user();
        if (!in_array('insurance_representative', (array)$user->roles)) {
            wp_safe_redirect(home_url());
            exit;
        }
        
        // Hangi sayfanın gösterileceğini belirle
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : 'dashboard';
        
        // Navigasyon menüsü
        echo '<div class="insurance-crm-wrapper">';
        
        // Navigation şablonunu dahil et
        $this->load_template('navigation');
        
        echo '<div class="insurance-crm-main-content">';
        
        // Template dosyasını dahil et
        switch ($page) {
            case 'customers':
                $this->load_template('customers');
                break;
                
            case 'policies':
                $this->load_template('policies');
                break;
                
            case 'offers':
                $this->load_template('offers');
                break;
                
            case 'tasks':
                $this->load_template('tasks');
                break;
                
            case 'universal-import':
                $this->load_template('universal-import');
                break;
                
            case 'reports':
                $this->load_template('reports');
                break;
                
            case 'helpdesk':
                $this->load_template('helpdesk');
                break;
                
            case 'settings':
                $this->load_template('settings');
                break;
                
            default:
                $this->load_template('dashboard');
                break;
        }
        
        echo '</div>'; // .insurance-crm-main-content
        echo '</div>'; // .insurance-crm-wrapper
        
        return ob_get_clean();
    }
    
    /**
     * Şablon dosyasını yükler
     */
    private function load_template($template) {
        $template_file = plugin_dir_path(dirname(__FILE__)) . 'templates/representative-panel/' . $template . '.php';
        
        if (file_exists($template_file)) {
            include_once $template_file;
        } else {
            // Şablon bulunamadığında hata göster
            echo '<div class="insurance-crm-error">';
            echo '<h1>Sayfa bulunamadı</h1>';
            echo '<p>İstediğiniz sayfa şu anda mevcut değil veya erişim yetkiniz yok.</p>';
            echo '<a href="' . add_query_arg('page', 'dashboard', remove_query_arg(array('action', 'id'))) . '" class="button">Dashboard\'a Dön</a>';
            echo '</div>';
        }
    }
}

// Sınıfı başlat
new Insurance_CRM_Frontend_Controller();