<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `pending_transfers` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `pending_transfers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `table_session_id` bigint(20) unsigned NOT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `payment_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `sender_name` varchar(120) NOT NULL,
  `customer_name_snapshot` varchar(120) DEFAULT NULL,
  `customer_phone_snapshot` varchar(32) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `proof_path` varchar(191) DEFAULT NULL,
  `status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `recorded_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `verified_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` varchar(500) DEFAULT NULL,
  `verification_notes` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pending_transfers_sync_uuid_unique` (`uuid`),
  KEY `pending_transfers_invoice_id_foreign` (`invoice_id`),
  KEY `pending_transfers_payment_id_foreign` (`payment_id`),
  KEY `pending_transfers_customer_id_foreign` (`customer_id`),
  KEY `pending_transfers_recorded_by_user_id_foreign` (`recorded_by_user_id`),
  KEY `pending_transfers_verified_by_user_id_foreign` (`verified_by_user_id`),
  KEY `pending_transfers_branch_id_status_index` (`branch_id`,`status`),
  KEY `pending_transfers_table_session_id_index` (`table_session_id`),
  KEY `pending_transfers_created_at_index` (`created_at`),
  CONSTRAINT `pending_transfers_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pending_transfers_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pending_transfers_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pending_transfers_payment_id_foreign` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pending_transfers_recorded_by_user_id_foreign` FOREIGN KEY (`recorded_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `pending_transfers_table_session_id_foreign` FOREIGN KEY (`table_session_id`) REFERENCES `table_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pending_transfers_verified_by_user_id_foreign` FOREIGN KEY (`verified_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_transfers');
    }
};

