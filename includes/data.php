<?php
/**
 * Settings, AWStats parsing, stored data and view lookups.
 *
 * Only the scheduled refresh, activation and the admin refresh button parse AWStats files.
 * Front-end requests read the last saved totals and never touch the files.
 */

defined('ABSPATH') || exit;

const AWSTATIUM_OPT_SETTINGS = 'awstatium_settings';
const AWSTATIUM_OPT_DATA     = 'awstatium_data';   // parsed data per month
const AWSTATIUM_OPT_TOTALS   = 'awstatium_totals'; // sum of all months, the only thing the front end reads
const AWSTATIUM_META_VIEWS   = '_awstatium_views'; // copy of the view count, for sorting the admin list
const AWSTATIUM_META_OLD     = '_awstatium_old_paths';

// Paths that are not readable pages: admin, API, assets, well-known and feeds
const AWSTATIUM_SKIP = '#(^|/)(wp-admin|wp-json|wp-content|wp-includes)/|^/\.well-known/|/(feed|embed|trackback)/$#';

/* ---------- Settings ---------- */

function awstatium_settings() {
    $s = get_option(AWSTATIUM_OPT_SETTINGS, []);
    return wp_parse_args(is_array($s) ? $s : [], [
        'dir'          => '',
        'config'       => '',
        'auto_display' => [],
        'position'     => 'after',
        'purge'        => 1,
    ]);
}

/** Public post types that can show views (attachments excluded). */
function awstatium_post_types() {
    $types = get_post_types(['public' => true], 'objects');
    unset($types['attachment']);
    return $types;
}

/** Views are looked up by URL path, which does not work with plain ?p=123 permalinks. */
function awstatium_pretty_permalinks() {
    return (string) get_option('permalink_structure') !== '';
}

/* ---------- Finding AWStats data ---------- */

function awstatium_host() {
    return strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
}

function awstatium_glob_escape($s) {
    return preg_replace('/[\\\\*?\[\]]/', '\\\\$0', (string) $s);
}

/** Likely home directories of the hosting account. */
function awstatium_home_dirs() {
    $dirs = [];
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        if (!empty($pw['dir'])) $dirs[] = $pw['dir'];
    }
    if (getenv('HOME')) $dirs[] = getenv('HOME');
    if (preg_match('#^(/home\d*/[^/]+)/#', ABSPATH, $m)) $dirs[] = $m[1];
    return array_values(array_unique(array_filter(array_map('untrailingslashit', $dirs))));
}

/** Directories where cPanel and DirectAdmin usually keep AWStats data. */
function awstatium_candidate_dirs() {
    $host = preg_replace('/^www\./', '', awstatium_host());
    $out  = [];
    foreach (awstatium_home_dirs() as $h) {
        $out[] = "$h/tmp/awstats/ssl";               // cPanel, HTTPS traffic
        $out[] = "$h/tmp/awstats";                   // cPanel, HTTP traffic
        $out[] = "$h/domains/$host/awstats/.data";   // DirectAdmin
        $out[] = "$h/domains/$host/awstats";
    }
    return array_values(array_unique($out));
}

/** AWStats config names found in a directory, with the number of monthly files for each. */
function awstatium_configs_in($dir) {
    $found = [];
    if ($dir === '' || !@is_dir($dir)) return $found;
    foreach (@glob(awstatium_glob_escape($dir) . '/awstats[0-9][0-9][0-9][0-9][0-9][0-9].*.txt') ?: [] as $f) {
        if (preg_match('/^awstats\d{6}\.(.+)\.txt$/', basename($f), $m)) $found[$m[1]] = ($found[$m[1]] ?? 0) + 1;
    }
    ksort($found);
    return $found;
}

/** Config that belongs to this site: exact host, host without www, or a cPanel addon domain (example.com.main.tld). */
function awstatium_guess_config(array $configs) {
    $host = awstatium_host();
    $bare = preg_replace('/^www\./', '', $host);
    foreach ([$host, $bare, "www.$bare"] as $c) if (isset($configs[$c])) return $c;
    foreach (array_keys($configs) as $c) if (strpos($c, $bare . '.') === 0) return $c;
    return '';
}

/** First candidate directory that has data for this site, or null. */
function awstatium_autodetect() {
    foreach (awstatium_candidate_dirs() as $dir) {
        $config = awstatium_guess_config(awstatium_configs_in($dir));
        if ($config !== '') return ['dir' => $dir, 'config' => $config];
    }
    return null;
}

function awstatium_files() {
    $s = awstatium_settings();
    if ($s['dir'] === '' || $s['config'] === '') return [];
    $pattern = awstatium_glob_escape($s['dir']) . '/awstats[0-9][0-9][0-9][0-9][0-9][0-9].' . awstatium_glob_escape($s['config']) . '.txt';
    return @glob($pattern) ?: [];
}

