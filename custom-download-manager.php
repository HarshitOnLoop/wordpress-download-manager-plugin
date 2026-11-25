<?php
/**
 * Plugin Name: Custom Download Manager
 * Description: Customizable download request forms that deliver submissions to a webhook, with daily limits, live form builder, custom CSS, and a searchable form submissions table.
 * Version: 1.4.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: custom-download-manager
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CDM_VERSION', '1.4.1');
define('CDM_PLUGIN_FILE', __FILE__);
define('CDM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CDM_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once CDM_PLUGIN_DIR . 'includes/class-installer.php';
require_once CDM_PLUGIN_DIR . 'includes/class-style-manager.php';
require_once CDM_PLUGIN_DIR . 'includes/class-form-manager.php';
require_once CDM_PLUGIN_DIR . 'includes/class-resource-manager.php';
require_once CDM_PLUGIN_DIR . 'includes/class-webhook-manager.php';
require_once CDM_PLUGIN_DIR . 'includes/class-submission-handler.php';
require_once CDM_PLUGIN_DIR . 'includes/class-shortcode-manager.php';
require_once CDM_PLUGIN_DIR . 'includes/class-admin-manager.php';

register_activation_hook(__FILE__, array('CDM_Installer', 'activate'));
register_deactivation_hook(__FILE__, array('CDM_Installer', 'deactivate'));

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=cdm-form-settings')) . '">' . esc_html__('Customize Form', 'custom-download-manager') . '</a>');
    return $links;
});

function cdm_bootstrap() {
    CDM_Installer::maybe_upgrade();
    CDM_Admin_Manager::init();
    CDM_Submission_Handler::init();
    CDM_Shortcode_Manager::init();
}
add_action('plugins_loaded', 'cdm_bootstrap');
