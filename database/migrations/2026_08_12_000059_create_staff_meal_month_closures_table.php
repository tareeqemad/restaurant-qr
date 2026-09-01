<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `staff_meal_month_closures` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `staff_meal_month_closures` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `month` date NOT NULL COMMENT 'First day of the month being closed.',
  `method` varchar(30) NOT NULL COMMENT 'payroll_deduction | cash | writeoff — applied to every charge in the batch.',
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `staff_count` int(10) unsigned NOT NULL DEFAULT 0,
  `charge_count` int(10) unsigned NOT NULL DEFAULT 0,
  `closed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `closed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` varchar(1000) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `staff_closures_branch_month_uq` (`branch_id`,`month`),
  UNIQUE KEY `staff_meal_month_closures_sync_uuid_unique` (`uuid`),
  KEY `staff_meal_month_closures_closed_by_user_id_foreign` (`closed_by_user_id`),
  KEY `staff_meal_month_closures_month_index` (`month`),
  CONSTRAINT `staff_meal_month_closures_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `staff_meal_month_closures_closed_by_user_id_foreign` FOREIGN KEY (`closed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_meal_month_closures');
    }
};