/* ---------- Parsing ---------- */

/** Common form of a path for comparison: decoded and lower case. */
function awstatium_norm_path($u) {
    return strtolower(rawurldecode((string) $u));
}

/**
 * When WordPress files live in a subdirectory of the site (e.g. /wordpress with the site at /),
 * pages are also reachable through that directory. Returns [site path, home path] to map one onto the other.
 */
function awstatium_site_prefix() {
    static $p = null;
    if ($p === null) {
        $site = strtolower(untrailingslashit((string) wp_parse_url(site_url(), PHP_URL_PATH)));
        $home = strtolower(untrailingslashit((string) wp_parse_url(home_url(), PHP_URL_PATH)));
        $p    = ($site !== '' && $site !== $home && ($home === '' || strpos($site, $home . '/') === 0)) ? [$site, $home] : [];
    }
    return $p;
}

/** Maps /wordpress/about/ to /about/ when WordPress is in its own directory. */
function awstatium_unprefix($u) {
    $p = awstatium_site_prefix();
    return ($p && strpos($u, $p[0] . '/') === 0) ? $p[1] . substr($u, strlen($p[0])) : $u;
}

/**
 * Path from user input (path or full URL), normalised with a trailing slash. Empty if unusable.
 * This is also the sanitiser for paths: percent-encoded letters must survive, which sanitize_text_field() would strip.
 */
function awstatium_input_path($s) {
    $s = trim(preg_replace('/[\x00-\x1F\x7F<>"\']/', '', wp_strip_all_tags((string) $s)));
    if ($s === '') return '';
    if (strpos($s, '://') !== false) $s = (string) wp_parse_url($s, PHP_URL_PATH);
    if ($s === '' || $s[0] !== '/') $s = '/' . $s;
    $s = awstatium_norm_path(trailingslashit($s));
    return awstatium_utf8($s) ? $s : '';
}

/** Invalid UTF-8 (e.g. Windows-1250 encoded URLs) cannot be saved in the database, so such entries are skipped. */
function awstatium_utf8($s) {
    return preg_match('//u', $s) === 1;
}

/** Returns null if the file is not a complete AWStats data file, so good data for that month is kept. */
function awstatium_parse_file($file) {
    $fh = @fopen($file, 'r');
    if (!$fh) return null;
    if (strncmp((string) fgets($fh), 'AWSTATS DATA FILE', 17) !== 0) { fclose($fh); return null; }
    $r = ['p' => [], 'd' => [], 'g' => [], 'days' => [], 'nvh' => 0, 'nvb' => 0];
    $sec    = null;
    $closed = [];
    while (($l = fgets($fh)) !== false) {
        if (strncmp($l, 'BEGIN_', 6) === 0) { $sec = strtok(substr($l, 6), " \r\n"); continue; }
        if (strncmp($l, 'END_', 4) === 0) {
            if ($sec !== null && rtrim(substr($l, 4)) === $sec) $closed[$sec] = true;
            $sec = null;
            continue;
        }
        if ($sec === null || $l === '' || $l[0] === '#') continue;
        $f = explode(' ', trim($l));
        switch ($sec) {
            case 'GENERAL':
                if (in_array($f[0], ['TotalVisits', 'TotalUnique', 'LastUpdate'], true)) $r['g'][$f[0]] = $f[1] ?? '';
                break;
            case 'DAY':       // Date Pages Hits Bandwidth Visits
                if (count($f) >= 5) $r['days'][$f[0]] = array_map('intval', array_slice($f, 1, 4));
                break;
            case 'TIME':      // Hour Pages Hits BW NotViewedPages NotViewedHits NotViewedBW
                $r['nvh'] += (int) ($f[5] ?? 0);
                $r['nvb'] += (int) ($f[6] ?? 0);
                break;
            case 'SIDER':     // URL Pages BW Entry Exit
                if (count($f) < 2 || $f[0] === '' || $f[0][0] !== '/') break;
                $u = awstatium_unprefix(awstatium_norm_path($f[0]));
                if (substr($u, -1) !== '/' || preg_match(AWSTATIUM_SKIP, $u) || !awstatium_utf8($u)) break;
                $r['p'][$u] = ($r['p'][$u] ?? 0) + (int) $f[1];
                break;
            case 'DOWNLOADS': // URL Hits 206Hits BW
                if (count($f) < 2 || $f[0] === '' || $f[0][0] !== '/') break;
                $k = rawurldecode(basename($f[0]));
                if (!awstatium_utf8($k)) break;
                $r['d'][$k] = ($r['d'][$k] ?? 0) + (int) $f[1];
                break;
        }
    }
    fclose($fh);
    // Empty or truncated file: every section we read must be opened and closed
    foreach (['GENERAL', 'TIME', 'DAY', 'SIDER', 'DOWNLOADS'] as $need) if (empty($closed[$need])) return null;
    return $r;
}

