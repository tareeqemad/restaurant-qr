<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `currency_exchange_rates` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `currency_exchange_rates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `currency_code` varchar(5) NOT NULL,
  `base_currency_code` varchar(5) NOT NULL,
  `rate` decimal(18,8) NOT NULL COMMENT 'Multiply currency amount by this rate to get base-currency amount',
  `valid_from` date NOT NULL,
  `valid_to` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `source` varchar(30) NOT NULL DEFAULT 'manual',
  `note` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `currency_exchange_rates_uuid_unique` (`uuid`),
  UNIQUE KEY `currency_exchange_rates_sync_uuid_unique` (`uuid`),
  KEY `currency_exchange_rates_created_by_foreign` (`created_by`),
  KEY `currency_rates_lookup_idx` (`currency_code`,`base_currency_code`,`valid_from`,`valid_to`),
  KEY `currency_rates_active_idx` (`currency_code`,`base_currency_code`,`is_active`),
  CONSTRAINT `currency_exchange_rates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_exchange_rates');
    }
};

