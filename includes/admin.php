<?php
/**
 * Admin: statistics page (Tools → AWStatium), manual refresh, dashboard widget,
 * sortable "Views" column and the "Previous URLs" box in the editor.
 */

defined('ABSPATH') || exit;

/* ---------- Manual refresh ---------- */

add_action('admin_post_awstatium_refresh', function () {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_die(esc_html__('Invalid request.', 'awstatium'), '', ['response' => 405]);
    if (!current_user_can('manage_options')) wp_die(esc_html__('You are not allowed to do this.', 'awstatium'), '', ['response' => 403]);
    check_admin_referer('awstatium_refresh');
    $status = awstatium_rebuild(true);
    $page   = (isset($_POST['back']) && $_POST['back'] === 'settings') ? 'options-general.php?page=awstatium-settings' : 'tools.php?page=awstatium';
    wp_safe_redirect(admin_url($page . '&awstatium_refresh=' . $status));
    exit;
});

function awstatium_refresh_notice() {
    $notices = [
        'ok'      => ['success', __('AWStats data reloaded.', 'awstatium')],
        'none'    => ['info', __('No new data, everything was already loaded.', 'awstatium')],
        'partial' => ['warning', __('Loaded, but some AWStats files were skipped (incomplete or being written), so the previous data was kept for those months. Details are in the PHP error log.', 'awstatium')],
        'error'   => ['error', __('Saving to the database failed, the last saved data is shown. Details are in the PHP error log.', 'awstatium')],
        'busy'    => ['warning', __('Another refresh is running. Try again in a minute.', 'awstatium')],
    ];
    $key = isset($_GET['awstatium_refresh']) ? sanitize_key(wp_unslash($_GET['awstatium_refresh'])) : '';
    if (isset($notices[$key])) {
        echo '<div class="notice notice-' . esc_attr($notices[$key][0]) . ' is-dismissible"><p>' . esc_html($notices[$key][1]) . '</p></div>';
    }
}

// Reminder on the dashboard and plugins screen while no AWStats files are found
add_action('admin_notices', function () {
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->id, ['dashboard', 'plugins'], true) || !current_user_can('manage_options')) return;
    if (awstatium_files()) return;
    echo '<div class="notice notice-warning"><p>' . esc_html__('AWStatium has no AWStats data yet.', 'awstatium')
       . ' <a href="' . esc_url(admin_url('options-general.php?page=awstatium-settings')) . '">' . esc_html__('Check the settings', 'awstatium') . '</a></p></div>';
});

/* ---------- Helpers ---------- */

function awstatium_ym_label($ym) {
    $ts = gmmktime(0, 0, 0, (int) substr($ym, 4, 2), 1, (int) substr($ym, 0, 4));
    return wp_date('F Y', $ts, new DateTimeZone('UTC'));
}

/** Title of the post at a root-relative path, or the path itself. */
function awstatium_path_title($path) {
    if ($path === awstatium_home_key()) return __('Home page', 'awstatium');
    $id = url_to_postid(awstatium_path_url($path));
    return $id ? get_the_title($id) : $path;
}

/** Monthly summary rows, newest first. */
function awstatium_months() {
    $months = [];
    foreach (awstatium_data() as $c) {
        $row = ['wp' => array_sum($c['p']), 'pages' => 0, 'hits' => 0, 'bw' => 0,
                'visits' => (int) ($c['g']['TotalVisits'] ?? 0), 'unique' => (int) ($c['g']['TotalUnique'] ?? 0),
                'nvh' => $c['nvh'], 'nvb' => $c['nvb']];
        foreach ($c['days'] as $d) { $row['pages'] += $d[0]; $row['hits'] += $d[1]; $row['bw'] += $d[2]; }
        $months[$c['ym']] = $row;
    }
    krsort($months);
    return $months;
}

/* ---------- Tools → AWStatium ---------- */

add_action('admin_menu', function () {
    add_management_page(__('AWStatium statistics', 'awstatium'), 'AWStatium', 'manage_options', 'awstatium', 'awstatium_stats_page');
});