/* ---------- Stored data ---------- */

/** Saved monthly data (no file access). */
function awstatium_data($fresh = null) {
    static $data = null;
    if ($fresh !== null) return $data = $fresh;
    if ($data === null) {
        $data = get_option(AWSTATIUM_OPT_DATA, []);
        if (!is_array($data)) $data = [];
    }
    return $data;
}

/** Sum of views and downloads over all months. */
function awstatium_totals($fresh = null) {
    static $t = null;
    if ($fresh !== null) return $t = $fresh;
    if ($t === null) {
        $t = get_option(AWSTATIUM_OPT_TOTALS);
        if (!is_array($t)) $t = ['p' => [], 'd' => []];
    }
    return $t;
}

function awstatium_sum(array $data) {
    $t = ['p' => [], 'd' => []];
    foreach ($data as $c) {
        foreach ($c['p'] as $u => $n) $t['p'][$u] = ($t['p'][$u] ?? 0) + $n;
        foreach ($c['d'] as $u => $n) $t['d'][$u] = ($t['d'][$u] ?? 0) + $n;
    }
    return $t;
}

/** Forget all stored data, e.g. after the data source changed. */
function awstatium_reset() {
    delete_option(AWSTATIUM_OPT_DATA);
    delete_option(AWSTATIUM_OPT_TOTALS);
    awstatium_data([]);
    awstatium_totals(['p' => [], 'd' => []]);
}

/** update_option() also returns false when the value is unchanged, so only a comparison tells an error apart. */
function awstatium_save($key, $value) {
    global $wpdb;
    if (update_option($key, $value, false) || get_option($key) === $value) return true;
    error_log('Awstatium: saving ' . $key . ' failed: ' . $wpdb->last_error);
    return false;
}

/**
 * Parses changed AWStats files (usually only the current month), saves the data and totals,
 * updates the admin "Views" column and purges page caches.
 * $force = parse every file regardless of its modification time. A bad file never removes a month's good data.
 * Returns 'ok' (saved), 'none' (no changes), 'partial' (some files skipped) or 'error' (saving failed).
 */
function awstatium_rebuild($force = false) {
    $data    = awstatium_data();
    $changed = false;
    $bad     = 0;
    foreach (awstatium_files() as $file) {
        $k = basename($file);
        $m = @filemtime($file);
        if (!$force && isset($data[$k]) && $data[$k]['m'] === $m) continue;
        if (!preg_match('/^awstats(\d\d)(\d{4})\./', $k, $mm)) continue;
        $r = awstatium_parse_file($file);
        clearstatcache(true, $file);
        // The file changed while we were reading it: skip it until the next run
        if (@filemtime($file) !== $m) {
            $bad++;
            error_log('Awstatium: ' . $k . ' changed while reading, skipped until the next run');
            continue;
        }
        if (!$r) {
            $bad++;
            error_log('Awstatium: ' . $k . ' is not a complete AWStats data file, keeping the previous data for that month');
            continue;
        }
        $new = $r + ['m' => $m, 'ym' => $mm[2] . $mm[1]];
        if (($data[$k] ?? null) !== $new) { $data[$k] = $new; $changed = true; }
    }

    // Totals are checked even without file changes, so a failed earlier save is retried
    $totals = awstatium_sum($data);
    $stale  = get_option(AWSTATIUM_OPT_TOTALS) !== $totals;

    // Monthly data is saved first. If that fails, new totals are not published either, so the admin
    // and the front end always show the same data. The next run tries again.
    // The in-memory copies only ever hold saved data.
    if ($changed) {
        if (!awstatium_save(AWSTATIUM_OPT_DATA, $data)) return 'error';
        awstatium_data($data);
    }
    if ($stale) {
        if (!awstatium_save(AWSTATIUM_OPT_TOTALS, $totals)) return 'error';
        awstatium_totals($totals);
        awstatium_sync_meta();
        awstatium_purge_caches();
    }

    if ($bad) return 'partial';
    return $changed || $stale ? 'ok' : 'none';
}

