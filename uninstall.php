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

// If uninstall not called from WordPress, then exit.
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Get the plugin options
$options = get_option('hw_substack_sync_settings');

// Check if the user wants to delete data
if (isset($options['delete_data_on_uninstall']) && $options['delete_data_on_uninstall']) {
    // Delete plugin settings
    delete_option('hw_substack_sync_settings');

    // Drop the custom database table
    global $wpdb;
    $table_name = $wpdb->prefix . 'hw_substack_sync_log';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
}
