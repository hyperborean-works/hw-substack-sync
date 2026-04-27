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
 * The cron-specific functionality of the plugin.
 *
 * Defines the cron event and hooks. Default schedule is daily; site owners
 * can override the schedule via the `hw_substack_sync_cron_schedule` filter.
 */
class Hw_Substack_Sync_Cron
{
    const EVENT_HOOK = 'hw_substack_sync_event';

    /**
     * Initialize the class and set its properties.
     */
    public function __construct()
    {
        // The custom hook that will run our sync logic
        add_action(self::EVENT_HOOK, [$this, 'run_sync']);

        // Schedule the event if not already scheduled.
        // First run is deferred 1 hour out so simply activating the plugin
        // (or visiting wp-admin after activation) doesn't auto-sync. Use the
        // "Sync Now" button in settings for an immediate manual run.
        if (! wp_next_scheduled(self::EVENT_HOOK)) {
            $schedule = apply_filters('hw_substack_sync_cron_schedule', 'daily');
            $first_run = apply_filters('hw_substack_sync_first_run_offset', HOUR_IN_SECONDS);
            wp_schedule_event(time() + (int) $first_run, $schedule, self::EVENT_HOOK);
        }
    }

    /**
     * Run the sync process.
     *
     * This is the callback function for the cron job.
     */
    public function run_sync(): void
    {
        // Ensure the processor class is available
        require_once HW_SUBSTACK_SYNC_PLUGIN_DIR . 'includes/class-hw-substack-sync-processor.php';
        $processor = new Hw_Substack_Sync_Processor();
        $processor->run_sync();
    }
}
