<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `orders` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `number` varchar(32) NOT NULL,
  `table_id` bigint(20) unsigned DEFAULT NULL,
  `table_number_snapshot` varchar(50) DEFAULT NULL COMMENT 'Snapshot of the table number at order creation. Survives table rename/delete.',
  `order_source` varchar(20) NOT NULL DEFAULT 'dine_in',
  `table_session_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `staff_consumer_employee_id` bigint(20) unsigned DEFAULT NULL,
  `staff_consumer_user_id` bigint(20) unsigned DEFAULT NULL,
  `customer_name` varchar(191) DEFAULT NULL,
  `customer_phone` varchar(32) DEFAULT NULL,
  `customer_address_id` bigint(20) unsigned DEFAULT NULL,
  `order_type` enum('dine_in','takeaway','delivery') NOT NULL DEFAULT 'dine_in',
  `status` enum('pending','approved','preparing','ready','delivered','completed','cancelled') NOT NULL DEFAULT 'pending',
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `approved_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `cancelled_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `customer_notes` text DEFAULT NULL,
  `delivery_address` text DEFAULT NULL,
  `internal_notes` text DEFAULT NULL,
  `cancelled_reason` text DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `service_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `delivery_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tip` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `service_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `scheduled_for` timestamp NULL DEFAULT NULL,
  `estimated_prep_minutes` smallint(5) unsigned DEFAULT NULL,
  `estimated_ready_at` timestamp NULL DEFAULT NULL,
  `estimated_delivered_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `prep_started_at` timestamp NULL DEFAULT NULL COMMENT 'When the order entered "preparing" status — kitchen pickup time',
  `ready_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `orders_number_unique` (`number`),
  UNIQUE KEY `orders_sync_uuid_unique` (`uuid`),
  KEY `orders_table_session_id_foreign` (`table_session_id`),
  KEY `orders_customer_address_id_foreign` (`customer_address_id`),
  KEY `orders_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `orders_approved_by_user_id_foreign` (`approved_by_user_id`),
  KEY `orders_cancelled_by_user_id_foreign` (`cancelled_by_user_id`),
  KEY `orders_status_index` (`status`),
  KEY `orders_table_id_status_index` (`table_id`,`status`),
  KEY `orders_submitted_at_index` (`submitted_at`),
  KEY `orders_order_source_index` (`order_source`),
  KEY `orders_branch_id_index` (`branch_id`),
  KEY `orders_customer_id_index` (`customer_id`),
  KEY `orders_scheduled_for_index` (`scheduled_for`),
  KEY `orders_branch_status_type_idx` (`branch_id`,`status`,`order_type`),
  KEY `orders_branch_source_idx` (`branch_id`,`order_source`),
  KEY `orders_branch_customer_phone_idx` (`branch_id`,`customer_phone`),
  KEY `orders_branch_created_idx` (`branch_id`,`created_at`),
  KEY `orders_staff_consumer_employee_id_foreign` (`staff_consumer_employee_id`),
  KEY `orders_staff_consumer_user_id_foreign` (`staff_consumer_user_id`),
  CONSTRAINT `orders_approved_by_user_id_foreign` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `orders_cancelled_by_user_id_foreign` FOREIGN KEY (`cancelled_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_customer_address_id_foreign` FOREIGN KEY (`customer_address_id`) REFERENCES `customer_addresses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_staff_consumer_employee_id_foreign` FOREIGN KEY (`staff_consumer_employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_staff_consumer_user_id_foreign` FOREIGN KEY (`staff_consumer_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_table_id_foreign` FOREIGN KEY (`table_id`) REFERENCES `tables` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_table_session_id_foreign` FOREIGN KEY (`table_session_id`) REFERENCES `table_sessions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

