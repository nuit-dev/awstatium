<?php
/**
 * Settings, AWStats parsing, stored data and view lookups.
 *
 * Only the scheduled refresh, the admin refresh button and a change of the data source parse AWStats files.
 * Front-end requests read the last saved totals and never touch the files.
 *
 * URL policy:
 * - Paths are compared in one canonical form (RFC 3986, 6.2.2): unreserved characters and valid UTF-8
 *   are decoded, reserved and other characters stay percent-encoded with upper-case hex. The form is
 *   idempotent, so a key can be normalised again (e.g. from "Previous URLs") without changing meaning.
 * - Paths are case-sensitive. A trailing slash does not make a different page: /about and /about/ are one key.
 * - Query strings are ignored, so plain ?p=123 permalinks are not supported.
 * - Paths are relative to the domain root, like the ones AWStats records.
 *
 * Stored data: each source (directory + config) has its own data and totals options. The "active" option
 * points at the snapshot that is shown. A new source becomes active only after its data and totals are
 * saved, so a failed switch keeps showing the previous numbers.
 */

defined('ABSPATH') || exit;

const AWSTATIUM_OPT_SETTINGS = 'awstatium_settings';
const AWSTATIUM_OPT_ACTIVE   = 'awstatium_active'; // source id of the snapshot that is shown
// Per source: awstatium_data_<id> = ['src', 'months'], awstatium_totals_<id> = ['src', 'p' => views, 'd' => downloads]
const AWSTATIUM_OPT_SYNC     = 'awstatium_sync';   // pending "Views" column sync: ['gen' => id, 'offset' => n]
const AWSTATIUM_OPT_LOCK     = 'awstatium_lock';
const AWSTATIUM_META_VIEWS   = '_awstatium_views'; // copy of the view count, for sorting the admin list
const AWSTATIUM_META_OLD     = '_awstatium_old_paths';
const AWSTATIUM_SYNC_BATCH   = 200;

// One valid UTF-8 sequence (RFC 3629), or a single other byte >= 0x80 in group 1
const AWSTATIUM_UTF8_OR_BYTE = '/[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}|([\x80-\xFF])/';

// Keys (without trailing slash) that are not readable pages: admin, API, assets, well-known and feeds
const AWSTATIUM_SKIP = '#(^|/)(wp-admin|wp-json|wp-content|wp-includes)(/|$)|^/\.well-known(/|$)|/(feed|embed|trackback)$#';

/* ---------- Settings ---------- */

function awstatium_defaults() {
    return [
        'dir'          => '',
        'config'       => '',
        'auto_display' => [],
        'position'     => 'after',
        'purge'        => 1,
        'column'       => 1,
    ];
}

function awstatium_settings() {
    $s = get_option(AWSTATIUM_OPT_SETTINGS, []);
    return wp_parse_args(is_array($s) ? $s : [], awstatium_defaults());
}

/** An option straight from the database, past any cache, for decisions that must not use a stale copy. */
function awstatium_option_fresh($name) {
    global $wpdb;
    $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    return $raw === null ? null : maybe_unserialize((string) $raw);
}

function awstatium_settings_fresh() {
    $s = awstatium_option_fresh(AWSTATIUM_OPT_SETTINGS);
    return wp_parse_args(is_array($s) ? $s : [], awstatium_defaults());
}

/** Option names of one source's snapshot. */
function awstatium_opt($kind, $src) {
    return 'awstatium_' . $kind . '_' . substr((string) $src, 0, 12);
}

