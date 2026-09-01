<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `fixed_assets` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `fixed_assets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint(20) unsigned NOT NULL,
  `asset_number` varchar(40) NOT NULL,
  `name` varchar(191) NOT NULL,
  `category` varchar(120) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `vendor_name` varchar(150) DEFAULT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `acquisition_date` date NOT NULL,
  `in_service_date` date NOT NULL,
  `cost` decimal(14,4) NOT NULL,
  `salvage_value` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `foreign_cost` decimal(18,4) NOT NULL,
  `foreign_salvage_value` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `currency_code` varchar(5) NOT NULL,
  `exchange_rate` decimal(18,8) NOT NULL DEFAULT 1.00000000,
  `useful_life_months` int(10) unsigned NOT NULL,
  `depreciation_method` varchar(40) NOT NULL DEFAULT 'straight_line',
  `payment_method` varchar(40) NOT NULL DEFAULT 'bank_transfer',
  `status` varchar(30) NOT NULL DEFAULT 'active',
  `accumulated_depreciation` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `depreciated_through` date DEFAULT NULL,
  `purchase_journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `disposal_journal_entry_id` bigint(20) unsigned DEFAULT NULL,
  `disposed_on` date DEFAULT NULL,
  `disposal_proceeds` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `disposal_payment_method` varchar(40) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fixed_assets_asset_number_unique` (`asset_number`),
  KEY `fixed_assets_supplier_id_foreign` (`supplier_id`),
  KEY `fixed_assets_purchase_journal_entry_id_foreign` (`purchase_journal_entry_id`),
  KEY `fixed_assets_disposal_journal_entry_id_foreign` (`disposal_journal_entry_id`),
  KEY `fixed_assets_created_by_foreign` (`created_by`),
  KEY `fixed_assets_branch_id_status_index` (`branch_id`,`status`),
  KEY `fixed_assets_acquisition_date_in_service_date_index` (`acquisition_date`,`in_service_date`),
  KEY `fixed_assets_depreciated_through_index` (`depreciated_through`),
  CONSTRAINT `fixed_assets_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fixed_assets_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fixed_assets_disposal_journal_entry_id_foreign` FOREIGN KEY (`disposal_journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fixed_assets_purchase_journal_entry_id_foreign` FOREIGN KEY (`purchase_journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fixed_assets_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};

