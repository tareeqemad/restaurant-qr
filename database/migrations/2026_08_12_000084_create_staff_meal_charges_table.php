<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `staff_meal_charges` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `staff_meal_charges` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Legacy linked login; operational ownership is employee_id.',
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL COMMENT 'Employee-due portion only; nominal consumption stays frozen on the linked order.',
  `charged_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `settled_at` timestamp NULL DEFAULT NULL COMMENT 'Set when the employee paid back (cash) or the manager wrote it off.',
  `settled_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `settlement_method` varchar(20) DEFAULT NULL COMMENT 'allowance | gift | cash | payroll_deduction | writeoff.',
  `month_closure_id` bigint(20) unsigned DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `staff_meal_charges_sync_uuid_unique` (`uuid`),
  KEY `staff_meal_charges_branch_id_foreign` (`branch_id`),
  KEY `staff_meal_charges_order_id_foreign` (`order_id`),
  KEY `staff_meal_charges_settled_by_user_id_foreign` (`settled_by_user_id`),
  KEY `staff_charges_employee_settled_idx` (`employee_id`,`settled_at`),
  KEY `staff_meal_charges_charged_at_index` (`charged_at`),
  KEY `staff_meal_charges_month_closure_id_foreign` (`month_closure_id`),
  CONSTRAINT `staff_meal_charges_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `staff_meal_charges_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `staff_meal_charges_month_closure_id_foreign` FOREIGN KEY (`month_closure_id`) REFERENCES `staff_meal_month_closures` (`id`) ON DELETE SET NULL,
  CONSTRAINT `staff_meal_charges_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `staff_meal_charges_settled_by_user_id_foreign` FOREIGN KEY (`settled_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `staff_meal_charges_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_meal_charges');
    }
};

