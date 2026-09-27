<?php
/**
 * Settings → Awstatium: data source, automatic display and cache purging.
 */

defined('ABSPATH') || exit;

add_action('admin_init', function () {
    register_setting('awstatium', AWSTATIUM_OPT_SETTINGS, [
        'type'              => 'array',
        'sanitize_callback' => 'awstatium_sanitize_settings',
        'default'           => [],
    ]);
});

add_action('admin_menu', function () {
    add_options_page(__('Awstatium settings', 'awstatium'), 'Awstatium', 'manage_options', 'awstatium-settings', 'awstatium_settings_page');
});

function awstatium_sanitize_settings($in) {
    $in     = is_array($in) ? $in : [];
    $dir    = untrailingslashit(trim(sanitize_text_field($in['dir'] ?? '')));
    $config = preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($in['config'] ?? ''));
    $types  = array_values(array_intersect(array_map('strval', (array) ($in['auto_display'] ?? [])), array_keys(awstatium_post_types())));

    if ($dir !== '' && !@is_dir($dir)) {
        /* translators: %s: directory path */
        add_settings_error(AWSTATIUM_OPT_SETTINGS, 'dir', sprintf(__('The directory %s does not exist or PHP is not allowed to read it.', 'awstatium'), $dir));
    }

    return [
        'dir'          => $dir,
        'config'       => $config,
        'auto_display' => $types,
        'position'     => ($in['position'] ?? '') === 'before' ? 'before' : 'after',
        'purge'        => empty($in['purge']) ? 0 : 1,
    ];
}

/** A new data source means the stored data belongs to something else: start over. */
function awstatium_settings_changed($old, $new) {
    $old = is_array($old) ? $old : [];
    $new = is_array($new) ? $new : [];
    if (($old['dir'] ?? '') === ($new['dir'] ?? '') && ($old['config'] ?? '') === ($new['config'] ?? '')) return;
    awstatium_reset();
    awstatium_rebuild();
}
add_action('update_option_' . AWSTATIUM_OPT_SETTINGS, 'awstatium_settings_changed', 10, 2);
add_action('add_option_' . AWSTATIUM_OPT_SETTINGS, function ($name, $value) {
    awstatium_settings_changed([], $value);
}, 10, 2);

add_filter('plugin_action_links_' . plugin_basename(AWSTATIUM_FILE), function ($links) {
    array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=awstatium-settings')) . '">' . esc_html__('Settings', 'awstatium') . '</a>');
    return $links;
});

// "Use" buttons fill the fields with a detected directory or config
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'settings_page_awstatium-settings') return;
    wp_register_script('awstatium-settings', false, [], AWSTATIUM_VERSION, true);
    wp_enqueue_script('awstatium-settings');
    wp_add_inline_script('awstatium-settings', "document.querySelectorAll('.awstatium-use').forEach(function (b) {
        b.addEventListener('click', function () {
            if (b.dataset.dir) document.getElementById('awstatium-dir').value = b.dataset.dir;
            if (b.dataset.config) document.getElementById('awstatium-config').value = b.dataset.config;
        });
    });");
});

/** POST form that reloads all AWStats data. */
function awstatium_refresh_form($back) {
    return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
         . '<input type="hidden" name="action" value="awstatium_refresh">'
         . '<input type="hidden" name="back" value="' . esc_attr($back) . '">'
         . wp_nonce_field('awstatium_refresh', '_wpnonce', true, false)
         . '<button class="button">' . esc_html__('Reload AWStats data', 'awstatium') . '</button></form>';
}

