<?php

declare(strict_types=1);

return [
    /*
     * Paths to the MySQL client binaries. Configurable rather than hardcoded:
     * Laragon puts them under C:\laragon\bin, a Linux VPS under /usr/bin, and
     * the installer will set these per machine (08-operations 1.2).
     */
    'mysql' => [
        'dump_binary' => env('OPES_MYSQLDUMP', 'mysqldump'),
        'client_binary' => env('OPES_MYSQL_CLIENT', 'mysql'),
    ],

    'backup' => [
        // Primary target. The second physical target is configured per school;
        // the health check goes AMBER when only one target is configured,
        // because a backup on the same disk as the database is not a backup.
        'path' => env('OPES_BACKUP_PATH', storage_path('opes-backups')),
        'second_target' => env('OPES_BACKUP_SECOND_TARGET'),

        // GFS retention (08-operations 3.3).
        'keep_daily' => (int) env('OPES_KEEP_DAILY', 7),
        'keep_weekly' => (int) env('OPES_KEEP_WEEKLY', 4),
        'keep_monthly' => (int) env('OPES_KEEP_MONTHLY', 12),
        'keep_yearly' => (int) env('OPES_KEEP_YEARLY', 10),

        // One file verified per run, per 08-operations 3.4: an unbounded
        // verification sweep on a timer was a real shipped bug in the
        // reference implementation.
        'verify_budget_per_run' => (int) env('OPES_VERIFY_BUDGET', 1),
    ],

    'health' => [
        'backup_amber_hours' => (int) env('OPES_BACKUP_AMBER_HOURS', 26),
        'backup_red_hours' => (int) env('OPES_BACKUP_RED_HOURS', 50),
        'drill_amber_days' => (int) env('OPES_DRILL_AMBER_DAYS', 40),
        'drill_red_days' => (int) env('OPES_DRILL_RED_DAYS', 60),
        'disk_amber_percent' => (int) env('OPES_DISK_AMBER_PERCENT', 85),
        'disk_red_percent' => (int) env('OPES_DISK_RED_PERCENT', 95),
        'queue_heartbeat_amber_minutes' => (int) env('OPES_QUEUE_AMBER_MINUTES', 10),
        'queue_heartbeat_red_minutes' => (int) env('OPES_QUEUE_RED_MINUTES', 30),
        'failed_jobs_amber' => (int) env('OPES_FAILED_JOBS_AMBER', 1),
        'failed_jobs_red' => (int) env('OPES_FAILED_JOBS_RED', 25),
    ],
];
