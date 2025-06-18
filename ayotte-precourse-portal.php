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
}
add_action('plugins_loaded', 'ayotte_precourse_init');

// Rewrite flush
register_activation_hook(__FILE__, function() {
    (new Ayotte_Precourse())->add_rewrite_rules();
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
?>