/** Identity of a data source. Stored data of another source is never shown or merged. */
function awstatium_source_id(array $s) {
    return md5($s['dir'] . "\0" . $s['config']);
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

/* ---------- Lock ---------- */

/**
 * Cross-process lock, so a refresh and a change of the data source never write at the same time.
 * INSERT IGNORE and a conditional UPDATE are atomic in MySQL; expired locks are taken over.
 */
function awstatium_lock($ttl = 300) {
    global $wpdb;
    $value = (time() + $ttl) . '.' . wp_rand(100000, 999999);
    $got   = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", AWSTATIUM_OPT_LOCK, $value));
    if (!$got) {
        $got = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", $value, AWSTATIUM_OPT_LOCK, time()));
    }
    if ($got) $GLOBALS['awstatium_lock'] = $value;
    return (bool) $got;
}

function awstatium_unlock() {
    global $wpdb;
    if (empty($GLOBALS['awstatium_lock'])) return;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", AWSTATIUM_OPT_LOCK, $GLOBALS['awstatium_lock']));
    $GLOBALS['awstatium_lock'] = '';
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

/** Monthly AWStats files of a source (current settings by default). */
function awstatium_files($s = null) {
    $s = $s ?: awstatium_settings();
    if ($s['dir'] === '' || $s['config'] === '') return [];
    $pattern = awstatium_glob_escape($s['dir']) . '/awstats[0-9][0-9][0-9][0-9][0-9][0-9].' . awstatium_glob_escape($s['config']) . '.txt';
    return @glob($pattern) ?: [];
}

/* ---------- Paths ---------- */

function awstatium_pct($m) {
    return sprintf('%%%02X', ord($m[0]));
}

/** Decodes one run of %XX escapes, keeping only unreserved ASCII and valid UTF-8 decoded. */
function awstatium_canon_run($m) {
    $b   = rawurldecode($m[0]);
    $out = '';
    $len = strlen($b);
    for ($i = 0; $i < $len;) {
        $c = ord($b[$i]);
        if ($c < 0x80) {
            $out .= preg_match('/[A-Za-z0-9\-._~]/', $b[$i]) ? $b[$i] : sprintf('%%%02X', $c);
            $i++;
            continue;
        }
        $n   = $c >= 0xF0 ? 4 : ($c >= 0xE0 ? 3 : ($c >= 0xC2 ? 2 : 0));
        $seq = $n ? substr($b, $i, $n) : '';
        if ($n && strlen($seq) === $n && preg_match('//u', $seq)) {
            $out .= $seq;
            $i   += $n;
        } else {
            $out .= sprintf('%%%02X', $c);
            $i++;
        }
    }
    return $out;
}

/**
 * Canonical percent-encoding of a path (RFC 3986, 6.2.2): unreserved characters and valid UTF-8 decoded,
 * everything else percent-encoded with upper-case hex. Idempotent and always valid UTF-8.
 */
function awstatium_canon($s) {
    // Encoding a raw byte can complete an escape sequence next to it (raw \xC4 + "%8D" becomes "%C4%8D"),
    // so the pass is repeated until nothing changes. Decoding only produces valid UTF-8, so this settles fast.
    $s = (string) $s;
    for ($i = 0; $i < 4; $i++) {
        $next = awstatium_canon_pass($s);
        if ($next === $s) break;
        $s = $next;
    }
    return $s;
}

function awstatium_canon_pass($s) {
    $s = preg_replace_callback('/(?:%[0-9A-Fa-f]{2})+/', 'awstatium_canon_run', $s);
    // A '%' that does not start an escape and characters not allowed in a path are encoded
    $s = preg_replace_callback('/%(?![0-9A-F]{2})|[^A-Za-z0-9\-._~!$&\'()*+,;=:@\/%\x80-\xFF]/', 'awstatium_pct', $s);
    // Raw bytes that are not part of valid UTF-8 are encoded as well, so every key can be stored.
    // Valid UTF-8 characters next to them stay as they are, which keeps the form idempotent.
    if (!preg_match('//u', $s)) $s = preg_replace_callback(AWSTATIUM_UTF8_OR_BYTE, 'awstatium_pct_invalid', $s);
    return $s;
}

/** Encodes a single invalid byte; a whole valid UTF-8 sequence is returned unchanged. */
function awstatium_pct_invalid($m) {
    return (isset($m[1]) && $m[1] !== '') ? sprintf('%%%02X', ord($m[1])) : $m[0];
}

/** Canonical path without query string or fragment. Case is kept. */
function awstatium_norm_path($u) {
    $u = (string) $u;
    return awstatium_canon(substr($u, 0, strcspn($u, '?#')));
}

/** Lookup key of a path: no trailing slash, except the root "/". */
function awstatium_key($path) {
    $k = rtrim((string) $path, '/');
    return $k === '' ? '/' : $k;
}

/**
 * When WordPress files live in a subdirectory of the site (e.g. /wordpress with the site at /),
 * pages are also reachable through that directory. Returns [site path, home path] to map one onto the other.
 */
function awstatium_site_prefix() {
    static $p = null;
    if ($p === null) {
        $site = awstatium_canon(untrailingslashit((string) wp_parse_url(site_url(), PHP_URL_PATH)));
        $home = awstatium_canon(untrailingslashit((string) wp_parse_url(home_url(), PHP_URL_PATH)));
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
 * Key of a recorded AWStats path if it is a readable page, otherwise ''.
 * Pages are paths ending with "/", without a file extension, or ending with .html/.htm.
 */
function awstatium_page_key($raw) {
    $u = awstatium_unprefix(awstatium_norm_path($raw));
    if ($u === '' || $u[0] !== '/') return '';
    $k = awstatium_key($u);
    if (preg_match(AWSTATIUM_SKIP, $k)) return '';
    $last = substr($k, strrpos($k, '/') + 1);
    if (substr($u, -1) !== '/' && strpos($last, '.') !== false && !preg_match('/\.html?$/i', $last)) return '';
    return $k;
}

/**
 * Key from user input (root-relative path, full URL or a stored key). Empty if unusable.
 * This is also the sanitiser for paths: percent-encoded letters must survive, which sanitize_text_field() would strip.
 */
function awstatium_input_path($s) {
    $s = trim(preg_replace('/[\x00-\x1F\x7F]/', '', wp_strip_all_tags((string) $s)));
    if ($s === '') return '';
    if (strpos($s, '://') !== false) $s = (string) wp_parse_url($s, PHP_URL_PATH);
    if ($s === '' || $s[0] !== '/') $s = '/' . $s;
    $s = awstatium_norm_path($s);
    return $s !== '' ? awstatium_key($s) : '';
}

/** Full URL of a root-relative key on this site's scheme, host and port (not appended to a home subdirectory). */
function awstatium_path_url($key) {
    $h    = wp_parse_url(home_url());
    $url  = ($h['scheme'] ?? 'https') . '://' . ($h['host'] ?? '') . (isset($h['port']) ? ':' . $h['port'] : '');
    $last = substr($key, strrpos($key, '/') + 1);
    if ($key !== '/' && strpos($last, '.') === false && substr((string) get_option('permalink_structure'), -1) === '/') $key .= '/';
    // Keys keep reserved characters encoded; UTF-8 letters are encoded here so the URL is plain ASCII
    return $url . preg_replace_callback('/[\x80-\xFF]/', 'awstatium_pct', $key);
}

/** Key of the home page, e.g. "/" or "/blog". */
function awstatium_home_key() {
    return awstatium_key(awstatium_canon((string) wp_parse_url(home_url('/'), PHP_URL_PATH)));
}

/* ---------- Parsing ---------- */

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
                if (count($f) < 2) break;
                $k = awstatium_page_key($f[0]);
                if ($k !== '') $r['p'][$k] = ($r['p'][$k] ?? 0) + (int) $f[1];
                break;
            case 'DOWNLOADS': // URL Hits 206Hits BW
                if (count($f) < 2 || $f[0] === '' || $f[0][0] !== '/') break;
                // File name taken from the encoded path and brought to the canonical form once
                $raw = substr($f[0], 0, strcspn($f[0], '?#'));
                $k   = awstatium_canon(substr($raw, strrpos($raw, '/') + 1));
                if ($k === '') break;
                $r['d'][$k] = ($r['d'][$k] ?? 0) + (int) $f[1];
                break;
        }
    }
    fclose($fh);
    // Empty or truncated file: every section we read must be opened and closed
    foreach (['GENERAL', 'TIME', 'DAY', 'SIDER', 'DOWNLOADS'] as $need) if (empty($closed[$need])) return null;
    return $r;
}

/**
 * Parses the files of a source on top of $months (only changed files unless $force).
 * Returns [months, changed, bad files, files found].
 */
function awstatium_parse_source(array $s, array $months, $force) {
    $changed = false;
    $bad     = 0;
    $files   = awstatium_files($s);
    foreach ($files as $file) {
        $k = basename($file);
        $m = @filemtime($file);
        if (!$force && isset($months[$k]) && $months[$k]['m'] === $m) continue;
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
        if (($months[$k] ?? null) !== $new) { $months[$k] = $new; $changed = true; }
    }
    return [$months, $changed, $bad, count($files)];
}

/* ---------- Stored data ---------- */

function awstatium_active() {
    return (string) get_option(AWSTATIUM_OPT_ACTIVE, '');
}

/** One part ('data' or 'totals') of a source's snapshot, or null if it is missing. */
function awstatium_snapshot($kind, $src) {
    if ($src === '') return null;
    $v = get_option(awstatium_opt($kind, $src));
    return (is_array($v) && ($v['src'] ?? '') === $src) ? $v : null;
}

/**
 * Part of the active snapshot. A switch of source may remove the previous snapshot right after this request
 * read the pointer; then the pointer is read again from the database and the new snapshot is used.
 * Readers never take the lock and see either the previous or the new numbers, never nothing.
 */
function awstatium_active_snapshot($kind) {
    $src = awstatium_active();
    $v   = awstatium_snapshot($kind, $src);
    if ($v === null && $src !== '') {
        $now = (string) awstatium_option_fresh(AWSTATIUM_OPT_ACTIVE);
        if ($now !== $src) $v = awstatium_snapshot($kind, $now);
    }
    return $v;
}

/** Monthly data of the active snapshot (no file access). false = forget the copy in memory. */
function awstatium_data($fresh = null) {
    static $data = null;
    if ($fresh === false) return $data = null;
    if ($fresh !== null) return $data = $fresh;
    if ($data === null) {
        $v    = awstatium_active_snapshot('data');
        $data = ($v && is_array($v['months'] ?? null)) ? $v['months'] : [];
    }
    return $data;
}

/** Sum of views and downloads of the active snapshot. false = forget the copy in memory. */
function awstatium_totals($fresh = null) {
    static $t = null;
    if ($fresh === false) return $t = null;
    if ($fresh !== null) return $t = $fresh;
    if ($t === null) {
        $v = awstatium_active_snapshot('totals');
        $t = $v ?: ['p' => [], 'd' => []];
    }
    return $t;
}

function awstatium_sum(array $months, $src) {
    $t = ['src' => $src, 'p' => [], 'd' => []];
    foreach ($months as $c) {
        foreach ($c['p'] as $u => $n) $t['p'][$u] = ($t['p'][$u] ?? 0) + $n;
        foreach ($c['d'] as $u => $n) $t['d'][$u] = ($t['d'][$u] ?? 0) + $n;
    }
    return $t;
}

/** update_option() also returns false when the value is unchanged, so only a comparison tells an error apart. */
function awstatium_save($key, $value) {
    global $wpdb;
    if (update_option($key, $value, false) || get_option($key) === $value) return true;
    error_log('Awstatium: saving ' . $key . ' failed: ' . $wpdb->last_error);
    return false;
}

/**
 * Updates the active source with new months. Monthly data is saved first; if that fails, the totals are
 * not published either. The in-memory copies only ever hold saved data. Returns [ok, totals changed].
 */
function awstatium_store($src, array $months, $changed) {
    $totals = awstatium_sum($months, $src);
    // Totals are checked even without changes, so a failed earlier save is retried
    $stale = get_option(awstatium_opt('totals', $src)) !== $totals;
    if ($changed) {
        if (!awstatium_save(awstatium_opt('data', $src), ['src' => $src, 'months' => $months])) return [false, false];
        awstatium_data($months);
    }
    if ($stale) {
        if (!awstatium_save(awstatium_opt('totals', $src), $totals)) return [false, false];
        awstatium_totals($totals);
    }
    return [true, $stale];
}

/**
 * Makes a new source active: its data and totals are saved first, then the "active" pointer is switched
 * and the previous snapshot removed. Until the pointer moves, the previous numbers stay visible.
 */
function awstatium_switch($src, array $months) {
    $totals = awstatium_sum($months, $src);
    if (!awstatium_save(awstatium_opt('data', $src), ['src' => $src, 'months' => $months])) return false;
    if (!awstatium_save(awstatium_opt('totals', $src), $totals)) return false;
    $old = (string) awstatium_option_fresh(AWSTATIUM_OPT_ACTIVE);
    if (!awstatium_save(AWSTATIUM_OPT_ACTIVE, $src)) return false;
    if ($old !== '' && $old !== $src) {
        delete_option(awstatium_opt('data', $old));
        delete_option(awstatium_opt('totals', $old));
    }
    awstatium_data($months);
    awstatium_totals($totals);
    return true;
}

/** After new counts are published: purge page caches first (fast), then sync the admin column in batches. */
function awstatium_after_publish() {
    awstatium_purge_caches();
    awstatium_schedule_sync();
}

/** A failed save is retried in a few minutes (and by the hourly refresh anyway). */
function awstatium_schedule_retry() {
    if (!wp_next_scheduled('awstatium_refresh_now')) wp_schedule_single_event(time() + 300, 'awstatium_refresh_now');
}

/**
 * Parses changed AWStats files of the configured source (usually only the current month) and publishes them.
 * If the configured source is not the active one yet, it is parsed completely and switched to.
 * $force = parse every file regardless of its modification time. A bad file never removes a month's good data.
 * Returns 'ok' (saved), 'none' (no changes), 'partial' (some files skipped), 'error' (saving failed)
 * or 'busy' (another refresh or a change of the data source is running).
 */
function awstatium_rebuild($force = false) {
    if (!awstatium_lock()) return 'busy';
    try {
        $s      = awstatium_settings_fresh();
        $src    = awstatium_source_id($s);
        $active = (string) awstatium_option_fresh(AWSTATIUM_OPT_ACTIVE);
        if ($src === $active) {
            $stored = get_option(awstatium_opt('data', $src));
            $months = (is_array($stored) && ($stored['src'] ?? '') === $src && is_array($stored['months'] ?? null)) ? $stored['months'] : [];
            [$months, $changed, $bad] = awstatium_parse_source($s, $months, $force || !$months);
        } else {
            // Another source is configured: build it completely, never on top of the active one
            [$months, $changed, $bad] = awstatium_parse_source($s, [], true);
        }
        // The data source changed while we were parsing: do not write data of the old one
        if (awstatium_source_id(awstatium_settings_fresh()) !== $src) return 'busy';
        if ($src === $active) {
            [$ok, $published] = awstatium_store($src, $months, $changed);
        } elseif ($months) {
            $ok = $published = awstatium_switch($src, $months);
        } else {
            $ok = true; // nothing usable in the configured source: the previous numbers stay
            $published = false;
        }
    } finally {
        awstatium_unlock();
    }
    if (!$ok) {
        awstatium_schedule_retry();
        return 'error';
    }
    if ($published) awstatium_after_publish();
    if ($bad) return 'partial';
    return $changed || $published ? 'ok' : 'none';
}

/**
 * Publishes a new data source that was already parsed (by the settings validation) or parses it now.
 * Waits briefly for a running refresh, which notices the new source and stops without writing.
 */
function awstatium_publish_source(array $s, $months = null) {
    $got = false;
    for ($i = 0; $i < 20 && !($got = awstatium_lock()); $i++) usleep(500000);
    if (!$got) {
        wp_schedule_single_event(time(), 'awstatium_refresh_now');
        return 'busy';
    }
    try {
        $src = awstatium_source_id($s);
        if (awstatium_source_id(awstatium_settings_fresh()) !== $src) return 'busy';
        $bad = 0;
        if ($months === null) [$months, , $bad] = awstatium_parse_source($s, [], true);
        $ok = $months && awstatium_switch($src, $months);
    } finally {
        awstatium_unlock();
    }
    if (!$ok) {
        awstatium_schedule_retry();
        return 'error';
    }
    awstatium_after_publish();
    return $bad ? 'partial' : 'ok';
}

/* ---------- Admin column sync (batched) ---------- */

/** Starts (or restarts) storing each published item's view count as post meta, so the admin list can sort by it. */
function awstatium_schedule_sync() {
    if (!awstatium_settings()['column']) return;
    $gen = (string) wp_rand(1, PHP_INT_MAX);
    update_option(AWSTATIUM_OPT_SYNC, ['gen' => $gen, 'offset' => 0], false);
    wp_schedule_single_event(time(), 'awstatium_sync_batch', [$gen]);
}

/** One batch of the column sync. The offset is saved before the next batch, so an interrupted batch is simply redone. */
function awstatium_sync_batch($gen = '') {
    $state = get_option(AWSTATIUM_OPT_SYNC);
    if (!is_array($state) || (string) $state['gen'] !== (string) $gen) return; // superseded by a newer sync
    $posts = get_posts(['post_type' => array_keys(awstatium_post_types()), 'post_status' => 'publish',
                        'posts_per_page' => AWSTATIUM_SYNC_BATCH, 'offset' => (int) $state['offset'],
                        'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true]);
    foreach ($posts as $post) update_post_meta($post->ID, AWSTATIUM_META_VIEWS, awstatium_get_views($post));
    if (count($posts) < AWSTATIUM_SYNC_BATCH) {
        delete_option(AWSTATIUM_OPT_SYNC);
        return;
    }
    $state['offset'] = (int) $state['offset'] + AWSTATIUM_SYNC_BATCH;
    update_option(AWSTATIUM_OPT_SYNC, $state, false);
    wp_schedule_single_event(time(), 'awstatium_sync_batch', [$state['gen']]);
}
add_action('awstatium_sync_batch', 'awstatium_sync_batch');

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

// Hourly check for new AWStats data (AWStats itself usually updates once a day).
// It also resumes an interrupted column sync.
add_action('awstatium_refresh', function () {
    awstatium_rebuild();
    $state = get_option(AWSTATIUM_OPT_SYNC);
    if (is_array($state) && !wp_next_scheduled('awstatium_sync_batch', [$state['gen']])) {
        wp_schedule_single_event(time(), 'awstatium_sync_batch', [$state['gen']]);
    }
});

// One-off refresh in the background (after activation or when a change of source had to wait)
add_action('awstatium_refresh_now', function () {
    awstatium_rebuild(true);
});

add_action('init', function () {
    if (!is_multisite() && !wp_next_scheduled('awstatium_refresh')) wp_schedule_event(time() + 300, 'hourly', 'awstatium_refresh');
});

/** On activation: find the AWStats data if not configured yet and load it in the background. */
function awstatium_activate() {
    if (is_multisite()) {
        deactivate_plugins(plugin_basename(AWSTATIUM_FILE));
        wp_die(esc_html__('Awstatium does not support WordPress Multisite yet.', 'awstatium'));
    }
    $s = awstatium_settings();
    if ($s['dir'] === '' || $s['config'] === '') {
        $found = awstatium_autodetect();
        if ($found) {
            $GLOBALS['awstatium_activating'] = true; // the settings hook must not parse during activation
            update_option(AWSTATIUM_OPT_SETTINGS, array_merge($s, $found), false);
            $GLOBALS['awstatium_activating'] = false;
        }
    }
    wp_schedule_single_event(time(), 'awstatium_refresh_now');
}

/* ---------- Lookups (public API) ---------- */

/** Pages strictly below a prefix, without the prefix page itself and pagination (/page/2). */
function awstatium_under_prefix($u, $prefix) {
    if ($prefix === '' || $u === $prefix) return false;
    $p = $prefix === '/' ? '/' : $prefix . '/';
    return strpos($u, $p) === 0 && !preg_match('#/page/\d+$#', $u);
}

/** All URL paths of a post: current permalink, old slugs remembered by WordPress and "Previous URLs". */
function awstatium_post_paths($post = null) {
    $post = get_post($post);
    if (!$post || !awstatium_pretty_permalinks()) return [];
    $url = get_permalink($post);
    if (!$url) return [];
    $path  = (string) wp_parse_url($url, PHP_URL_PATH);
    $paths = [$path];
    // Old slugs (WordPress keeps them for non-hierarchical types when a slug changes). The slug is replaced
    // in the last path segment, keeping a suffix such as .html and the trailing slash.
    $base = untrailingslashit($path);
    $last = basename($base);
    $name = (string) $post->post_name;
    if ($name !== '' && ($last === $name || strpos($last, $name . '.') === 0)) {
        $dir    = trailingslashit(dirname($base));
        $suffix = substr($last, strlen($name)) . (substr($path, -1) === '/' ? '/' : '');
        foreach ((array) get_post_meta($post->ID, '_wp_old_slug') as $old) {
            if ($old !== '' && $old !== $name) $paths[] = $dir . $old . $suffix;
        }
    }
    foreach (preg_split('/\R/', (string) get_post_meta($post->ID, AWSTATIUM_META_OLD, true)) as $line) {
        if (trim($line) !== '') $paths[] = $line;
    }
    return $paths;
}

/**
 * Total views of one or more root-relative URL paths (array or comma separated). Each path is counted once.
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

/** Total views of all pages below a root-relative path, e.g. a section of the site. */
function awstatium_get_prefix_views($prefix) {
    $prefix = awstatium_input_path($prefix);
    if ($prefix === '') return 0;
    $s = 0;
    foreach (awstatium_totals()['p'] as $u => $n) if (awstatium_under_prefix((string) $u, $prefix)) $s += $n;
    return $s;
}

/** Total downloads of files whose name contains $match (case insensitive). */
function awstatium_get_downloads($match) {
    $match = (string) $match;
    if ($match === '') return 0;
    $s = 0;
    foreach (awstatium_totals()['d'] as $f => $n) {
        // Compared with the real file name: the canonical key decoded once (%20 is a space, %2520 a literal "%20")
        if (stripos(rawurldecode((string) $f), $match) !== false) $s += $n;
    }
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
