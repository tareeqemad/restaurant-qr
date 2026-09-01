<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `customer_advance_transactions` table.
 *
 * The customer row keeps a fast cached balance, while this immutable ledger
 * preserves who moved it, at which branch, and against which invoice/payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `customer_advance_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `payment_id` bigint(20) unsigned DEFAULT NULL COMMENT 'immutable audit pointer to a posted or voided payment',
  `refund_id` bigint(20) unsigned DEFAULT NULL,
  `reversed_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `type` enum('deposit','redemption','deposit_reversal','redemption_reversal','opening_balance','refund_credit','refund_credit_reversal') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `balance_after` decimal(12,2) NOT NULL,
  `payment_method` enum('cash','card','transfer','palpay','jawwal_pay') DEFAULT NULL,
  `reference` varchar(191) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned NOT NULL,
  `occurred_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_advance_transactions_uuid_unique` (`uuid`),
  KEY `customer_advance_transactions_customer_id_id_index` (`customer_id`,`id`),
  KEY `customer_advance_transactions_branch_occurred_index` (`branch_id`,`occurred_at`),
  KEY `customer_advance_transactions_invoice_id_index` (`invoice_id`),
  KEY `customer_advance_transactions_payment_id_index` (`payment_id`),
  KEY `customer_advance_transactions_refund_id_index` (`refund_id`),
  KEY `customer_advance_transactions_reversed_id_index` (`reversed_transaction_id`),
  CONSTRAINT `customer_advance_transactions_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `customer_advance_transactions_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `customer_advance_transactions_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `customer_advance_transactions_refund_id_foreign` FOREIGN KEY (`refund_id`) REFERENCES `refunds` (`id`) ON DELETE SET NULL,
  CONSTRAINT `customer_advance_transactions_reversed_id_foreign` FOREIGN KEY (`reversed_transaction_id`) REFERENCES `customer_advance_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `customer_advance_transactions_created_by_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_advance_transactions');
    }
};
