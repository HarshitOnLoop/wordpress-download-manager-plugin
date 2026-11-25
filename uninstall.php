<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

wp_clear_scheduled_hook('cdm_retention_cleanup');

$settings = get_option('cdm_settings', array());
if (is_array($settings) && !empty($settings['delete_data_on_uninstall'])) {
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cdm_requests");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cdm_resources");
    delete_option('cdm_settings');
    delete_option('cdm_db_version');
}
