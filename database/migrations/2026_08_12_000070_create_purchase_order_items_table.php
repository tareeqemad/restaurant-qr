<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `purchase_order_items` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `purchase_order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `purchase_order_id` bigint(20) unsigned NOT NULL,
  `ingredient_id` bigint(20) unsigned NOT NULL,
  `unit_id` bigint(20) unsigned NOT NULL,
  `ingredient_unit_id` bigint(20) unsigned DEFAULT NULL,
  `quantity_ordered` decimal(15,4) NOT NULL,
  `unit_price` decimal(12,4) NOT NULL COMMENT 'Price per ordered unit (not base unit)',
  `subtotal` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT 'quantity_ordered × unit_price',
  `quantity_received` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `fully_received_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_order_items_sync_uuid_unique` (`uuid`),
  KEY `purchase_order_items_ingredient_id_foreign` (`ingredient_id`),
  KEY `purchase_order_items_unit_id_foreign` (`unit_id`),
  KEY `purchase_order_items_purchase_order_id_ingredient_id_index` (`purchase_order_id`,`ingredient_id`),
  KEY `purchase_order_items_ingredient_unit_id_foreign` (`ingredient_unit_id`),
  CONSTRAINT `purchase_order_items_ingredient_id_foreign` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`),
  CONSTRAINT `purchase_order_items_ingredient_unit_id_foreign` FOREIGN KEY (`ingredient_unit_id`) REFERENCES `ingredient_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_order_items_purchase_order_id_foreign` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_order_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};

