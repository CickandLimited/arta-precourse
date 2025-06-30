<?php
class Ayotte_Progress_Tracker {
    public function init() {
        add_action('wp_ajax_ayotte_save_progress', [$this, 'save_progress']);
        add_action('wp_ajax_nopriv_ayotte_save_progress', [$this, 'save_progress']);
        // Track custom form submissions
        add_action('ayotte_custom_form_submitted', [$this, 'handle_form_submit'], 10, 2);
    }

    public function save_progress() {
        if (!is_user_logged_in()) wp_send_json_error(['message'=>'not logged in']);
        $user_id = get_current_user_id();
        $progress = sanitize_text_field($_POST['progress'] ?? '');
        update_user_meta($user_id, 'ayotte_progress', $progress);
        update_user_meta($user_id, 'ayotte_progress_updated', current_time('mysql'));
        ayotte_log_message('INFO', "Saved progress for user {$user_id}: {$progress}", 'progress tracker');
        wp_send_json_success(['message'=>'progress saved']);
    }

    public function get_progress($user_id) {
        return get_user_meta($user_id, 'ayotte_progress', true);
    }


    /**
     * Fetch all custom forms.
     *
     * @return array
     */
    public static function get_custom_forms() {
        $db = Custom_DB::get_instance()->get_connection();
        if ($db instanceof WP_Error) {
            return [];
        }
        $res = $db->query('SELECT id,name FROM custom_forms');
        $forms = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $forms[] = $row;
        }
        return $forms;
    }

    /**
     * Get a form name by ID.
     *
     * @param int $id
     * @return string
     */
    public static function get_form_name($id) {
        $db = Custom_DB::get_instance()->get_connection();
        if ($db instanceof WP_Error) {
            return 'Form ' . intval($id);
        }
        $id   = intval($id);
        $res  = $db->query("SELECT name FROM custom_forms WHERE id=$id");
        $row  = ($res && $res->num_rows) ? $res->fetch_assoc() : null;
        return $row ? $row['name'] : 'Form ' . $id;
    }

    /**
     * Determine the status of a user's form entry.
     *
     * @param int $form_id Form ID
     * @param int $user_id WordPress user ID
     * @return string completed|outstanding
     */
    public function get_form_status($form_id, $user_id) {

        $db = Custom_DB::get_instance()->get_connection();
        if ($db instanceof WP_Error) {
            return 'outstanding';
        }

        $form_id = intval($form_id);
        $user_id = intval($user_id);
        $res = $db->query(
            "SELECT status, locked FROM custom_form_submissions " .
            "WHERE form_id=$form_id AND user_id=$user_id " .
            "ORDER BY submitted_at DESC LIMIT 1"
        );
        if ($res && $res->num_rows) {
            $row    = $res->fetch_assoc();
            $locked = intval($row['locked']);
            $status = $row['status'];

            if ($locked) {
                return 'locked';
            }

            return ($status === 'draft') ? 'draft' : 'completed';
        }
        return 'outstanding';
    }

    /**
     * Mark forms complete when submitted and recalc overall progress.
     *
     * @param int $form_id       Submitted form ID
     * @param int $submission_id Record ID in custom_form_submissions
     */
    public function handle_form_submit($form_id, $submission_id) {
        $user_id  = get_current_user_id();
        if (!$user_id) {
            return;
        }

        $assigned = (array) get_user_meta($user_id, 'ayotte_assigned_forms', true);
        if (!in_array($form_id, $assigned, true)) {
            return;
        }

        if ($submission_id) {
            update_user_meta($user_id, "ayotte_form_{$form_id}_entry", $submission_id);
            ayotte_log_message('INFO', "Recorded submission $submission_id for form $form_id user $user_id", 'progress tracker');
        } else {
            ayotte_log_message('ERROR', "Missing submission ID for form $form_id user $user_id", 'progress tracker');
        }

        $status = 'completed';
        $db     = Custom_DB::get_instance()->get_connection();
        if (!$db instanceof WP_Error) {
            $sid = $submission_id ? intval($submission_id) : 0;
            $query = $sid
                ? "SELECT status, locked FROM custom_form_submissions WHERE id=$sid LIMIT 1"
                : "SELECT status, locked FROM custom_form_submissions WHERE form_id=" . intval($form_id) . " AND user_id=$user_id ORDER BY submitted_at DESC LIMIT 1";
            $res = $db->query($query);
            if ($res && $res->num_rows) {
                $row = $res->fetch_assoc();
                if (intval($row['locked'])) {
                    $status = 'locked';
                } else {
                    $status = ($row['status'] === 'draft') ? 'draft' : 'completed';
                }
            }
        }

        update_user_meta($user_id, "ayotte_form_{$form_id}_status", $status);
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
            if (get_option('ayotte_debug_enabled', false)) {
                ayotte_log_message('DEBUG', "Recalculated progress user {$user_id}: forms=[] percent=0", 'progress tracker');
            }
            return;
        }

        $points = 0;
        foreach ($assigned as $id) {
            $status = $this->get_form_status($id, $user_id);
            update_user_meta($user_id, "ayotte_form_{$id}_status", $status);
            if ($status === 'completed' || $status === 'locked') {
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

        if (get_option('ayotte_debug_enabled', false)) {
            $forms = implode(',', $assigned);
            ayotte_log_message('DEBUG', "Recalculated progress user {$user_id}: forms=[{$forms}] percent={$percent}", 'progress tracker');
        }
    }
}
