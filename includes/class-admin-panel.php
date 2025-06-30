<?php
class Ayotte_Admin_Panel {

    public function render_debug_console() {
        if (isset($_POST['ayotte_debug_submit']) && check_admin_referer('ayotte_debug_settings')) {
            $enabled  = isset($_POST['ayotte_debug_enabled']);
            $current  = (bool) get_option('ayotte_debug_enabled', false);
            if ($enabled && !$current) {
                update_option('ayotte_debug_enabled', true);
                ayotte_log_message('NOTICE', 'Debug mode enabled', 'admin panel');
            } elseif (!$enabled && $current) {
                ayotte_log_message('NOTICE', 'Debug mode disabled', 'admin panel');
                update_option('ayotte_debug_enabled', false);
            }
        }

        $debug_enabled = (bool) get_option('ayotte_debug_enabled', false);
        ?>
        <div class="wrap ayotte-admin-panel">
            <h1>Debug Console</h1>
            <form method="post" style="margin-bottom:1em;">
                <?php wp_nonce_field('ayotte_debug_settings'); ?>
                <label><input type="checkbox" name="ayotte_debug_enabled" <?php checked($debug_enabled); ?>> Enable debug logging</label>
                <input type="submit" name="ayotte_debug_submit" class="button button-secondary" value="Save">
            </form>
            <textarea id="debugCommand" rows="3" style="width:100%;" placeholder="Forminator_API::get_forms();"></textarea>
            <button id="runCommand" class="button button-secondary" style="margin-top:5px;">Run Command</button>
            <p id="commandResult"></p>
            <button id="clearLogs">Clear Logs</button>
            <button id="sendTestInvite">Send Test Invite (kris@psss.uk)</button>
            <div style="margin:10px 0;">
                <label>
                    Level
                    <select id="logLevel">
                        <option value="">All</option>
                        <option value="DEBUG">DEBUG</option>
                        <option value="INFO">INFO</option>
                        <option value="NOTICE">NOTICE</option>
                        <option value="ERROR">ERROR</option>
                    </select>
                </label>
                <label style="margin-left:10px;">
                    Module
                    <select id="logModule">
                        <option value="">All</option>
                        <?php
                        $logs    = get_option('ayotte_debug_logs', []);
                        $modules = [];
                        foreach ((is_array($logs) ? $logs : []) as $entry) {
                            if (!empty($entry['module']) && !in_array($entry['module'], $modules, true)) {
                                $modules[] = $entry['module'];
                            }
                        }
                        sort($modules);
                        foreach ($modules as $m) {
                            echo '<option value="' . esc_attr($m) . '">' . esc_html($m) . '</option>';
                        }
                        ?>
                    </select>
                </label>
            </div>
            <pre id="logOutput">Loading logs...</pre>
        </div>
        <script>
            const levelSel  = document.getElementById('logLevel');
            const moduleSel = document.getElementById('logModule');

            levelSel.value  = localStorage.getItem('ayotte_log_level')  || '';
            moduleSel.value = localStorage.getItem('ayotte_log_module') || '';

            levelSel.onchange = moduleSel.onchange = () => {
                localStorage.setItem('ayotte_log_level', levelSel.value);
                localStorage.setItem('ayotte_log_module', moduleSel.value);
                fetchLogs();
            };

            async function fetchLogs() {
                const level  = encodeURIComponent(levelSel.value);
                const module = encodeURIComponent(moduleSel.value);
                const res = await fetch(ajaxurl + '?action=ayotte_fetch_logs&level=' + level + '&module=' + module);
                const data = await res.json();
                const logs = data.success ? data.data : [];
                document.getElementById('logOutput').textContent =
                    logs.slice().reverse().map(log => `[${log.time}] [${log.level}] ${log.message}`).join('\n') || 'No logs available.';
            }

            document.getElementById('clearLogs').onclick = async () => {
                await fetch(ajaxurl + '?action=ayotte_clear_logs');
                fetchLogs();
            };

            document.getElementById('sendTestInvite').onclick = async () => {
                await fetch(ajaxurl + '?action=ayotte_send_test_invite');
                fetchLogs();
            };

            document.getElementById('runCommand').onclick = async () => {
                const cmd = document.getElementById('debugCommand').value;
                const res = await fetch(ajaxurl + '?action=ayotte_debug_execute', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ command: cmd })
                });
                const data = await res.json();
                document.getElementById('commandResult').textContent = data.success
                    ? (typeof data.data.result === 'string' ? data.data.result : JSON.stringify(data.data.result, null, 2))
                    : data.data.message;
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

        $available_ids = get_option('ayotte_available_forms', []);
        $all_forms     = Ayotte_Progress_Tracker::get_custom_forms();
        $form_options  = [];
        foreach ($all_forms as $form) {
            $fid   = intval($form['id'] ?? $form->id);
            $fname = $form['name'] ?? $form->name;
            if (in_array($fid, $available_ids, true)) {
                $form_options[$fid] = $fname;
            }
        }

        if (isset($_POST['ayotte_assigned_forms']) && check_admin_referer('ayotte_assign_forms')) {
            $tracker = new Ayotte_Progress_Tracker();
            foreach ($users as $user) {
                $user_id = (int) $user->ID;
                $forms   = array_map('intval', (array) ($_POST['ayotte_assigned_forms'][$user_id] ?? []));
                // Only store IDs for forms that actually exist in the custom database
                $forms   = array_values(array_intersect($forms, array_keys($form_options)));
                update_user_meta($user_id, 'ayotte_assigned_forms', $forms);

                // Always recalculate progress after updating assignments
                $tracker->recalculate_progress($user_id);
            }
        }

        $tracker = new Ayotte_Progress_Tracker();


        echo '<div class="wrap ayotte-admin-panel"><h1>Student Progress</h1><form method="post">';
        wp_nonce_field('ayotte_assign_forms');
        echo '<table class="widefat"><thead><tr><th>Email</th><th>Progress</th><th>Status</th><th>Forms</th><th>Unlock</th></tr></thead><tbody>';
        foreach ($users as $user) {
            $assigned = (array) get_user_meta($user->ID, 'ayotte_assigned_forms', true);
            $status_items    = [];
            $form_status_map = [];
            $changed         = false;

            foreach ($assigned as $form_id) {
                $status = $tracker->get_form_status($form_id, $user->ID);
                $form_status_map[$form_id] = $status;
                $stored = get_user_meta($user->ID, "ayotte_form_{$form_id}_status", true);
                if ($status !== $stored) {
                    update_user_meta($user->ID, "ayotte_form_{$form_id}_status", $status);
                    $changed = true;
                }
                $name   = $form_options[$form_id] ?? Ayotte_Progress_Tracker::get_form_name($form_id);
                switch ($status) {
                    case 'locked':
                        $label   = 'Completed (Locked)';
                        $percent = 100;
                        break;
                    case 'completed':
                        $label   = 'Completed';
                        $percent = 100;
                        break;
                    case 'draft':
                        $label   = 'In Progress';
                        $percent = 50;
                        break;
                    default:
                        $label   = 'Outstanding';
                        $percent = 0;
                        break;
                }
                $progress_class = ($status === 'locked' || $percent === 100) ? 'completed'
                                 : (($percent >= 50) ? 'draft' : 'outstanding');

                $item  = '<li>' . esc_html($name . ' - ' . $label)
                    . '<div class="ayotte-progress-bar"><div class="ayotte-progress-fill '
                    . $progress_class . '" style="width:' . $percent . '%"></div></div>';

                if (in_array($status, ['completed', 'locked'], true)) {
                    $view_url = esc_url(add_query_arg([
                        'form_id' => $form_id,
                        'user_id' => $user->ID,
                    ], site_url('/precourse-form')));
                    $item .= ' <a href="' . $view_url . '">View</a>';
                }

                $item .= '</li>';
                $status_items[] = $item;
            }

            if ($changed) {
                $tracker->recalculate_progress($user->ID);
            }
            $progress_val     = intval($tracker->get_progress($user->ID));
            $progress_display = $progress_val . '%';
            $progress_class   = ($progress_val == 100) ? 'completed'
                                : (($progress_val >= 50) ? 'draft' : 'outstanding');

            echo '<tr>';
            echo '<td>' . esc_html($user->user_email) . '</td>';
            echo '<td class="progress-cell">'
                 . esc_html($progress_display)
                 . '<div class="ayotte-progress-bar"><div class="ayotte-progress-fill ' . $progress_class . '" style="width:' . $progress_val . '%"></div></div>'
                 . '</td>';
            echo '<td><ul class="status-list">' . implode('', $status_items) . '</ul></td>';
            echo '<td><ul class="form-checkbox-list">';
            foreach ($form_options as $id => $name) {
                $checked = in_array($id, $assigned, true) ? 'checked' : '';
                echo '<li><label><input type="checkbox" name="ayotte_assigned_forms[' . intval($user->ID) . '][]" value="' . esc_attr($id) . '" ' . $checked . '> ' . esc_html($name) . '</label></li>';
            }
            echo '</ul></td>';

            $buttons = '';
            foreach ($assigned as $id) {
                if (($form_status_map[$id] ?? '') === 'locked') {
                    $name = $form_options[$id] ?? Ayotte_Progress_Tracker::get_form_name($id);
                    $buttons .= '<li>' . esc_html($name) .
                        ' <button type="button" class="ayotte-unlock-btn" data-user="' . intval($user->ID) . '" data-form="' . esc_attr($id) . '">Unlock</button> '
                        . '<span class="unlock-msg"></span></li>';
                }
            }
            echo '<td><ul class="form-unlock-list">' . $buttons . '</ul></td>';

            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p><button type="submit" class="button button-primary">Save Assignments</button></p>';
        echo '</form></div>';
        ?>
        <script>
        document.querySelectorAll('.ayotte-unlock-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const user = btn.dataset.user;
                const form = btn.dataset.form;
                const res = await fetch(ajaxurl + '?action=ayotte_unlock_form', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: user, form_id: form })
                });
                const data = await res.json();
                if (data.success) {
                    btn.nextElementSibling.textContent = 'Form unlocked';
                } else {
                    btn.nextElementSibling.textContent = data.data.message || 'Error';
                }
            });
        });
        </script>
        <?php
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
        $forms    = Ayotte_Progress_Tracker::get_custom_forms();

        echo '<div class="wrap ayotte-admin-panel">';
        echo '<h1>Form Sets</h1>';

        echo '<form method="post">';
        wp_nonce_field('ayotte_form_sets');

        foreach ($forms as $form) {
            $fid     = intval($form['id'] ?? $form->id);
            $fname   = $form['name'] ?? $form->name;
            $checked = in_array($fid, $selected, true) ? 'checked' : '';
            echo '<p><label><input type="checkbox" name="ayotte_available_forms[]" value="' . esc_attr($fid) . '" ' . $checked . '> ' . esc_html($fname) . '</label></p>';
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
        add_action('wp_ajax_ayotte_unlock_form', [$this, 'unlock_form']);
        add_action('wp_ajax_ayotte_debug_execute', [$this, 'debug_execute']);
    }

    public function fetch_logs() {
        $level  = isset($_GET['level']) ? strtoupper(sanitize_text_field($_GET['level'])) : '';
        $module = isset($_GET['module']) ? sanitize_text_field($_GET['module']) : '';

        $logs = get_option('ayotte_debug_logs', []);
        $logs = is_array($logs) ? $logs : [];

        if ($level !== '') {
            $logs = array_filter($logs, fn($log) => ($log['level'] ?? '') === $level);
        }
        if ($module !== '') {
            $logs = array_filter($logs, fn($log) => ($log['module'] ?? '') === $module);
        }

        $formatted = array_map(fn($log) => [
            'time' => $log['time'] ?? '',
            'level' => $log['level'] ?? '',
            'message' => $log['message'] ?? '',
            'module' => $log['module'] ?? '',
        ], $logs);
        wp_send_json_success(array_values($formatted));
    }

    public function clear_logs() {
        update_option('ayotte_debug_logs', []);
        wp_send_json_success(['message' => 'Logs cleared']);
    }

    public function send_test_invite() {
        (new Invitation_Manager())->send_invite_email('kris@psss.uk');
        ayotte_log_message('INFO', 'Test invitation sent to kris@psss.uk', 'admin panel');
        wp_send_json_success(['message' => 'Invite sent']);
    }

    public function send_invite_email() {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    $email = sanitize_email($_GET['email'] ?? '');
    if (!$email) {
        wp_send_json_error(['message' => 'Invalid email']);
    } else {
        $result = (new Invitation_Manager())->send_invite_email($email);
        if ($result) {
            ayotte_log_message('INFO', "Invitation sent to {$email}", 'admin panel');
        }
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
        ayotte_log_message('INFO', "Sent {$count} invitations", 'admin panel');
        wp_send_json_success(['message' => "Sent $count invitations"]);
    }

    /**
     * Unlock a previously locked form for a user.
     */
    public function unlock_form() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden'], 403);

        $body    = json_decode(file_get_contents('php://input'), true);
        $user_id = intval($body['user_id'] ?? 0);
        $form_id = intval($body['form_id'] ?? 0);
        if (!$user_id || !$form_id) {
            wp_send_json_error(['message' => 'Invalid parameters']);
        }

        update_user_meta($user_id, "ayotte_form_{$form_id}_unlocked", 1);

        $db = Custom_DB::get_instance()->get_connection();
        if (!$db instanceof WP_Error) {
            $res = $db->query("SELECT id FROM custom_form_submissions WHERE form_id=$form_id AND user_id=$user_id ORDER BY submitted_at DESC LIMIT 1");
            if ($res && $res->num_rows) {
                $row = $res->fetch_assoc();
                $sid = intval($row['id']);
                $db->query("UPDATE custom_form_submissions SET locked=0 WHERE id=$sid");
            }
        }

        ayotte_log_message('INFO', "Form {$form_id} unlocked for user {$user_id}", 'admin panel');
        wp_send_json_success(['message' => 'Form unlocked']);
    }

    /**
     * Execute limited debug commands via AJAX.
     */
    public function debug_execute() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $body    = json_decode(file_get_contents('php://input'), true);
        $command = trim($body['command'] ?? '');

        if ($command === '') {
            wp_send_json_error(['message' => 'Empty command']);
        }

        $pattern = '/^\s*Forminator_API::\w+\s*\(.*\)\s*;?\s*$/s';
        if (!preg_match($pattern, $command)) {
            wp_send_json_error(['message' => 'Invalid command']);
        }

        ayotte_log_message('DEBUG', 'EXECUTE ' . $command, 'admin panel');

        try {
            $result = eval('return ' . rtrim($command, ';') . ';');
            if (is_array($result) || is_object($result)) {
                $result = json_encode($result);
            }
            wp_send_json_success(['result' => $result]);
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

}
?>
