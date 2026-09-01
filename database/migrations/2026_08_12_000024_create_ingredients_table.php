<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `ingredients` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `ingredients` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `sku` varchar(64) DEFAULT NULL,
  `name` varchar(191) NOT NULL,
  `base_unit_id` bigint(20) unsigned NOT NULL,
  `measurement_type` varchar(20) DEFAULT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `current_stock` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `reorder_threshold` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `cost_per_unit` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `yield_pct` decimal(5,2) DEFAULT NULL COMMENT 'Usable percentage from received quantity. 70 = 30% loss to trim/cleaning.',
  `track_stock` tinyint(1) NOT NULL DEFAULT 1,
  `tracks_expiry` tinyint(1) NOT NULL DEFAULT 0,
  `default_shelf_life_days` smallint(5) unsigned DEFAULT NULL,
  `is_composite` tinyint(1) NOT NULL DEFAULT 0,
  `composite_yield` decimal(15,4) DEFAULT NULL COMMENT 'Output qty in base unit produced by the sub-recipe (e.g. 280g sauce). Null when not composite.',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ingredients_sku_unique` (`sku`),
  UNIQUE KEY `ingredients_sync_uuid_unique` (`uuid`),
  KEY `ingredients_base_unit_id_foreign` (`base_unit_id`),
  KEY `ingredients_supplier_id_foreign` (`supplier_id`),
  KEY `ingredients_active_index` (`active`),
  KEY `ingredients_active_track_idx` (`active`,`track_stock`),
  KEY `ingredients_stock_expiry_idx` (`track_stock`,`tracks_expiry`),
  CONSTRAINT `ingredients_base_unit_id_foreign` FOREIGN KEY (`base_unit_id`) REFERENCES `units` (`id`),
  CONSTRAINT `ingredients_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};

