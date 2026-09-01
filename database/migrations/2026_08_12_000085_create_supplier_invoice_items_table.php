<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `supplier_invoice_items` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `supplier_invoice_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(26) DEFAULT NULL,
  `supplier_invoice_id` bigint(20) unsigned NOT NULL,
  `purchase_order_item_id` bigint(20) unsigned DEFAULT NULL,
  `ingredient_id` bigint(20) unsigned DEFAULT NULL,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `ingredient_unit_id` bigint(20) unsigned DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` decimal(15,4) NOT NULL DEFAULT 1.0000,
  `unit_price` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `subtotal` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `tax_total` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `total` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `received_qty` decimal(15,4) DEFAULT NULL,
  `received_total` decimal(12,4) DEFAULT NULL,
  `received_base_total` decimal(18,4) DEFAULT NULL,
  `variance_qty` decimal(15,4) DEFAULT NULL,
  `variance_total` decimal(12,4) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `supplier_invoice_items_sync_uuid_unique` (`uuid`),
  KEY `supplier_invoice_items_unit_id_foreign` (`unit_id`),
  KEY `supplier_invoice_items_supplier_invoice_id_index` (`supplier_invoice_id`),
  KEY `supplier_invoice_items_purchase_order_item_id_index` (`purchase_order_item_id`),
  KEY `supplier_invoice_items_ingredient_id_index` (`ingredient_id`),
  KEY `supplier_invoice_items_ingredient_unit_id_foreign` (`ingredient_unit_id`),
  CONSTRAINT `supplier_invoice_items_ingredient_id_foreign` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `supplier_invoice_items_ingredient_unit_id_foreign` FOREIGN KEY (`ingredient_unit_id`) REFERENCES `ingredient_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `supplier_invoice_items_purchase_order_item_id_foreign` FOREIGN KEY (`purchase_order_item_id`) REFERENCES `purchase_order_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `supplier_invoice_items_supplier_invoice_id_foreign` FOREIGN KEY (`supplier_invoice_id`) REFERENCES `supplier_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `supplier_invoice_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_items');
    }
};

