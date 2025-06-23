<?php
class Ayotte_Form_Manager {

    /**
     * Track when a Forminator form is being rendered by this plugin
     * so the hidden email field can be injected only once per form.
     *
     * @var bool
     */
    private $inject_email = false;

    /**
     * Ensure we don't add the field multiple times on a single form.
     *
     * @var bool
     */
    private $email_added = false;

    public function init() {
        add_shortcode('ayotte_precourse_form', [$this, 'render_form']);
        add_shortcode('ayotte_form_dashboard', [$this, 'render_dashboard']);
        add_action('wp_ajax_ayotte_save_precourse_form', [$this, 'save_form']);
        add_action('wp_ajax_nopriv_ayotte_save_precourse_form', [$this, 'save_form']);
        add_action('wp_ajax_ayotte_request_unlock', [$this, 'request_unlock']);

        // Inject logged-in user email as hidden field when rendering Forminator forms
        add_filter('forminator_render_form', [$this, 'add_email_field'], 10, 2);
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

            $this->inject_email = true;
            $this->email_added  = false;
            $html = do_shortcode('[forminator_form id="' . $form_id . '"]');
            $this->inject_email = false;

            return $html;
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

        // If a form ID is provided, display that form on the same page.
        $form_id = intval($_GET['form_id'] ?? 0);
        if ($form_id) {
            return $this->render_form(['id' => $form_id]);
        }

        $user_id  = get_current_user_id();
        $tracker  = new Ayotte_Progress_Tracker();
        $tracker->sync_user_forms($user_id);
        $assigned = (array) get_user_meta($user_id, 'ayotte_assigned_forms', true);

        $progress = get_user_meta($user_id, 'ayotte_progress', true);
        $progress = $progress ?: '0%';

        ob_start();
        echo '<div class="ayotte-dashboard">';
        echo '<h2>Your Assigned Forms</h2>';
        echo '<div class="ayotte-progress-bar"><div class="ayotte-progress-fill" style="width:' . esc_attr($progress) . ';">' . esc_html($progress) . '</div></div>';
        echo '<p class="ayotte-progress-summary">Progress: ' . esc_html($progress) . '</p>';

        if (!$assigned) {
            echo '<p>No forms assigned.</p>';
        } else {
            echo '<table class="widefat ayotte-dashboard-table"><thead><tr><th>Form</th><th>Status</th><th>Action</th></tr></thead><tbody>';
            foreach ($assigned as $form_id) {
                $form_id = intval($form_id);
                if (!$form_id) {
                    continue;
                }

                $status   = get_user_meta($user_id, "ayotte_form_{$form_id}_status", true);

                $name = 'Form ' . $form_id;
                if (class_exists('Forminator_API')) {
                    $form = Forminator_API::get_form($form_id);
                    if ($form && !is_wp_error($form)) {
                        $name = $form->name;
                    }
                }

                $submitted    = ($status === 'complete');
                $status_label = $submitted ? 'Submitted' : 'Pending';

                $url    = esc_url(add_query_arg('form_id', $form_id, site_url('/precourse-forms')));
                $action = '<a class="button" href="' . $url . '">' . ($submitted ? 'View' : 'Fill') . '</a>';

                echo '<tr>';
                echo '<td>' . esc_html($name) . '</td>';
                echo '<td>' . esc_html($status_label) . '</td>';
                echo '<td>' . $action . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
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

        $status   = get_user_meta($user_id, "ayotte_form_{$form_id}_status", true);
        $unlocked = get_user_meta($user_id, "ayotte_form_{$form_id}_unlocked", true);
        $locked   = ($status === 'complete' && !$unlocked);

        $html = '<div class="ayotte-readonly-form"><table class="widefat">';
        foreach ($fields as $label => $value) {
            $html .= '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        $html .= '</table></div>';

        if ($locked) {
            $ajax = admin_url('admin-ajax.php');
            $html .= '<p><button id="ayotteRequestUnlock">Request unlocking</button> <span id="ayotteUnlockMsg"></span></p>';
            $html .= "<script>document.getElementById('ayotteRequestUnlock').onclick = async () => {const f=new FormData();f.append('action','ayotte_request_unlock');f.append('form_id','" . intval($form_id) . "');const r=await fetch('" . esc_url_raw($ajax) . "',{method:'POST',credentials:'same-origin',body:f});const d=await r.json();document.getElementById('ayotteUnlockMsg').textContent=d.data.message;};</script>";
        }

        return $html;
    }

    /**
     * Display a submitted form in the admin area.
     */
    public function render_admin_submission_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        $form_id = intval($_GET['form_id'] ?? 0);
        $user_id = intval($_GET['user_id'] ?? 0);

        echo '<div class="wrap"><h1>Form Submission</h1>';

        if (!$form_id || !$user_id) {
            echo '<p>Invalid form or user.</p></div>';
            return;
        }

        echo $this->render_readonly_submission($form_id, $user_id);
        echo '</div>';
    }

    /**
     * Inject a hidden Email field into Forminator forms rendered by this plugin.
     *
     * Hooked to the `forminator_render_form` filter.
     *
     * @param string $html    Existing form HTML.
     * @param int    $form_id ID of the form being rendered.
     * @return string Modified form HTML.
     */
    public function add_email_field($html, $form_id) {
        if (!$this->inject_email || !is_user_logged_in()) {
            return $html;
        }

        if ($this->email_added) {
            return $html;
        }

        if (strpos($html, 'name="Email"') !== false) {
            $this->email_added = true;
            return $html;
        }

        $email  = esc_attr(wp_get_current_user()->user_email);
        $hidden = '<input type="hidden" name="Email" value="' . $email . '" />';

        if (false !== strpos($html, '</form>')) {
            $html = str_replace('</form>', $hidden . '</form>', $html);
        } else {
            $html .= $hidden;
        }

        $this->email_added = true;

        return $html;
    }

    /**
     * Handle unlock requests from students.
     */
    public function request_unlock() {
        if (!is_user_logged_in()) wp_send_json_error(['message' => 'Not logged in']);
        $form_id = intval($_POST['form_id'] ?? 0);
        if (!$form_id) wp_send_json_error(['message' => 'Invalid form']);

        $user_id = get_current_user_id();
        update_user_meta($user_id, "ayotte_form_{$form_id}_unlock_request", current_time('mysql'));

        $admin = get_option('admin_email');
        $user  = wp_get_current_user();
        wp_mail($admin, 'Unlock Request', "User {$user->user_email} requested unlocking for form {$form_id}.");

        wp_send_json_success(['message' => 'Unlock request sent']);
    }

    /**
     * Handle form save
     */
    public function save_form() {
        check_admin_referer('ayotte_precourse_form', 'ayotte_precourse_nonce');
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
