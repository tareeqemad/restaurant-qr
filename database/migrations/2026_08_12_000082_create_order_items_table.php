<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `order_items` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `order_id` bigint(20) unsigned NOT NULL,
  `menu_item_id` bigint(20) unsigned NOT NULL,
  `station_id` bigint(20) unsigned DEFAULT NULL,
  `name_snapshot` varchar(191) NOT NULL COMMENT 'Menu item name at time of order',
  `quantity` decimal(8,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(10,2) NOT NULL COMMENT 'Menu item price snapshot',
  `unit_price_original` decimal(12,2) DEFAULT NULL COMMENT 'Menu price before any active promotion. Null = unit_price is the full price.',
  `promotion_id` bigint(20) unsigned DEFAULT NULL,
  `modifiers_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL COMMENT '(unit_price + modifiers) * quantity',
  `notes` text DEFAULT NULL COMMENT 'Customer notes',
  `course` enum('appetizer','main','dessert','drink','other') NOT NULL DEFAULT 'main',
  `fire_order` int(11) NOT NULL DEFAULT 0 COMMENT 'Lower fires first; 0=fire-now',
  `status` enum('pending','approved','preparing','ready','served','cancelled') NOT NULL DEFAULT 'pending',
  `prepared_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `served_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `cancelled_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `cancelled_reason` varchar(191) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `prep_started_at` timestamp NULL DEFAULT NULL,
  `ready_at` timestamp NULL DEFAULT NULL,
  `served_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_items_sync_uuid_unique` (`uuid`),
  KEY `order_items_menu_item_id_foreign` (`menu_item_id`),
  KEY `order_items_prepared_by_user_id_foreign` (`prepared_by_user_id`),
  KEY `order_items_served_by_user_id_foreign` (`served_by_user_id`),
  KEY `order_items_cancelled_by_user_id_foreign` (`cancelled_by_user_id`),
  KEY `order_items_station_id_status_index` (`station_id`,`status`),
  KEY `order_items_order_id_status_index` (`order_id`,`status`),
  KEY `order_items_promotion_id_foreign` (`promotion_id`),
  CONSTRAINT `order_items_cancelled_by_user_id_foreign` FOREIGN KEY (`cancelled_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `order_items_menu_item_id_foreign` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`id`),
  CONSTRAINT `order_items_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_items_prepared_by_user_id_foreign` FOREIGN KEY (`prepared_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `order_items_promotion_id_foreign` FOREIGN KEY (`promotion_id`) REFERENCES `menu_promotions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `order_items_served_by_user_id_foreign` FOREIGN KEY (`served_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `order_items_station_id_foreign` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};

