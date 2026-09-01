<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Complete pre-production schema for auditable partial and full debt write-offs. */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `debt_writeoffs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `number` varchar(40) NOT NULL,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(12,4) NOT NULL,
  `status` enum('posted','reversed') NOT NULL DEFAULT 'posted',
  `reason` text NOT NULL,
  `notes` text DEFAULT NULL,
  `written_off_by` bigint(20) unsigned DEFAULT NULL,
  `written_off_at` timestamp NOT NULL,
  `reversed_by` bigint(20) unsigned DEFAULT NULL,
  `reversed_at` timestamp NULL DEFAULT NULL,
  `reversal_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `debt_writeoffs_number_unique` (`number`),
  UNIQUE KEY `debt_writeoffs_sync_uuid_unique` (`uuid`),
  KEY `debt_writeoffs_invoice_status_idx` (`invoice_id`,`status`),
  KEY `debt_writeoffs_branch_written_idx` (`branch_id`,`written_off_at`),
  KEY `debt_writeoffs_written_by_foreign` (`written_off_by`),
  KEY `debt_writeoffs_reversed_by_foreign` (`reversed_by`),
  CONSTRAINT `debt_writeoffs_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `debt_writeoffs_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`),
  CONSTRAINT `debt_writeoffs_written_by_foreign` FOREIGN KEY (`written_off_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `debt_writeoffs_reversed_by_foreign` FOREIGN KEY (`reversed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_writeoffs');
    }
};
