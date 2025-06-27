<?php
function insurance_crm_process_login() {
    // Handle both underscore and hyphen versions for fallback compatibility
    if(isset($_POST['insurance_crm_login']) || isset($_POST['insurance-crm-login'])) {
        // Verify nonce
        if (!wp_verify_nonce($_POST['insurance_crm_login_nonce'], 'insurance_crm_login')) {
            wp_redirect(add_query_arg('login', 'failed', $_SERVER['HTTP_REFERER']));
            exit;
        }
        
        $username = sanitize_text_field($_POST['username']);
        $password = $_POST['password'];
        $remember = isset($_POST['remember']);
        
        $credentials = array(
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => $remember
        );
        
        $user = wp_signon($credentials, false);
        
        if(is_wp_error($user)) {
            wp_redirect(add_query_arg('login', 'failed', $_SERVER['HTTP_REFERER']));
            exit;
        }
        
        // Kullanıcının müşteri temsilcisi olup olmadığını kontrol et
        if(!in_array('insurance_representative', (array)$user->roles)) {
            wp_redirect(add_query_arg('login', 'failed', $_SERVER['HTTP_REFERER']));
            exit;
        }
        
        // Veritabanında temsilci durumunu kontrol et
        global $wpdb;
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
            $user->ID
        ));
        
        // If user not found in database but has role, create active entry
        if (!$status) {
            $wpdb->insert(
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
                )
            );
            $status = 'active';
        }
        
        if ($status !== 'active') {
            wp_logout();
            wp_redirect(add_query_arg('login', 'inactive', $_SERVER['HTTP_REFERER']));
            exit;
        }
        
        // Session açıldığını doğrula ve sistem anasayfasına yönlendir
        if(is_user_logged_in()) {
            wp_redirect(home_url('/temsilci-paneli/'));
            exit;
        } else {
            wp_redirect(add_query_arg('login', 'failed', $_SERVER['HTTP_REFERER']));
            exit;
        }
    }
}
add_action('init', 'insurance_crm_process_login');