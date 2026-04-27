<?php

declare(strict_types=1);

/**
 * Plugin Name:       HW Substack Sync
 * Plugin URI:        https://github.com/hyperborean-works/hw-substack-sync
 * Description:       Syncs a Substack RSS feed into WordPress on a daily cron, with rank_math canonical URL pointing back to the original Substack post and a configurable import batch tag for bulk operations. Fork of Christopher S. Penn's Substack Sync. NO SUPPORT PROVIDED. Use at your own risk.
 * Version:           1.1.0
 * Author:            Hyperborean Works
 * Author URI:        https://github.com/hyperborean-works/hw-substack-sync
 * License:           Apache-2.0
 * License URI:       https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain:       hw-substack-sync
 * Network:           false
 * Requires at least: 6.0
 * Tested up to:      6.6
 * Requires PHP:      8.0
 *
 * ---------------------------------------------------------------------------
 * Attribution (Apache License 2.0)
 *
 * This plugin is a derivative work of "Substack Sync" v1.0.2 by Christopher S. Penn
 * (https://github.com/cspenn/substack-wp-sync), licensed under the Apache License,
 * Version 2.0. Significant changes from the original:
 *
 *   - Cron interval changed from hourly to daily.
 *   - Adds canonical-URL postmeta (`rank_math_canonical_url`) on every imported
 *     post pointing back to the Substack permalink.
 *   - Adds a configurable `_substack_import_batch` postmeta on every imported
 *     post for bulk rollback / cleanup operations.
 *   - Expanded HTML cleanup pass that strips Substack chrome, normalizes oEmbeds,
 *     and rewrites internal substack.com links to local site URLs.
 *   - Renamed namespace, classes, options, table, hooks, and text domain to
 *     `hw-substack-sync` to avoid collision with the upstream plugin.
 *
 * Original copyright retained per the Apache License, Section 4(b).
 * ---------------------------------------------------------------------------
 */

// If this file is called directly, abort.
if (! defined('WPINC')) {
    die;
}

// Define Plugin Constants
define('HW_SUBSTACK_SYNC_VERSION', '1.0.2');
define('HW_SUBSTACK_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));

/**
 * The code that runs during plugin activation.
 */
function activate_hw_substack_sync(): void
{
    require_once HW_SUBSTACK_SYNC_PLUGIN_DIR . 'includes/class-hw-substack-sync-activator.php';
    Hw_Substack_Sync_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_hw_substack_sync(): void
{
    require_once HW_SUBSTACK_SYNC_PLUGIN_DIR . 'includes/class-hw-substack-sync-deactivator.php';
    Hw_Substack_Sync_Deactivator::deactivate();
}

register_activation_hook(__FILE__, 'activate_hw_substack_sync');
register_deactivation_hook(__FILE__, 'deactivate_hw_substack_sync');

// Include All Other Files
require_once HW_SUBSTACK_SYNC_PLUGIN_DIR . 'admin/class-hw-substack-sync-admin.php';
require_once HW_SUBSTACK_SYNC_PLUGIN_DIR . 'includes/class-hw-substack-sync-cron.php';
require_once HW_SUBSTACK_SYNC_PLUGIN_DIR . 'includes/class-hw-substack-sync-processor.php';

// Initialize the classes
new Hw_Substack_Sync_Admin();
new Hw_Substack_Sync_Cron();
