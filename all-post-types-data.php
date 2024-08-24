
<?php
/**
 * Plugin Name: All Post Types Data
 * Description: Retrieves and displays data for all post types in tabs, including unregistered ones, with pagination, multisite support, and expandable content.
 * Version: 1.5
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('APT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('APT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once APT_PLUGIN_DIR . 'includes/class-apt-admin.php';
require_once APT_PLUGIN_DIR . 'includes/class-apt-ajax-handler.php';

// Initialize the plugin
function apt_init()
{
    $apt_admin = new APT_Admin();
    $apt_admin->init();

    $apt_ajax_handler = new APT_Ajax_Handler();
    $apt_ajax_handler->init();
}
add_action('plugins_loaded', 'apt_init');
