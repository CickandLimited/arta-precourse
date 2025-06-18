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
        <div class="wrap">
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
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $users = get_users(['meta_key' => 'ayotte_precourse_token']);
        $forms = get_posts(['post_type' => 'ayotte_form', 'numberposts' => -1]);

        echo '<div class="wrap"><h1>Student Progress</h1>';
        echo '<div class="bulk-actions"><select id="ayotteBulkForm">';
        foreach ($forms as $f) {
            echo '<option value="' . esc_attr($f->ID) . '">' . esc_html($f->post_title) . '</option>';
        }
        echo '</select> <button id="ayotteBulkAssign" class="button">Assign</button>';
        echo ' <button id="ayotteBulkRemove" class="button">Remove</button></div>';

        echo '<table class="widefat"><thead><tr><th><input type="checkbox" id="ayotteSelectAll" /></th><th>Email</th><th>Progress</th><th>Forms</th></tr></thead><tbody>';
        foreach ($users as $user) {
            $progress = get_user_meta($user->ID, 'ayotte_progress', true);
            $assigned = get_user_meta($user->ID, 'ayotte_assigned_forms', true);
            if (!is_array($assigned)) $assigned = [];
            echo '<tr>';
            echo '<td><input type="checkbox" class="ayotteUserSelect" value="' . esc_attr($user->ID) . '"></td>';
            echo '<td>' . esc_html($user->user_email) . '</td>';
            echo '<td>' . esc_html($progress ?: '0%') . '</td>';
            echo '<td><select multiple class="ayotteUserForms" data-user="' . esc_attr($user->ID) . '">';
            foreach ($forms as $f) {
                $sel = in_array($f->ID, $assigned) ? ' selected' : '';
                $status = get_user_meta($user->ID, 'ayotte_form_' . $f->ID . '_progress', true);
                if ($status === 'complete') $status = ' (done)';
                elseif ($status) $status = ' (' . $status . ')';
                echo '<option value="' . esc_attr($f->ID) . '"' . $sel . '>' . esc_html($f->post_title . $status) . '</option>';
            }
            echo '</select></td></tr>';
        }
        echo '</tbody></table></div>';

        ?>
        <script>
        document.getElementById('ayotteSelectAll').onchange = function(){
            document.querySelectorAll('.ayotteUserSelect').forEach(cb=>cb.checked = this.checked);
        };
        document.querySelectorAll('.ayotteUserForms').forEach(sel => {
            sel.addEventListener('change', async function(){
                const uid = this.dataset.user;
                const forms = Array.from(this.selectedOptions).map(o => o.value);
                const fd = new FormData();
                fd.append('action','ayotte_set_assigned_forms');
                fd.append('user_ids[]', uid);
                forms.forEach(f => fd.append('forms[]', f));
                await fetch(ajaxurl,{method:'POST', body: fd});
            });
        });
        async function bulkModify(mode){
            const ids = Array.from(document.querySelectorAll('.ayotteUserSelect:checked')).map(c=>c.value);
            if(!ids.length) return;
            const fd = new FormData();
            fd.append('action','ayotte_bulk_modify_forms');
            fd.append('mode',mode);
            fd.append('form_id', document.getElementById('ayotteBulkForm').value);
            ids.forEach(id => fd.append('user_ids[]', id));
            await fetch(ajaxurl,{method:'POST', body: fd});
            location.reload();
        }
        document.getElementById('ayotteBulkAssign').onclick = ()=>bulkModify('assign');
        document.getElementById('ayotteBulkRemove').onclick = ()=>bulkModify('remove');
        </script>
        <?php
    }

    /**
     * Simple page for managing optional form sets
     */
    public function render_form_sets_page() {
        if (isset($_POST['new_set']) && check_admin_referer('ayotte_form_sets')) {
            $sets = get_option('ayotte_form_sets', []);
            $sets[] = sanitize_text_field($_POST['new_set']);
            update_option('ayotte_form_sets', $sets);
        }
        $sets = get_option('ayotte_form_sets', []);
        echo '<div class="wrap"><h1>Form Sets</h1><form method="post">';
        wp_nonce_field('ayotte_form_sets');
        echo '<input type="text" name="new_set" placeholder="Form set name" />';
        echo '<button type="submit" class="button">Add</button></form><ul>';
        foreach ($sets as $set) echo '<li>' . esc_html($set) . '</li>';
        echo '</ul></div>';
    }

    public function init() {
        add_action('wp_ajax_ayotte_fetch_logs', [$this, 'fetch_logs']);
        add_action('wp_ajax_ayotte_clear_logs', [$this, 'clear_logs']);
        add_action('wp_ajax_ayotte_send_test_invite', [$this, 'send_test_invite']);
        add_action('wp_ajax_ayotte_send_invite_email', [$this, 'send_invite_email']);
        add_action('wp_ajax_ayotte_send_bulk_invites', [$this, 'send_bulk_invites']);
        add_action('wp_ajax_ayotte_set_assigned_forms', [$this, 'set_assigned_forms']);
        add_action('wp_ajax_ayotte_bulk_modify_forms', [$this, 'bulk_modify_forms']);
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

    public function set_assigned_forms() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $user_ids = array_map('intval', $_POST['user_ids'] ?? []);
        $forms    = array_map('intval', $_POST['forms'] ?? []);
        foreach ($user_ids as $uid) {
            update_user_meta($uid, 'ayotte_assigned_forms', $forms);
        }
        wp_send_json_success(['message' => 'updated']);
    }

    public function bulk_modify_forms() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $user_ids = array_map('intval', $_POST['user_ids'] ?? []);
        $form_id  = intval($_POST['form_id'] ?? 0);
        $mode     = sanitize_text_field($_POST['mode'] ?? 'assign');
        foreach ($user_ids as $uid) {
            $current = get_user_meta($uid, 'ayotte_assigned_forms', true);
            if (!is_array($current)) $current = [];
            if ($mode === 'assign') {
                if (!in_array($form_id, $current, true)) $current[] = $form_id;
            } else {
                $current = array_diff($current, [$form_id]);
            }
            update_user_meta($uid, 'ayotte_assigned_forms', array_values($current));
        }
        wp_send_json_success(['message' => 'updated']);
    }
    /**
     * Render the Form Builder page for creating custom forms.
     */
    public function render_form_builder() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        $edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;

        if (isset($_POST['ayotte_form_builder_nonce']) && wp_verify_nonce($_POST['ayotte_form_builder_nonce'], 'ayotte_form_builder')) {
            $form_id = intval($_POST['form_id'] ?? 0);
            $title   = sanitize_text_field($_POST['form_title'] ?? '');
            $fields  = wp_unslash($_POST['form_fields'] ?? '[]');
            $set     = sanitize_text_field($_POST['form_set'] ?? '');

            $post_data = [
                'post_type'   => 'ayotte_form',
                'post_title'  => $title,
                'post_status' => 'publish',
            ];

            if ($form_id) {
                $post_data['ID'] = $form_id;
                wp_update_post($post_data);
            } else {
                $form_id = wp_insert_post($post_data);
            }

            update_post_meta($form_id, 'ayotte_form_title', $title);
            update_post_meta($form_id, 'ayotte_form_fields', $fields);
            update_post_meta($form_id, 'ayotte_form_set', $set);
            $edit_id = $form_id;

            echo '<div class="updated"><p>Form saved.</p></div>';
        }

        $sets  = get_option('ayotte_form_sets', []);
        $forms = get_posts(['post_type' => 'ayotte_form', 'numberposts' => -1]);

        $title     = '';
        $fields_js = '[]';
        $form_set  = '';
        if ($edit_id) {
            $p = get_post($edit_id);
            if ($p) {
                $title     = $p->post_title;
                $fields_js = get_post_meta($edit_id, 'ayotte_form_fields', true) ?: '[]';
                $form_set  = get_post_meta($edit_id, 'ayotte_form_set', true);
            }
        }

        echo '<div class="wrap"><h1>Form Builder</h1>';
        echo '<form method="post" id="ayotteFormBuilder">';
        wp_nonce_field('ayotte_form_builder', 'ayotte_form_builder_nonce');
        echo '<input type="hidden" name="form_id" value="' . esc_attr($edit_id) . '" />';
        echo '<p><label>Form Title:<br><input type="text" name="form_title" value="' . esc_attr($title) . '" style="width:300px" /></label></p>';
        echo '<p><label>Form Set:<br><select name="form_set"><option value="">None</option>';
        foreach ($sets as $set) {
            echo '<option value="' . esc_attr($set) . '"' . selected($form_set, $set, false) . '>' . esc_html($set) . '</option>';
        }
        echo '</select></label></p>';
        echo '<div id="fieldsContainer"></div>';
        echo '<button type="button" id="addField" class="button">Add Field</button>';
        echo '<input type="hidden" name="form_fields" id="formFieldsInput" />';
        echo '<p><input type="submit" class="button button-primary" value="Save Form" /></p>';
        echo '</form>';

        echo '<h2>Existing Forms</h2><ul>';
        foreach ($forms as $f) {
            $url = add_query_arg('edit', $f->ID, menu_page_url('precourse-form-builder', false));
            echo '<li><a href="' . esc_url($url) . '">' . esc_html($f->post_title) . '</a></li>';
        }
        echo '</ul></div>';

        echo '<script>var fields = ' . $fields_js . ';\n' .
             'function renderFields(){const c=document.getElementById("fieldsContainer");c.innerHTML="";fields.forEach((f,i)=>{const d=document.createElement("div");d.innerHTML=`<input type="text" class="field-label" placeholder="Label" value="${f.label||""}"> <select class="field-type"><option value="text"${f.type==="text"?" selected":""}>Text</option><option value="textarea"${f.type==="textarea"?" selected":""}>Textarea</option><option value="file"${f.type==="file"?" selected":""}>File</option></select> <button type="button" class="removeField">Remove</button>`;d.querySelector(".removeField").onclick=()=>{fields.splice(i,1);renderFields();};c.appendChild(d);});document.getElementById("formFieldsInput").value=JSON.stringify(fields);}document.getElementById("addField").onclick=()=>{fields.push({label:"",type:"text"});renderFields();};renderFields();</script>';
    }

}
?>
