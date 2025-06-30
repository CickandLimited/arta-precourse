<?php
class Ayotte_Form_Manager {

    public function init() {
        add_shortcode('ayotte_precourse_form', [$this, 'render_form']);
        add_shortcode('ayotte_form_dashboard', [$this, 'render_dashboard']);
        add_action('wp_ajax_ayotte_save_precourse_form', [$this, 'save_form']);
        add_action('wp_ajax_nopriv_ayotte_save_precourse_form', [$this, 'save_form']);
    }

    /**
     * Render simple precourse form with personal info and ID upload.
     */
    public function render_form($atts = []) {
        if (!is_user_logged_in()) return '<p>Please log in first.</p>';

        $user_id = get_current_user_id();

        if (current_user_can('manage_options') && !empty($_GET['user_id'])) {
            $user_id = intval($_GET['user_id']);
        }
        $form_id = intval($atts['id'] ?? ($_GET['form_id'] ?? 0));

        // If a form ID is provided, display a custom form
        if ($form_id) {
            $assigned = (array) get_user_meta($user_id, 'ayotte_assigned_forms', true);
            if (!in_array($form_id, $assigned, true)) {
                return '<p>Form not assigned to you.</p>';
            }

            $status   = get_user_meta($user_id, "ayotte_form_{$form_id}_status", true);
            $unlocked = get_user_meta($user_id, "ayotte_form_{$form_id}_unlocked", true);

            if (in_array($status, ['completed', 'locked'], true) && !$unlocked) {
                return $this->render_readonly_submission($form_id, $user_id);
            }

            return do_shortcode('[ayotte_custom_form id="' . $form_id . '"]');
        }

        // Legacy precourse form
        $phone  = esc_attr(get_user_meta($user_id, 'ayotte_phone', true));
        $reason = esc_textarea(get_user_meta($user_id, 'ayotte_reason', true));

        ob_start();
        ?>
        <form id="ayottePrecourseForm" enctype="multipart/form-data">
            <?php wp_nonce_field('ayotte_precourse_form', 'ayotte_precourse_nonce'); ?>
            <p><label>Phone:<br><input type="text" name="phone" value="<?php echo $phone; ?>"/></label></p>
            <p><label>Photo ID:<br><input type="file" name="id_file" accept="image/*,application/pdf"/></label></p>
            <p><label>Why do you want to join the course?<br><textarea name="reason"><?php echo $reason; ?></textarea></label></p>
            <button type="submit" class="button">Submit</button>
            <span id="ayottePrecourseMsg"></span>
        </form>
        <script>
        document.getElementById('ayottePrecourseForm').onsubmit = async (e) => {
            e.preventDefault();
            const form = new FormData(e.target);
            const res = await fetch(ajaxurl + '?action=ayotte_save_precourse_form', {method:'POST', body: form});
            const data = await res.json();
            document.getElementById('ayottePrecourseMsg').textContent = data.data.message;
        };
        setInterval(async () => {
            const form = new FormData(document.getElementById('ayottePrecourseForm'));
            form.append('progress','partial');
            await fetch(ajaxurl + '?action=ayotte_save_progress', {method:'POST', body: form});
        }, 30000);
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Display a list of forms available to the current user.
     */
    public function render_dashboard() {
        if (!is_user_logged_in()) return '<p>Please log in first.</p>';
        $user_id  = get_current_user_id();
        $assigned = (array) get_user_meta($user_id, 'ayotte_assigned_forms', true);

        $tracker = new Ayotte_Progress_Tracker();
        $status_map = [];
        $changed    = false;
        foreach ($assigned as $fid) {
            $fid    = intval($fid);
            if (!$fid) continue;
            $status = $tracker->get_form_status($fid, $user_id);
            $status_map[$fid] = $status;
            $stored = get_user_meta($user_id, "ayotte_form_{$fid}_status", true);
            if ($status !== $stored) {
                update_user_meta($user_id, "ayotte_form_{$fid}_status", $status);
                $changed = true;
            }
        }

        if ($changed) {
            $tracker->recalculate_progress($user_id);
        }
        $progress_val = intval($tracker->get_progress($user_id));
        $progress = $progress_val . '%';

        ob_start();
        echo '<div class="ayotte-dashboard">';
        echo '<h2>Your Assigned Forms</h2>';
        echo '<p class="ayotte-progress-summary">Progress: ' . esc_html($progress) . '</p>';

        if (!$assigned) {
            echo '<p>No forms assigned.</p>';
        } else {
            echo '<table class="widefat"><thead><tr><th>Form</th><th>Status</th><th>Action</th></tr></thead><tbody>';
            foreach ($assigned as $form_id) {
                $form_id = intval($form_id);
                if (!$form_id) {
                    continue;
                }

                $status   = $status_map[$form_id] ?? $tracker->get_form_status($form_id, $user_id);
                $unlocked = get_user_meta($user_id, "ayotte_form_{$form_id}_unlocked", true);

                $url  = esc_url(add_query_arg('form_id', $form_id, site_url('/precourse-form')));
                $name = Ayotte_Progress_Tracker::get_form_name($form_id);

                $submitted     = in_array($status, ['completed', 'locked'], true);
                $locked        = ($status === 'locked') && !$unlocked;
                $request       = get_user_meta($user_id, "ayotte_form_{$form_id}_unlock_request", true);
                $status_class  = $status;
                if ($unlocked) {
                    $status_label = 'Unlocked for editing';
                    $action       = '<a class="button" href="' . $url . '">Edit</a>';
                    $status_class = 'unlocked';
                } else {
                    switch ($status) {
                        case 'locked':
                            $status_label = 'Completed (Locked)';
                            break;
                        case 'completed':
                            $status_label = 'Completed';
                            break;
                        case 'draft':
                            $status_label = 'In Progress';
                            break;
                        default:
                            $status_label = 'Outstanding';
                            break;
                    }

                    if ($locked) {
                        $action = '<a class="button" href="' . $url . '">View</a>';
                        if (!$request) {
                            $action .= ' <button type="button" class="button ayotte-request-unlock" data-form="' . esc_attr($form_id) . '">Request Unlock</button>';
                        } else {
                            $action .= ' <span class="unlock-requested">Request Sent</span>';
                        }
                    } else {
                        if ($submitted) {
                            $text = 'View';
                        } elseif ($status === 'draft') {
                            $text = 'Continue';
                        } else {
                            $text = 'Start';
                        }
                        $action = '<a class="button" href="' . $url . '">' . $text . '</a>';
                    }
                }

                echo '<tr>';
                echo '<td>' . esc_html($name) . '</td>';
                echo '<td><span class="ayotte-status ' . esc_attr($status_class) . '">' . esc_html($status_label) . '</span></td>';
                echo '<td>' . $action . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
        ?>
        <script>
        document.querySelectorAll('.ayotte-request-unlock').forEach(btn => {
            btn.addEventListener('click', async () => {
                const reason = prompt('Please provide a reason for unlocking this form:');
                if (!reason) return;
                btn.disabled = true;
                const res = await fetch(ajaxurl + '?action=ayotte_request_unlock', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ form_id: btn.dataset.form, reason })
                });
                const data = await res.json();
                if (data.success) {
                    btn.textContent = 'Requested';
                } else {
                    alert(data.data.message || 'Error');
                    btn.disabled = false;
                }
            });
        });
        </script>
        <?php

        return ob_get_clean();
    }

