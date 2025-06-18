<?php
class Ayotte_Progress_Tracker {
    public function init() {
        add_action('wp_ajax_ayotte_save_progress', [$this, 'save_progress']);
        add_action('wp_ajax_nopriv_ayotte_save_progress', [$this, 'save_progress']);
    }

    /**
     * Save progress for a specific form. Expects 'form_id' and 'progress'
     * parameters from the request. Progress is a simple string (partial/complete)
     * which is used when calculating the overall percentage.
     */
    public function save_progress() {
        if (!is_user_logged_in()) wp_send_json_error(['message' => 'not logged in']);

        $user_id = get_current_user_id();
        $form_id = intval($_POST['form_id'] ?? 0);
        $status  = sanitize_text_field($_POST['progress'] ?? 'partial');

        update_user_meta($user_id, 'ayotte_form_' . $form_id . '_progress', $status);
        update_user_meta($user_id, 'ayotte_progress_updated', current_time('mysql'));

        self::update_overall_progress($user_id);

        wp_send_json_success(['message' => 'progress saved']);
    }

    public function get_progress($user_id) {
        return get_user_meta($user_id, 'ayotte_progress', true);
    }

    /**
     * Calculate overall progress based on assigned forms and the main
     * precourse form (form_id 0).
     */
    public static function update_overall_progress($user_id) {
        $forms = get_user_meta($user_id, 'ayotte_assigned_forms', true);
        if (!is_array($forms)) $forms = [];
        // include the precourse form represented by ID 0
        $forms[] = 0;

        $total     = count($forms);
        $completed = 0;
        foreach ($forms as $fid) {
            $status = get_user_meta($user_id, 'ayotte_form_' . $fid . '_progress', true);
            if ($status === 'complete') $completed++;
        }

        $percent = $total ? round(($completed / $total) * 100) : 0;
        $overall = $percent >= 100 ? 'complete' : $percent . '%';
        update_user_meta($user_id, 'ayotte_progress', $overall);
        return $percent;
    }
}
