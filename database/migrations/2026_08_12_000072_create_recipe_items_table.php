<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `recipe_items` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `recipe_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `menu_item_id` bigint(20) unsigned DEFAULT NULL,
  `parent_ingredient_id` bigint(20) unsigned DEFAULT NULL,
  `ingredient_id` bigint(20) unsigned NOT NULL,
  `quantity` decimal(15,4) NOT NULL,
  `unit_id` bigint(20) unsigned NOT NULL,
  `ingredient_unit_id` bigint(20) unsigned DEFAULT NULL,
  `is_optional` tinyint(1) NOT NULL DEFAULT 0,
  `notes` varchar(191) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `recipe_items_menu_item_id_ingredient_id_unique` (`menu_item_id`,`ingredient_id`),
  UNIQUE KEY `recipe_items_sync_uuid_unique` (`uuid`),
  KEY `recipe_items_ingredient_id_foreign` (`ingredient_id`),
  KEY `recipe_items_unit_id_foreign` (`unit_id`),
  KEY `recipe_items_parent_ingredient_idx` (`parent_ingredient_id`,`ingredient_id`),
  KEY `recipe_items_ingredient_unit_id_foreign` (`ingredient_unit_id`),
  CONSTRAINT `recipe_items_ingredient_id_foreign` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`),
  CONSTRAINT `recipe_items_ingredient_unit_id_foreign` FOREIGN KEY (`ingredient_unit_id`) REFERENCES `ingredient_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `recipe_items_menu_item_id_foreign` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `recipe_items_parent_ingredient_id_foreign` FOREIGN KEY (`parent_ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `recipe_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_items');
    }
};

