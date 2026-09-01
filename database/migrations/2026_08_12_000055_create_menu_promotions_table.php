<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `menu_promotions` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `menu_promotions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(200) NOT NULL COMMENT 'Human-readable name shown on the dashboard + audit log.',
  `description` varchar(500) DEFAULT NULL COMMENT 'Optional context for the manager (used on the receipt too).',
  `type` enum('sale_price','percent','fixed_off') NOT NULL COMMENT 'How `value` should be interpreted.',
  `value` decimal(12,2) NOT NULL COMMENT 'sale_price → absolute new price | percent → 0..100 | fixed_off → currency amount',
  `min_subtotal` decimal(12,2) DEFAULT NULL COMMENT 'Minimum order subtotal before promo applies. Null = no minimum.',
  `usage_limit` int(11) DEFAULT NULL COMMENT 'Null = unlimited; otherwise the max distinct orders that can claim this promo.',
  `usage_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Atomic counter; incremented exactly once per order that claims this promo.',
  `target_type` enum('menu_item','category') NOT NULL,
  `target_id` bigint(20) unsigned NOT NULL COMMENT 'References menu_items.id OR categories.id depending on target_type.',
  `starts_at` timestamp NULL DEFAULT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `time_from` time DEFAULT NULL COMMENT 'Time-of-day window start (e.g. 15:00) — together with time_to gives happy-hour.',
  `time_to` time DEFAULT NULL,
  `days_of_week` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Array of weekday ints 0=Sunday .. 6=Saturday. Null = every day.' CHECK (json_valid(`days_of_week`)),
  `channels` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Array of allowed order_source values. Null = all channels.' CHECK (json_valid(`channels`)),
  `excluded_item_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'For category-targeted promos: menu items that opt out of the discount. Null = none.' CHECK (json_valid(`excluded_item_ids`)),
  `audience` varchar(30) NOT NULL DEFAULT 'everyone' COMMENT 'everyone | birthday_month — picks the customer-side filter.',
  `free_modifier_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Modifier ids that go free on the parent item this promo targets (cheese-with-burger).' CHECK (json_valid(`free_modifier_ids`)),
  `bxgy_buy_qty` int(11) DEFAULT NULL COMMENT '"Buy X" portion of a Buy-N-Get-M promo. Null = not a BXGY promo.',
  `bxgy_get_qty` int(11) DEFAULT NULL COMMENT '"Get Y free" portion of a Buy-N-Get-M promo.',
  `active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Master kill-switch; admin can pause without editing the schedule.',
  `priority` smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT 'Higher beats lower when multiple promos match. Default 0.',
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `menu_promotions_sync_uuid_unique` (`uuid`),
  KEY `menu_promotions_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `menu_promos_target_active_idx` (`target_type`,`target_id`,`active`),
  KEY `menu_promos_branch_active_idx` (`branch_id`,`active`),
  CONSTRAINT `menu_promotions_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `menu_promotions_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_promotions');
    }
};

