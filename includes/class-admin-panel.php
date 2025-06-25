<?php
class Ayotte_Admin_Panel {

    public function render_debug_console() {
        ?>
        <div class="wrap ayotte-admin-panel">
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
                    logs.map(log => `[${log.time}] [${log.level}] ${log.message}`).join('\n') || 'No logs available.';
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

    /**
     * Render the main invite panel with support for bulk email sending.
     */
    public function render_invite_panel() {
        ?>
        <div class="wrap ayotte-admin-panel">
            <h1>Ayotte Precourse Portal</h1>
            <p>Invite attendees by email and track progress.</p>
            <h2>Send Invitations</h2>
            <textarea id="inviteEmails" placeholder="Enter one email per line" rows="5" style="width:300px;"></textarea>
            <button id="sendBulkInvites">Send Invites</button>
            <p id="bulkResult"></p>
        </div>
        <script>
        document.getElementById('sendBulkInvites').onclick = async () => {
            const emails = document.getElementById('inviteEmails').value.split(/\n|,/).map(e => e.trim()).filter(e => e);
            if(!emails.length) {
                document.getElementById('bulkResult').textContent = 'Please enter at least one email.';
                return;
            }
            const res = await fetch(ajaxurl + '?action=ayotte_send_bulk_invites', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ emails })
            });
            const data = await res.json();
            document.getElementById('bulkResult').textContent = data.data.message;
        };
        </script>
        <?php
    }

    /**
     * Display a simple progress dashboard
     */
    public function render_tracking_dashboard() {
        $users = get_users(['role' => 'customer']);

        if (isset($_POST['ayotte_assigned_forms']) && check_admin_referer('ayotte_assign_forms')) {
            $tracker = new Ayotte_Progress_Tracker();
            foreach ($users as $user) {
                $user_id = (int) $user->ID;
                $forms   = array_map('intval', (array) ($_POST['ayotte_assigned_forms'][$user_id] ?? []));
                update_user_meta($user_id, 'ayotte_assigned_forms', $forms);

                $unlock = $_POST['ayotte_unlock_forms'][$user_id] ?? [];
                foreach ((array) $unlock as $form_id) {
                    $form_id = intval($form_id);
                    delete_user_meta($user_id, "ayotte_form_{$form_id}_status");
                }

                // Always recalculate progress after updating assignments
                $tracker->recalculate_progress($user_id);
            }
        }

        $available_ids = get_option('ayotte_available_forms', []);
        $all_forms     = class_exists('Forminator_API') ? Forminator_API::get_forms() : [];
        $form_options  = [];
        foreach ($all_forms as $form) {
            if (in_array($form->id, $available_ids, true)) {
                $form_options[$form->id] = $form->name;
            }
        }

        $tracker = new Ayotte_Progress_Tracker();


        echo '<div class="wrap ayotte-admin-panel"><h1>Student Progress</h1><form method="post">';
        wp_nonce_field('ayotte_assign_forms');
        echo '<table class="widefat"><thead><tr><th>Email</th><th>Progress</th><th>Status</th><th>Forms</th><th>Unlock</th></tr></thead><tbody>';
        foreach ($users as $user) {
            $assigned = (array) get_user_meta($user->ID, 'ayotte_assigned_forms', true);
            $status_items = [];
            $changed = false;

            foreach ($assigned as $form_id) {
                $status = $tracker->get_form_status($form_id, $user->ID);
                $stored = get_user_meta($user->ID, "ayotte_form_{$form_id}_status", true);
                if ($status !== $stored) {
                    update_user_meta($user->ID, "ayotte_form_{$form_id}_status", $status);
                    $changed = true;
                }
                $name   = $form_options[$form_id] ?? 'Form ' . $form_id;
                switch ($status) {
                    case 'completed':
                        $label = 'Completed';
                        break;
                    case 'draft':
                        $label = 'Draft';
                        break;
                    default:
                        $label = 'Outstanding';
                        break;
                }
                $status_items[] = '<li>' . esc_html($name . ' - ' . $label) . '</li>';
            }

            if ($changed) {
                $tracker->recalculate_progress($user->ID);
            }
            $progress_val     = intval($tracker->get_progress($user->ID));
            $progress_display = $progress_val . '%';

            echo '<tr>';
            echo '<td>' . esc_html($user->user_email) . '</td>';
            echo '<td>' . esc_html($progress_display) . '</td>';
            echo '<td><ul class="status-list">' . implode('', $status_items) . '</ul></td>';
            echo '<td>';
            foreach ($form_options as $id => $name) {
                $checked = in_array($id, $assigned, true) ? 'checked' : '';
                echo '<label style="margin-right:10px;"><input type="checkbox" name="ayotte_assigned_forms[' . intval($user->ID) . '][]" value="' . esc_attr($id) . '" ' . $checked . '> ' . esc_html($name) . '</label>';
            }
            echo '</td>';

            echo '<td>';
            foreach ($assigned as $id) {
                $name = $form_options[$id] ?? 'Form ' . $id;
                echo '<label style="margin-right:10px;"><input type="checkbox" name="ayotte_unlock_forms[' . intval($user->ID) . '][]" value="' . esc_attr($id) . '"> ' . esc_html($name) . '</label>';
            }
            echo '</td>';

            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p><button type="submit" class="button button-primary">Save Assignments</button></p>';
        echo '</form></div>';
    }

    /**
     * Simple page for managing optional form sets
     */
    public function render_form_sets_page() {
        if (!empty($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'ayotte_form_sets')) {
            $selected = array_map('intval', (array) ($_POST['ayotte_available_forms'] ?? []));
            update_option('ayotte_available_forms', $selected);
        }

        $selected = get_option('ayotte_available_forms', []);
        $forms    = class_exists('Forminator_API') ? Forminator_API::get_forms() : [];

        echo '<div class="wrap ayotte-admin-panel">';
        echo '<h1>Form Sets</h1>';

        echo '<form method="post">';
        wp_nonce_field('ayotte_form_sets');

        foreach ($forms as $form) {
            $checked = in_array($form->id, $selected, true) ? 'checked' : '';
            echo '<p><label><input type="checkbox" name="ayotte_available_forms[]" value="' . esc_attr($form->id) . '" ' . $checked . '> ' . esc_html($form->name) . '</label></p>';
        }

        echo '<p><button type="submit" class="button button-primary">Save Forms</button></p>';
        echo '</form></div>';
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

    /**
     * AJAX handler for sending multiple invites at once.
     */
    public function send_bulk_invites() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $body = json_decode(file_get_contents('php://input'), true);
        $emails = array_filter(array_map('sanitize_email', $body['emails'] ?? []));
        $count  = 0;
        foreach ($emails as $email) {
            if ($email) {
                (new Invitation_Manager())->send_invite_email($email);
                $count++;
            }
        }
        wp_send_json_success(['message' => "Sent $count invitations"]);
    }

}
?>
