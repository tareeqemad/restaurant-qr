<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Nightly database backup
    |--------------------------------------------------------------------------
    |
    | The local/on-prem server is the source of truth between syncs, so a
    | reliable local dump is the safety net before the cloud sync layer
    | (phases 2–3) exists. `backup:run` writes a gzipped mysqldump under
    | storage/app/<local_path> and, when `disk` is set, also uploads the
    | same file to a Laravel filesystem disk (e.g. an off-site S3 bucket or
    | a mounted cloud share) so a hardware failure at the branch never loses
    | the day's data.
    |
    */

    // Local directory (relative to the `local` filesystem disk root,
    // i.e. storage/app/) where dumps are written and rotated.
    'local_path' => env('BACKUP_LOCAL_PATH', 'backups'),

    // Optional off-site filesystem disk to copy each dump to. Leave null to
    // keep backups local-only. Must be a disk defined in config/filesystems.php
    // (e.g. 's3'). Upload failures are logged but never abort the backup.
    'disk' => env('BACKUP_DISK'),

    // Sub-path on the off-site disk.
    'disk_path' => env('BACKUP_DISK_PATH', 'restaurant-qr-backups'),

    // How many of the most recent dumps to retain locally (older are pruned).
    'keep' => (int) env('BACKUP_KEEP', 14),

    // Path to the mysqldump binary. Override if it is not on $PATH.
    'mysqldump_binary' => env('MYSQLDUMP_BINARY', 'mysqldump'),
];
