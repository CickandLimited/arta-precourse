<?php
class Ayotte_Form_DB_Settings {
    public function init() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('wp_ajax_ayotte_test_db_connection', [$this, 'ajax_test_connection']);
    }

    public function add_menu() {
        add_submenu_page(
            'ayotte-precourse',
            'Form DB Settings',
            'Form DB Settings',
            'manage_options',
            'ayotte-form-db-settings',
            [$this, 'render_page']
        );
    }

    public function render_page() {
        if (!empty($_POST['ayotte_form_db_nonce']) && check_admin_referer('ayotte_form_db_settings', 'ayotte_form_db_nonce')) {
            update_option('ayotte_form_db_host', sanitize_text_field($_POST['ayotte_form_db_host'] ?? ''));
            update_option('ayotte_form_db_user', sanitize_text_field($_POST['ayotte_form_db_user'] ?? ''));
            update_option('ayotte_form_db_pass', sanitize_text_field($_POST['ayotte_form_db_pass'] ?? ''));
            update_option('ayotte_form_db_name', sanitize_text_field($_POST['ayotte_form_db_name'] ?? ''));
            echo '<div class="updated"><p>Settings saved.</p></div>';
        }

        $host = esc_attr(get_option('ayotte_form_db_host', ''));
        $user = esc_attr(get_option('ayotte_form_db_user', ''));
        $pass = esc_attr(get_option('ayotte_form_db_pass', ''));
        $name = esc_attr(get_option('ayotte_form_db_name', ''));
        ?>
        <div class="wrap">
            <h1>Form DB Settings</h1>
            <form method="post">
                <?php wp_nonce_field('ayotte_form_db_settings', 'ayotte_form_db_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="ayotte_form_db_host">Host</label></th>
                        <td><input name="ayotte_form_db_host" id="ayotte_form_db_host" type="text" value="<?php echo $host; ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ayotte_form_db_user">Username</label></th>
                        <td><input name="ayotte_form_db_user" id="ayotte_form_db_user" type="text" value="<?php echo $user; ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ayotte_form_db_pass">Password</label></th>
                        <td><input name="ayotte_form_db_pass" id="ayotte_form_db_pass" type="password" value="<?php echo $pass; ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ayotte_form_db_name">Database</label></th>
                        <td><input name="ayotte_form_db_name" id="ayotte_form_db_name" type="text" value="<?php echo $name; ?>" class="regular-text"></td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" class="button button-primary" value="Save Changes">
                    <button type="button" id="ayotte-test-db" class="button">Test Connection</button>
                    <span id="ayotte-test-db-result" style="margin-left:10px;"></span>
                </p>
            </form>
        </div>
        <script>
        document.getElementById('ayotte-test-db').onclick = function() {
            const resultEl = document.getElementById('ayotte-test-db-result');
            resultEl.textContent = 'Testing...';
            const data = new FormData();
            data.append('action', 'ayotte_test_db_connection');
            data.append('nonce', '<?php echo wp_create_nonce("ayotte-test-db"); ?>');
            fetch(ajaxurl, {method:'POST', body: data})
                .then(r => r.json())
                .then(res => {
                    resultEl.textContent = res.success ? 'Connection successful' : 'Connection failed';
                });
        };
        </script>
        <?php
    }

    public function ajax_test_connection() {
        check_ajax_referer('ayotte-test-db', 'nonce');
        $db = Custom_DB::get_instance();
        $conn = $db->connect();
        if ($conn instanceof WP_Error) {
            wp_send_json_error();
        } else {
            $db->ensure_schema();
            wp_send_json_success();
        }
    }
}
?>
