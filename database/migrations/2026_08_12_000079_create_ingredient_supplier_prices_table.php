<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `ingredient_supplier_prices` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `ingredient_supplier_prices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint(20) unsigned NOT NULL,
  `ingredient_id` bigint(20) unsigned NOT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `unit_id` bigint(20) unsigned NOT NULL,
  `unit_price` decimal(12,4) NOT NULL,
  `currency_code` varchar(3) DEFAULT NULL,
  `exchange_rate` decimal(18,8) NOT NULL DEFAULT 1.00000000,
  `unit_price_in_base` decimal(12,4) NOT NULL,
  `previous_price_in_base` decimal(12,4) DEFAULT NULL,
  `change_pct` decimal(8,2) DEFAULT NULL COMMENT '+ = price up, − = price down. Null on first obs.',
  `purchase_order_id` bigint(20) unsigned DEFAULT NULL,
  `purchase_order_item_id` bigint(20) unsigned DEFAULT NULL,
  `source` enum('receipt','manual','import') NOT NULL DEFAULT 'receipt',
  `recorded_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `observed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ingredient_supplier_prices_unit_id_foreign` (`unit_id`),
  KEY `ingredient_supplier_prices_purchase_order_id_foreign` (`purchase_order_id`),
  KEY `ingredient_supplier_prices_purchase_order_item_id_foreign` (`purchase_order_item_id`),
  KEY `ingredient_supplier_prices_recorded_by_user_id_foreign` (`recorded_by_user_id`),
  KEY `isp_ing_sup_obs_idx` (`ingredient_id`,`supplier_id`,`observed_at`),
  KEY `ingredient_supplier_prices_supplier_id_observed_at_index` (`supplier_id`,`observed_at`),
  KEY `ingredient_supplier_prices_branch_id_observed_at_index` (`branch_id`,`observed_at`),
  CONSTRAINT `ingredient_supplier_prices_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ingredient_supplier_prices_ingredient_id_foreign` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ingredient_supplier_prices_purchase_order_id_foreign` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ingredient_supplier_prices_purchase_order_item_id_foreign` FOREIGN KEY (`purchase_order_item_id`) REFERENCES `purchase_order_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ingredient_supplier_prices_recorded_by_user_id_foreign` FOREIGN KEY (`recorded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ingredient_supplier_prices_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ingredient_supplier_prices_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_supplier_prices');
    }
};

