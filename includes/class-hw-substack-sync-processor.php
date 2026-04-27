<?php

declare(strict_types=1);

/**
 * HW Substack Sync - WordPress Plugin (fork)
 *
 * Copyright (c) 2025 Christopher S. Penn (original)
 * Copyright (c) 2026 Hyperborean Works (modifications)
 * Licensed under Apache License Version 2.0
 *
 * NO SUPPORT PROVIDED. USE AT YOUR OWN RISK.
 */

/**
 * The core plugin class for processing Substack content.
 *
 * This class handles fetching RSS feeds, processing content, and importing posts.
 */
class Hw_Substack_Sync_Processor
{
    /**
     * Plugin settings.
     *
     * @var array<string, mixed>
     */
    private array $settings;

    /**
     * Initialize the class and set its properties.
     */
    public function __construct()
    {
        $this->settings = get_option('hw_substack_sync_settings', []);
    }

    /**
     * Run the sync process.
     *
     * Main method that orchestrates the synchronization process.
     *
     * @param bool $return_status Whether to return detailed status information.
     * @return array<string, mixed>|void Status information if requested.
     */
    public function run_sync(bool $return_status = false)
    {
        if (empty($this->settings['feed_url'])) {
            error_log('HW Substack Sync: No feed URL configured');

            if ($return_status) {
                return [
                    'success' => false,
                    'error' => 'No feed URL configured',
                    'total_posts' => 0,
                    'posts_processed' => 0,
                ];
            }

            return;
        }

        $feed = fetch_feed($this->settings['feed_url']);

        if (is_wp_error($feed)) {
            error_log('HW Substack Sync: Error fetching feed - ' . $feed->get_error_message());

            if ($return_status) {
                return [
                    'success' => false,
                    'error' => 'Error fetching feed: ' . $feed->get_error_message(),
                    'total_posts' => 0,
                    'posts_processed' => 0,
                ];
            }

            return;
        }

        $items = $feed->get_items();

        // Optional cap for testing - setting "max_items_per_run" = N > 0 only
        // processes the newest N items. 0 or unset = unlimited.
        $max_items = (int) ($this->settings['max_items_per_run'] ?? 0);
        if ($max_items > 0 && count($items) > $max_items) {
            $items = array_slice($items, 0, $max_items);
        }

        $total_posts = count($items);
        $posts_processed = 0;
        $posts_imported = 0;
        $posts_updated = 0;
        $posts_skipped = 0;
        $errors = [];

        if ($return_status && $total_posts === 0) {
            return [
                'success' => true,
                'total_posts' => 0,
                'posts_processed' => 0,
                'posts_imported' => 0,
                'posts_updated' => 0,
                'posts_skipped' => 0,
                'message' => 'No posts found in feed',
            ];
        }

        foreach ($items as $item) {
            try {
                $result = $this->process_feed_item($item, $return_status);
                $posts_processed++;

                if ($return_status && isset($result['action'])) {
                    switch ($result['action']) {
                        case 'imported':
                            $posts_imported++;

                            break;
                        case 'updated':
                            $posts_updated++;

                            break;
                        case 'skipped':
                            $posts_skipped++;

                            break;
                    }
                }
            } catch (Exception $e) {
                error_log('HW Substack Sync: Error processing post - ' . $e->getMessage());
                $errors[] = $e->getMessage();
                $posts_processed++;
            }
        }

        if ($return_status) {
            return [
                'success' => true,
                'total_posts' => $total_posts,
                'posts_processed' => $posts_processed,
                'posts_imported' => $posts_imported,
                'posts_updated' => $posts_updated,
                'posts_skipped' => $posts_skipped,
                'errors' => $errors,
                'message' => sprintf(
                    'Processed %d posts: %d imported, %d updated, %d skipped',
                    $posts_processed,
                    $posts_imported,
                    $posts_updated,
                    $posts_skipped
                ),
            ];
        }
    }

