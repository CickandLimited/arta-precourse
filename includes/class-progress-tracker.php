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

        update_user_meta($user_id, "ayotte_form_{$form_id}_status", 'complete');
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
            update_user_meta($user_id, 'ayotte_progress', '0%');
            return;
        }

        $complete = 0;
        foreach ($assigned as $id) {
            if (get_user_meta($user_id, "ayotte_form_{$id}_status", true) === 'complete') {
                $complete++;
            }
        }

        $percent = intval(($complete / $total) * 100);
        $progress = $percent >= 100 ? 'complete' : $percent . '%';

        update_user_meta($user_id, 'ayotte_progress', $progress);
        update_user_meta($user_id, 'ayotte_progress_updated', current_time('mysql'));
    }

    /**
     * Synchronize submitted entries for a user and update progress.
     *
     * @param int $user_id
     */
    public function sync_user_forms($user_id) {
        if (!class_exists('Forminator_API')) {
            return;
        }

        $assigned = (array) get_user_meta($user_id, 'ayotte_assigned_forms', true);
        if (empty($assigned)) {
            return;
        }

        $email = get_userdata($user_id)->user_email ?? '';

        foreach ($assigned as $form_id) {
            $form_id = intval($form_id);
            if (!$form_id) {
                continue;
            }

            $args = [
                'paged'    => 1,
                'per_page' => 1,
                'search'   => [
                    'fields' => [
                        [
                            'name'  => 'Email',
                            'value' => $email,
                        ],
                    ],
                ],
            ];

            $entries = Forminator_API::get_entries($form_id, $args);
            if ($entries && !empty($entries->entries)) {
                $entry = $entries->entries[0];
                $entry_id = $entry->entry_id ?? ($entry->id ?? 0);
                if ($entry_id) {
                    update_user_meta($user_id, "ayotte_form_{$form_id}_entry", $entry_id);
                }
                update_user_meta($user_id, "ayotte_form_{$form_id}_status", 'complete');
            }
        }

        $this->recalculate_progress($user_id);
    }
}
