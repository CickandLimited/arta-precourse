<?php
class Ayotte_Progress_Tracker {
    public function init() {
        add_action('wp_ajax_ayotte_save_progress', [$this, 'save_progress']);
        add_action('wp_ajax_nopriv_ayotte_save_progress', [$this, 'save_progress']);
        // Track Forminator form submissions
        // form_id and entry_id are provided after the entry is stored
        add_action('forminator_custom_form_after_save_entry', [$this, 'handle_form_submit'], 10, 2);
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

    /**
     * Determine the status of a user's form entry.
     *
     * @param int $form_id Forminator form ID
     * @param int $user_id WordPress user ID
     * @return string completed|draft|outstanding
     */
    public function get_form_status($form_id, $user_id) {
        if (!class_exists('Forminator_API')) {
            return 'outstanding';
        }

        $user       = get_user_by('ID', $user_id);
        $identifier = $user ? $user->user_login : $user_id;

        $args = [
            'search' => [
                'field' => 'hidden-1',
                'value' => $identifier,
            ],
            'drafts' => true,
        ];

        $entries = Forminator_API::get_entries($form_id, 0, 1, $args);

        if (!$entries || empty($entries->entries)) {
            return 'outstanding';
        }

        $entry = $entries->entries[0];

        if (!empty($entry->draft) || (isset($entry->status) && $entry->status === 'draft')) {
            return 'draft';
        }

        return 'completed';
    }

    /**
     * Mark forms complete when submitted and recalc overall progress.
     *
     * @param int $entry_id Forminator entry ID
     * @param int $form_id  Submitted form ID
     */
    public function handle_form_submit($entry_id, $form_id) {
        $user_id = 0;
        $assigned = [];

        if (class_exists('Forminator_API') && method_exists('Forminator_API', 'get_entry')) {
            $entry = Forminator_API::get_entry($form_id, $entry_id);
            if ($entry && isset($entry->user_id)) {
                $user_id = intval($entry->user_id);
            }
        }

        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            return;
        }

        $assigned = (array) get_user_meta($user_id, 'ayotte_assigned_forms', true);

        if (!in_array($form_id, $assigned, true)) {
            return;
        }

        if ($entry_id) {
            update_user_meta($user_id, "ayotte_form_{$form_id}_entry", $entry_id);
        }

        update_user_meta($user_id, "ayotte_form_{$form_id}_status", 'completed');
        $this->recalculate_progress($user_id);
    }

    /**
     * Recalculate completion percentage based on assigned forms.
     *
     * @param int $user_id
     */
    public function recalculate_progress($user_id) {
        $assigned = (array) get_user_meta($user_id, 'ayotte_assigned_forms', true);
        $total    = count($assigned);

        if ($total === 0) {
            update_user_meta($user_id, 'ayotte_progress', 0);
            return;
        }

        $points = 0;
        foreach ($assigned as $id) {
            $status = $this->get_form_status($id, $user_id);
            update_user_meta($user_id, "ayotte_form_{$id}_status", $status);
            if ($status === 'completed') {
                $points += 100;
            } elseif ($status === 'draft') {
                $points += 50;
            }
        }

        $percent = intval($points / $total);
        if ($percent > 100) {
            $percent = 100;
        }

        update_user_meta($user_id, 'ayotte_progress', $percent);
        update_user_meta($user_id, 'ayotte_progress_updated', current_time('mysql'));
    }
}