    /**
     * Process a single feed item.
     *
     * @param SimplePie_Item $item The feed item to process.
     * @param bool $return_status Whether to return status information.
     * @return array<string, mixed>|void Status information if requested.
     */
    private function process_feed_item($item, bool $return_status = false)
    {
        $guid = $item->get_id();
        $existing_post = $this->get_existing_post($guid);
        $post_title = $item->get_title();

        if ($existing_post) {
            // SAFE-BY-DEFAULT: existing posts are never touched. The upstream
            // plugin's "update on every fetch" behavior is destructive for
            // archive use-cases - it overwrites post_content, reverts status
            // to draft, replaces categories and postmeta, and re-sideloads
            // images creating media-library duplicates.
            //
            // Opt in to upstream's update behavior by setting
            //   update_existing_posts = '1'
            // in the plugin settings (or via the
            //   hw_substack_sync_update_existing filter).
            $update_existing = ! empty($this->settings['update_existing_posts'])
                && (string) $this->settings['update_existing_posts'] === '1';
            $update_existing = (bool) apply_filters(
                'hw_substack_sync_update_existing',
                $update_existing,
                $item,
                $existing_post
            );

            if (! $update_existing) {
                if ($return_status) {
                    return [
                        'action'     => 'skipped',
                        'post_title' => $post_title,
                        'post_id'    => (int) $existing_post['post_id'],
                        'success'    => true,
                        'message'    => "Skipped (already imported, not modifying): {$post_title}",
                    ];
                }
                return;
            }

            $result = $this->update_post($item, $existing_post, $return_status);

            if ($return_status) {
                return [
                    'action' => $result['success'] ? 'updated' : ($result['message'] && strpos($result['message'], 'Skipped') !== false ? 'skipped' : 'error'),
                    'post_title' => $post_title,
                    'post_id' => $existing_post['post_id'],
                    'success' => $result['success'] ?? false,
                    'message' => $result['message'] ?? "Updated: {$post_title}",
                ];
            }
        } else {
            $result = $this->import_post($item, $return_status);

            if ($return_status) {
                return [
                    'action' => $result['success'] ? 'imported' : ($result['message'] && strpos($result['message'], 'Skipped') !== false ? 'skipped' : 'error'),
                    'post_title' => $post_title,
                    'post_id' => $result['post_id'] ?? null,
                    'success' => $result['success'] ?? false,
                    'message' => $result['message'] ?? "Imported: {$post_title}",
                ];
            }
        }
    }

    /**
     * Process individual posts with detailed progress tracking.
     *
     * @param int $batch_size Number of posts to process per batch.
     * @param int $offset Starting offset.
     * @return array<string, mixed> Detailed status information.
     */
    public function run_batch_sync(int $batch_size = 1, int $offset = 0): array
    {
        if (empty($this->settings['feed_url'])) {
            return [
                'success' => false,
                'error' => 'No feed URL configured',
                'total_posts' => 0,
                'posts_processed' => 0,
                'has_more' => false,
            ];
        }

        $feed = fetch_feed($this->settings['feed_url']);

        if (is_wp_error($feed)) {
            return [
                'success' => false,
                'error' => 'Error fetching feed: ' . $feed->get_error_message(),
                'total_posts' => 0,
                'posts_processed' => 0,
                'has_more' => false,
            ];
        }

        $items = $feed->get_items();

        // Honor max_items_per_run setting so UI and cron sync paths agree.
        $max_items = (int) ($this->settings['max_items_per_run'] ?? 0);
        if ($max_items > 0 && count($items) > $max_items) {
            $items = array_slice($items, 0, $max_items);
        }

        $total_posts = count($items);

        if ($total_posts === 0) {
            return [
                'success' => true,
                'total_posts' => 0,
                'posts_processed' => 0,
                'has_more' => false,
                'message' => 'No posts found in feed',
            ];
        }

        $batch_items = array_slice($items, $offset, $batch_size);
        $posts_processed = 0;
        $processed_posts = [];
        $errors = [];

        foreach ($batch_items as $item) {
            try {
                $result = $this->process_feed_item($item, true);
                $posts_processed++;
                $processed_posts[] = $result;
            } catch (Exception $e) {
                error_log('HW Substack Sync: Error processing post - ' . $e->getMessage());
                $errors[] = $e->getMessage();
                $posts_processed++;
                $processed_posts[] = [
                    'action' => 'error',
                    'post_title' => $item->get_title() ?? 'Unknown',
                    'success' => false,
                    'message' => 'Error: ' . $e->getMessage(),
                ];
            }
        }

        $new_offset = $offset + $batch_size;
        $has_more = $new_offset < $total_posts;

        return [
            'success' => true,
            'total_posts' => $total_posts,
            'posts_processed' => $posts_processed,
            'current_offset' => $offset,
            'next_offset' => $new_offset,
            'has_more' => $has_more,
            'progress_percentage' => round(($new_offset / $total_posts) * 100, 1),
            'processed_posts' => $processed_posts,
            'errors' => $errors,
        ];
    }