    /**
     * Fetch a user submission and render it in read-only mode.
     */
    public function render_readonly_submission($form_id, $user_id) {
        $db = Custom_DB::get_instance()->get_connection();
        if ($db instanceof WP_Error) {
            return '<p>Form submitted.</p>';
        }

        $fields_res = $db->query("SELECT id,label FROM custom_form_fields WHERE form_id=" . intval($form_id));
        $labels = [];
        while ($fields_res && ($row = $fields_res->fetch_assoc())) {
            $labels['field_' . intval($row['id'])] = $row['label'];
        }

        $sub_res = $db->query(
            "SELECT data FROM custom_form_submissions WHERE form_id=" . intval($form_id) .
            " AND user_id=" . intval($user_id) .
            " ORDER BY submitted_at DESC LIMIT 1"
        );

        if (!$sub_res || !$sub_res->num_rows) {
            return '<p>Form submitted.</p>';
        }

        $sub_row = $sub_res->fetch_assoc();
        $data = json_decode($sub_row['data'] ?? '', true);
        if (!is_array($data) || !$data) {
            return '<p>Form submitted.</p>';
        }

        $html = '<div class="ayotte-readonly-form"><table class="widefat">';
        foreach ($data as $key => $value) {
            $label = $labels[$key] ?? $key;
            $html .= '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        $html .= '</table></div>';

        return $html;
    }

    /**
     * Handle form save
     */
    public function save_form() {
        check_admin_referer('ayotte_precourse_form');
        if (!is_user_logged_in()) wp_send_json_error(['message' => 'Not logged in']);
        $user_id = get_current_user_id();
        update_user_meta($user_id, 'ayotte_phone', sanitize_text_field($_POST['phone'] ?? ''));
        update_user_meta($user_id, 'ayotte_reason', sanitize_textarea_field($_POST['reason'] ?? ''));
        $file = $_FILES['id_file'] ?? null;
        if ($file && !empty($file['tmp_name'])) {
            $uploaded = wp_handle_upload($file, ['test_form' => false, 'mimes' => ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','pdf'=>'application/pdf']]);
            if(!isset($uploaded['error'])) {
                update_user_meta($user_id, 'ayotte_id_file', $uploaded['url']);
            }
        }
        ayotte_log_message('INFO', "Precourse form saved for user {$user_id}", 'form manager');
        wp_send_json_success(['message' => 'Saved']);
    }
}
