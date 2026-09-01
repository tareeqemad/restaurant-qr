<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `branch_transfer_items` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `branch_transfer_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_transfer_id` bigint(20) unsigned NOT NULL,
  `ingredient_id` bigint(20) unsigned NOT NULL,
  `quantity_base` decimal(15,4) NOT NULL,
  `from_location_id` bigint(20) unsigned DEFAULT NULL,
  `to_location_id` bigint(20) unsigned DEFAULT NULL,
  `unit_cost` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `branch_transfer_items_sync_uuid_unique` (`uuid`),
  KEY `branch_transfer_items_ingredient_id_foreign` (`ingredient_id`),
  KEY `branch_transfer_items_from_location_id_foreign` (`from_location_id`),
  KEY `branch_transfer_items_to_location_id_foreign` (`to_location_id`),
  KEY `bti_transfer_ing_idx` (`branch_transfer_id`,`ingredient_id`),
  CONSTRAINT `branch_transfer_items_branch_transfer_id_foreign` FOREIGN KEY (`branch_transfer_id`) REFERENCES `branch_transfers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `branch_transfer_items_from_location_id_foreign` FOREIGN KEY (`from_location_id`) REFERENCES `storage_locations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `branch_transfer_items_ingredient_id_foreign` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`),
  CONSTRAINT `branch_transfer_items_to_location_id_foreign` FOREIGN KEY (`to_location_id`) REFERENCES `storage_locations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_transfer_items');
    }
};

