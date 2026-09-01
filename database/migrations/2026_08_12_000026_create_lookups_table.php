<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `lookups` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `lookups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `group` varchar(60) NOT NULL COMMENT 'e.g. expense_categories',
  `code` varchar(60) DEFAULT NULL COMMENT 'stable text key for system rows; informational for user rows',
  `label` varchar(120) NOT NULL,
  `color` varchar(30) DEFAULT NULL COMMENT 'hex or bootstrap color name',
  `icon` varchar(60) DEFAULT NULL COMMENT 'Bootstrap Icons class, e.g. bi-lightning',
  `display_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_system` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'seeded by code; cannot be hard-deleted',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lookups_group_branch_code_uniq` (`group`,`branch_id`,`code`),
  UNIQUE KEY `lookups_sync_uuid_unique` (`uuid`),
  KEY `lookups_branch_id_foreign` (`branch_id`),
  KEY `lookups_group_active_order_idx` (`group`,`is_active`,`display_order`),
  KEY `lookups_group_branch_active_order_idx` (`group`,`branch_id`,`is_active`,`display_order`),
  CONSTRAINT `lookups_group_foreign` FOREIGN KEY (`group`) REFERENCES `lookup_groups` (`code`) ON UPDATE CASCADE,
  CONSTRAINT `lookups_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lookups');
    }
};