function awstatium_stats_page() {
    if (!current_user_can('manage_options')) return;
    $data   = awstatium_data();
    $months = awstatium_months();
    $num    = 'number_format_i18n';
    $base   = admin_url('tools.php?page=awstatium');

    $last = '';
    foreach ($data as $c) {
        $lu = (string) strtok((string) ($c['g']['LastUpdate'] ?? ''), ' ');
        if ($lu > $last) $last = $lu;
    }

    echo '<div class="wrap"><h1>' . esc_html__('AWStatium statistics', 'awstatium') . '</h1>';
    awstatium_refresh_notice();

    if (!$months) {
        echo '<p>' . esc_html__('No AWStats data yet.', 'awstatium') . ' ';
        if (wp_next_scheduled('awstatium_refresh_now')) echo esc_html__('Data is being loaded in the background. Reload this page in a minute.', 'awstatium') . ' ';
        echo '</p>' . awstatium_actions('stats') . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
        return;
    }

    echo '<p>' . esc_html__('Last AWStats update:', 'awstatium') . ' <strong>' . esc_html(awstatium_dt($last)) . '</strong></p>';
    echo awstatium_actions('stats'); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts

    // Views of one page (or section) by month
    $q         = isset($_GET['path']) ? trim(wp_strip_all_tags((string) wp_unslash($_GET['path']))) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- paths are sanitised by awstatium_input_path()
    $is_prefix = !empty($_GET['prefix']);
    echo '<form method="get" style="margin:1em 0"><input type="hidden" name="page" value="awstatium">'
       . '<label>' . esc_html__('Page path (several separated by commas):', 'awstatium') . ' <input type="text" name="path" class="regular-text" value="' . esc_attr($q) . '" placeholder="/about/"></label> '
       . '<label><input type="checkbox" name="prefix" value="1"' . checked($is_prefix, true, false) . '> ' . esc_html__('everything below this path', 'awstatium') . '</label> '
       . '<button class="button">' . esc_html__('Show', 'awstatium') . '</button></form>';

    if ($q !== '') {
        $qs  = array_values(array_unique(array_filter(array_map('awstatium_input_path', explode(',', $q)))));
        $per = [];
        foreach ($data as $c) {
            $s = 0;
            foreach ($c['p'] as $u => $n) {
                foreach ($qs as $qn) {
                    if ($is_prefix ? awstatium_under_prefix((string) $u, $qn) : (string) $u === $qn) { $s += $n; break; }
                }
            }
            $per[$c['ym']] = $s;
        }
        krsort($per);
        echo '<h2>' . esc_html__('Views', 'awstatium') . ' – <code>' . esc_html(implode(', ', $qs)) . '</code>' . ($is_prefix ? ' ' . esc_html__('(everything below)', 'awstatium') : '') . '</h2>';
        echo '<table class="widefat striped" style="max-width:420px"><thead><tr><th>' . esc_html__('Month', 'awstatium') . '</th><th>' . esc_html__('Views', 'awstatium') . '</th></tr></thead><tbody>';
        foreach ($per as $m => $n) echo '<tr><td>' . esc_html(awstatium_ym_label($m)) . '</td><td>' . esc_html($num($n)) . '</td></tr>';
        echo '</tbody><tfoot><tr><td><strong>' . esc_html__('Total', 'awstatium') . '</strong></td><td><strong>' . esc_html($num(array_sum($per))) . '</strong></td></tr></tfoot></table>';
    }

    // By month
    $tot = array_fill_keys(['wp', 'pages', 'hits', 'bw', 'visits', 'unique', 'nvh', 'nvb'], 0);
    foreach ($months as $m) foreach ($tot as $k => $v) $tot[$k] += $m[$k];

    $cell = function ($label, $m) use ($num) {
        return '<tr><td>' . $label . '</td><td>' . esc_html($num($m['visits'])) . '</td><td>' . esc_html($num($m['unique'])) . '</td><td>'
             . esc_html($num($m['wp'])) . '</td><td>' . esc_html($num($m['pages'])) . '</td><td>' . esc_html($num($m['hits'])) . '</td><td>'
             . esc_html(size_format($m['bw'], 2)) . '</td><td>' . esc_html($num($m['nvh'])) . ' / ' . esc_html(size_format($m['nvb'], 2)) . '</td></tr>';
    };

    $ym  = isset($_GET['ym']) ? sanitize_key(wp_unslash($_GET['ym'])) : '';
    $sel = isset($months[$ym]) ? $ym : (string) array_key_first($months);

    echo '<h2>' . esc_html__('By month', 'awstatium') . '</h2><table class="widefat striped"><thead><tr>'
       . '<th>' . esc_html__('Month', 'awstatium') . '</th><th>' . esc_html__('Visits', 'awstatium') . '</th><th>' . esc_html__('Unique*', 'awstatium') . '</th>'
       . '<th>' . esc_html__('Page views**', 'awstatium') . '</th><th>' . esc_html__('AWStats pages***', 'awstatium') . '</th><th>' . esc_html__('Hits', 'awstatium') . '</th>'
       . '<th>' . esc_html__('Bandwidth', 'awstatium') . '</th><th>' . esc_html__('Not viewed (bots, errors)', 'awstatium') . '</th></tr></thead><tbody>';
    foreach ($months as $m_ym => $m) {
        $label = '<a href="' . esc_url(add_query_arg('ym', $m_ym, $base)) . '">' . esc_html(awstatium_ym_label((string) $m_ym)) . '</a>';
        echo $cell((string) $m_ym === $sel ? "<strong>$label</strong>" : $label, $m); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
    }
    echo '</tbody><tfoot>' . $cell('<strong>' . esc_html__('Total', 'awstatium') . '</strong>', $tot) . '</tfoot></table>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
    echo '<p class="description">'
       . esc_html__('* Unique visitors are summed per month, so the total is not unique over the whole period.', 'awstatium') . '<br>'
       . esc_html__('** Pages only: addresses ending with /, without a file extension or ending with .html, without admin, API and feeds. The same number the shortcodes show.', 'awstatium') . '<br>'
       . esc_html__('*** AWStats counts every file type that is not listed as a non-page in its config (often images, fonts and admin-ajax.php), so this number is usually much higher.', 'awstatium')
       . '</p>';

    // Selected month by day
    $days = [];
    foreach ($data as $c) if ($c['ym'] === $sel) $days = $c['days'];
    ksort($days);
    echo '<h2>' . esc_html__('By day', 'awstatium') . ' – ' . esc_html(awstatium_ym_label($sel)) . '</h2>';
    echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Day', 'awstatium') . '</th><th>' . esc_html__('Visits', 'awstatium') . '</th><th>'
       . esc_html__('AWStats pages', 'awstatium') . '</th><th>' . esc_html__('Hits', 'awstatium') . '</th><th>' . esc_html__('Bandwidth', 'awstatium') . '</th></tr></thead><tbody>';
    foreach ($days as $date => $d) {
        echo '<tr><td>' . esc_html(awstatium_dt($date . '000000', get_option('date_format'))) . '</td><td>' . esc_html($num($d[3])) . '</td><td>'
           . esc_html($num($d[0])) . '</td><td>' . esc_html($num($d[1])) . '</td><td>' . esc_html(size_format($d[2], 2)) . '</td></tr>';
    }
    echo '</tbody></table>';

    // Most viewed pages
    $p = awstatium_totals()['p'];
    arsort($p);
    echo '<h2>' . esc_html__('Most viewed pages (all time)', 'awstatium') . '</h2><table class="widefat striped"><thead><tr><th>'
       . esc_html__('Page', 'awstatium') . '</th><th>' . esc_html__('Path', 'awstatium') . '</th><th>' . esc_html__('Views', 'awstatium') . '</th><th></th></tr></thead><tbody>';
    foreach (array_slice($p, 0, 50, true) as $u => $n) {
        $u = (string) $u;
        echo '<tr><td><a href="' . esc_url(awstatium_path_url($u)) . '" target="_blank">' . esc_html(awstatium_path_title($u)) . '</a></td><td><code>' . esc_html($u) . '</code></td><td>' . esc_html($num($n)) . '</td>'
           . '<td><a href="' . esc_url(add_query_arg('path', rawurlencode($u), $base)) . '">' . esc_html__('by month', 'awstatium') . '</a></td></tr>';
    }
    echo '</tbody></table>';

    // Downloads
    $d = awstatium_totals()['d'];
    arsort($d);
    echo '<h2>' . esc_html__('Downloads (all time)', 'awstatium') . '</h2>';
    if (!$d) {
        echo '<p>' . esc_html__('No downloads recorded.', 'awstatium') . '</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('File', 'awstatium') . '</th><th>' . esc_html__('Downloads', 'awstatium') . '</th></tr></thead><tbody>';
        // Readable form for display only (%20 as a space); the stored name stays canonical
        foreach (array_slice($d, 0, 30, true) as $f => $n) echo '<tr><td>' . esc_html(rawurldecode((string) $f)) . '</td><td>' . esc_html($num($n)) . '</td></tr>';
        echo '</tbody></table>';
    }
    echo '</div>';
}

