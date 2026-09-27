<?php
/**
 * Plugin Name:       Awstatium
 * Plugin URI:        https://github.com/nuit-dev/awstatium
 * Description:       Page views, downloads and traffic statistics from your server's AWStats data. No tracking scripts, no cookies.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            NUIT d.o.o.
 * Author URI:        https://nuit.hr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       awstatium
 * Domain Path:       /languages
 */

defined('ABSPATH') || exit;

define('AWSTATIUM_VERSION', '1.0.0');
define('AWSTATIUM_FILE', __FILE__);
define('AWSTATIUM_DIR', plugin_dir_path(__FILE__));

require AWSTATIUM_DIR . 'includes/data.php';
require AWSTATIUM_DIR . 'includes/frontend.php';

if (is_admin()) {
    require AWSTATIUM_DIR . 'includes/settings.php';
    require AWSTATIUM_DIR . 'includes/admin.php';
}

add_action('init', function () {
    load_plugin_textdomain('awstatium', false, dirname(plugin_basename(AWSTATIUM_FILE)) . '/languages');
});

register_activation_hook(__FILE__, 'awstatium_activate');
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('awstatium_refresh');
});
