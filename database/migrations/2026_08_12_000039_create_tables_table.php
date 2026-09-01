<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `tables` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `tables` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `number` varchar(16) NOT NULL COMMENT 'Display number on QR',
  `name` varchar(191) DEFAULT NULL COMMENT 'e.g. بالقرب �
ن النافذة',
  `qr_token` varchar(64) NOT NULL COMMENT 'Immutable per-table token in QR URL',
  `capacity` int(11) NOT NULL DEFAULT 4,
  `zone_lookup_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('available','occupied','reserved','out_of_service') NOT NULL DEFAULT 'available',
  `needs_cleaning_since` timestamp NULL DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tables_branch_number_uniq` (`branch_id`,`number`),
  UNIQUE KEY `tables_qr_token_unique` (`qr_token`),
  UNIQUE KEY `tables_sync_uuid_unique` (`uuid`),
  KEY `tables_zone_lookup_id_foreign` (`zone_lookup_id`),
  KEY `tables_status_index` (`status`),
  KEY `tables_branch_id_index` (`branch_id`),
  KEY `tables_branch_cleaning_idx` (`branch_id`,`needs_cleaning_since`),
  CONSTRAINT `tables_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `tables_zone_lookup_id_foreign` FOREIGN KEY (`zone_lookup_id`) REFERENCES `lookups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};

