<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Complete pre-production schema for immutable sales credit notes. */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `credit_notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `number` varchar(40) NOT NULL,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `kind` enum('refund','debt_adjustment','allowance') NOT NULL DEFAULT 'refund',
  `status` enum('posted','reversed') NOT NULL DEFAULT 'posted',
  `revenue_total` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `tax_total` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `service_total` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `delivery_total` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `tip_total` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `total` decimal(12,4) NOT NULL,
  `reason` text NOT NULL,
  `notes` text DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `issued_by` bigint(20) unsigned DEFAULT NULL,
  `issued_at` timestamp NOT NULL,
  `reversed_by` bigint(20) unsigned DEFAULT NULL,
  `reversed_at` timestamp NULL DEFAULT NULL,
  `reversal_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `credit_notes_number_unique` (`number`),
  UNIQUE KEY `credit_notes_sync_uuid_unique` (`uuid`),
  KEY `credit_notes_invoice_status_idx` (`invoice_id`,`status`),
  KEY `credit_notes_branch_issued_idx` (`branch_id`,`issued_at`),
  KEY `credit_notes_issued_by_foreign` (`issued_by`),
  KEY `credit_notes_reversed_by_foreign` (`reversed_by`),
  CONSTRAINT `credit_notes_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `credit_notes_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`),
  CONSTRAINT `credit_notes_issued_by_foreign` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `credit_notes_reversed_by_foreign` FOREIGN KEY (`reversed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
