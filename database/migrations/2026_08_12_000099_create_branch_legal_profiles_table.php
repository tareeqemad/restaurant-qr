<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice/legal identity is branch-specific. Operational branch data remains
 * on `branches`; this profile holds only registration and invoice identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `branch_legal_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `registered_name` varchar(191) DEFAULT NULL,
  `tax_number` varchar(80) DEFAULT NULL,
  `commercial_registration_number` varchar(100) DEFAULT NULL,
  `municipal_license_number` varchar(100) DEFAULT NULL,
  `invoice_phone` varchar(20) DEFAULT NULL,
  `invoice_email` varchar(191) DEFAULT NULL,
  `invoice_address` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `branch_legal_profiles_sync_uuid_unique` (`uuid`),
  UNIQUE KEY `branch_legal_profiles_branch_id_unique` (`branch_id`),
  KEY `branch_legal_profiles_tax_number_index` (`tax_number`),
  KEY `branch_legal_profiles_created_by_index` (`created_by_user_id`),
  KEY `branch_legal_profiles_updated_by_index` (`updated_by_user_id`),
  CONSTRAINT `branch_legal_profiles_branch_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `branch_legal_profiles_created_by_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `branch_legal_profiles_updated_by_foreign` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_legal_profiles');
    }
};
