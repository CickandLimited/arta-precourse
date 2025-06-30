<?php
/*
Plugin Name: Ayotte Precourse Portal
Description: Invite-based pre-course workflow with admin tracking and debug console.
Version: 1.0
Author: Kris Rabai
*/

defined('ABSPATH') || exit;

define('AYOTTE_PRECOURSE_VERSION', '1.0');

// Includes
require_once plugin_dir_path(__FILE__) . 'includes/class-ayotte-precourse.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-invitation-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-email-sender.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-admin-panel.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-form-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-progress-tracker.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-custom-db.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-form-db-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-custom-form-manager.php';

// Enqueue styles for admin pages and frontend dashboard
function ayotte_precourse_enqueue_admin($hook) {
    if (strpos($hook, 'ayotte-precourse') === false) {
        return;
    }
    wp_enqueue_style(
        'ayotte-admin',
        plugin_dir_url(__FILE__) . 'assets/css/admin.css',
        [],
        AYOTTE_PRECOURSE_VERSION
    );
}
add_action('admin_enqueue_scripts', 'ayotte_precourse_enqueue_admin');

function ayotte_precourse_enqueue_frontend() {
    wp_enqueue_style(
        'ayotte-frontend',
        plugin_dir_url(__FILE__) . 'assets/css/frontend.css',
        [],
        AYOTTE_PRECOURSE_VERSION
    );
}
add_action('wp_enqueue_scripts', 'ayotte_precourse_enqueue_frontend');

// Logging
if (!function_exists('ayotte_log_message')) {
    function ayotte_log_message($level, $message, $module = '') {
        $enabled = get_option('ayotte_debug_enabled', false);
        if (!$enabled) {
            return;
        }

        $logs = get_option('ayotte_debug_logs', []);
        $entry = [
            'time' => current_time('mysql'),
            'level' => strtoupper($level),
            'message' => $message,
            'module' => $module
        ];
        $logs[] = $entry;
        if (count($logs) > 1000) array_shift($logs);
        update_option('ayotte_debug_logs', $logs);
    }
}

/**
 * Display an admin notice when Forminator is missing.
 */
function ayotte_forminator_missing_notice() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $link = admin_url('plugin-install.php?tab=plugin-information&plugin=forminator');
    echo '<div class="notice notice-error"><p>';
    echo 'Ayotte Precourse Portal requires the <a href="' . esc_url($link) . '">Forminator</a> plugin.';
    echo '</p></div>';
}

/**
 * Add a submenu linking to the Forminator builder.
 */
function ayotte_add_forminator_submenu() {
    add_submenu_page(
        'ayotte-precourse',
        'Forminator Forms',
        'Forminator Forms',
        'manage_options',
        'ayotte-forminator',
        function () {
            wp_redirect(admin_url('admin.php?page=forminator-cform'));
            exit;
        }
    );
}

// Plugin Initialization with verbose logging restored
/**
 * Initialize plugin and ensure Forminator is available.
 */
function ayotte_precourse_init() {
    ayotte_log_message('INFO', 'Ayotte Precourse Portal plugin initializing...');

    if (!class_exists('Forminator')) {
        ayotte_log_message('ERROR', 'Forminator plugin not detected');
        add_action('admin_notices', 'ayotte_forminator_missing_notice');
        return;
    }

    // Register the Forminator submenu after the main menu is created.
    add_action('admin_menu', 'ayotte_add_forminator_submenu', 20);

    $plugin = new Ayotte_Precourse();
    $plugin->run();
    (new Ayotte_Admin_Panel())->init();
    (new Ayotte_Form_Manager())->init();
    (new Ayotte_Progress_Tracker())->init();
    (new Ayotte_Form_DB_Settings())->init();
    (new Custom_Form_Manager())->init();
}
add_action('plugins_loaded', 'ayotte_precourse_init');

// Rewrite flush
register_activation_hook(__FILE__, function() {
    (new Ayotte_Precourse())->add_rewrite_rules();
    add_option('ayotte_debug_enabled', false);
    // Create student dashboard page if it doesn't exist
    if (!get_page_by_path('precourse-forms')) {
        wp_insert_post([
            'post_title'   => 'Precourse Forms',
            'post_name'    => 'precourse-forms',
            'post_content' => '[ayotte_form_dashboard]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ]);
    }
    // Create precourse form page
    if (!get_page_by_path('precourse-form')) {
        wp_insert_post([
            'post_title'   => 'Precourse Form',
            'post_name'    => 'precourse-form',
            'post_content' => '[ayotte_precourse_form]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ]);
    }
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');

// Daily progress reminder
if (!wp_next_scheduled('ayotte_progress_reminder')) {
    wp_schedule_event(time(), 'daily', 'ayotte_progress_reminder');
}
add_action('ayotte_progress_reminder', 'ayotte_send_progress_reminders');

function ayotte_send_progress_reminders() {
    $users = get_users(['meta_key' => 'ayotte_precourse_token']);
    foreach ($users as $user) {
        $progress = intval(get_user_meta($user->ID, 'ayotte_progress', true));
        if ($progress < 100) {
            $emailer = new Ayotte_Email_Sender();
            $emailer->send_email($user->user_email, 'Reminder', 'Please complete your precourse forms.');
        }
    }
}
?>
