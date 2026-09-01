<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `ingredient_batches` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `ingredient_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `ingredient_id` bigint(20) unsigned NOT NULL,
  `storage_location_id` bigint(20) unsigned DEFAULT NULL,
  `batch_number` varchar(80) DEFAULT NULL COMMENT 'Lot/batch number from supplier, if any',
  `received_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL COMMENT 'null = no expiry (e.g., salt, sugar)',
  `initial_qty` decimal(15,4) NOT NULL COMMENT 'Qty received into this batch (base unit)',
  `remaining_qty` decimal(15,4) NOT NULL COMMENT 'Qty left (depleted by FIFO deductions)',
  `unit_cost` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT 'Cost/base-unit for this specific batch',
  `source_type` varchar(60) DEFAULT NULL COMMENT 'e.g., App\\Models\\PurchaseOrderItem',
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ingredient_batches_sync_uuid_unique` (`uuid`),
  KEY `ingredient_batches_ingredient_id_expiry_date_index` (`ingredient_id`,`expiry_date`),
  KEY `ingredient_batches_ingredient_id_received_date_index` (`ingredient_id`,`received_date`),
  KEY `ingredient_batches_source_type_source_id_index` (`source_type`,`source_id`),
  KEY `ingredient_batches_expiry_date_index` (`expiry_date`),
  KEY `ingredient_batches_branch_id_index` (`branch_id`),
  KEY `ingredient_batches_storage_location_id_index` (`storage_location_id`),
  KEY `ingredient_batches_fifo_location_idx` (`ingredient_id`,`storage_location_id`,`expiry_date`),
  KEY `batches_expiry_remaining_idx` (`expiry_date`,`remaining_qty`),
  CONSTRAINT `ingredient_batches_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `ingredient_batches_ingredient_id_foreign` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`),
  CONSTRAINT `ingredient_batches_storage_location_id_foreign` FOREIGN KEY (`storage_location_id`) REFERENCES `storage_locations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_batches');
    }
};

