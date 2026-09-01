<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `customers` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `name` varchar(191) NOT NULL,
  `phone` varchar(32) NOT NULL COMMENT 'canonical customer identity',
  `email` varchar(191) DEFAULT NULL,
  `avatar` varchar(191) DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `gender` enum('male','female','unspecified') NOT NULL DEFAULT 'unspecified',
  `preferences` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'dietary tags, favourite items, seating preference, etc.' CHECK (json_valid(`preferences`)),
  `default_branch_id` bigint(20) unsigned DEFAULT NULL,
  `credit_limit` decimal(12,2) DEFAULT NULL,
  `advance_balance` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'cached balance backed by customer_advance_transactions',
  `loyalty_customer_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('active','blocked') NOT NULL DEFAULT 'active',
  `blocked_reason` varchar(191) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customers_phone_unique` (`phone`),
  UNIQUE KEY `customers_email_unique` (`email`),
  UNIQUE KEY `customers_sync_uuid_unique` (`uuid`),
  KEY `customers_loyalty_customer_id_foreign` (`loyalty_customer_id`),
  KEY `customers_status_index` (`status`),
  KEY `customers_default_branch_id_index` (`default_branch_id`),
  CONSTRAINT `customers_default_branch_id_foreign` FOREIGN KEY (`default_branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `customers_loyalty_customer_id_foreign` FOREIGN KEY (`loyalty_customer_id`) REFERENCES `loyalty_customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