/* ---------- Dashboard widget ---------- */

add_action('wp_dashboard_setup', function () {
    if (!current_user_can('manage_options')) return;
    wp_add_dashboard_widget('awstatium_dashboard', 'AWStatium', 'awstatium_dashboard_widget');
});

function awstatium_dashboard_widget() {
    $by = [];
    foreach (awstatium_data() as $c) $by[$c['ym']] = $c;
    if (!$by) {
        echo '<p>' . esc_html__('No AWStats data yet.', 'awstatium') . ' <a href="' . esc_url(admin_url('options-general.php?page=awstatium-settings')) . '">' . esc_html__('Check the settings', 'awstatium') . '</a></p>';
        return;
    }
    krsort($by);
    $cur  = reset($by);
    $prev = next($by) ?: null;
    $num  = 'number_format_i18n';

    $sum = function ($c) {
        return $c ? [(int) ($c['g']['TotalVisits'] ?? 0), (int) ($c['g']['TotalUnique'] ?? 0), array_sum($c['p'])] : [0, 0, 0];
    };
    $a = $sum($cur);
    $b = $sum($prev);

    echo '<table class="widefat striped" style="margin-bottom:12px"><thead><tr><th></th>'
       . '<th>' . esc_html(awstatium_ym_label($cur['ym'])) . '</th>'
       . '<th>' . ($prev ? esc_html(awstatium_ym_label($prev['ym'])) : '–') . '</th></tr></thead><tbody>';
    foreach ([__('Visits', 'awstatium'), __('Unique visitors', 'awstatium'), __('Page views', 'awstatium')] as $i => $label) {
        echo '<tr><td>' . esc_html($label) . '</td><td><strong>' . esc_html($num($a[$i])) . '</strong></td><td>' . esc_html($num($b[$i])) . '</td></tr>';
    }
    echo '</tbody></table>';

    $top = $cur['p'];
    arsort($top);
    $top = array_slice($top, 0, 5, true);
    if ($top) {
        $all = awstatium_totals()['p'];
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Most viewed this month', 'awstatium') . '</th><th>' . esc_html__('Month', 'awstatium') . '</th><th>' . esc_html__('Total', 'awstatium') . '</th></tr></thead><tbody>';
        foreach ($top as $u => $n) {
            $u = (string) $u;
            echo '<tr><td><a href="' . esc_url(awstatium_path_url($u)) . '" target="_blank">' . esc_html(awstatium_path_title($u)) . '</a></td><td>'
               . esc_html($num($n)) . '</td><td>' . esc_html($num($all[$u] ?? 0)) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    echo '<p style="margin-bottom:0"><a href="' . esc_url(admin_url('tools.php?page=awstatium')) . '">' . esc_html__('All statistics →', 'awstatium') . '</a></p>';
}

/* ---------- "Views" column in post lists ---------- */

add_action('admin_init', function () {
    if (!awstatium_settings()['column']) return;
    foreach (array_keys(awstatium_post_types()) as $type) {
        add_filter("manage_{$type}_posts_columns", function ($cols) {
            $cols['awstatium_views'] = __('Views', 'awstatium');
            return $cols;
        });
        add_action("manage_{$type}_posts_custom_column", function ($col, $id) {
            if ($col !== 'awstatium_views') return;
            $n = awstatium_get_views($id);
            echo $n > 0 ? esc_html(number_format_i18n($n)) : '–';
        }, 10, 2);
        // true = the first click sorts from the most viewed
        add_filter("manage_edit-{$type}_sortable_columns", function ($cols) {
            $cols['awstatium_views'] = ['awstatium_views', true];
            return $cols;
        });
    }
});

// Sorts by the stored count. Items without a count (new, not synced yet) are last when sorting from the most viewed.
add_action('pre_get_posts', function ($q) {
    if (!is_admin() || !$q->is_main_query() || $q->get('orderby') !== 'awstatium_views' || !awstatium_settings()['column']) return;
    $q->set('meta_query', [
        'relation'        => 'OR',
        'awstatium_views' => ['key' => AWSTATIUM_META_VIEWS, 'type' => 'NUMERIC'],
        ['key' => AWSTATIUM_META_VIEWS, 'compare' => 'NOT EXISTS'],
    ]);
    $q->set('orderby', 'awstatium_views');
});

add_action('admin_head-edit.php', function () {
    echo '<style>.fixed .column-awstatium_views{width:90px}</style>';
});

/* ---------- "Previous URLs" box in the editor ---------- */

add_action('add_meta_boxes', function () {
    add_meta_box('awstatium', 'AWStatium', 'awstatium_meta_box', array_keys(awstatium_post_types()), 'side', 'low');
});

function awstatium_meta_box($post) {
    wp_nonce_field('awstatium_meta', 'awstatium_meta_nonce');
    if ($post->post_status === 'publish') {
        echo '<p><strong>' . esc_html(awstatium_format_views(awstatium_get_views($post))) . '</strong></p>';
    }
    echo '<p><label for="awstatium-old-paths">' . esc_html__('Previous URLs', 'awstatium') . '</label></p>'
       . '<textarea id="awstatium-old-paths" name="awstatium_old_paths" rows="3" class="widefat code" placeholder="/old-address/">'
       . esc_textarea((string) get_post_meta($post->ID, AWSTATIUM_META_OLD, true)) . '</textarea>'
       . '<p class="description">' . esc_html__('One per line. Views of these addresses are added to this page, e.g. after moving it. Old slugs of posts are added automatically.', 'awstatium') . '</p>';
}

add_action('save_post', function ($post_id) {
    if (!isset($_POST['awstatium_meta_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['awstatium_meta_nonce'])), 'awstatium_meta')) return;
    if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id) || !current_user_can('edit_post', $post_id)) return;

    // Each line is sanitised by awstatium_input_path(), which keeps percent-encoded letters
    $raw   = isset($_POST['awstatium_old_paths']) ? (string) wp_unslash($_POST['awstatium_old_paths']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $paths = array_values(array_unique(array_filter(array_map('awstatium_input_path', preg_split('/\R/', $raw)))));
    if ($paths) update_post_meta($post_id, AWSTATIUM_META_OLD, implode("\n", $paths));
    else delete_post_meta($post_id, AWSTATIUM_META_OLD);

    // Keep the sortable column in step right away
    if (get_post_status($post_id) === 'publish') update_post_meta($post_id, AWSTATIUM_META_VIEWS, awstatium_get_views($post_id));
});
