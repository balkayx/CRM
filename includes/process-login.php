<?php
function insurance_crm_process_login() {
    // Handle both underscore and hyphen versions for fallback compatibility
    if(isset($_POST['insurance_crm_login']) || isset($_POST['insurance-crm-login'])) {
        $username = $_POST['username'];
        $password = $_POST['password'];
        
        $user = wp_authenticate($username, $password);
        
        if(is_wp_error($user)) {
            wp_redirect(add_query_arg('login', 'failed', $_SERVER['HTTP_REFERER']));
            exit;
        }
        
        // Kullanıcının müşteri temsilcisi olup olmadığını kontrol et
        if(!in_array('insurance_representative', (array)$user->roles)) {
            wp_redirect(add_query_arg('login', 'failed', $_SERVER['HTTP_REFERER']));
            exit;
        }
        
        // Session ve auth cookie ayarla
        wp_set_auth_cookie($user->ID);
        wp_set_current_user($user->ID);
        
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