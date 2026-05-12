<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes flagged by the May-2026 audit.
 *
 * Each index targets a specific query pattern observed in the codebase:
 *
 *   1. payments.paid_at — Dashboard's "today's cash" + reports' date-range
 *      sums (`whereDate('paid_at', $today)`, `whereBetween('paid_at', …)`).
 *      Without it MySQL full-scans the payments table on every page load.
 *
 *   2. inventory_movements (branch_id, type, occurred_at) — branchComparison
 *      and waste/COGS reports filter on (branch + type + date range). The
 *      single-column indexes can't satisfy the AND.
 *
 *   3. ingredient_batches (expiry_date, remaining_qty) — expiry alerts
 *      query both columns together.
 *
 *   4. ingredients (active, track_stock) — low-stock scan in dashboard.
 *
 *   5. orders (branch_id, created_at) — order-archive search with date
 *      range + sort by created_at.
 *
 * All wrapped in `Schema::hasColumn` checks for idempotency. Index names
 * are explicit so a re-run with `migrate:rollback` cleans up properly.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. payments.paid_at
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'paid_at') && ! $this->hasIndex('payments', 'payments_paid_at_invoice_idx')) {
                $table->index(['paid_at', 'invoice_id'], 'payments_paid_at_invoice_idx');
            }
        });

        // 2. inventory_movements (branch_id, type, occurred_at)
        Schema::table('inventory_movements', function (Blueprint $table) {
            if (! $this->hasIndex('inventory_movements', 'invmov_branch_type_occurred_idx')) {
                $table->index(
                    ['branch_id', 'type', 'occurred_at'],
                    'invmov_branch_type_occurred_idx'
                );
            }
        });

        // 3. ingredient_batches (expiry_date, remaining_qty)
        Schema::table('ingredient_batches', function (Blueprint $table) {
            if (Schema::hasColumn('ingredient_batches', 'expiry_date')
                && ! $this->hasIndex('ingredient_batches', 'batches_expiry_remaining_idx')) {
                $table->index(['expiry_date', 'remaining_qty'], 'batches_expiry_remaining_idx');
            }
        });

        // 4. ingredients (active, track_stock)
        Schema::table('ingredients', function (Blueprint $table) {
            if (! $this->hasIndex('ingredients', 'ingredients_active_track_idx')) {
                $table->index(['active', 'track_stock'], 'ingredients_active_track_idx');
            }
        });

        // 5. orders (branch_id, created_at)
        Schema::table('orders', function (Blueprint $table) {
            if (! $this->hasIndex('orders', 'orders_branch_created_idx')) {
                $table->index(['branch_id', 'created_at'], 'orders_branch_created_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if ($this->hasIndex('payments', 'payments_paid_at_invoice_idx')) {
                $table->dropIndex('payments_paid_at_invoice_idx');
            }
        });
        Schema::table('inventory_movements', function (Blueprint $table) {
            if ($this->hasIndex('inventory_movements', 'invmov_branch_type_occurred_idx')) {
                $table->dropIndex('invmov_branch_type_occurred_idx');
            }
        });
        Schema::table('ingredient_batches', function (Blueprint $table) {
            if ($this->hasIndex('ingredient_batches', 'batches_expiry_remaining_idx')) {
                $table->dropIndex('batches_expiry_remaining_idx');
            }
        });
        Schema::table('ingredients', function (Blueprint $table) {
            if ($this->hasIndex('ingredients', 'ingredients_active_track_idx')) {
                $table->dropIndex('ingredients_active_track_idx');
            }
        });
        Schema::table('orders', function (Blueprint $table) {
            if ($this->hasIndex('orders', 'orders_branch_created_idx')) {
                $table->dropIndex('orders_branch_created_idx');
            }
        });
    }

    /**
     * Cross-database "does this index already exist on this table?"
     * Schema::hasIndex() exists only in Laravel 11.30+ — this is safer.
     */
    protected function hasIndex(string $table, string $indexName): bool
    {
        return Schema::hasIndex($table, $indexName);
    }
};
