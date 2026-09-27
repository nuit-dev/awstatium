<?php
/**
 * Removes everything Awstatium stored when the plugin is deleted. AWStats files are never touched.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('awstatium_settings');
delete_option('awstatium_data');
delete_option('awstatium_totals');
delete_post_meta_by_key('_awstatium_views');
delete_post_meta_by_key('_awstatium_old_paths');
wp_clear_scheduled_hook('awstatium_refresh');