/** Stores each published item's view count as post meta, so the admin list can sort by it. */
function awstatium_sync_meta() {
    $ids = get_posts(['post_type' => array_keys(awstatium_post_types()), 'post_status' => 'publish',
                      'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true]);
    foreach ($ids as $id) update_post_meta($id, AWSTATIUM_META_VIEWS, awstatium_get_views($id));
}

/** Pages show view counts in their HTML, so page caches are purged when the numbers change. */
function awstatium_purge_caches() {
    if (!awstatium_settings()['purge']) return;
    do_action('litespeed_purge_all');                                   // LiteSpeed Cache
    do_action('cache_enabler_clear_complete_cache');                    // Cache Enabler
    if (function_exists('rocket_clean_domain')) rocket_clean_domain();  // WP Rocket
    if (function_exists('w3tc_flush_all')) w3tc_flush_all();            // W3 Total Cache
    if (function_exists('wp_cache_clear_cache')) wp_cache_clear_cache(); // WP Super Cache
    if (function_exists('sg_cachepress_purge_cache')) sg_cachepress_purge_cache(); // SiteGround Speed Optimizer
    /** Fires after view counts changed, for other cache plugins. */
    do_action('awstatium_purge_cache');
}

/* ---------- Refresh ---------- */

// Hourly check for new AWStats data (AWStats itself usually updates once a day)
add_action('awstatium_refresh', function () {
    awstatium_rebuild();
});

add_action('init', function () {
    if (!wp_next_scheduled('awstatium_refresh')) wp_schedule_event(time() + 300, 'hourly', 'awstatium_refresh');
});

/** On activation: find the AWStats data if not configured yet and load it. */
function awstatium_activate() {
    $s = awstatium_settings();
    if ($s['dir'] === '' || $s['config'] === '') {
        $found = awstatium_autodetect();
        if ($found) update_option(AWSTATIUM_OPT_SETTINGS, array_merge($s, $found), false);
    }
    awstatium_rebuild();
}

/* ---------- Lookups (public API) ---------- */

/** Pages strictly below a prefix, without the prefix page itself and pagination (/page/2/). */
function awstatium_under_prefix($u, $prefix) {
    return $u !== $prefix && strpos($u, $prefix) === 0 && !preg_match('#/page/\d+/$#', $u);
}

/** All URL paths of a post: current permalink, old slugs remembered by WordPress and "Previous URLs". */
function awstatium_post_paths($post = null) {
    $post = get_post($post);
    if (!$post || !awstatium_pretty_permalinks()) return [];
    $url = get_permalink($post);
    if (!$url) return [];
    $path  = (string) wp_parse_url($url, PHP_URL_PATH);
    $paths = [$path];
    // Old slugs (WordPress keeps them for non-hierarchical types when a slug changes)
    if (basename(untrailingslashit($path)) === $post->post_name) {
        foreach ((array) get_post_meta($post->ID, '_wp_old_slug') as $old) {
            if ($old !== '' && $old !== $post->post_name) $paths[] = trailingslashit(dirname(untrailingslashit($path))) . $old . '/';
        }
    }
    foreach (preg_split('/\R/', (string) get_post_meta($post->ID, AWSTATIUM_META_OLD, true)) as $line) {
        if (trim($line) !== '') $paths[] = $line;
    }
    return $paths;
}

/**
 * Total views of one or more URL paths (array or comma separated). Each path is counted once.
 *
 * @param string|string[] $paths
 */
function awstatium_get_path_views($paths) {
    if (!is_array($paths)) $paths = explode(',', (string) $paths);
    $p = awstatium_totals()['p'];
    $s = 0;
    foreach (array_unique(array_filter(array_map('awstatium_input_path', $paths))) as $one) $s += $p[$one] ?? 0;
    return $s;
}

/** Total views of a post or page (current post by default). */
function awstatium_get_views($post = null) {
    return awstatium_get_path_views(awstatium_post_paths($post));
}

/** Total views of all pages below a path, e.g. a section of the site. */
function awstatium_get_prefix_views($prefix) {
    $prefix = awstatium_input_path($prefix);
    $s = 0;
    foreach (awstatium_totals()['p'] as $u => $n) if (awstatium_under_prefix((string) $u, $prefix)) $s += $n;
    return $s;
}

/** Total downloads of files whose name contains $match (case insensitive). */
function awstatium_get_downloads($match) {
    $match = (string) $match;
    if ($match === '') return 0;
    $s = 0;
    foreach (awstatium_totals()['d'] as $f => $n) if (stripos((string) $f, $match) !== false) $s += $n;
    return $s;
}

function awstatium_format_views($n) {
    /* translators: %s: number of page views */
    return sprintf(_n('%s view', '%s views', $n, 'awstatium'), number_format_i18n($n));
}

function awstatium_format_downloads($n) {
    /* translators: %s: number of downloads */
    return sprintf(_n('%s download', '%s downloads', $n, 'awstatium'), number_format_i18n($n));
}

/** AWStats stores server local time, so it is shown as is, without time zone conversion. */
function awstatium_dt($ymdhis, $fmt = '') {
    $d = DateTime::createFromFormat('YmdHis', substr((string) $ymdhis, 0, 14), new DateTimeZone('UTC'));
    if (!$d) return '–';
    if ($fmt === '') $fmt = get_option('date_format') . ' ' . get_option('time_format');
    return wp_date($fmt, $d->getTimestamp(), new DateTimeZone('UTC'));
}