function awstatium_settings_page() {
    if (!current_user_can('manage_options')) return;
    $s     = awstatium_settings();
    $data  = awstatium_data();
    $files = awstatium_files();

    $last = '';
    foreach ($data as $c) {
        $lu = (string) strtok((string) ($c['g']['LastUpdate'] ?? ''), ' ');
        if ($lu > $last) $last = $lu;
    }

    echo '<div class="wrap"><h1>' . esc_html__('Awstatium settings', 'awstatium') . '</h1>';
    awstatium_refresh_notice();

    // Status
    echo '<h2>' . esc_html__('Status', 'awstatium') . '</h2><table class="widefat striped" style="max-width:800px"><tbody>';
    $row = function ($label, $value) {
        echo '<tr><th style="width:220px">' . esc_html($label) . '</th><td>' . $value . '</td></tr>';
    };
    $row(__('AWStats files found', 'awstatium'), $files
        ? esc_html(number_format_i18n(count($files)))
        : '<strong style="color:#b32d2e">' . esc_html__('None – check the directory and config below.', 'awstatium') . '</strong>');
    $row(__('Months loaded', 'awstatium'), esc_html(number_format_i18n(count($data))));
    $row(__('Last AWStats update', 'awstatium'), esc_html($last !== '' ? awstatium_dt($last) : '–') . ' ' . awstatium_refresh_form('settings'));
    if (!awstatium_pretty_permalinks()) {
        $row(__('Permalinks', 'awstatium'), '<strong style="color:#b32d2e">' . esc_html__('Plain permalinks (?p=123) are in use. AWStats ignores query strings, so views per page cannot be counted. Choose another structure in Settings → Permalinks.', 'awstatium') . '</strong>');
    }
    if (ini_get('open_basedir')) {
        $row('open_basedir', '<code>' . esc_html(ini_get('open_basedir')) . '</code><br>' . esc_html__('PHP can only read these directories. If the AWStats directory is outside them, ask your host or copy the files to an allowed directory with a cron job.', 'awstatium'));
    }
    echo '</tbody></table>';

    echo '<form method="post" action="options.php">';
    settings_fields('awstatium');
    $name = AWSTATIUM_OPT_SETTINGS;
    echo '<table class="form-table" role="presentation"><tbody>';

    // Data directory
    echo '<tr><th scope="row"><label for="awstatium-dir">' . esc_html__('AWStats data directory', 'awstatium') . '</label></th><td>'
       . '<input type="text" id="awstatium-dir" name="' . esc_attr($name) . '[dir]" value="' . esc_attr($s['dir']) . '" class="large-text code">'
       . '<p class="description">' . esc_html__('Directory with files named awstatsMMYYYY.example.com.txt. On cPanel this is usually ~/tmp/awstats/ssl (HTTPS) or ~/tmp/awstats (HTTP).', 'awstatium') . '</p>';
    $detected = [];
    foreach (awstatium_candidate_dirs() as $dir) {
        $configs = awstatium_configs_in($dir);
        if ($configs) $detected[$dir] = $configs;
    }
    if ($detected) {
        echo '<p><strong>' . esc_html__('Found on this server:', 'awstatium') . '</strong></p><ul>';
        foreach ($detected as $dir => $configs) {
            $guess = awstatium_guess_config($configs);
            echo '<li><code>' . esc_html($dir) . '</code> ';
            echo '<button type="button" class="button button-small awstatium-use" data-dir="' . esc_attr($dir) . '" data-config="' . esc_attr($guess) . '">' . esc_html__('Use', 'awstatium') . '</button></li>';
        }
        echo '</ul>';
    }
    echo '</td></tr>';

    // Config
    echo '<tr><th scope="row"><label for="awstatium-config">' . esc_html__('AWStats config', 'awstatium') . '</label></th><td>'
       . '<input type="text" id="awstatium-config" name="' . esc_attr($name) . '[config]" value="' . esc_attr($s['config']) . '" class="regular-text code">'
       . '<p class="description">' . esc_html__('The middle part of the file names, usually your domain. Addon domains on cPanel look like example.com.maindomain.com.', 'awstatium') . '</p>';
    $here = awstatium_configs_in($s['dir']);
    if ($here) {
        echo '<p><strong>' . esc_html__('In this directory:', 'awstatium') . '</strong></p><ul>';
        foreach ($here as $config => $months) {
            /* translators: %s: number of monthly files */
            $label = sprintf(_n('%s month', '%s months', $months, 'awstatium'), number_format_i18n($months));
            echo '<li><code>' . esc_html($config) . '</code> (' . esc_html($label) . ') '
               . '<button type="button" class="button button-small awstatium-use" data-config="' . esc_attr($config) . '">' . esc_html__('Use', 'awstatium') . '</button></li>';
        }
        echo '</ul>';
    }
    echo '</td></tr>';

    // Automatic display
    echo '<tr><th scope="row">' . esc_html__('Show views automatically', 'awstatium') . '</th><td><fieldset>';
    foreach (awstatium_post_types() as $type => $obj) {
        echo '<label style="margin-right:16px"><input type="checkbox" name="' . esc_attr($name) . '[auto_display][]" value="' . esc_attr($type) . '"'
           . checked(in_array($type, (array) $s['auto_display'], true), true, false) . '> ' . esc_html($obj->labels->name) . '</label>';
    }
    echo '<p><label>' . esc_html__('Position:', 'awstatium') . ' <select name="' . esc_attr($name) . '[position]">'
       . '<option value="after"' . selected($s['position'], 'after', false) . '>' . esc_html__('Below the content', 'awstatium') . '</option>'
       . '<option value="before"' . selected($s['position'], 'before', false) . '>' . esc_html__('Above the content', 'awstatium') . '</option>'
       . '</select></label></p>'
       . '<p class="description">' . esc_html__('Adds e.g. "1,234 views" to single posts of the selected types. It is hidden while the count is 0. You can also use the [awstatium_views] shortcode or awstatium_get_views() in your theme.', 'awstatium') . '</p>'
       . '</fieldset></td></tr>';

    // Cache
    echo '<tr><th scope="row">' . esc_html__('Page cache', 'awstatium') . '</th><td><label><input type="checkbox" name="' . esc_attr($name) . '[purge]" value="1"' . checked($s['purge'], 1, false) . '> '
       . esc_html__('Purge the page cache when new AWStats data arrives', 'awstatium') . '</label>'
       . '<p class="description">' . esc_html__('Supports LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, Cache Enabler and SiteGround Speed Optimizer. Other plugins can use the awstatium_purge_cache action.', 'awstatium') . '</p></td></tr>';

    echo '</tbody></table>';
    submit_button();
    echo '</form></div>';
}
