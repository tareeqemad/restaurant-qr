<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `order_item_modifiers` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `order_item_modifiers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `order_item_id` bigint(20) unsigned NOT NULL,
  `modifier_id` bigint(20) unsigned DEFAULT NULL,
  `name_snapshot` varchar(191) NOT NULL,
  `price_delta` decimal(10,2) NOT NULL DEFAULT 0.00,
  `price_delta_original` decimal(12,2) DEFAULT NULL COMMENT 'Original modifier price before any promotion zeroed it. Null = no promo.',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_item_modifiers_sync_uuid_unique` (`uuid`),
  KEY `order_item_modifiers_order_item_id_foreign` (`order_item_id`),
  KEY `order_item_modifiers_modifier_id_foreign` (`modifier_id`),
  CONSTRAINT `order_item_modifiers_modifier_id_foreign` FOREIGN KEY (`modifier_id`) REFERENCES `modifiers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `order_item_modifiers_order_item_id_foreign` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_modifiers');
    }
};

