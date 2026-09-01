<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `modifier_recipe_items` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `modifier_recipe_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `modifier_id` bigint(20) unsigned NOT NULL,
  `ingredient_id` bigint(20) unsigned NOT NULL,
  `quantity` decimal(15,4) NOT NULL,
  `unit_id` bigint(20) unsigned NOT NULL,
  `ingredient_unit_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mri_unique` (`modifier_id`,`ingredient_id`),
  UNIQUE KEY `modifier_recipe_items_sync_uuid_unique` (`uuid`),
  KEY `modifier_recipe_items_ingredient_id_foreign` (`ingredient_id`),
  KEY `modifier_recipe_items_unit_id_foreign` (`unit_id`),
  KEY `modifier_recipe_items_ingredient_unit_id_foreign` (`ingredient_unit_id`),
  CONSTRAINT `modifier_recipe_items_ingredient_id_foreign` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`),
  CONSTRAINT `modifier_recipe_items_ingredient_unit_id_foreign` FOREIGN KEY (`ingredient_unit_id`) REFERENCES `ingredient_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `modifier_recipe_items_modifier_id_foreign` FOREIGN KEY (`modifier_id`) REFERENCES `modifiers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `modifier_recipe_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_recipe_items');
    }
};

