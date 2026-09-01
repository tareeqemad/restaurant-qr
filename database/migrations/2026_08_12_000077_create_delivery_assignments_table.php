<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `delivery_assignments` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `delivery_assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint(20) unsigned NOT NULL,
  `order_id` bigint(20) unsigned NOT NULL,
  `driver_user_id` bigint(20) unsigned DEFAULT NULL,
  `provider` varchar(32) NOT NULL DEFAULT 'internal',
  `status` enum('pending','assigned','picked_up','en_route','delivered','failed','cancelled') NOT NULL DEFAULT 'pending',
  `recipient_name` varchar(191) DEFAULT NULL,
  `recipient_phone` varchar(32) DEFAULT NULL,
  `address_snapshot` text DEFAULT NULL,
  `delivery_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `distance_km` decimal(8,2) DEFAULT NULL,
  `delivery_notes` text DEFAULT NULL,
  `failure_reason` text DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `picked_up_at` timestamp NULL DEFAULT NULL,
  `en_route_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `delivery_assignments_order_id_unique` (`order_id`),
  KEY `delivery_assignments_branch_id_status_index` (`branch_id`,`status`),
  KEY `delivery_assignments_driver_user_id_status_index` (`driver_user_id`,`status`),
  KEY `delivery_assignments_status_assigned_at_index` (`status`,`assigned_at`),
  CONSTRAINT `delivery_assignments_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `delivery_assignments_driver_user_id_foreign` FOREIGN KEY (`driver_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `delivery_assignments_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_assignments');
    }
};

