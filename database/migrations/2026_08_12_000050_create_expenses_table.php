<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `expenses` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `expenses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `expense_category_id` bigint(20) unsigned NOT NULL,
  `expense_number` varchar(32) NOT NULL,
  `description` varchar(255) NOT NULL,
  `notes` text DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency_code` varchar(3) DEFAULT NULL,
  `exchange_rate` decimal(18,8) NOT NULL DEFAULT 1.00000000,
  `payment_method` enum('cash','card','bank_transfer','cheque','other') NOT NULL DEFAULT 'cash',
  `payment_reference` varchar(100) DEFAULT NULL COMMENT 'cheque #, transfer id, etc.',
  `vendor_name` varchar(150) DEFAULT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `attachment_path` varchar(191) DEFAULT NULL,
  `expense_date` date NOT NULL,
  `status` enum('pending_approval','approved','rejected') NOT NULL DEFAULT 'pending_approval',
  `rejection_reason` varchar(255) DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned NOT NULL,
  `approved_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `expenses_expense_number_unique` (`expense_number`),
  UNIQUE KEY `expenses_sync_uuid_unique` (`uuid`),
  KEY `expenses_expense_category_id_foreign` (`expense_category_id`),
  KEY `expenses_supplier_id_foreign` (`supplier_id`),
  KEY `expenses_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `expenses_approved_by_user_id_foreign` (`approved_by_user_id`),
  KEY `exp_branch_date_idx` (`branch_id`,`expense_date`),
  KEY `exp_branch_status_idx` (`branch_id`,`status`),
  KEY `exp_branch_category_idx` (`branch_id`,`expense_category_id`),
  CONSTRAINT `expenses_approved_by_user_id_foreign` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expenses_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `expenses_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `expenses_expense_category_id_foreign` FOREIGN KEY (`expense_category_id`) REFERENCES `lookups` (`id`),
  CONSTRAINT `expenses_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};

