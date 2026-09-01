<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `stock_count_items` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `stock_count_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `stock_count_id` bigint(20) unsigned NOT NULL,
  `ingredient_id` bigint(20) unsigned NOT NULL,
  `system_qty` decimal(15,4) NOT NULL COMMENT 'Snapshot of ingredient.current_stock at time count was started',
  `counted_qty` decimal(15,4) DEFAULT NULL COMMENT 'Actual qty counted — null means not yet entered',
  `variance` decimal(15,4) NOT NULL DEFAULT 0.0000 COMMENT 'counted − system',
  `variance_cost` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT 'Monetary value of the variance',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stock_count_items_stock_count_id_ingredient_id_unique` (`stock_count_id`,`ingredient_id`),
  UNIQUE KEY `stock_count_items_sync_uuid_unique` (`uuid`),
  KEY `stock_count_items_ingredient_id_foreign` (`ingredient_id`),
  CONSTRAINT `stock_count_items_ingredient_id_foreign` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`),
  CONSTRAINT `stock_count_items_stock_count_id_foreign` FOREIGN KEY (`stock_count_id`) REFERENCES `stock_counts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_items');
    }
};

