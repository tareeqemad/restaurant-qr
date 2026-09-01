<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `table_sessions` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `table_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `table_id` bigint(20) unsigned DEFAULT NULL,
  `table_number_snapshot` varchar(50) DEFAULT NULL COMMENT 'Snapshot of the table number at session open. Survives table rename/delete.',
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `token` varchar(64) NOT NULL COMMENT 'Cookie-held session identifier for customer',
  `ordering_device_hash` varchar(64) DEFAULT NULL COMMENT 'SHA-256 identity of the browser that submitted the first QR order',
  `cover_count` int(11) NOT NULL DEFAULT 1,
  `status` enum('active','closed','abandoned') NOT NULL DEFAULT 'active',
  `customer_name` varchar(191) DEFAULT NULL,
  `customer_phone` varchar(20) DEFAULT NULL,
  `opened_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `assigned_waiter_id` bigint(20) unsigned DEFAULT NULL,
  `opened_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `engaged_at` timestamp NULL DEFAULT NULL COMMENT 'First real seating signal: order, waiter call, or staff opening the table.',
  `closed_at` timestamp NULL DEFAULT NULL,
  `last_activity_at` timestamp NULL DEFAULT NULL,
  `bill_requested_at` timestamp NULL DEFAULT NULL,
  `bill_request_note` text DEFAULT NULL,
  `help_requested_at` timestamp NULL DEFAULT NULL,
  `help_request_note` varchar(500) DEFAULT NULL,
  `help_ack_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `table_sessions_token_unique` (`token`),
  UNIQUE KEY `table_sessions_sync_uuid_unique` (`uuid`),
  KEY `table_sessions_opened_by_user_id_foreign` (`opened_by_user_id`),
  KEY `table_sessions_assigned_waiter_id_foreign` (`assigned_waiter_id`),
  KEY `table_sessions_table_id_status_index` (`table_id`,`status`),
  KEY `table_sessions_status_index` (`status`),
  KEY `table_sessions_engaged_at_index` (`engaged_at`),
  KEY `table_sessions_branch_id_index` (`branch_id`),
  KEY `table_sessions_customer_id_index` (`customer_id`),
  KEY `table_sessions_bill_requested_at_index` (`bill_requested_at`),
  KEY `table_sessions_help_ack_by_user_id_foreign` (`help_ack_by_user_id`),
  CONSTRAINT `table_sessions_assigned_waiter_id_foreign` FOREIGN KEY (`assigned_waiter_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `table_sessions_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `table_sessions_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `table_sessions_help_ack_by_user_id_foreign` FOREIGN KEY (`help_ack_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `table_sessions_opened_by_user_id_foreign` FOREIGN KEY (`opened_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `table_sessions_table_id_foreign` FOREIGN KEY (`table_id`) REFERENCES `tables` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('table_sessions');
    }
};

