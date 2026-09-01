<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `invoices` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `number` varchar(32) NOT NULL,
  `table_session_id` bigint(20) unsigned DEFAULT NULL,
  `table_number_snapshot` varchar(50) DEFAULT NULL COMMENT 'Snapshot of the table number at invoice issuance. Survives table rename/delete.',
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `issued_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `service_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `delivery_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tip` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `refunded_total` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT 'Denormalized sum of completed refunds — keeps reports fast',
  `credited_total` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT 'Posted credit notes that reduce the sale value',
  `written_off_total` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT 'Posted debt write-offs that reduce collectible A/R',
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','issued','paid','partially_paid','cancelled','unpaid_writeoff') NOT NULL DEFAULT 'draft',
  `is_opening_balance` tinyint(1) NOT NULL DEFAULT 0,
  `customer_name` varchar(191) DEFAULT NULL,
  `customer_phone` varchar(20) DEFAULT NULL,
  `loyalty_customer_id` bigint(20) unsigned DEFAULT NULL,
  `loyalty_points_earned` int(10) unsigned NOT NULL DEFAULT 0,
  `loyalty_points_redeemed` int(10) unsigned NOT NULL DEFAULT 0,
  `loyalty_discount` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `notes` text DEFAULT NULL,
  `issued_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `settled_on_account_at` timestamp NULL DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `payment_terms_days` smallint(5) unsigned DEFAULT NULL,
  `settled_on_account_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoices_number_unique` (`number`),
  UNIQUE KEY `invoices_order_id_unique` (`order_id`),
  UNIQUE KEY `invoices_sync_uuid_unique` (`uuid`),
  KEY `invoices_table_session_id_foreign` (`table_session_id`),
  KEY `invoices_issued_by_user_id_foreign` (`issued_by_user_id`),
  KEY `invoices_loyalty_customer_id_foreign` (`loyalty_customer_id`),
  KEY `invoices_status_index` (`status`),
  KEY `invoices_branch_id_index` (`branch_id`),
  KEY `invoices_customer_id_index` (`customer_id`),
  KEY `invoices_branch_status_issued_at_idx` (`branch_id`,`status`,`issued_at`),
  KEY `invoices_settled_on_account_by_user_id_foreign` (`settled_on_account_by_user_id`),
  KEY `invoices_customer_settled_idx` (`customer_id`,`settled_on_account_at`),
  KEY `invoices_customer_due_idx` (`customer_id`,`due_date`),
  CONSTRAINT `invoices_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `invoices_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_issued_by_user_id_foreign` FOREIGN KEY (`issued_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_loyalty_customer_id_foreign` FOREIGN KEY (`loyalty_customer_id`) REFERENCES `loyalty_customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_settled_on_account_by_user_id_foreign` FOREIGN KEY (`settled_on_account_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_table_session_id_foreign` FOREIGN KEY (`table_session_id`) REFERENCES `table_sessions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};

