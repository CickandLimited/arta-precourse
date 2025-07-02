<?php
/*
Plugin Name: Ayotte Precourse Portal
Description: Invite-based pre-course workflow with admin tracking and debug console.
Version: 1.0
Author: Kris Rabai
*/

defined('ABSPATH') || exit;

define('AYOTTE_PRECOURSE_VERSION', '1.0');

// Composer autoloader for bundled libraries
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    error_log('Autoload file not found.');
}

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
require_once plugin_dir_path(__FILE__) . 'includes/class-pdf-generator.php';

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
    $cdn = 'https://cdn.jsdelivr.net/npm/interactjs/dist/interact.min.js';
    $local = plugin_dir_url(__FILE__) . 'assets/js/interact.min.js';
    $use_local = false;
    $resp = wp_remote_head($cdn);
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
        $use_local = true;
    }
    wp_enqueue_script(
        'interactjs',
        $use_local ? $local : $cdn,
        [],
        AYOTTE_PRECOURSE_VERSION,
        true
    );
    wp_enqueue_script(
        'ayotte-pdf-preview',
        plugin_dir_url(__FILE__) . 'assets/js/pdf-preview.js',
        ['interactjs'],
        AYOTTE_PRECOURSE_VERSION,
        true
    );
    wp_add_inline_script(
        'ayotte-pdf-preview',
        'var ajaxurl = ' . json_encode(admin_url('admin-ajax.php')) . ';',
        'before'
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
    wp_enqueue_script(
        'ayotte-frontend',
        plugin_dir_url(__FILE__) . 'assets/js/script.js',
        [],
        AYOTTE_PRECOURSE_VERSION,
        true
    );
    wp_add_inline_script(
        'ayotte-frontend',
        'var ajaxurl = ' . json_encode(admin_url('admin-ajax.php')) . ';',
        'before'
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


// Plugin Initialization with verbose logging restored
/**
 * Initialize the plugin.
 */
function ayotte_precourse_init() {
    ayotte_log_message('INFO', 'Ayotte Precourse Portal plugin initializing...');

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
    // Remove any legacy precourse form page
    $legacy = get_page_by_path('precourse-form');
    if ($legacy) {
        wp_delete_post($legacy->ID, true);
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

// Daily invite cleanup
if (!wp_next_scheduled('ayotte_invite_cleanup')) {
    wp_schedule_event(time(), 'daily', 'ayotte_invite_cleanup');
}
add_action('ayotte_invite_cleanup', 'ayotte_cleanup_expired_invites');

function ayotte_cleanup_expired_invites() {
    global $wpdb;
    $like    = $wpdb->esc_like('ayotte_invite_') . '%';
    $options = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like));

    foreach ($options as $option) {
        $data = get_option($option);
        if (!$data || (isset($data['expires']) && $data['expires'] < time())) {
            delete_option($option);
        }
    }
}
?>
