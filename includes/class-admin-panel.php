<?php
class Ayotte_Admin_Panel {

    public function render_debug_console() {
        ?>
        <div class="wrap">
            <h1>Debug Console</h1>
            <button id="clearLogs">Clear Logs</button>
            <button id="sendTestInvite">Send Test Invite (kris@psss.uk)</button>
            <pre id="logOutput">Loading logs...</pre>
        </div>
        <script>
            async function fetchLogs() {
                const res = await fetch(ajaxurl + '?action=ayotte_fetch_logs');
                const data = await res.json();
                const logs = data.success ? data.data : [];
                document.getElementById('logOutput').textContent =
                    logs.map(log => `[${log.time}] [${log.level}] ${log.message}`).join('\\n') || 'No logs available.';
            }

            document.getElementById('clearLogs').onclick = async () => {
                await fetch(ajaxurl + '?action=ayotte_clear_logs');
                fetchLogs();
            };

            document.getElementById('sendTestInvite').onclick = async () => {
                await fetch(ajaxurl + '?action=ayotte_send_test_invite');
                fetchLogs();
            };

            setInterval(fetchLogs, 3000);
            fetchLogs();
        </script>
        <?php
    }

    public function init() {
        add_action('wp_ajax_ayotte_fetch_logs', [$this, 'fetch_logs']);
        add_action('wp_ajax_ayotte_clear_logs', [$this, 'clear_logs']);
        add_action('wp_ajax_ayotte_send_test_invite', [$this, 'send_test_invite']);
        add_action('wp_ajax_ayotte_send_invite_email', [$this, 'send_invite_email']);
        add_action('wp_ajax_ayotte_send_bulk_invites', [$this, 'send_bulk_invites']);
    }

    public function fetch_logs() {
        $logs = get_option('ayotte_debug_logs', []);
        $formatted = array_map(fn($log) => [
            'time' => $log['time'] ?? '',
            'level' => $log['level'] ?? '',
            'message' => $log['message'] ?? '',
        ], is_array($logs) ? $logs : []);
        wp_send_json_success($formatted);
    }

    public function clear_logs() {
        update_option('ayotte_debug_logs', []);
        wp_send_json_success(['message' => 'Logs cleared']);
    }

    public function send_test_invite() {
        (new Invitation_Manager())->send_invite_email('kris@psss.uk');
        wp_send_json_success(['message' => 'Invite sent']);
    }

    public function send_invite_email() {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    $email = sanitize_email($_GET['email'] ?? '');
    if (!$email) {
        wp_send_json_error(['message' => 'Invalid email']);
    } else {
        (new Invitation_Manager())->send_invite_email($email);
        wp_send_json_success(['message' => "Invitation sent to $email"]);
    }
}

    public function send_bulk_invites() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $emails_raw = isset($_POST['emails']) ? wp_unslash($_POST['emails']) : '';
        $emails = array_filter(array_map('trim', preg_split('/\r?\n|,/', $emails_raw)));
        if (empty($emails)) {
            wp_send_json_error(['message' => 'No emails provided']);
            return;
        }
        $manager = new Invitation_Manager();
        $sent = 0;
        foreach ($emails as $email) {
            $email = sanitize_email($email);
            if ($email && $manager->send_invite_email($email)) {
                $sent++;
            }
        }
        wp_send_json_success(['message' => "$sent invitation(s) sent"]);
    }

}
?>
