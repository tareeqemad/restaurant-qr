<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `journal_entries` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `journal_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `entry_no` varchar(40) NOT NULL,
  `posted_on` date NOT NULL,
  `description` varchar(191) NOT NULL,
  `base_currency_code` varchar(5) DEFAULT NULL,
  `currency_code` varchar(5) DEFAULT NULL,
  `exchange_rate` decimal(18,8) NOT NULL DEFAULT 1.00000000,
  `source_type` varchar(191) DEFAULT NULL,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `event_type` varchar(80) DEFAULT NULL,
  `status` enum('posted','void') NOT NULL DEFAULT 'posted',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `journal_entries_entry_no_unique` (`entry_no`),
  UNIQUE KEY `journal_source_event_unique` (`source_type`,`source_id`,`event_type`),
  UNIQUE KEY `journal_entries_sync_uuid_unique` (`uuid`),
  KEY `journal_entries_created_by_foreign` (`created_by`),
  KEY `journal_entries_branch_id_posted_on_index` (`branch_id`,`posted_on`),
  KEY `journal_entries_source_type_source_id_index` (`source_type`,`source_id`),
  CONSTRAINT `journal_entries_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `journal_entries_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};

