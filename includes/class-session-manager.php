<?php
/**
 * Session Manager Class
 * 
 * Handles automatic session timeout and logout functionality
 * 
 * @package Insurance_CRM
 * @author  Anadolu Birlik
 * @since   1.8.2
 */

if (!defined('ABSPATH')) {
    exit;
}

class Insurance_CRM_Session_Manager {
    
    /**
     * Session timeout in seconds (30 minutes)
     */
    private $session_timeout = 1800; // 30 minutes = 1800 seconds
    
    /**
     * Constructor
     */
    public function __construct() {
        // Hook into WordPress
        add_action('init', array($this, 'check_session_timeout'));
        add_action('wp_login', array($this, 'on_user_login'), 10, 2);
        add_action('wp_loaded', array($this, 'update_user_activity'));
        
        // AJAX handler for session check
        add_action('wp_ajax_insurance_crm_check_session', array($this, 'ajax_check_session'));
        add_action('wp_ajax_nopriv_insurance_crm_check_session', array($this, 'ajax_check_session'));
        
        // Add JavaScript for session monitoring
        add_action('wp_footer', array($this, 'add_session_monitor_script'));
        add_action('admin_footer', array($this, 'add_session_monitor_script'));
    }
    
    /**
     * Update user activity timestamp on login
     */
    public function on_user_login($user_login, $user) {
        // Only track insurance representatives and admins
        if (!in_array('insurance_representative', (array)$user->roles) && 
            !in_array('administrator', (array)$user->roles)) {
            return;
        }
        
        $this->update_last_activity($user->ID);
        
        // Set session login time
        update_user_meta($user->ID, '_session_login_time', time());
        
        error_log("[SESSION MANAGER] User {$user_login} logged in, session started");
    }
    
    /**
     * Update user activity timestamp
     */
    public function update_user_activity() {
        if (!is_user_logged_in()) {
            return;
        }
        
        $user_id = get_current_user_id();
        $user = wp_get_current_user();
        
        // Only track insurance representatives and admins
        if (!in_array('insurance_representative', (array)$user->roles) && 
            !current_user_can('manage_insurance_crm')) {
            return;
        }
        
        $this->update_last_activity($user_id);
    }
    
    /**
     * Check for session timeout and automatically logout inactive users
     */
    public function check_session_timeout() {
        if (!is_user_logged_in()) {
            return;
        }
        
        $user_id = get_current_user_id();
        $user = wp_get_current_user();
        
        // Only check insurance representatives and admins
        if (!in_array('insurance_representative', (array)$user->roles) && 
            !current_user_can('manage_insurance_crm')) {
            return;
        }
        
        $last_activity = get_user_meta($user_id, '_user_last_activity', true);
        
        if (!$last_activity) {
            // If no last activity recorded, set it to now
            $this->update_last_activity($user_id);
            return;
        }
        
        $current_time = time();
        $inactive_time = $current_time - $last_activity;
        
        // Check if user has been inactive for more than the timeout period
        if ($inactive_time > $this->session_timeout) {
            $this->logout_user_due_to_timeout($user_id);
        }
    }
    
    /**
     * Logout user due to session timeout
     */
    private function logout_user_due_to_timeout($user_id) {
        $user = get_user_by('ID', $user_id);
        
        if (!$user) {
            return;
        }
        
        // Log the automatic logout
        $this->log_automatic_logout($user_id, $user->user_login);
        
        // Clear user session
        $this->clear_user_session($user_id);
        
        // Logout the user
        wp_logout();
        
        // Redirect to custom login panel with timeout message
        $login_url = add_query_arg(array(
            'session_timeout' => '1',
            'login_info' => urlencode('Oturumunuz 30 dakika hareketsizlik nedeniyle otomatik olarak sonlandırıldı.')
        ), home_url('/temsilci-girisi/'));
        
        wp_safe_redirect($login_url);
        exit;
    }
    
