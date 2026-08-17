<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Backups
|--------------------------------------------------------------------------
|
| CLAUDE.md: nightly pg_dump, encrypted, off-box, restore tested before
| launch. Read docs/BACKUP.md before changing anything here — particularly
| before deciding where the key lives.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Encryption key
    |--------------------------------------------------------------------------
    |
    | 32 bytes, base64. Generate with `php artisan backup:key`.
    |
    | **This key must not be stored with the backups.** A key sitting in the
    | same bucket as the file it opens is a longer filename, not encryption.
    | Backups are refused outright when this is empty, because writing them in
    | the clear and printing a warning produces exactly the file we were trying
    | to avoid and the warning scrolls past.
    |
    | Losing it means losing every backup. See docs/BACKUP.md.
    |
    */
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Where backups are written
    |--------------------------------------------------------------------------
    |
    | A filesystem disk name. `local` writes to this machine, which is **not a
    | backup** in the sense that matters: the machine is the thing that fails.
    | A run to a local disk is recorded with `offsite = false` and the health
    | check treats it as unprotected, deliberately and visibly.
    |
    | Configure an off-box disk in config/filesystems.php — object storage, or
    | a remote mount — and name it here. `local_is_offsite` exists only for the
    | case where the local path is itself a mount of something remote; setting
    | it to true when it is not is a lie the health check will believe.
    |
    */
    'disk' => env('BACKUP_DISK', 'local'),
    'path' => env('BACKUP_PATH', 'backups'),
    'local_is_offsite' => (bool) env('BACKUP_LOCAL_IS_OFFSITE', false),

    /*
    |--------------------------------------------------------------------------
    | What goes in
    |--------------------------------------------------------------------------
    |
    | The database always. `files` covers storage/app/private, which holds the
    | raw price list uploads kept forever and the faktur pajak files as filed —
    | evidence that cannot be regenerated from the database, so a
    | database-only backup quietly loses it.
    |
    */
    'include_files' => (bool) env('BACKUP_INCLUDE_FILES', true),
    'files_root' => storage_path('app/private'),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Nightly forever fills any disk, and a full disk stops the backups — the
    | failure mode where the thing that was meant to save you is the thing that
    | broke. Old runs are pruned after a successful, verified new one, never
    | before: pruning first would mean a bad night costs the good copy too.
    |
    */
    'keep_daily' => (int) env('BACKUP_KEEP_DAILY', 14),
    'keep_monthly' => (int) env('BACKUP_KEEP_MONTHLY', 12),

    /*
    |--------------------------------------------------------------------------
    | Health
    |--------------------------------------------------------------------------
    |
    | Hours after which the dashboard says the backups have gone stale. The
    | commonest backup failure is silent — cron stops, credentials expire, the
    | disk fills — and nobody notices for months, so this number is what turns
    | that into something somebody sees.
    |
    */
    'stale_after_hours' => (int) env('BACKUP_STALE_AFTER_HOURS', 30),

    /*
    |--------------------------------------------------------------------------
    | Tools
    |--------------------------------------------------------------------------
    */
    'pg_dump' => env('BACKUP_PG_DUMP', 'pg_dump'),
    'psql' => env('BACKUP_PSQL', 'psql'),
    'timeout_seconds' => (int) env('BACKUP_TIMEOUT_SECONDS', 3600),

];
