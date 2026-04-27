=== HW Substack Sync ===
Contributors: hyperborean.works
Tags: substack, rss, sync, import, content
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 1.1.0
License: Apache License 2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0

Sync a Substack RSS feed into WordPress on a daily cron. Sets canonical URLs back to Substack and tags every import for bulk rollback.

⚠️ IMPORTANT DISCLAIMER
NO SUPPORT IS PROVIDED FOR THIS PLUGIN. USE AT YOUR OWN RISK.

This plugin is provided "as is" without warranty of any kind. The author is not responsible for any issues, data loss, or damage that may occur from using this plugin. If it lights your computer on fire, it's not the author's fault.

== Description ==

HW Substack Sync pulls posts from a Substack RSS feed into WordPress on a daily schedule. Imported posts get a `rank_math_canonical_url` postmeta pointing back to the original Substack permalink (so Rank Math outputs the correct canonical link), plus an `_substack_import_batch` postmeta for bulk rollback. Images are sideloaded into the media library, and post content is rewritten to reference the local copies.

This is a fork of [Substack Sync](https://github.com/cspenn/substack-wp-sync) v1.0.2 by Christopher S. Penn (Apache 2.0). See README.md for the full list of changes.

= Features =

* Daily cron pull from Substack RSS feed
* Imports new posts as drafts (or publish-on-import, configurable)
* Sideloads images into the WP media library, including original-resolution source files
* Rewrites image URLs in post content to local copies
* Sets featured images (first image in each post)
* Sets `rank_math_canonical_url` postmeta on every import
* Tags every import with a configurable `_substack_import_batch` postmeta
* Auto-categorizes by configurable keyword-to-category rules
* Strips Substack chrome (subscribe widgets, like buttons, pencraft classes, decorative data-attrs)
* Converts iframe embeds (YouTube, Vimeo, Spotify, SoundCloud, Twitter/X) to bare URLs for WordPress auto-oEmbed
* Dedupes via Substack RSS GUID
* Manual "Sync Now" button for immediate runs

= Hooks =

The plugin exposes extension points so you don't have to fork:

`do_action('hw_substack_sync_post_imported', $post_id, $simplepie_item, $settings)` - fires after every successful import or update.

`apply_filters('hw_substack_sync_cron_schedule', 'daily')` - override the cron schedule.

`apply_filters('hw_substack_sync_first_run_offset', HOUR_IN_SECONDS)` - override how far in the future the first cron run is scheduled.

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/hw-substack-sync/` or upload the zip via Plugins -> Add New.
2. Activate the plugin via the Plugins menu.
3. Go to Settings -> HW Substack Sync.
4. Enter your Substack feed URL (e.g. `https://yourname.substack.com/feed`).
5. Configure default author, post status, category mapping, and batch tag.
6. Click "Sync Now" for an immediate run, or wait for the daily cron.

== Frequently Asked Questions ==

= Will this duplicate posts I already have on my site? =

If your site already contains historical Substack posts (e.g. from a one-time WXR import), the first sync will see those URLs in the feed and try to re-import them. Seed the dedup log first by inserting a row into `wp_hw_substack_sync_log` for each existing post (with the Substack URL as the `substack_guid`). A reference script is included in the project repository.

= Does this require Rank Math? =

No. The plugin sets `rank_math_canonical_url` postmeta because Rank Math reads it. If you don't use Rank Math, the postmeta is harmless. The plugin also sets a redundant `_substack_source_url` postmeta you can read directly from your theme.

= How do I roll back a sync? =

Every imported post is tagged with `_substack_import_batch` postmeta. Query by that meta key and bulk-delete via wp-cli, or use the rollback button in the plugin's admin page.

= Can I run it more often than daily? =

Yes. Use the `hw_substack_sync_cron_schedule` filter to set it to `hourly`, `twicedaily`, or any custom WP-Cron schedule. Note that Substack RSS only carries the latest ~20 items, so syncing more frequently than daily provides no real benefit unless you publish many times per day.

== Changelog ==

= 1.1.0 =
* Forked from cspenn/substack-wp-sync 1.0.2.
* Daily cron, deferred 1 hour after activation.
* Canonical URL postmeta, source URL postmeta, batch tag postmeta.
* Image URL rewriting in post content to local copies.
* Substack CDN URL unwrapping for cleaner filenames and original-resolution images.
* Attachment metadata enrichment (titles, alt text).
* Expanded HTML cleanup (pencraft classes, data attributes, iframe -> oEmbed conversion).
* Fixed type-strict bug in log_sync.
* Fixed activator SQL incompatible with MySQL strict mode.
* Renamed namespace, classes, options, AJAX actions, table, hooks, text domain to `hw_substack_sync`.
* Removed promotional/personal branding from the upstream admin UI.
* Added action + filters for extension.
* Max items per sync setting for testing.

== Upgrade Notice ==

= 1.1.0 =
Initial release of the HW fork.

== License ==

Apache License 2.0.

Original work Copyright (c) 2025 Christopher S. Penn. Modifications Copyright (c) 2026 Hyperborean Works.
