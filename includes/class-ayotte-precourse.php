<?php
class Ayotte_Precourse {
    public function run() {
        add_action('init', [$this, 'add_rewrite_rules']);
        add_action('template_redirect', [$this, 'handle_token_redirect']);
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('user_register', [$this, 'link_token_to_user'], 10, 1);
        add_action('user_register', [$this, 'auto_login_after_register'], 20, 1);
        add_action('register_form', [$this, 'prefill_registration']);
        add_filter('registration_redirect', [$this, 'registration_redirect']);
        add_filter('wp_nav_menu_items', [$this, 'add_student_portal_menu'], 10, 2);
        add_filter('login_redirect', [$this, 'customer_login_redirect'], 10, 3);
    }

    public function add_menu() {
        add_menu_page(
            'Precourse Portal',
            'Precourse Portal',
            'manage_options',
            'ayotte-precourse',
            [$this, 'render_main_panel'],
            'dashicons-welcome-learn-more',
            30
        );

        add_submenu_page(
            'ayotte-precourse',
            'Debug Console',
            'Debug Console',
            'manage_options',
            'precourse-debug-console',
            [new Ayotte_Admin_Panel(), 'render_debug_console']
        );

        add_submenu_page(
            'ayotte-precourse',
            'Student Progress',
            'Student Progress',
            'manage_options',
            'precourse-progress',
            [new Ayotte_Admin_Panel(), 'render_tracking_dashboard']
        );

        add_submenu_page(
            'ayotte-precourse',
            'Form Sets',
            'Form Sets',
            'manage_options',
            'precourse-form-sets',
            [new Ayotte_Admin_Panel(), 'render_form_sets_page']
        );
    }

    public function render_main_panel() {
        (new Ayotte_Admin_Panel())->render_invite_panel();
    }

    public function add_rewrite_rules() {
        add_rewrite_rule('^precourse-invite/?(.*)', 'index.php?precourse_invite=1', 'top');
        add_rewrite_tag('%precourse_invite%', '1');
        ayotte_log_message('INFO', 'Rewrite rules added', 'admin panel');
    }

    public function handle_token_redirect() {
        if (get_query_var('precourse_invite') == '1') {
            $token = sanitize_text_field($_GET['token'] ?? '');
            $manager = new Invitation_Manager();
            $email = $manager->validate_token($token);
            if ($email) {
                if (!session_id()) session_start();
                $_SESSION['ayotte_precourse_email'] = $email;
                $_SESSION['ayotte_precourse_token'] = $token;
                ayotte_log_message('INFO', "Token valid for email: $email", 'email invitation manager');
                $reg_url = wp_registration_url();
                $query   = '?email=' . urlencode($email) . '&token=' . urlencode($token);
                wp_redirect($reg_url . $query);
                exit;
            } else {
                ayotte_log_message('ERROR', "Invalid or expired token: $token", 'email invitation manager');
                wp_redirect(site_url('/error-page?reason=invalid-token'));
                exit;
            }
        }
    }

    public function prefill_registration() {
        if (isset($_GET['email']) && isset($_GET['token'])) {
            if (!session_id()) session_start();
            $_SESSION['ayotte_precourse_email'] = sanitize_email($_GET['email']);
            $_SESSION['ayotte_precourse_token'] = sanitize_text_field($_GET['token']);
            echo '<script>document.addEventListener(\"DOMContentLoaded\",function(){const f=document.getElementById(\"user_email\");if(f)f.value=\"'.esc_js($_GET['email']).'\";});</script>';
        }
    }

    public function link_token_to_user($user_id) {
        if (!session_id()) session_start();
        $email = $_SESSION['ayotte_precourse_email'] ?? '';
        $token = $_SESSION['ayotte_precourse_token'] ?? '';
        if ($token && $email) {
            update_user_meta($user_id, 'ayotte_precourse_email', $email);
            update_user_meta($user_id, 'ayotte_precourse_token', $token);
            ayotte_log_message('INFO', "Linked token $token to user $user_id", 'email invitation manager');
            unset($_SESSION['ayotte_precourse_email'], $_SESSION['ayotte_precourse_token']);
        }
    }

    public function auto_login_after_register($user_id) {
        if (!session_id()) session_start();
        if (isset($_SESSION['ayotte_precourse_token'])) {
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id);
        }
    }

    public function registration_redirect($redirect_to) {
        if (!session_id()) session_start();
        if (isset($_SESSION['ayotte_precourse_token'])) {
            return site_url('/precourse-forms');
        }
        return $redirect_to;
    }

    /**
     * Append a "Student Portal" menu item for logged-in customers.
     */
    public function add_student_portal_menu($items, $args) {
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if (in_array('customer', (array) $user->roles, true)) {
                $url   = esc_url(site_url('/precourse-forms'));
                $items .= '<li class="menu-item menu-item-student-portal"><a href="' . $url . '">Student Portal</a></li>';
            }
        }
        return $items;
    }

    /**
     * Redirect customers to the student portal after login.
     */
    public function customer_login_redirect($redirect_to, $requested, $user) {
        if ($user instanceof WP_User && in_array('customer', (array) $user->roles, true)) {
            return site_url('/precourse-forms');
        }
        return $redirect_to;
    }
}
?>
