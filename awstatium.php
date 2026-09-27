<?php
/**
 * Plugin Name:       AWStatium – Page Views from AWStats
 * Plugin URI:        https://github.com/nuit-dev/awstatium
 * Description:       Page views, downloads and traffic statistics from your server's AWStats data. No tracking scripts, no cookies.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            NUIT d.o.o.
 * Author URI:        https://nuit.hr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       awstatium
 * Domain Path:       /languages
 * Update URI:        https://github.com/nuit-dev/awstatium
 */

defined('ABSPATH') || exit;

define('AWSTATIUM_VERSION', '1.0.1');
define('AWSTATIUM_FILE', __FILE__);
define('AWSTATIUM_DIR', plugin_dir_path(__FILE__));

add_action('init', function () {
    load_plugin_textdomain('awstatium', false, dirname(plugin_basename(AWSTATIUM_FILE)) . '/languages');
});

require AWSTATIUM_DIR . 'includes/data.php';
register_activation_hook(__FILE__, 'awstatium_activate');
register_deactivation_hook(__FILE__, function () {
    wp_unschedule_hook('awstatium_refresh');
    wp_unschedule_hook('awstatium_refresh_now');
    wp_unschedule_hook('awstatium_sync_batch');
});

// Multisite is not supported yet: on a network every site admin could pick any AWStats data the server can read
if (is_multisite()) {
    add_action('admin_notices', function () {
        if (current_user_can('activate_plugins')) {
            echo '<div class="notice notice-error"><p>' . esc_html__('AWStatium does not support WordPress Multisite yet.', 'awstatium') . '</p></div>';
        }
    });
    return;
}

require AWSTATIUM_DIR . 'includes/frontend.php';

if (is_admin()) {
    require AWSTATIUM_DIR . 'includes/settings.php';
    require AWSTATIUM_DIR . 'includes/admin.php';
}
