<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup root folder
    |--------------------------------------------------------------------------
    |
    | Layout:
    |   {path}/daily/YYYY-MM-DD/     → kept BACKUP_RETENTION_DAYS then deleted
    |   {path}/weekly/YYYY-MM-Wn/    → kept forever (W1..W5 of each month)
    |
    */
    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    'daily_subdir' => env('BACKUP_DAILY_SUBDIR', 'daily'),

    'weekly_subdir' => env('BACKUP_WEEKLY_SUBDIR', 'weekly'),

    /*
    |--------------------------------------------------------------------------
    | Daily retention (days)
    |--------------------------------------------------------------------------
    |
    | Only daily/ (and legacy root YYYY-MM-DD folders) are pruned.
    | weekly/ is never pruned by this job.
    |
    */
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Weekly forever archive
    |--------------------------------------------------------------------------
    |
    | After each successful daily backup, the current month-week folder is
    | refreshed (2026-09-W1 … W4/W5). Previous week folders stay forever.
    |
    */
    'weekly_enabled' => filter_var(env('BACKUP_WEEKLY_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Application files to include
    |--------------------------------------------------------------------------
    */
    'files_path' => storage_path('app/public'),

    /*
    |--------------------------------------------------------------------------
    | Schedule time (24h clock, used by Kernel)
    |--------------------------------------------------------------------------
    */
    'daily_at' => env('BACKUP_DAILY_AT', '02:00'),

    /*
    |--------------------------------------------------------------------------
    | Prefer native SQL Server .bak when driver is sqlsrv
    |--------------------------------------------------------------------------
    */
    'use_native_sqlsrv_backup' => filter_var(
        env('BACKUP_USE_NATIVE_SQLSRV', true),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
    |--------------------------------------------------------------------------
    | Optional staging folder writable by the SQL Server service
    |--------------------------------------------------------------------------
    */
    'sqlsrv_staging_path' => env('BACKUP_SQLSRV_STAGING_PATH'),

];
