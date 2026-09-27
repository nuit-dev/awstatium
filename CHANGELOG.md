# Changelog

## 1.0.5
- Copyright and license notice (GPL-2.0-or-later, NUIT d.o.o.) and a note that AWStatium is not affiliated with the AWStats project.

## 1.0.4
- Drafts, pending and scheduled items no longer show the home page views: their temporary ?p= / ?page_id= address has no views of its own. The Views column stores counts for items of every status, so sorting is correct in lists with drafts and private items.

## 1.0.3
- Sorting by the Views column: items without a stored count sort as 0 instead of by an unrelated value, the stored count is corrected while the list is shown, and "Reload AWStats data" always resyncs the column.

## 1.0.2
- Settings and statistics pages: "Reload AWStats data" below the status, buttons to switch between the two pages, post type checkboxes one per line.

## 1.0.1
- Renamed to "AWStatium – Page Views from AWStats". Slug, shortcodes, functions and settings are unchanged.

## 1.0.0
First stable release, in production on nuit.hr.
- Readers survive a change of the data source: if the snapshot they read was just replaced, the new one is used.
- Canonical form of URL keys is idempotent, also for mixed UTF-8 and invalid raw bytes.

## Pre-release versions
- **0.3.0** – Canonical percent-encoding of URL keys (RFC 3986), old slugs with `.html`, a separate snapshot per data source that becomes active only when fully saved.
- **0.2.0** – Pages without a trailing slash and `.html` pages, case-sensitive paths, validation of a new data source, database lock, links for sites in a subdirectory, Multisite blocked, batched sync of the Views column, `Update URI`.
- **0.1.0** – First build, based on the nuit-awstats plugin used on nuit.hr.
