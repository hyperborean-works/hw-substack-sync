# HW Substack Sync

A WordPress plugin that syncs a Substack RSS feed into your site on a daily cron, with first-class support for canonical URLs and bulk-rollback tagging.

Fork of [Christopher S. Penn's `substack-wp-sync`](https://github.com/cspenn/substack-wp-sync) v1.0.2 (Apache 2.0). Significant changes are listed below.

## What it does

- **Daily cron** pulls the latest items from a Substack RSS feed (`yourname.substack.com/feed`).
- **Imports new posts** as drafts (configurable to publish-on-import).
- **Sideloads images** from Substack's CDN into the WP media library, including original (un-resized) source files where possible.
- **Rewrites image URLs** in post content to point at the local copies, so nothing depends on Substack staying up.
- **Sets featured images** automatically (first image in each post).
- **Sets `rank_math_canonical_url` postmeta** on every imported post pointing back to the original Substack URL. If your site uses Rank Math, the canonical link tag is output for free. If it doesn't, the postmeta is harmless.
- **Tags every imported post** with a configurable `_substack_import_batch` postmeta value for later bulk operations (rollback, re-categorization, re-import).
- **Auto-categorizes** posts based on a keyword-to-category mapping you configure.
- **Strips Substack chrome** (subscribe widgets, like buttons, "pencraft" utility classes, decorative `data-` attributes) from imported HTML.
- **Converts iframe embeds** (YouTube, Vimeo, Spotify, SoundCloud, Twitter/X) into bare URLs that WordPress will auto-oEmbed.
- **Dedupes** imported posts via Substack RSS GUID stored in a custom log table.
- **Manual sync** button in the admin for immediate runs.

## Requirements

- WordPress 6.0+
- PHP 8.0+
- Standard `wp-cron` enabled (or replaced with a real system cron - either works)

## Installation

1. Download or clone this repo as a `.zip`.
2. WP Admin -> Plugins -> Add New -> Upload Plugin -> upload the zip -> Activate.
3. Go to **Settings -> HW Substack Sync** and configure (see below).
4. Click **Sync Now** for an immediate first sync, or wait for the daily cron (first run is scheduled +1 hour after activation so reactivating doesn't kick off an immediate import).

## Configuration

| Setting | What it does |
|---|---|
| **RSS Feed URL** | Your Substack feed URL, e.g. `https://yourname.substack.com/feed`. |
| **Default Author** | The WP user assigned as author on imported posts. |
| **Default Post Status** | `Draft` (recommended for review) or `Published`. |
| **Category Mapping** | List of keyword -> category rules. Each post is scanned (title + body, case-insensitive) and assigned to all matching categories. |
| **Import Batch Tag** | Stored as `_substack_import_batch` postmeta on every imported post. Default `ongoing-sync`. Change between sync runs if you want them independently rollback-able. |
| **Max Items Per Sync (Testing)** | Cap each sync to N newest items. `0` = no limit. Useful for iteration during setup. |
| **Delete Data on Uninstall** | If checked, the sync log table and plugin options are dropped when the plugin is uninstalled. |

## Backfilling existing posts

If your WP site already contains the historical Substack posts (e.g. imported via WXR), the plugin's first run will see those URLs in the feed and try to re-import them as duplicates. To prevent this, seed the sync log with the Substack URLs of your existing posts before activating the plugin's cron.

A reference seed script is included in the project's parent migration folder (`seed-sync-log.php`). It reads `rank_math_canonical_url` postmeta from posts tagged with a configurable batch tag and inserts them into `wp_hw_substack_sync_log` with `status='imported'`. Adjust the batch tag inside the script to match your install.

## Hooks for extension

The plugin exposes one action and two filters for site-specific customization without forking:

```php
// Fires after every successful import or update. Hook in to set additional
// postmeta, push notifications, log to an external system, etc.
do_action('hw_substack_sync_post_imported', $post_id, $simplepie_item, $settings);

// Override the cron schedule. Default 'daily'.
apply_filters('hw_substack_sync_cron_schedule', 'daily');

// Override how far in the future the first cron run is scheduled. Default
// HOUR_IN_SECONDS so plugin activation doesn't kick off an immediate sync.
apply_filters('hw_substack_sync_first_run_offset', HOUR_IN_SECONDS);
```

## Postmeta written on imported posts

| Meta key | Value | Why |
|---|---|---|
| `rank_math_canonical_url` | Original Substack permalink | Rank Math reads this and outputs `<link rel="canonical">`. |
| `_substack_source_url` | Same as above, redundant | Plain-postmeta copy for themes/plugins that don't depend on Rank Math. |
| `_substack_import_batch` | The configured batch tag | Bulk-operation handle. |

## Database

A custom table `wp_hw_substack_sync_log` tracks imported items by Substack RSS GUID for dedup. Schema is created on activation; dropped on uninstall only if "Delete Data on Uninstall" was checked.

## Differences from upstream (cspenn's `substack-wp-sync` v1.0.2)

- Daily cron with deferred first run, replacing hourly.
- Canonical-URL postmeta and batch-tag postmeta.
- Image content URLs rewritten to local copies (upstream sideloaded but didn't rewrite).
- Substack CDN URLs unwrapped to their underlying source URL before sideload (cleaner filenames, original-resolution images).
- Attachment metadata enrichment: parent-post-titled, alt text populated, multi-image titles indexed.
- HTML cleanup: pencraft/pc-* utility classes, decorative data-attributes, iframe -> oEmbed bare URLs, Substack subscribe/like widgets.
- Bug fixes for upstream type-strict mismatch in `log_sync` and a TEXT column with an illegal `DEFAULT ''`.
- AJAX action names renamed to `hw_substack_*` to avoid collision with upstream.
- Class names, options, table, hooks, text domain all renamed to `hw_substack_sync` namespace.
- One action and two filters exposed for extension.
- Promotional content and personal branding from upstream removed.

## License

Apache License 2.0. See [LICENSE](./LICENSE).

Original work: Copyright (c) 2025 Christopher S. Penn.
Modifications: Copyright (c) 2026 Hyperborean Works.

## No support

This plugin is provided as-is. Bug reports via GitHub issues are welcome but not guaranteed a response. Test on a staging site before running in production.
