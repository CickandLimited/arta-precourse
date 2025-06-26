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
     * Generic wrapper for Forminator API calls with optional debug logging.
     *
     * @param string $method Method name on Forminator_API
     * @param array  $args   Arguments for the API call
     * @return mixed|null API response or null on failure
     */
    public static function forminator_call($method, array $args = []) {
        if (!class_exists('Forminator_API') || !method_exists('Forminator_API', $method)) {
            return null;
        }

        $response = call_user_func_array(['Forminator_API', $method], $args);

        self::log_api_debug($method, $args, $response);

        return $response;
    }

    public static function forminator_get_entry($form_id, $entry_id) {
        return self::forminator_call('get_entry', [$form_id, $entry_id]);
    }

    public static function forminator_get_entries(...$args) {
        return self::forminator_call('get_entries', $args);
    }

    public static function forminator_get_form($form_id) {
        return self::forminator_call('get_form', [$form_id]);
    }

    public static function forminator_get_forms() {
        return self::forminator_call('get_forms');
    }

    private static function log_api_debug($method, $args, $response) {
        if (!get_option('ayotte_debug_enabled', false)) {
            return;
        }


        $params = json_encode(
            $args,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        $result = json_encode(
            $response,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        $message = "$method\nparams=\n{$params}\nresult=\n{$result}";
        ayotte_log_message('DEBUG', $message);

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

        $email    = get_userdata($user_id)->user_email;

        // Fetch entries using the wrapper to ensure we get actual entry objects.
        // Explicitly set pagination defaults to avoid passing search criteria as
        // the second parameter.
        $entries = self::forminator_get_entries($form_id, 0, 1);

        $entries_list = is_array($entries) ? $entries : ($entries->entries ?? []);

        if (!$entries_list) {
            $type = is_object($entries) ? 'object' : gettype($entries);
            ayotte_log_message('ERROR', "No entries found for form $form_id (response type: $type)");
            return 'outstanding';
        }

        foreach ($entries_list as $e) {
            if (!isset($e->meta_data) || !is_array($e->meta_data)) {
                continue;
            }
            foreach ($e->meta_data as $meta) {
                $name  = $meta['name'] ?? ($meta->name ?? '');
                $value = $meta['value'] ?? ($meta->value ?? '');
                if ($name === 'hidden-1' && $value === $email) {
                    $draft_id = $e->draftid ?? ($e->draft_id ?? null);
                    if ($draft_id === null || $draft_id === 'null' || $draft_id === '') {
                        return 'completed';
                    }
                    return 'draft';
                }
            }
        }

        return 'outstanding';
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

        $entry = self::forminator_get_entry($form_id, $entry_id);
        if ($entry && isset($entry->user_id)) {
            $user_id = intval($entry->user_id);
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
            if (get_option('ayotte_debug_enabled', false)) {
                ayotte_log_message('DEBUG', "Recalculated progress user {$user_id}: forms=[] percent=0");
            }
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

        if (get_option('ayotte_debug_enabled', false)) {
            $forms = implode(',', $assigned);
            ayotte_log_message('DEBUG', "Recalculated progress user {$user_id}: forms=[{$forms}] percent={$percent}");
        }
    }
}
