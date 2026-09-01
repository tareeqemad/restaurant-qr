<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable audit ledger for the base selling price of a menu item.
 *
 * Temporary offers remain in `menu_promotions`; this table records only the
 * catalogue price itself so an old order, a past offer, and a permanent price
 * change never become the same business event.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `menu_item_price_histories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `menu_item_id` bigint(20) unsigned NOT NULL,
  `change_type` enum('initial','base_price_change') NOT NULL DEFAULT 'base_price_change',
  `old_price` decimal(12,2) DEFAULT NULL,
  `new_price` decimal(12,2) NOT NULL,
  `reason` varchar(300) DEFAULT NULL,
  `changed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `menu_item_price_histories_uuid_unique` (`uuid`),
  KEY `menu_item_price_histories_item_changed_index` (`menu_item_id`,`changed_at`),
  KEY `menu_item_price_histories_branch_changed_index` (`branch_id`,`changed_at`),
  KEY `menu_item_price_histories_changed_by_foreign` (`changed_by_user_id`),
  CONSTRAINT `menu_item_price_histories_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `menu_item_price_histories_menu_item_id_foreign` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `menu_item_price_histories_changed_by_foreign` FOREIGN KEY (`changed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }

        // Existing development/demo catalogues receive one honest starting
        // point. Fresh installations are empty here and MenuItem's model event
        // records the initial price when seeders create each item.
        DB::statement(<<<'SQL'
INSERT INTO `menu_item_price_histories`
    (`branch_id`, `menu_item_id`, `change_type`, `old_price`, `new_price`, `reason`, `changed_at`, `created_at`, `updated_at`)
SELECT
    `branch_id`, `id`, 'initial', NULL, `price`, 'تثبيت السعر الموجود عند تفعيل سجل الأسعار', NOW(), NOW(), NOW()
FROM `menu_items`
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_price_histories');
    }
};
