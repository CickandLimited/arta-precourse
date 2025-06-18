<?php
class Ayotte_Precourse {
    public function run() {
        add_action('init', [$this, 'add_rewrite_rules']);
        add_action('init', [$this, 'register_form_post_type']);
        add_action('template_redirect', [$this, 'handle_token_redirect']);
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('add_meta_boxes', [$this, 'add_form_meta_boxes']);
        add_action('save_post_ayotte_form', [$this, 'save_form_meta']);
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
            AYOTTE_PRECOURSE_CAPABILITY,
            'ayotte-precourse',
            [$this, 'render_main_panel'],
            'dashicons-welcome-learn-more',
            30
        );

        add_submenu_page(
            'ayotte-precourse',
            'Debug Console',
            'Debug Console',
            AYOTTE_PRECOURSE_CAPABILITY,
            'precourse-debug-console',
            [new Ayotte_Admin_Panel(), 'render_debug_console']
        );

        add_submenu_page(
            'ayotte-precourse',
            'Student Progress',
            'Student Progress',
            AYOTTE_PRECOURSE_CAPABILITY,
            'precourse-progress',
            [new Ayotte_Admin_Panel(), 'render_tracking_dashboard']
        );

        add_submenu_page(
            'ayotte-precourse',
            'Form Sets',
            'Form Sets',
            AYOTTE_PRECOURSE_CAPABILITY,
            'precourse-form-sets',
            [new Ayotte_Admin_Panel(), 'render_form_sets_page']
        );

        add_submenu_page(
            'ayotte-precourse',
            'Form Builder',
            'Form Builder',
            AYOTTE_PRECOURSE_CAPABILITY,
            'precourse-form-builder',
            [new Ayotte_Admin_Panel(), 'render_form_builder']
        );
    }

    public function render_main_panel() {
        (new Ayotte_Admin_Panel())->render_invite_panel();
    }

    public function add_rewrite_rules() {
        add_rewrite_rule('^precourse-invite/?(.*)', 'index.php?precourse_invite=1', 'top');
        add_rewrite_tag('%precourse_invite%', '1');
        ayotte_log_message('INFO', 'Rewrite rules added');
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
                ayotte_log_message('INFO', "Token valid for email: $email");
                $reg_url = wp_registration_url();
                $query   = '?email=' . urlencode($email) . '&token=' . urlencode($token);
                wp_redirect($reg_url . $query);
                exit;
            } else {
                ayotte_log_message('ERROR', "Invalid or expired token: $token");
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
            ayotte_log_message('INFO', "Linked token $token to user $user_id");
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

    /**
     * Register the ayotte_form custom post type.
     */
    public function register_form_post_type() {
        $labels = [
            'name'               => 'Forms',
            'singular_name'      => 'Form',
            'add_new_item'       => 'Add New Form',
            'edit_item'          => 'Edit Form',
            'new_item'           => 'New Form',
            'view_item'          => 'View Form',
            'search_items'       => 'Search Forms',
            'not_found'          => 'No forms found',
            'not_found_in_trash' => 'No forms found in Trash',
        ];

        $caps = [
            'edit_post'              => AYOTTE_PRECOURSE_CAPABILITY,
            'read_post'              => 'read',
            'delete_post'            => AYOTTE_PRECOURSE_CAPABILITY,
            'edit_posts'             => AYOTTE_PRECOURSE_CAPABILITY,
            'edit_others_posts'      => AYOTTE_PRECOURSE_CAPABILITY,
            'publish_posts'          => AYOTTE_PRECOURSE_CAPABILITY,
            'read_private_posts'     => AYOTTE_PRECOURSE_CAPABILITY,
            'delete_posts'           => AYOTTE_PRECOURSE_CAPABILITY,
            'delete_private_posts'   => AYOTTE_PRECOURSE_CAPABILITY,
            'delete_published_posts' => AYOTTE_PRECOURSE_CAPABILITY,
            'delete_others_posts'    => AYOTTE_PRECOURSE_CAPABILITY,
            'edit_private_posts'     => AYOTTE_PRECOURSE_CAPABILITY,
            'edit_published_posts'   => AYOTTE_PRECOURSE_CAPABILITY,
            'create_posts'           => AYOTTE_PRECOURSE_CAPABILITY,
        ];

        register_post_type('ayotte_form', [
            'labels'          => $labels,
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => 'ayotte-precourse',
            'supports'        => ['title'],
            'capability_type' => 'ayotte_form',
            'capabilities'    => $caps,
            'map_meta_cap'    => true,
        ]);
    }

    /**
     * Add meta boxes for ayotte_form posts.
     */
    public function add_form_meta_boxes() {
        add_meta_box(
            'ayotte_form_meta',
            'Form Details',
            [$this, 'render_form_meta_box'],
            'ayotte_form'
        );
    }

    /**
     * Render the form details meta box.
     */
    public function render_form_meta_box($post) {
        wp_nonce_field('ayotte_form_meta', 'ayotte_form_meta_nonce');
        $title  = get_post_meta($post->ID, 'ayotte_form_title', true);
        $form_set = get_post_meta($post->ID, 'ayotte_form_set', true);
        $fields = get_post_meta($post->ID, 'ayotte_form_fields', true);
        echo '<p><label>Form Title:<br>';
        echo '<input type="text" name="ayotte_form_title" value="' . esc_attr($title) . '" style="width:100%" />';
        echo '</label></p>';
        $sets = get_option('ayotte_form_sets', []);
        echo '<p><label>Form Set:<br><select name="ayotte_form_set"><option value="">None</option>';
        foreach($sets as $set) echo '<option value="' . esc_attr($set) . '"' . selected($form_set, $set, false) . '>' . esc_html($set) . '</option>';
        echo '</select></label></p>';
        echo '<p><label>Field Definitions (JSON):<br>';
        echo '<textarea name="ayotte_form_fields" rows="6" style="width:100%">' . esc_textarea($fields) . '</textarea>';
        echo '</label></p>';
    }

    /**
     * Save form metadata when the post is saved.
     */
    public function save_form_meta($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['ayotte_form_meta_nonce']) || !wp_verify_nonce($_POST['ayotte_form_meta_nonce'], 'ayotte_form_meta')) return;
        if (!current_user_can(AYOTTE_PRECOURSE_CAPABILITY)) return;

        if (isset($_POST['ayotte_form_fields'])) {
            update_post_meta($post_id, 'ayotte_form_fields', wp_unslash($_POST['ayotte_form_fields']));
        }
        if (isset($_POST['ayotte_form_title'])) {
            update_post_meta($post_id, 'ayotte_form_title', sanitize_text_field($_POST['ayotte_form_title']));
        }
        if (isset($_POST['ayotte_form_set'])) {
            update_post_meta($post_id, 'ayotte_form_set', sanitize_text_field($_POST['ayotte_form_set']));
        }
    }
}
?>
