<?php
class Ayotte_Precourse {
    public function run() {
        add_action('init', [$this, 'add_rewrite_rules']);
        add_action('template_redirect', [$this, 'handle_token_redirect']);
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('user_register', [$this, 'link_token_to_user'], 10, 1);
        add_action('register_form', [$this, 'prefill_registration']);
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
    }

    public function render_main_panel() {
        ?>
        <div class="wrap">
            <h1>Ayotte Precourse Portal</h1>
            <p>Invite attendees by email and track progress.</p>
        <h2>Send Invitations</h2>
        <textarea id="inviteEmails" placeholder="Enter email addresses separated by newlines" rows="5" style="width:300px;"></textarea>
        <p><button id="sendInviteBtn">Send Invites</button></p>
        <p id="inviteResult"></p>
        <script>
            document.getElementById('sendInviteBtn').onclick = async () => {
                    const emails = document.getElementById('inviteEmails').value.trim();
                    if (!emails) {
                        document.getElementById('inviteResult').textContent = 'Please enter at least one email address.';
                        return;
                    }
                    const formData = new FormData();
                    formData.append('action', 'ayotte_send_bulk_invites');
                    formData.append('emails', emails);
                    const res = await fetch(ajaxurl, { method: 'POST', body: formData });
                    const data = await res.json();
                    document.getElementById('inviteResult').textContent = data.success ? data.data.message : 'Failed to send invitations.';
                };
            </script>
        </div>
        <?php
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
                wp_redirect(site_url('/register?email=' . urlencode($email) . '&token=' . urlencode($token)));
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
}
?>
