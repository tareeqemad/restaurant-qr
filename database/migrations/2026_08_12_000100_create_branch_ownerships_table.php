<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Many-to-many ownership: one owner can own several branches and a branch
 * can have several owners. Percentages are optional until formally known.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `branch_ownerships` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `business_owner_id` bigint(20) unsigned NOT NULL,
  `ownership_percentage` decimal(5,2) DEFAULT NULL,
  `title` varchar(100) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `is_authorized_signatory` tinyint(1) NOT NULL DEFAULT 0,
  `starts_on` date DEFAULT NULL,
  `ends_on` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `branch_ownerships_sync_uuid_unique` (`uuid`),
  UNIQUE KEY `branch_ownerships_branch_owner_unique` (`branch_id`,`business_owner_id`),
  KEY `branch_ownerships_owner_branch_index` (`business_owner_id`,`branch_id`),
  KEY `branch_ownerships_branch_primary_index` (`branch_id`,`is_primary`),
  CONSTRAINT `branch_ownerships_branch_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `branch_ownerships_owner_foreign` FOREIGN KEY (`business_owner_id`) REFERENCES `business_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_ownerships');
    }
};
