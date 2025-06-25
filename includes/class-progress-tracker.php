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
        $stored = get_user_meta($user_id, "ayotte_form_{$form_id}_status", true);
        if (in_array($stored, ['completed', 'draft', 'outstanding'], true)) {
            return $stored;
        }

        if (!class_exists('Forminator_API')) {
            return 'outstanding';
        }

        $entry_id = get_user_meta($user_id, "ayotte_form_{$form_id}_entry", true);

        if ($entry_id && method_exists('Forminator_API', 'get_entry')) {
            $entry = Forminator_API::get_entry($form_id, $entry_id);

            if (!$entry || is_wp_error($entry)) {
                ayotte_log_message('ERROR', "Entry lookup failed for form $form_id entry $entry_id");
            } else {
                if (isset($entry->user_id) && intval($entry->user_id) !== intval($user_id)) {
                    ayotte_log_message('ERROR', "Entry $entry_id user mismatch: expected $user_id got {$entry->user_id}");
                }

                if (!empty($entry->draft) || (isset($entry->status) && $entry->status === 'draft')) {
                    return 'draft';
                }

                return 'completed';
            }
        }

        $args = [
            'search' => [
                'field' => 'user_id',
                'value' => $user_id,
            ],
            'drafts' => true,
        ];

        $entries = Forminator_API::get_entries($form_id, 0, 1, $args);

        if (!$entries || empty($entries->entries)) {
            ayotte_log_message('ERROR', "No entries found for form $form_id and user $user_id");
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
            ayotte_log_message('INFO', "Recorded entry $entry_id for form $form_id user $user_id");
        } else {
            ayotte_log_message('ERROR', "Missing entry ID for form $form_id submission by user $user_id");
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
