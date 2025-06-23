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
        $form_id = intval($atts['id'] ?? ($_GET['form_id'] ?? 0));

        // If a Forminator form ID is provided, display that form
        if ($form_id) {
            $assigned = (array) get_user_meta($user_id, 'ayotte_assigned_forms', true);
            if (!in_array($form_id, $assigned, true)) {
                return '<p>Form not assigned to you.</p>';
            }

            $status   = get_user_meta($user_id, "ayotte_form_{$form_id}_status", true);
            $unlocked = get_user_meta($user_id, "ayotte_form_{$form_id}_unlocked", true);

            if ($status === 'complete' && !$unlocked) {
                return $this->render_readonly_submission($form_id, $user_id);
            }

            return do_shortcode('[forminator_form id="' . $form_id . '"]');
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
            <button type="submit">Submit</button>
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

        $progress = get_user_meta($user_id, 'ayotte_progress', true);
        $progress = $progress ?: '0%';

        ob_start();
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

                $status   = get_user_meta($user_id, "ayotte_form_{$form_id}_status", true);
                $unlocked = get_user_meta($user_id, "ayotte_form_{$form_id}_unlocked", true);

                $name = 'Form ' . $form_id;
                if (class_exists('Forminator_API')) {
                    $form = Forminator_API::get_form($form_id);
                    if ($form && !is_wp_error($form)) {
                        $name = $form->name;
                    }
                }

                $submitted = ($status === 'complete');
                $locked    = $submitted && !$unlocked;
                $status_label = $submitted ? 'Submitted' : 'Pending';

                if ($locked) {
                    $action = '<span class="dashicons dashicons-lock"></span>';
                } else {
                    $url    = esc_url(add_query_arg('form_id', $form_id, site_url('/precourse-form')));
                    $action = '<a class="button" href="' . $url . '">' . ($submitted ? 'View' : 'Fill') . '</a>';
                }

                echo '<tr>';
                echo '<td>' . esc_html($name) . '</td>';
                echo '<td>' . esc_html($status_label) . '</td>';
                echo '<td>' . $action . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        return ob_get_clean();
    }

    /**
     * Fetch a user submission and render it in read-only mode.
     */
    private function render_readonly_submission($form_id, $user_id) {
        if (!class_exists('Forminator_API')) {
            return '<p>Form submitted.</p>';
        }

        $args     = [
            'paged'    => 1,
            'per_page' => 1,
            'search'   => [
                'user_id' => $user_id,
            ],
        ];
        $entries  = Forminator_API::get_entries($form_id, $args);
        $entry    = $entries && !empty($entries->entries[0]) ? $entries->entries[0] : null;

        if (!$entry) {
            return '<p>Form submitted.</p>';
        }

        $fields = [];
        if (isset($entry->meta_data) && is_array($entry->meta_data)) {
            foreach ($entry->meta_data as $meta) {
                $label = $meta['name'] ?? ($meta->name ?? '');
                $value = $meta['value'] ?? ($meta->value ?? '');
                $fields[$label] = $value;
            }
        }

        if (!$fields) {
            return '<p>Form submitted.</p>';
        }

        $html = '<div class="ayotte-readonly-form"><table class="widefat">';
        foreach ($fields as $label => $value) {
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
        wp_send_json_success(['message' => 'Saved']);
    }
}
