<?php
/**
 * Shortcodes and the optional view count below posts.
 *
 * [awstatium_views]                                 views of the current post or page
 * [awstatium_views id="123"]                        views of another post
 * [awstatium_views path="/old/,/new/"]              views of one or more URL paths
 * [awstatium_views prefix="/comics/"]               views of all pages below a path
 * [awstatium_views format="number"]                 just the number, without "views"
 * [awstatium_downloads match="report-2026"]         downloads of files whose name contains the text
 */

defined('ABSPATH') || exit;

add_shortcode('awstatium_views', function ($atts) {
    $a = shortcode_atts(['id' => '', 'path' => '', 'prefix' => '', 'format' => 'text'], $atts, 'awstatium_views');
    if ($a['prefix'] !== '')    $n = awstatium_get_prefix_views($a['prefix']);
    elseif ($a['path'] !== '')  $n = awstatium_get_path_views($a['path']);
    elseif ($a['id'] !== '')    $n = awstatium_get_views((int) $a['id']);
    else                        $n = awstatium_get_views();
    $out = $a['format'] === 'number' ? number_format_i18n($n) : awstatium_format_views($n);
    return '<span class="awstatium-views">' . esc_html($out) . '</span>';
});

add_shortcode('awstatium_downloads', function ($atts) {
    $a = shortcode_atts(['match' => '', 'format' => 'text'], $atts, 'awstatium_downloads');
    if ($a['match'] === '') return '';
    $n   = awstatium_get_downloads($a['match']);
    $out = $a['format'] === 'number' ? number_format_i18n($n) : awstatium_format_downloads($n);
    return '<span class="awstatium-downloads">' . esc_html($out) . '</span>';
});

// Optional: views below (or above) the content of selected post types. Hidden while the count is 0.
add_filter('the_content', function ($content) {
    if (!is_singular() || !in_the_loop() || !is_main_query()) return $content;
    $s = awstatium_settings();
    if (!in_array(get_post_type(), (array) $s['auto_display'], true)) return $content;
    $n = awstatium_get_views();
    if ($n < 1) return $content;
    $box = '<p class="awstatium-views-box">' . esc_html(awstatium_format_views($n)) . '</p>';
    return $s['position'] === 'before' ? $box . $content : $content . $box;
}, 20);
