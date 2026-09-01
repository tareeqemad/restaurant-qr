<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auditable ingredient removals requested for an individual order line.
 *
 * The ingredient reference may be retired later, while name_snapshot keeps
 * historical kitchen tickets and receipts understandable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `order_item_ingredient_exclusions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_item_id` bigint(20) unsigned NOT NULL,
  `ingredient_id` bigint(20) unsigned DEFAULT NULL,
  `name_snapshot` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_item_ingredient_exclusions_line_ingredient_unique` (`order_item_id`,`ingredient_id`),
  KEY `order_item_ingredient_exclusions_ingredient_id_foreign` (`ingredient_id`),
  CONSTRAINT `order_item_ingredient_exclusions_ingredient_id_foreign` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `order_item_ingredient_exclusions_order_item_id_foreign` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_ingredient_exclusions');
    }
};

