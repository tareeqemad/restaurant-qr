<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `inventory_movements` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `inventory_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `ingredient_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `storage_location_id` bigint(20) unsigned DEFAULT NULL,
  `type` enum('in','out','waste','adjustment','return') NOT NULL COMMENT 'in=purchase/return, out=order-usage, waste=spoilage, adjustment=manual',
  `quantity` decimal(15,4) NOT NULL COMMENT 'Positive always; direction determined by type',
  `unit_id` bigint(20) unsigned NOT NULL,
  `quantity_in_base` decimal(15,4) NOT NULL COMMENT 'Normalized to ingredient base unit',
  `unit_cost` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `total_cost` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `stock_before` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `stock_after` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `reference_type` varchar(191) DEFAULT NULL,
  `reference_id` bigint(20) unsigned DEFAULT NULL,
  `reason` varchar(191) DEFAULT NULL,
  `waste_reason` varchar(32) DEFAULT NULL,
  `waste_reason_lookup_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `occurred_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inventory_movements_sync_uuid_unique` (`uuid`),
  KEY `inventory_movements_batch_id_foreign` (`batch_id`),
  KEY `inventory_movements_storage_location_id_foreign` (`storage_location_id`),
  KEY `inventory_movements_unit_id_foreign` (`unit_id`),
  KEY `inventory_movements_user_id_foreign` (`user_id`),
  KEY `inventory_movements_reference_type_reference_id_index` (`reference_type`,`reference_id`),
  KEY `inventory_movements_ingredient_id_occurred_at_index` (`ingredient_id`,`occurred_at`),
  KEY `inventory_movements_type_index` (`type`),
  KEY `inventory_movements_branch_id_index` (`branch_id`),
  KEY `movements_waste_reason_idx` (`type`,`waste_reason`),
  KEY `inventory_movements_waste_reason_lookup_id_foreign` (`waste_reason_lookup_id`),
  KEY `invmov_branch_type_occurred_idx` (`branch_id`,`type`,`occurred_at`),
  CONSTRAINT `inventory_movements_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `ingredient_batches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_movements_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `inventory_movements_ingredient_id_foreign` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`),
  CONSTRAINT `inventory_movements_storage_location_id_foreign` FOREIGN KEY (`storage_location_id`) REFERENCES `storage_locations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_movements_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`),
  CONSTRAINT `inventory_movements_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_movements_waste_reason_lookup_id_foreign` FOREIGN KEY (`waste_reason_lookup_id`) REFERENCES `lookups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};

