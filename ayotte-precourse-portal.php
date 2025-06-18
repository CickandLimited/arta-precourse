<?php
/*
Plugin Name: Ayotte Precourse Portal
Description: Invite-based pre-course workflow with admin tracking and debug console.
Version: 1.0
Author: Kris Rabai
*/

defined('ABSPATH') || exit;

define('AYOTTE_PRECOURSE_VERSION', '1.0');
define('AYOTTE_PRECOURSE_CAPABILITY', 'manage_precourse');

// Includes
require_once plugin_dir_path(__FILE__) . 'includes/class-ayotte-precourse.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-invitation-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-email-sender.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-admin-panel.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-form-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-progress-tracker.php';

// Logging
if (!function_exists('ayotte_log_message')) {
    function ayotte_log_message($level, $message) {
        $logs = get_option('ayotte_debug_logs', []);
        $entry = [
            'time' => current_time('mysql'),
            'level' => strtoupper($level),
            'message' => $message
        ];
        $logs[] = $entry;
        if (count($logs) > 1000) array_shift($logs);
        update_option('ayotte_debug_logs', $logs);
    }
}

// Plugin Initialization with verbose logging restored
function ayotte_precourse_init() {
    ayotte_log_message('INFO', 'Ayotte Precourse Portal plugin initializing...');
    $plugin = new Ayotte_Precourse();
    $plugin->run();
    (new Ayotte_Admin_Panel())->init();
    (new Ayotte_Form_Manager())->init();
    (new Ayotte_Progress_Tracker())->init();
}
add_action('plugins_loaded', 'ayotte_precourse_init');

// Rewrite flush
register_activation_hook(__FILE__, function() {
    $precourse = new Ayotte_Precourse();
    $precourse->add_rewrite_rules();
    $precourse->register_form_post_type();
    $admin = get_role('administrator');
    if ($admin && !$admin->has_cap(AYOTTE_PRECOURSE_CAPABILITY)) {
        $admin->add_cap(AYOTTE_PRECOURSE_CAPABILITY);
    }
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
        $progress = get_user_meta($user->ID, 'ayotte_progress', true);
        if ($progress !== 'complete') {
            $emailer = new Ayotte_Email_Sender();
            $emailer->send_email($user->user_email, 'Reminder', 'Please complete your precourse forms.');
        }
    }
}

