<?php
class Ayotte_Submission_Redirect {
    public function init() {
        // Hook after a Forminator form entry is saved to handle non-AJAX submissions
        add_action('forminator_custom_form_after_save_entry', [$this, 'handle_redirect'], 99, 2);
        // Inject JS to handle AJAX submissions
        add_action('wp_footer', [$this, 'add_redirect_script']);
    }

    /**
     * Redirect the user after a non-AJAX submission.
     *
     * @param int $entry_id Forminator entry ID.
     * @param int $form_id  Forminator form ID.
     */
    public function handle_redirect($entry_id, $form_id) {
        if (wp_doing_ajax()) {
            return;
        }
        if (!is_user_logged_in()) {
            return;
        }
        wp_safe_redirect(site_url('/precourse-forms/'));
        exit;
    }

    /**
     * Output JS that redirects after successful AJAX form submission.
     */
    public function add_redirect_script() {
        if (!is_page('precourse-forms') || empty($_GET['form_id'])) {
            return;
        }
        if (!is_user_logged_in()) {
            return;
        }
        $url = esc_url(site_url('/precourse-forms/'));
        ?>
        <script>
        document.addEventListener('forminator:form:submit:success', function(){
            window.location.href = <?php echo wp_json_encode($url); ?>;
        });
        </script>
        <?php
    }
}
