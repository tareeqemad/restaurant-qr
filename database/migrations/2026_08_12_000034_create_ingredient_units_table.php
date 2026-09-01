<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `ingredient_units` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `ingredient_units` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `ingredient_id` bigint(20) unsigned NOT NULL,
  `name` varchar(100) NOT NULL COMMENT 'Display name in Arabic, e.g. "كرتون 24 علبة"',
  `factor_to_base` decimal(15,4) NOT NULL COMMENT 'How many base units in 1 of this unit. Example: 24 (cans per carton).',
  `barcode` varchar(64) DEFAULT NULL COMMENT 'EAN/UPC/whatever the supplier prints on the pack.',
  `purchase_price` decimal(12,4) DEFAULT NULL COMMENT 'Last known purchase price PER THIS UNIT (not per base).',
  `sale_price` decimal(12,4) DEFAULT NULL COMMENT 'Counter sale price PER THIS UNIT — for selling packs as-is to walk-ins.',
  `is_default_purchase` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Pre-selected when adding the ingredient to a PO line.',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ing_units_name_uniq` (`ingredient_id`,`name`),
  UNIQUE KEY `ing_units_barcode_uniq` (`barcode`),
  UNIQUE KEY `ingredient_units_sync_uuid_unique` (`uuid`),
  KEY `ingredient_units_ingredient_id_is_default_purchase_index` (`ingredient_id`,`is_default_purchase`),
  CONSTRAINT `ingredient_units_ingredient_id_foreign` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_units');
    }
};

