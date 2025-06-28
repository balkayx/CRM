<?php
/**
 * Modern Login Handler Class
 * A secure, reliable and modern login system for the Insurance CRM
 */

if (!defined('ABSPATH')) {
    exit;
}

class InsuranceCrmModernLogin {
    
    private $session_key = 'insurance_crm_login_attempt';
    private $max_attempts = 5;
    private $lockout_duration = 900; // 15 minutes
    
    public function __construct() {
        add_action('init', array($this, 'handle_login_request'), 1);
        add_action('wp_login', array($this, 'after_successful_login'), 10, 2);
        add_action('wp_logout', array($this, 'clear_login_session'));
    }
    
    /**
     * Handle login form submission
     */
    public function handle_login_request() {
        // Only process on login form submission
        if (!isset($_POST['insurance_crm_modern_login']) || !$_POST['insurance_crm_modern_login']) {
            return;
        }
        
        // Start session if not already started
        if (!session_id()) {
            session_start();
        }
        
        try {
            $this->process_login();
        } catch (Exception $e) {
            error_log('Insurance CRM Modern Login Error: ' . $e->getMessage());
            $this->redirect_with_error('Bir hata oluştu. Lütfen tekrar deneyin.');
        }
    }
    
    /**
     * Process the login with security checks
     */
    private function process_login() {
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['insurance_crm_login_nonce'], 'insurance_crm_modern_login')) {
            $this->redirect_with_error('Güvenlik doğrulaması başarısız. Lütfen sayfayı yenileyin.');
            return;
        }
        
        // Check for brute force attempts
        if ($this->is_login_locked()) {
            $this->redirect_with_error('Çok fazla başarısız giriş denemesi. 15 dakika sonra tekrar deneyin.');
            return;
        }
        
        // Sanitize input
        $username = sanitize_text_field($_POST['username']);
        $password = $_POST['password'];
        $remember = isset($_POST['remember']) && $_POST['remember'] === 'on';
        
        // Validate input
        if (empty($username) || empty($password)) {
            $this->increment_login_attempts();
            $this->redirect_with_error('Kullanıcı adı ve şifre boş bırakılamaz.');
            return;
        }
        
        // Attempt authentication
        $user = $this->authenticate_user($username, $password, $remember);
        
        if (is_wp_error($user)) {
            $this->increment_login_attempts();
            $this->redirect_with_error('Kullanıcı adı veya şifre hatalı.');
            return;
        }
        
        // Check user role
        if (!$this->is_valid_user_role($user)) {
            $this->increment_login_attempts();
            $this->redirect_with_error('Bu kullanıcı sisteme giriş yapma yetkisine sahip değil.');
            return;
        }
        
        // Check user status in database
        $status_check = $this->check_user_status($user);
        if (!$status_check['allowed']) {
            $this->increment_login_attempts();
            $this->redirect_with_error($status_check['message']);
            return;
        }
        
        // Success - clear attempts and login
        $this->clear_login_attempts();
        $this->login_user($user, $remember);
    }
    
    /**
     * Authenticate user with WordPress
     */
    private function authenticate_user($username, $password, $remember) {
        $credentials = array(
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => $remember
        );
        
        return wp_signon($credentials, is_ssl());
    }
    
    /**
     * Check if user has valid role
     */
    private function is_valid_user_role($user) {
        $valid_roles = array('insurance_representative', 'administrator');
        return !empty(array_intersect($valid_roles, (array)$user->roles));
    }
    
    /**
     * Check user status in CRM database
     */
    private function check_user_status($user) {
        global $wpdb;
        
        // Administrators always allowed
        if (in_array('administrator', (array)$user->roles)) {
            return array('allowed' => true, 'message' => '');
        }
        
        // Check representative status
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
            $user->ID
        ));
        
        // If not found but has role, create active entry
        if ($status === null) {
            $result = $wpdb->insert(
                $wpdb->prefix . 'insurance_crm_representatives',
                array(
                    'user_id' => $user->ID,
                    'title' => 'Müşteri Temsilcisi',
                    'phone' => '',
                    'department' => 'Genel',
                    'monthly_target' => 0.00,
                    'status' => 'active',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ),
                array('%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s')
            );
            
            if ($result !== false) {
                $status = 'active';
            }
        }
        
        if ($status !== 'active') {
            return array(
                'allowed' => false, 
                'message' => 'Hesabınız pasif durumda. Lütfen yöneticiniz ile iletişime geçin.'
            );
        }
        
        return array('allowed' => true, 'message' => '');
    }
    
    /**
     * Log user in and redirect
     */
    private function login_user($user, $remember) {
        // Set authentication cookie
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, $remember, is_ssl());
        
        // Log the successful login
        error_log("Insurance CRM Modern Login: Successful login for user ID {$user->ID} ({$user->user_login})");
        
        // Determine redirect URL
        $redirect_url = $this->get_redirect_url($user);
        
        // Redirect with proper headers
        wp_safe_redirect($redirect_url);
        exit;
    }
    
    /**
     * Get redirect URL based on user role
     */
    private function get_redirect_url($user) {
        if (in_array('administrator', (array)$user->roles)) {
            return home_url('/boss-panel/');
        } else {
            return home_url('/temsilci-paneli/');
        }
    }
    
    /**
     * Check if login is locked due to too many attempts
     */
    private function is_login_locked() {
        if (!isset($_SESSION[$this->session_key])) {
            return false;
        }
        
        $attempts = $_SESSION[$this->session_key];
        
        if ($attempts['count'] >= $this->max_attempts) {
            if (time() - $attempts['last_attempt'] < $this->lockout_duration) {
                return true;
            } else {
                // Lockout period expired, reset
                unset($_SESSION[$this->session_key]);
                return false;
            }
        }
        
        return false;
    }
    
    /**
     * Increment login attempts counter
     */
    private function increment_login_attempts() {
        if (!isset($_SESSION[$this->session_key])) {
            $_SESSION[$this->session_key] = array('count' => 0, 'last_attempt' => 0);
        }
        
        $_SESSION[$this->session_key]['count']++;
        $_SESSION[$this->session_key]['last_attempt'] = time();
    }
    
    /**
     * Clear login attempts counter
     */
    private function clear_login_attempts() {
        if (isset($_SESSION[$this->session_key])) {
            unset($_SESSION[$this->session_key]);
        }
    }
    
    /**
     * Clear login session on logout
     */
    public function clear_login_session() {
        $this->clear_login_attempts();
    }
    
    /**
     * Hook for after successful login
     */
    public function after_successful_login($user_login, $user) {
        error_log("Insurance CRM: User {$user_login} logged in successfully");
        
        // Set a transient to show update notification popup after login
        // This will be checked on the dashboard page and trigger the popup
        set_transient('insurance_crm_show_popup_' . $user->ID, true, 60); // Expires in 60 seconds
    }
    
    /**
     * Redirect with error message
     */
    private function redirect_with_error($message) {
        $redirect_url = add_query_arg(array(
            'login_error' => urlencode($message),
            'login_time' => time()
        ), $_SERVER['HTTP_REFERER']);
        
        wp_safe_redirect($redirect_url);
        exit;
    }
}

// Initialize the modern login handler
new InsuranceCrmModernLogin();