<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final pre-production schema for the `sync_states` table.
 *
 * This file intentionally contains the complete table definition. Historical
 * ALTER migrations were retired before first production deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `sync_states` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stream` varchar(191) NOT NULL,
  `direction` varchar(8) NOT NULL,
  `cursor` varchar(191) DEFAULT NULL,
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `last_status` varchar(16) NOT NULL DEFAULT 'pending',
  `last_error` text DEFAULT NULL,
  `last_count` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sync_states_stream_unique` (`stream`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) {
            DB::unprepared(trim($statement));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_states');
    }
};

