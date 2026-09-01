<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `attendances` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `attendances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `clock_in_at` timestamp NULL DEFAULT NULL,
  `clock_out_at` timestamp NULL DEFAULT NULL,
  `break_minutes` smallint(5) unsigned NOT NULL DEFAULT 0,
  `worked_minutes` int(10) unsigned DEFAULT NULL COMMENT 'computed when clock_out_at is set',
  `notes` varchar(500) DEFAULT NULL,
  `source` enum('self','manager_added','auto') NOT NULL DEFAULT 'self',
  `edited_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attendances_sync_uuid_unique` (`uuid`),
  KEY `attendances_edited_by_user_id_foreign` (`edited_by_user_id`),
  KEY `att_branch_in_idx` (`branch_id`,`clock_in_at`),
  KEY `att_user_in_idx` (`user_id`,`clock_in_at`),
  KEY `att_user_open_idx` (`user_id`,`clock_out_at`),
  CONSTRAINT `attendances_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `attendances_edited_by_user_id_foreign` FOREIGN KEY (`edited_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendances_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};

