<?php
class Ayotte_Progress_Tracker {
    public function init() {
        add_action('wp_ajax_ayotte_save_progress', [$this, 'save_progress']);
        add_action('wp_ajax_nopriv_ayotte_save_progress', [$this, 'save_progress']);
    }

    public function save_progress() {
        if (!is_user_logged_in()) wp_send_json_error(['message'=>'not logged in']);
        $user_id = get_current_user_id();
        $progress = sanitize_text_field($_POST['progress'] ?? '');
        update_user_meta($user_id, 'ayotte_progress', $progress);
        update_user_meta($user_id, 'ayotte_progress_updated', current_time('mysql'));
        wp_send_json_success(['message'=>'progress saved']);
    }

    public function get_progress($user_id) {
        return get_user_meta($user_id, 'ayotte_progress', true);
    }
}
