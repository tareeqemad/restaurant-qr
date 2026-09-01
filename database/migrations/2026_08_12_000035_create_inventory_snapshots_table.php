<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `inventory_snapshots` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `inventory_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `ingredient_id` bigint(20) unsigned NOT NULL,
  `taken_on` date NOT NULL,
  `quantity_in_base` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `cost_value` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT 'quantity_in_base × cost_per_unit at snapshot time.',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_snapshot_uniq` (`ingredient_id`,`branch_id`,`taken_on`),
  UNIQUE KEY `inventory_snapshots_sync_uuid_unique` (`uuid`),
  KEY `inventory_snapshots_branch_id_foreign` (`branch_id`),
  KEY `inventory_snapshots_taken_on_index` (`taken_on`),
  CONSTRAINT `inventory_snapshots_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_snapshots_ingredient_id_foreign` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_snapshots');
    }
};