    /**
     * Log automatic logout to database
     */
    private function log_automatic_logout($user_id, $user_login) {
        global $wpdb;
        
        // Get current session duration
        $session_login_time = get_user_meta($user_id, '_session_login_time', true);
        $session_duration = $session_login_time ? (time() - $session_login_time) : null;
        
        $ip_address = $this->get_client_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Insert into user logs
        $wpdb->insert(
            $wpdb->prefix . 'insurance_user_logs',
            array(
                'user_id' => $user_id,
                'action' => 'auto_logout',
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'browser' => $this->get_browser_from_agent($user_agent),
                'device' => $this->get_device_from_agent($user_agent),
                'location' => 'Unknown',
                'session_duration' => $session_duration,
                'created_at' => current_time('mysql'),
                'details' => 'Automatic logout due to 30 minutes of inactivity'
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );
        
        error_log("[SESSION MANAGER] User {$user_login} automatically logged out due to inactivity");
    }
    
    /**
     * Clear user session data
     */
    private function clear_user_session($user_id) {
        delete_user_meta($user_id, '_session_login_time');
        // Note: _user_last_activity is kept for tracking purposes
    }
    
    /**
     * Update last activity timestamp
     */
    private function update_last_activity($user_id) {
        update_user_meta($user_id, '_user_last_activity', time());
    }
    
    /**
     * AJAX handler to check session status
     */
    public function ajax_check_session() {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Not logged in'));
            return;
        }
        
        $user_id = get_current_user_id();
        $last_activity = get_user_meta($user_id, '_user_last_activity', true);
        
        if (!$last_activity) {
            wp_send_json_success(array('remaining_time' => $this->session_timeout));
            return;
        }
        
        $current_time = time();
        $inactive_time = $current_time - $last_activity;
        $remaining_time = $this->session_timeout - $inactive_time;
        
        if ($remaining_time <= 0) {
            wp_send_json_error(array('message' => 'Session expired'));
            return;
        }
        
        wp_send_json_success(array('remaining_time' => $remaining_time));
    }
    
    /**
     * Add session monitoring JavaScript
     */
    public function add_session_monitor_script() {
        if (!is_user_logged_in()) {
            return;
        }
        
        $user = wp_get_current_user();
        
        // Only add for insurance representatives and admins
        if (!in_array('insurance_representative', (array)$user->roles) && 
            !current_user_can('manage_insurance_crm')) {
            return;
        }
        
        ?>
        <script type="text/javascript">
        (function() {
            var sessionTimeout = <?php echo $this->session_timeout; ?> * 1000; // Convert to milliseconds
            var warningTime = 5 * 60 * 1000; // 5 minutes warning
            var checkInterval = 60 * 1000; // Check every minute
            var lastActivity = Date.now();
            var warningShown = false;
            
            // Track user activity
            function updateActivity() {
                lastActivity = Date.now();
                warningShown = false;
                
                // Update server-side activity via AJAX
                if (typeof jQuery !== 'undefined') {
                    jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                        action: 'insurance_crm_check_session'
                    });
                }
            }
            
            // Check session status
            function checkSession() {
                var inactive = Date.now() - lastActivity;
                var remaining = sessionTimeout - inactive;
                
                if (remaining <= 0) {
                    alert('Oturumunuz 30 dakika hareketsizlik nedeniyle sona erdi. Yeniden giriş yapmanız gerekiyor.');
                    window.location.reload();
                    return;
                }
                
                if (remaining <= warningTime && !warningShown) {
                    warningShown = true;
                    var minutes = Math.floor(remaining / (60 * 1000));
                    alert('Uyarı: Oturumunuz ' + minutes + ' dakika sonra otomatik olarak sona erecek. Devam etmek için herhangi bir işlem yapın.');
                }
            }
            
            // Event listeners for user activity
            var events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
            events.forEach(function(event) {
                document.addEventListener(event, updateActivity, false);
            });
            
            // Start monitoring
            setInterval(checkSession, checkInterval);
        })();
        </script>
        <?php
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
     * Get browser from user agent
     */
    private function get_browser_from_agent($user_agent) {
        if (strpos($user_agent, 'Chrome') !== false) return 'Chrome';
        if (strpos($user_agent, 'Firefox') !== false) return 'Firefox';
        if (strpos($user_agent, 'Safari') !== false) return 'Safari';
        if (strpos($user_agent, 'Edge') !== false) return 'Edge';
        if (strpos($user_agent, 'Opera') !== false) return 'Opera';
        return 'Unknown';
    }
    
    /**
     * Get device from user agent
     */
    private function get_device_from_agent($user_agent) {
        if (strpos($user_agent, 'Mobile') !== false) return 'Mobile';
        if (strpos($user_agent, 'Tablet') !== false) return 'Tablet';
        return 'Desktop';
    }
}

// Initialize the session manager
new Insurance_CRM_Session_Manager();