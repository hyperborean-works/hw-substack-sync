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
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 */
class Hw_Substack_Sync_Deactivator
{
    /**
     * Deactivate the plugin.
     *
     * Clears scheduled cron events.
     */
    public static function deactivate(): void
    {
        // The hook name must match the one created in the Cron class.
        wp_clear_scheduled_hook('hw_substack_sync_event');
        // Defensive: clear any legacy hook name from earlier 1.0.x versions
        wp_clear_scheduled_hook('hw_substack_sync_hourly_event');
    }
}
