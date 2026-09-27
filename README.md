# Awstatium

**Page views, downloads and traffic statistics for WordPress from your server's AWStats data. No tracking scripts, no cookies.**

Most hosting control panels (cPanel, DirectAdmin) already run [AWStats](https://www.awstats.org/), which builds statistics from the web server's access logs. Awstatium reads that data and brings it into WordPress.

- **View counts per post and page**: automatically below posts, with a shortcode or from your theme
- **Download counts** for PDFs and other files
- **Statistics page** (Tools → Awstatium): visits, unique visitors, page views, hits and bandwidth by month and by day, most viewed pages, downloads
- **Dashboard widget**: this month compared to the last one, most viewed pages
- **Sortable "Views" column** in the posts and pages lists
- **Previous URLs**: moved or renamed a page? Add its old addresses and their views are counted too. Old post slugs are added automatically.

Nothing is added to your pages for counting: no JavaScript, no cookies, no database writes on page views, no requests to other services.

## Requirements

- WordPress 6.0+, PHP 7.4+
- AWStats data on the same server, readable by PHP. Typical for cPanel (`~/tmp/awstats/ssl`, `~/tmp/awstats`) and DirectAdmin; Awstatium finds these automatically.
- Pretty permalinks (AWStats ignores query strings, so `?p=123` links cannot be counted per page)

It does not work on hosts without AWStats or where PHP cannot read its data, which includes most managed WordPress hosting.

## Installation

1. Download the ZIP from [Releases](https://github.com/nuit-dev/awstatium/releases) and upload it in Plugins → Add New → Upload Plugin.
2. Activate. Settings → Awstatium shows what was detected; pick another directory there if needed.
3. Choose where to show view counts, or use the shortcodes.

## Shortcodes

| Shortcode | Shows |
|---|---|
| `[awstatium_views]` | views of the current post or page |
| `[awstatium_views id="123"]` | views of another post |
| `[awstatium_views path="/old/,/new/"]` | views of one or more URL paths |
| `[awstatium_views prefix="/blog/"]` | views of all pages below a path |
| `[awstatium_views format="number"]` | just the number |
| `[awstatium_downloads match="report-2026"]` | downloads of files whose name contains the text |

## PHP

```php
awstatium_get_views( $post = null );      // int, current post by default
awstatium_get_path_views( $paths );       // int, path or array of paths
awstatium_get_prefix_views( $prefix );    // int, all pages below a path
awstatium_get_downloads( $match );        // int
awstatium_format_views( $count );         // "1,234 views", translated
```

Example for a theme that builds its own post meta line:

```php
if ( function_exists( 'awstatium_get_views' ) && ( $n = awstatium_get_views() ) > 0 ) {
    echo '<span class="views">' . esc_html( awstatium_format_views( $n ) ) . '</span>';
}
```

Other cache plugins can hook into `awstatium_purge_cache`, which fires when the counts change.

## How it works

AWStats usually updates its data once a day. Awstatium checks for new data every hour, parses only the files that changed, stores the monthly numbers in the database and purges the page cache when counts change. Visitors only read the stored numbers; AWStats files are never parsed during a page view.

A broken or half-written AWStats file never replaces good data: the previous numbers for that month are kept and the problem is logged.

## Translations

English and Croatian are included. The template is in `languages/awstatium.pot`.

## License

GPL-2.0-or-later. Made by [NUIT d.o.o.](https://nuit.hr)
