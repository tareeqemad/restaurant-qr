<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `cash_reconciliations` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `cash_reconciliations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `accounting_period_id` bigint(20) unsigned DEFAULT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `statement_date` date NOT NULL,
  `book_balance` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `statement_balance` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `difference` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `status` enum('matched','variance','resolved') NOT NULL DEFAULT 'matched',
  `resolution_journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `resolved_by` bigint(20) unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
  `reconciled_at` timestamp NULL DEFAULT NULL,
  `reconciled_by` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cash_reconciliations_uuid_unique` (`uuid`),
  UNIQUE KEY `cash_reconciliations_sync_uuid_unique` (`uuid`),
  KEY `cash_reconciliations_accounting_period_id_foreign` (`accounting_period_id`),
  KEY `cash_reconciliations_reconciled_by_foreign` (`reconciled_by`),
  KEY `cash_reconciliations_resolution_entry_foreign` (`resolution_journal_entry_id`),
  KEY `cash_reconciliations_resolved_by_foreign` (`resolved_by`),
  KEY `cash_reconciliations_branch_id_statement_date_index` (`branch_id`,`statement_date`),
  KEY `cash_reconciliations_account_id_statement_date_index` (`account_id`,`statement_date`),
  CONSTRAINT `cash_reconciliations_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `cash_reconciliations_accounting_period_id_foreign` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cash_reconciliations_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cash_reconciliations_reconciled_by_foreign` FOREIGN KEY (`reconciled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cash_reconciliations_resolution_entry_foreign` FOREIGN KEY (`resolution_journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cash_reconciliations_resolved_by_foreign` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_reconciliations');
    }
};

