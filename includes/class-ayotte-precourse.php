<?php
class Ayotte_Precourse {
    public function run() {
        add_action('admin_menu', [$this, 'add_menu']);
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

        // Hidden page for viewing individual submissions
        add_submenu_page(
            null,
            'View Submission',
            'View Submission',
            'manage_options',
            'precourse-view-submission',
            [new Ayotte_Form_Manager(), 'render_admin_submission_page']
        );
    }

    public function render_main_panel() {
        (new Ayotte_Admin_Panel())->render_invite_panel();
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