    /**
     * Check if a post with the given GUID already exists.
     *
     * @param string $guid The Substack post GUID.
     * @return array<string, mixed>|null The existing post data or null.
     */
    private function get_existing_post(string $guid): ?array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'hw_substack_sync_log';

        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE substack_guid = %s", $guid),
            ARRAY_A
        );

        return $result ?: null;
    }

    /**
     * Import a new post from Substack.
     *
     * @param SimplePie_Item $item The feed item to import.
     * @param bool $return_status Whether to return status information.
     * @return array<string, mixed>|void Status information if requested.
     */
    private function import_post($item, bool $return_status = false)
    {
        $post_data = $this->prepare_post_data($item);
        $post_title = $post_data['post_title'];
        $guid = $item->get_id();

        // Check if we should skip due to max retries
        if ($this->should_skip_post($guid)) {
            if ($return_status) {
                return [
                    'success' => false,
                    'post_id' => null,
                    'message' => "Skipped: {$post_title} (max retries exceeded)",
                ];
            }

            return;
        }

        $post_id = wp_insert_post($post_data);

        if ($post_id && ! is_wp_error($post_id)) {
            $this->apply_post_metadata((int) $post_id, $item);
            $this->log_sync($post_id, $guid, 'imported', $post_title);
            $this->process_post_images($post_id, $post_data['post_content']);

            if ($return_status) {
                return [
                    'success' => true,
                    'post_id' => $post_id,
                    'message' => "Successfully imported: {$post_title}",
                ];
            }
        } else {
            $error_message = is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error occurred';
            error_log("HW Substack Sync: Failed to import post - {$error_message}");
            $this->log_sync(0, $guid, 'error', $post_title, $error_message);

            if ($return_status) {
                return [
                    'success' => false,
                    'post_id' => null,
                    'message' => "Failed to import: {$post_title} - {$error_message}",
                ];
            }
        }
    }

    /**
     * Update an existing post.
     *
     * @param SimplePie_Item $item The feed item.
     * @param array<string, mixed> $existing_post The existing post data.
     * @param bool $return_status Whether to return status information.
     * @return array<string, mixed>|void Status information if requested.
     */
    private function update_post($item, array $existing_post, bool $return_status = false)
    {
        $post_data = $this->prepare_post_data($item);
        $post_data['ID'] = $existing_post['post_id'];
        $post_data['post_status'] = 'draft'; // Set to draft for review
        $post_title = $post_data['post_title'];
        $guid = $item->get_id();

        // Check if we should skip due to max retries
        if ($this->should_skip_post($guid)) {
            if ($return_status) {
                return [
                    'success' => false,
                    'post_id' => $existing_post['post_id'],
                    'message' => "Skipped: {$post_title} (max retries exceeded)",
                ];
            }

            return;
        }

        $post_id = wp_update_post($post_data);

        if ($post_id && ! is_wp_error($post_id)) {
            $this->apply_post_metadata((int) $post_id, $item);
            $this->log_sync($post_id, $guid, 'updated', $post_title);
            $this->process_post_images($post_id, $post_data['post_content']);

            if ($return_status) {
                return [
                    'success' => true,
                    'post_id' => $post_id,
                    'message' => "Successfully updated: {$post_title}",
                ];
            }
        } else {
            $error_message = is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error occurred';
            error_log("HW Substack Sync: Failed to update post - {$error_message}");
            // Cast to int - $wpdb returns column values as strings by default
            // and log_sync's signature declares `int $post_id`.
            $this->log_sync((int) $existing_post['post_id'], $guid, 'error', $post_title, $error_message);

            if ($return_status) {
                return [
                    'success' => false,
                    'post_id' => (int) $existing_post['post_id'],
                    'message' => "Failed to update: {$post_title} - {$error_message}",
                ];
            }
        }
    }

    /**
     * Prepare post data for WordPress insertion.
     *
     * @param SimplePie_Item $item The feed item.
     * @return array<string, mixed> Post data array.
     */
    private function prepare_post_data($item): array
    {
        $content = $this->process_content($item->get_content());
        $title = $item->get_title();

        // Apply category mapping based on content and title
        $full_text = $title . ' ' . $content;
        $categories = $this->apply_category_mapping($full_text);

        $post_data = [
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => $this->settings['default_post_status'] ?? 'draft',
            'post_author' => $this->settings['default_author'] ?? 1,
            'post_date' => $item->get_date('Y-m-d H:i:s'),
            'post_type' => 'post',
        ];

        // Add categories if mapping found any
        if (! empty($categories)) {
            $post_data['post_category'] = $categories;
        }

        return $post_data;
    }

    /**
     * Apply HW-specific postmeta after a post is imported or updated.
     *
     * Sets:
     *   - rank_math_canonical_url -> the original Substack permalink
     *   - _substack_import_batch  -> a configurable batch tag (default 'ongoing-sync')
     *   - _substack_source_url    -> redundant explicit copy of the canonical, useful
     *                                for theme templates that don't depend on Rank Math
     *
     * Also fires the `hw_substack_sync_post_imported` action so other plugins or
     * mu-plugins can extend behavior without modifying core. Args:
     *   ($post_id, $item, $settings)
     *
     * @param int            $post_id WordPress post ID.
     * @param SimplePie_Item $item    The original feed item.
     */
    private function apply_post_metadata(int $post_id, $item): void
    {
        $substack_url = (string) $item->get_permalink();
        if ($substack_url !== '') {
            // Rank Math reads this and outputs <link rel="canonical">. If a site
            // doesn't use Rank Math, the postmeta is harmless.
            update_post_meta($post_id, 'rank_math_canonical_url', esc_url_raw($substack_url));
            update_post_meta($post_id, '_substack_source_url', esc_url_raw($substack_url));
        }

        $batch_tag = $this->settings['import_batch_tag'] ?? 'ongoing-sync';
        $batch_tag = sanitize_text_field((string) $batch_tag);
        if ($batch_tag !== '') {
            update_post_meta($post_id, '_substack_import_batch', $batch_tag);
        }

        do_action('hw_substack_sync_post_imported', $post_id, $item, $this->settings);
    }

    /**
     * Process and clean content from Substack.
     *
     * Strips Substack chrome (subscribe widgets, like buttons, image-expand
     * overlays, pencraft utility classes), unwraps figure elements, and
     * converts known oEmbed iframes (YouTube, Vimeo, etc.) to bare URLs that
     * WordPress will auto-embed. Mirrors the cleanup in build_migration.py
     * used for the historical bulk import.
     *
     * @param string $content The raw content from Substack.
     * @return string The processed content.
     */
    private function process_content(string $content): string
    {
        if ($content === '') {
            return $content;
        }

        // ---- Pass 1: regex-based stripping of obvious chrome ----------
        $content = preg_replace('/<div[^>]*class="[^"]*subscription-widget[^"]*"[^>]*>.*?<\/div>/is', '', $content) ?? $content;
        $content = preg_replace('/<form[^>]*class="[^"]*subscription-widget[^"]*"[^>]*>.*?<\/form>/is', '', $content) ?? $content;
        $content = preg_replace('/<div[^>]*class="[^"]*like-button[^"]*"[^>]*>.*?<\/div>/is', '', $content) ?? $content;
        $content = preg_replace('/<a[^>]*class="[^"]*button primary[^"]*show-subscribe[^"]*"[^>]*>.*?<\/a>/is', '', $content) ?? $content;

        // ---- Pass 2: DOM-based structural cleanup ---------------------
        // Wrap the content in a root element so DOMDocument has something
        // to anchor to, and use a UTF-8 prefix so multi-byte characters
        // round-trip correctly.
        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev_libxml = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="hw-root">' . $content . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev_libxml);

        if (! $loaded) {
            // If the parse failed entirely, fall back to the regex-cleaned content.
            return $content;
        }

        // 2a. Drop all <button> elements (Substack edit, image-expand, etc.)
        $xpath = new DOMXPath($doc);
        foreach (iterator_to_array($xpath->query('//button')) as $btn) {
            $btn->parentNode->removeChild($btn);
        }

        // 2b. Convert iframes from known oEmbed providers into bare URLs in
        //     their own paragraph (WP auto-embeds bare URLs on their own line).
        $oembed_hosts = [
            'youtube.com', 'youtu.be', 'youtube-nocookie.com',
            'vimeo.com', 'player.vimeo.com',
            'open.spotify.com', 'spotify.com',
            'soundcloud.com', 'w.soundcloud.com',
            'twitter.com', 'x.com',
        ];
        foreach (iterator_to_array($xpath->query('//iframe')) as $iframe) {
            $src = trim((string) $iframe->getAttribute('src'));
            $host = strtolower((string) parse_url($src, PHP_URL_HOST));
            $is_oembed = false;
            foreach ($oembed_hosts as $h) {
                if ($host === $h || str_ends_with($host, '.' . $h)) {
                    $is_oembed = true;
                    break;
                }
            }
            if ($is_oembed && $src !== '') {
                $url = $this->normalize_oembed_url($src);
                $p = $doc->createElement('p');
                $p->appendChild($doc->createTextNode($url));
                // Walk up to outermost embed wrapper and replace it
                $target = $iframe;
                $parent = $iframe->parentNode;
                while ($parent && $this->is_embed_wrapper($parent)) {
                    $target = $parent;
                    $parent = $parent->parentNode;
                }
                $target->parentNode->replaceChild($p, $target);
            } else {
                // Unknown iframe - drop it and any wrapper
                $target = $iframe;
                $parent = $iframe->parentNode;
                while ($parent && $this->is_embed_wrapper($parent)) {
                    $target = $parent;
                    $parent = $parent->parentNode;
                }
                if ($target->parentNode) {
                    $target->parentNode->removeChild($target);
                }
            }
        }

        // 2c. Strip pencraft / pc-* utility classes off every element.
        foreach (iterator_to_array($xpath->query('//*[@class]')) as $el) {
            $classes = preg_split('/\s+/', (string) $el->getAttribute('class')) ?: [];
            $kept = array_filter($classes, function ($c) {
                if ($c === '') return false;
                if (str_starts_with($c, 'pencraft')) return false;
                if (str_starts_with($c, 'pc-')) return false;
                if (str_starts_with($c, 'image2-')) return false;
                return true;
            });
            if (empty($kept)) {
                $el->removeAttribute('class');
            } else {
                $el->setAttribute('class', implode(' ', $kept));
            }
        }

        // 2d. Strip data-* attributes (Substack uses many for client-side state).
        foreach (iterator_to_array($xpath->query('//*[@*[starts-with(name(), "data-")]]')) as $el) {
            $to_remove = [];
            foreach ($el->attributes as $attr) {
                if (str_starts_with($attr->nodeName, 'data-')) {
                    $to_remove[] = $attr->nodeName;
                }
            }
            foreach ($to_remove as $name) {
                $el->removeAttribute($name);
            }
        }

        // ---- Serialize back to HTML, drop the wrapper -----------------
        $root = $doc->getElementById('hw-root');
        if (! $root) {
            return $content;
        }
        $cleaned = '';
        foreach ($root->childNodes as $child) {
            $cleaned .= $doc->saveHTML($child);
        }

        return $cleaned;
    }

    /**
     * Convert known iframe embed URLs to canonical "bare" URLs that WordPress
     * will auto-oEmbed when placed alone on a paragraph.
     */
    private function normalize_oembed_url(string $src): string
    {
        // YouTube embed -> watch
        if (preg_match('#https?://(?:www\.)?youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]+)#i', $src, $m)) {
            return 'https://www.youtube.com/watch?v=' . $m[1];
        }
        // Vimeo player -> canonical
        if (preg_match('#https?://player\.vimeo\.com/video/(\d+)#i', $src, $m)) {
            return 'https://vimeo.com/' . $m[1];
        }
        // SoundCloud player wrapper -> embedded url
        if (str_contains($src, 'w.soundcloud.com/player')) {
            $query = parse_url($src, PHP_URL_QUERY) ?: '';
            parse_str($query, $params);
            if (! empty($params['url'])) {
                return urldecode((string) $params['url']);
            }
        }
        return $src;
    }

    /**
     * Given an image URL, if it's a Substack CDN fetch wrapper
     * (https://substackcdn.com/image/fetch/$params/{url-encoded-real-url})
     * return the inner real URL. Otherwise return the URL unchanged.
     *
     * Without this unwrap, media_sideload_image uses the entire CDN URL
     * (including all the URL-encoded chars) as the filename, producing
     * unreadable names like "https3A2F2Fsubstack-post-media...jpeg".
     */
    private function unwrap_substack_cdn_url(string $url): string
    {
        // Only handle the known pattern.
        if (! preg_match('#^https?://substackcdn\.com/image/fetch/#i', $url)) {
            return $url;
        }
        // The inner URL is the final path segment, URL-encoded. Find the last
        // occurrence of an encoded "http" - that's where the inner URL starts.
        // Use ~ as the regex delimiter because the pattern contains literal #.
        if (preg_match('~/(https?%3[Aa]%2[Ff]%2[Ff][^/?#]+)(?:[?#].*)?$~', $url, $m)) {
            $inner = urldecode($m[1]);
            if (filter_var($inner, FILTER_VALIDATE_URL)) {
                return $inner;
            }
        }
        // Fallback: return original. At worst we get the CDN-resized image
        // with an ugly filename, same as before this helper existed.
        return $url;
    }

    /**
     * True if a DOM node is a Substack embed wrapper div (so we know how far
     * to walk up the tree when replacing iframes with oEmbed URLs).
     */
    private function is_embed_wrapper(\DOMNode $el): bool
    {
        if (! ($el instanceof \DOMElement) || strtolower($el->tagName) !== 'div') {
            return false;
        }
        $class = (string) $el->getAttribute('class');
        $id    = (string) $el->getAttribute('id');
        $class_prefixes = ['youtube-wrap', 'youtube2-wrap', 'embed-wrap', 'tweet', 'spotify-', 'soundcloud-'];
        foreach ($class_prefixes as $prefix) {
            if (str_contains($class, $prefix)) {
                return true;
            }
        }
        $id_prefixes = ['youtube2-', 'vimeo-', 'spotify-', 'soundcloud-', 'tweet-'];
        foreach ($id_prefixes as $prefix) {
            if (str_starts_with($id, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Process and import images from post content.
     *
     * Sideloads every remote image into the media library, sets the first one
     * as the featured image, and rewrites every `<img src>` in post_content
     * to point at the local copy. This last step is the critical fix vs the
     * upstream plugin, which sideloaded images but left content URLs pointing
     * at substackcdn.com / substack-post-media.s3.amazonaws.com.
     *
     * @param int $post_id The WordPress post ID.
     * @param string $content The post content (already HTML-cleaned).
     */
    private function process_post_images(int $post_id, string $content): void
    {
        // media_sideload_image needs these admin includes to be loaded
        // (they're not always available in cron context).
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $doc = new DOMDocument();
        $prev_libxml = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="hw-img-root">' . $content . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev_libxml);

        $images = $doc->getElementsByTagName('img');
        $first_image_set = false;
        $image_index = 0;
        $post_title = (string) get_the_title($post_id);
        $home = home_url();

        // Map of original remote URL -> local URL, applied to post_content
        // after we finish processing every image.
        $url_replacements = [];

        foreach ($images as $img) {
            $src = trim((string) $img->getAttribute('src'));
            if ($src === '' || ! filter_var($src, FILTER_VALIDATE_URL)) {
                continue;
            }
            // Already pointing at our own site? Skip - already local.
            if (str_starts_with($src, $home)) {
                continue;
            }
            // Avoid re-sideloading the same URL within a single post.
            if (isset($url_replacements[$src])) {
                continue;
            }

            $image_index++;
            $original_alt = trim((string) $img->getAttribute('alt'));

            // Substack wraps images in a CDN fetch URL:
            //   https://substackcdn.com/image/fetch/$params/{url-encoded-real-url}
            // If we pass that to media_sideload_image it downloads correctly
            // (follows redirects) but uses the entire ugly URL as the filename.
            // Unwrap to the underlying source URL so the on-disk filename is
            // sane and we also grab the original (un-downsized) image.
            $download_url = $this->unwrap_substack_cdn_url($src);

            $attachment_id = media_sideload_image($download_url, $post_id, '', 'id');
            if (is_wp_error($attachment_id) || ! $attachment_id) {
                error_log('HW Substack Sync: sideload failed for ' . $download_url
                    . (is_wp_error($attachment_id) ? ' - ' . $attachment_id->get_error_message() : ''));
                continue;
            }
            $attachment_id = (int) $attachment_id;

            // Enrich the attachment's metadata so it's browsable in the Media
            // Library instead of showing up as "(no title)".
            //   - post_title: parent post title + index for multi-image posts
            //   - _wp_attachment_image_alt: original RSS alt text, else post title
            $title_for_image = $image_index === 1
                ? $post_title
                : sprintf('%s - image %d', $post_title, $image_index);
            $alt_for_image = $original_alt !== '' ? $original_alt : $post_title;

            wp_update_post([
                'ID'         => $attachment_id,
                'post_title' => $title_for_image,
            ]);
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_for_image);

            $local_url = wp_get_attachment_url($attachment_id);
            if ($local_url) {
                $url_replacements[$src] = $local_url;
            }

            if (! $first_image_set) {
                set_post_thumbnail($post_id, $attachment_id);
                $first_image_set = true;
            }
        }

        // Rewrite remote URLs to local in post_content and save back.
        // Using strtr against the original (pre-DOM-parse) content so we
        // don't have to re-serialize through DOMDocument (which mangles
        // HTML entities and adds boilerplate).
        if (! empty($url_replacements)) {
            $updated = strtr($content, $url_replacements);
            if ($updated !== $content) {
                // remove_action / add_action dance avoids retriggering our
                // own sync hooks if any have been wired into save_post.
                wp_update_post([
                    'ID'           => $post_id,
                    'post_content' => $updated,
                ]);
            }
        }
    }

    /**
     * Log sync activity to the database.
     *
     * @param int $post_id The WordPress post ID.
     * @param string $substack_guid The Substack GUID.
     * @param string $status The sync status.
     * @param string $post_title The post title for reference.
     * @param string $error_message Optional error message.
     */
    private function log_sync(int $post_id, string $substack_guid, string $status, string $post_title = '', string $error_message = ''): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'hw_substack_sync_log';

        // Get existing record to preserve retry count
        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT retry_count FROM $table_name WHERE substack_guid = %s", $substack_guid)
        );

        $retry_count = 0;
        if ($existing && $status === 'error') {
            $retry_count = $existing->retry_count + 1;
        }

        $wpdb->replace(
            $table_name,
            [
                'post_id' => $post_id,
                'substack_guid' => $substack_guid,
                'substack_title' => $post_title,
                'sync_date' => current_time('mysql'),
                'last_modified' => current_time('mysql'),
                'status' => $status,
                'retry_count' => $retry_count,
                'error_message' => $error_message,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s']
        );
    }

    /**
     * Get sync statistics for resumable operations.
     *
     * @return array<string, mixed> Sync statistics.
     */
    public function get_sync_stats(): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'hw_substack_sync_log';

        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_synced,
                SUM(CASE WHEN status = 'imported' THEN 1 ELSE 0 END) as imported_count,
                SUM(CASE WHEN status = 'updated' THEN 1 ELSE 0 END) as updated_count,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count,
                MAX(sync_date) as last_sync_date
            FROM $table_name
        ", ARRAY_A);

        return [
            'total_synced' => intval($stats['total_synced'] ?? 0),
            'imported_count' => intval($stats['imported_count'] ?? 0),
            'updated_count' => intval($stats['updated_count'] ?? 0),
            'error_count' => intval($stats['error_count'] ?? 0),
            'last_sync_date' => $stats['last_sync_date'] ?? null,
        ];
    }

    /**
     * Get posts that need retry due to errors.
     *
     * @param int $max_retries Maximum number of retries allowed.
     * @return array<array<string, mixed>> Posts that need retry.
     */
    public function get_posts_needing_retry(int $max_retries = 3): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'hw_substack_sync_log';

        return $wpdb->get_results(
            $wpdb->prepare("
                SELECT substack_guid, substack_title, retry_count, error_message 
                FROM $table_name 
                WHERE status = 'error' AND retry_count < %d 
                ORDER BY sync_date ASC
            ", $max_retries),
            ARRAY_A
        );
    }

    /**
     * Check if a post should be skipped due to max retries.
     *
     * @param string $guid The Substack GUID.
     * @param int $max_retries Maximum retries allowed.
     * @return bool True if post should be skipped.
     */
    private function should_skip_post(string $guid, int $max_retries = 3): bool
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'hw_substack_sync_log';

        $retry_count = $wpdb->get_var(
            $wpdb->prepare("SELECT retry_count FROM $table_name WHERE substack_guid = %s AND status = 'error'", $guid)
        );

        return $retry_count !== null && intval($retry_count) >= $max_retries;
    }

    /**
     * Reset retry count for a specific post.
     *
     * @param string $guid The Substack GUID.
     * @return bool True if reset successfully.
     */
    public function reset_post_retry_count(string $guid): bool
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'hw_substack_sync_log';

        return $wpdb->update(
            $table_name,
            ['retry_count' => 0, 'status' => 'pending'],
            ['substack_guid' => $guid],
            ['%d', '%s'],
            ['%s']
        ) !== false;
    }

    /**
     * Get recent sync logs for display.
     *
     * @param int $limit Number of logs to retrieve.
     * @return array<array<string, mixed>> Recent sync logs.
     */
    public function get_recent_sync_logs(int $limit = 50): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'hw_substack_sync_log';

        return $wpdb->get_results(
            $wpdb->prepare("
                SELECT substack_guid, substack_title, sync_date, status, error_message 
                FROM $table_name 
                ORDER BY sync_date DESC 
                LIMIT %d
            ", $limit),
            ARRAY_A
        );
    }

    /**
     * Rollback all synced posts.
     *
     * @return int Number of posts deleted.
     */
    public function rollback_all_posts(): int
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'hw_substack_sync_log';

        $post_ids = $wpdb->get_col("SELECT post_id FROM $table_name WHERE post_id > 0");
        $deleted_count = 0;

        foreach ($post_ids as $post_id) {
            if (wp_delete_post($post_id, true)) {
                $deleted_count++;
            }
        }

        // Clear the sync log
        $wpdb->query("DELETE FROM $table_name");

        return $deleted_count;
    }

    /**
     * Rollback only failed posts.
     *
     * @return int Number of posts deleted.
     */
    public function rollback_failed_posts(): int
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'hw_substack_sync_log';

        $post_ids = $wpdb->get_col("SELECT post_id FROM $table_name WHERE status = 'error' AND post_id > 0");
        $deleted_count = 0;

        foreach ($post_ids as $post_id) {
            if (wp_delete_post($post_id, true)) {
                $deleted_count++;
            }
        }

        // Remove failed entries from log
        $wpdb->delete($table_name, ['status' => 'error'], ['%s']);

        return $deleted_count;
    }

    /**
     * Rollback posts by date range.
     *
     * @param string $date_from Start date.
     * @param string $date_to End date.
     * @return int Number of posts deleted.
     */
    public function rollback_posts_by_date(string $date_from, string $date_to): int
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'hw_substack_sync_log';

        $post_ids = $wpdb->get_col(
            $wpdb->prepare("
                SELECT post_id 
                FROM $table_name 
                WHERE post_id > 0 
                AND sync_date BETWEEN %s AND %s
            ", $date_from . ' 00:00:00', $date_to . ' 23:59:59')
        );

        $deleted_count = 0;

        foreach ($post_ids as $post_id) {
            if (wp_delete_post($post_id, true)) {
                $deleted_count++;
            }
        }

        // Remove entries from log
        $wpdb->query(
            $wpdb->prepare("
                DELETE FROM $table_name 
                WHERE sync_date BETWEEN %s AND %s
            ", $date_from . ' 00:00:00', $date_to . ' 23:59:59')
        );

        return $deleted_count;
    }

    /**
     * Apply category mapping based on keywords in post content.
     *
     * @param string $content The post content to analyze.
     * @return array<int> Array of category IDs.
     */
    private function apply_category_mapping(string $content): array
    {
        $category_mappings = $this->settings['category_mapping'] ?? [];
        $assigned_categories = [];

        if (empty($category_mappings)) {
            return $assigned_categories;
        }

        foreach ($category_mappings as $mapping) {
            if (empty($mapping['keyword']) || empty($mapping['category'])) {
                continue;
            }

            $keyword = strtolower(trim($mapping['keyword']));
            $content_lower = strtolower($content);

            // Check if keyword exists in content
            if (strpos($content_lower, $keyword) !== false) {
                $category_id = intval($mapping['category']);
                if ($category_id > 0 && ! in_array($category_id, $assigned_categories)) {
                    $assigned_categories[] = $category_id;
                }
            }
        }

        return $assigned_categories;
    }
}
