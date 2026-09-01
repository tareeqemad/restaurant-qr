<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `customer_addresses` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `customer_addresses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `label` varchar(40) DEFAULT NULL,
  `recipient_name` varchar(191) DEFAULT NULL,
  `recipient_phone` varchar(32) DEFAULT NULL,
  `city` varchar(191) DEFAULT NULL,
  `area` varchar(191) DEFAULT NULL,
  `street` varchar(191) DEFAULT NULL,
  `building` varchar(191) DEFAULT NULL,
  `floor` varchar(40) DEFAULT NULL,
  `apartment` varchar(40) DEFAULT NULL,
  `landmark` varchar(191) DEFAULT NULL,
  `address_line` text DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `delivery_notes` text DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_addresses_sync_uuid_unique` (`uuid`),
  KEY `customer_addresses_customer_id_is_default_index` (`customer_id`,`is_default`),
  KEY `customer_addresses_city_area_index` (`city`,`area`),
  CONSTRAINT `customer_addresses_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};

