<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `payments` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `method` enum('cash','card','transfer','app','credit','palpay','jawwal_pay','customer_advance') NOT NULL DEFAULT 'cash',
  `amount` decimal(12,2) NOT NULL,
  `status` enum('posted','voided') NOT NULL DEFAULT 'posted',
  `reference` varchar(191) DEFAULT NULL COMMENT 'Card last 4 / transaction id',
  `received_by_user_id` bigint(20) unsigned NOT NULL,
  `notes` text DEFAULT NULL,
  `paid_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `voided_at` timestamp NULL DEFAULT NULL,
  `voided_by` bigint(20) unsigned DEFAULT NULL,
  `void_reason` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payments_sync_uuid_unique` (`uuid`),
  KEY `payments_invoice_id_foreign` (`invoice_id`),
  KEY `payments_received_by_user_id_foreign` (`received_by_user_id`),
  KEY `payments_method_index` (`method`),
  KEY `payments_paid_at_invoice_idx` (`paid_at`,`invoice_id`),
  KEY `payments_branch_paid_at_idx` (`branch_id`,`paid_at`),
  KEY `payments_invoice_id_status_index` (`invoice_id`,`status`),
  KEY `payments_voided_by_foreign` (`voided_by`),
  CONSTRAINT `payments_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `payments_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_received_by_user_id_foreign` FOREIGN KEY (`received_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `payments_voided_by_foreign` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
