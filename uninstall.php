<?php
/**
 * Removes everything Awstatium stored when the plugin is deleted. AWStats files are never touched.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// Named options first (clears their cache), then the per-source snapshots awstatium_data_* and awstatium_totals_*
foreach (['awstatium_settings', 'awstatium_active', 'awstatium_sync', 'awstatium_lock'] as $name) delete_option($name);
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like('awstatium_data_') . '%', $wpdb->esc_like('awstatium_totals_') . '%'));

delete_post_meta_by_key('_awstatium_views');
delete_post_meta_by_key('_awstatium_old_paths');
wp_unschedule_hook('awstatium_refresh');
wp_unschedule_hook('awstatium_refresh_now');
wp_unschedule_hook('awstatium_sync_batch');
