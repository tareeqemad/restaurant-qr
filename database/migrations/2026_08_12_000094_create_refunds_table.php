<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `refunds` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `refunds` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `number` varchar(40) NOT NULL COMMENT 'Human-readable, e.g. REF-20260421-0001',
  `invoice_id` bigint(20) unsigned NOT NULL,
  `credit_note_id` bigint(20) unsigned DEFAULT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `payment_id` bigint(20) unsigned DEFAULT NULL,
  `amount` decimal(12,4) NOT NULL,
  `method` enum('cash','card','transfer','app','credit','palpay','jawwal_pay','customer_advance','mixed','other') NOT NULL DEFAULT 'cash',
  `reference` varchar(100) DEFAULT NULL COMMENT 'External txn id for card refunds',
  `idempotency_key` varchar(100) DEFAULT NULL,
  `status` enum('pending','completed','cancelled','reversed') NOT NULL DEFAULT 'completed',
  `reason` text NOT NULL,
  `notes` text DEFAULT NULL,
  `processed_by` bigint(20) unsigned DEFAULT NULL,
  `completed_by` bigint(20) unsigned DEFAULT NULL,
  `cancelled_by` bigint(20) unsigned DEFAULT NULL,
  `reversed_by` bigint(20) unsigned DEFAULT NULL,
  `refunded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `reversed_at` timestamp NULL DEFAULT NULL,
  `reversal_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `refunds_number_unique` (`number`),
  UNIQUE KEY `refunds_sync_uuid_unique` (`uuid`),
  UNIQUE KEY `refunds_idempotency_key_unique` (`idempotency_key`),
  KEY `refunds_credit_note_id_foreign` (`credit_note_id`),
  KEY `refunds_payment_id_foreign` (`payment_id`),
  KEY `refunds_processed_by_foreign` (`processed_by`),
  KEY `refunds_completed_by_foreign` (`completed_by`),
  KEY `refunds_cancelled_by_foreign` (`cancelled_by`),
  KEY `refunds_reversed_by_foreign` (`reversed_by`),
  KEY `refunds_invoice_id_status_index` (`invoice_id`,`status`),
  KEY `refunds_refunded_at_index` (`refunded_at`),
  KEY `refunds_status_index` (`status`),
  KEY `refunds_branch_refunded_at_idx` (`branch_id`,`refunded_at`),
  CONSTRAINT `refunds_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `refunds_credit_note_id_foreign` FOREIGN KEY (`credit_note_id`) REFERENCES `credit_notes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `refunds_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `refunds_payment_id_foreign` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `refunds_processed_by_foreign` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `refunds_completed_by_foreign` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `refunds_cancelled_by_foreign` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `refunds_reversed_by_foreign` FOREIGN KEY (`reversed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};

