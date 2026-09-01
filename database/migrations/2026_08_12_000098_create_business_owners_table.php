<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legal owners are business records, not application users. One owner may
 * own several branches without having a login account in the application.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `business_owners` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `owner_type` varchar(20) NOT NULL DEFAULT 'person',
  `name` varchar(191) NOT NULL,
  `national_id` varchar(80) DEFAULT NULL,
  `tax_number` varchar(80) DEFAULT NULL,
  `commercial_registration_number` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `business_owners_sync_uuid_unique` (`uuid`),
  KEY `business_owners_national_id_index` (`national_id`),
  KEY `business_owners_tax_number_index` (`tax_number`),
  KEY `business_owners_registration_index` (`commercial_registration_number`),
  KEY `business_owners_active_name_index` (`is_active`,`name`),
  KEY `business_owners_created_by_index` (`created_by_user_id`),
  CONSTRAINT `business_owners_created_by_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('business_owners');
    }
};
