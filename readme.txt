=== Awstatium ===
Contributors: kibergospodar
Tags: awstats, statistics, page views, downloads, privacy
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Page views, downloads and traffic statistics from your server's AWStats data. No tracking scripts, no cookies.

== Description ==

Most hosting control panels (cPanel, DirectAdmin) already run AWStats, which builds statistics from the web server's access logs. Awstatium reads that data and brings it into WordPress:

* **View counts per post and page** – show them automatically below posts, with a shortcode or from your theme.
* **Download counts** for PDFs and other files.
* **Statistics page** (Tools → Awstatium) with visits, unique visitors, page views, hits and bandwidth by month and by day, the most viewed pages and downloads.
* **Dashboard widget** with this month compared to the last one and the most viewed pages.
* **Sortable "Views" column** in the posts and pages lists.
* **Previous URLs** – moved or renamed a page? Add its old addresses and their views are counted too. Old post slugs are added automatically.

Nothing is added to your pages for counting: no JavaScript, no cookies, no database writes on page views and no requests to other services. The numbers come from the server logs that exist anyway.

= How it works =

AWStats usually updates its data once a day. Awstatium checks for new data every hour, stores the monthly numbers in the database and purges the page cache when the counts change (LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, Cache Enabler and SiteGround Speed Optimizer are supported). Visitors only ever read the stored numbers, AWStats files are never parsed during a page view.

Counts are based on what AWStats records: every page load of a URL, including reloads, without known bots.

= Requirements =

* AWStats data on the same server, readable by PHP. This is typical for cPanel (`~/tmp/awstats/ssl`, `~/tmp/awstats`) and DirectAdmin. Awstatium finds these directories automatically.
* Pretty permalinks. AWStats ignores query strings, so plain `?p=123` links cannot be counted per page.

It will not work on hosts that do not run AWStats or keep its data out of reach of PHP, which includes most managed WordPress hosting.

== Installation ==

1. Install and activate the plugin.
2. Open Settings → Awstatium. The AWStats directory and config are usually detected automatically. If not, pick one of the directories found on the server or enter the path.
3. Choose where to show view counts, or use the shortcodes below.

== Frequently Asked Questions ==

= Which shortcodes are there? =

* `[awstatium_views]` – views of the current post or page
* `[awstatium_views id="123"]` – views of another post
* `[awstatium_views path="/old/,/new/"]` – views of one or more URL paths
* `[awstatium_views prefix="/blog/"]` – views of all pages below a path
* `[awstatium_views format="number"]` – just the number
* `[awstatium_downloads match="report-2026"]` – downloads of files whose name contains the text

= Can I use it in my theme? =

Yes: `awstatium_get_views( $post )`, `awstatium_get_path_views( $paths )`, `awstatium_get_prefix_views( $prefix )`, `awstatium_get_downloads( $match )` and `awstatium_format_views( $count )`.

= Why are the numbers different from my old view counter? =

JavaScript counters usually count a visitor once per day or session and skip visitors who block scripts. AWStats counts every page load in the server log, without known bots.

= Why is "AWStats pages" so much higher than "Page views"? =

AWStats counts every file type that its config does not list as a non-page, often including images, fonts and admin-ajax.php. Awstatium's page views only include real pages: addresses ending with a slash, without admin, API and feed URLs.

= My site uses HTTP and HTTPS. Which directory should I pick? =

cPanel keeps HTTPS traffic in `~/tmp/awstats/ssl` and HTTP traffic in `~/tmp/awstats`. If your site redirects to HTTPS, the `ssl` directory has practically all visits.

= What happens to the data when I delete the plugin? =

Everything Awstatium stored is removed. AWStats files are never modified.

== Changelog ==

= 1.0.0 =
* First release.
