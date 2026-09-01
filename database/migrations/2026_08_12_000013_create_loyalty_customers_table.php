<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `loyalty_customers` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `loyalty_customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `phone` varchar(30) NOT NULL,
  `name` varchar(191) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `points_balance` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Currently redeemable',
  `lifetime_points` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Total earned — drives tier',
  `tier` varchar(20) NOT NULL DEFAULT 'bronze',
  `last_visit_at` timestamp NULL DEFAULT NULL,
  `total_visits` int(11) NOT NULL DEFAULT 0,
  `total_spent` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `loyalty_customers_phone_unique` (`phone`),
  UNIQUE KEY `loyalty_customers_sync_uuid_unique` (`uuid`),
  KEY `loyalty_customers_tier_index` (`tier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_customers');
    }
};

