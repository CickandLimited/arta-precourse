<?php
class Ayotte_Form_Manager {

    public function init() {
        add_shortcode('ayotte_precourse_form', [$this, 'render_form']);
        add_shortcode('ayotte_form_dashboard', [$this, 'render_dashboard']);
        add_shortcode('ayotte_form', [$this, 'render_dynamic_form']);
        add_action('wp_ajax_ayotte_save_precourse_form', [$this, 'save_form']);
        add_action('wp_ajax_nopriv_ayotte_save_precourse_form', [$this, 'save_form']);
        add_action('wp_ajax_ayotte_save_form', [$this, 'save_form']);
        add_action('wp_ajax_nopriv_ayotte_save_form', [$this, 'save_form']);
    }

    /**
     * Render simple precourse form with personal info and ID upload.
     */
    public function render_form() {
        if (!is_user_logged_in()) return '<p>Please log in first.</p>';
        $user_id = get_current_user_id();
        $phone   = esc_attr(get_user_meta($user_id, 'ayotte_phone', true));
        $reason  = esc_textarea(get_user_meta($user_id, 'ayotte_reason', true));
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
        ob_start();
        echo '<h2>Your Assigned Forms</h2><ul>';
        echo '<li><a href="' . esc_url( site_url('/precourse-form') ) . '">Precourse Form</a></li>';
        echo '</ul>';
        return ob_get_clean();
    }

    /**
     * Render a custom form defined via the ayotte_form post type.
     */
    public function render_dynamic_form($atts = []) {
        if (!is_user_logged_in()) return '<p>Please log in first.</p>';
        $form_id = intval($atts['id'] ?? 0);
        if (!$form_id) return '<p>Invalid form.</p>';
        $fields_json = get_post_meta($form_id, 'ayotte_form_fields', true) ?: '[]';
        $fields = json_decode($fields_json, true);
        if (!is_array($fields)) $fields = [];

        ob_start();
        ?>
        <form class="ayotteForm" data-form="<?php echo esc_attr($form_id); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field('ayotte_form_' . $form_id, 'ayotte_form_nonce'); ?>
            <?php foreach ($fields as $i => $f): $label = esc_html($f['label'] ?? ('Field ' . ($i+1))); $type = $f['type'] ?? 'text'; $name = 'field_' . $i; $value = '';
                if ($type !== 'file') $value = esc_attr(get_user_meta(get_current_user_id(), 'ayotte_form_' . $form_id . '_' . $name, true));
            ?>
                <p><label><?php echo $label; ?><br>
                    <?php if ($type === 'textarea'): ?>
                        <textarea name="<?php echo $name; ?>"><?php echo esc_textarea($value); ?></textarea>
                    <?php elseif ($type === 'file'): ?>
                        <input type="file" name="<?php echo $name; ?>">
                    <?php else: ?>
                        <input type="text" name="<?php echo $name; ?>" value="<?php echo $value; ?>">
                    <?php endif; ?>
                </label></p>
            <?php endforeach; ?>
            <input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>" />
            <button type="submit">Submit</button>
            <span class="ayotteFormMsg"></span>
        </form>
        <script>
        (function(){
            const f = document.currentScript.previousElementSibling;
            f.onsubmit = async (e) => {
                e.preventDefault();
                const fd = new FormData(f);
                const res = await fetch(ajaxurl + '?action=ayotte_save_form', {method:'POST', body: fd});
                const data = await res.json();
                f.querySelector('.ayotteFormMsg').textContent = data.data?.message || '';
            };
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle form save
     */
    public function save_form() {
        $form_id = intval($_POST['form_id'] ?? 0);
        if ($form_id) {
            check_admin_referer('ayotte_form_' . $form_id);
            if (!is_user_logged_in()) wp_send_json_error(['message' => 'Not logged in']);
            $fields_json = get_post_meta($form_id, 'ayotte_form_fields', true) ?: '[]';
            $fields = json_decode($fields_json, true);
            if (!is_array($fields)) $fields = [];
            $user_id = get_current_user_id();
            foreach ($fields as $i => $f) {
                $name = 'field_' . $i;
                $meta_key = 'ayotte_form_' . $form_id . '_' . $name;
                $type = $f['type'] ?? 'text';
                if ($type === 'file') {
                    $file = $_FILES[$name] ?? null;
                    if ($file && !empty($file['tmp_name'])) {
                        $uploaded = wp_handle_upload($file, ['test_form' => false]);
                        if (!isset($uploaded['error'])) {
                            update_user_meta($user_id, $meta_key, $uploaded['url']);
                        }
                    }
                } else {
                    $val = sanitize_text_field($_POST[$name] ?? '');
                    update_user_meta($user_id, $meta_key, $val);
                }
            }
            wp_send_json_success(['message' => 'Saved']);
        }

        // Fallback to original precourse form
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

