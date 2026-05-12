<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the moment a chef actually started cooking the order — i.e.,
 * when the order's status transitions to `preparing` (the first item
 * gets marked as being prepared).
 *
 * Why a new column instead of reusing `approved_at`:
 *   - `approved_at`  = waiter / cashier accepted the order (still in queue)
 *   - `prep_started_at` = kitchen actually picked it up and began cooking
 *   The gap between the two is dead time we want to measure (slow kitchen?
 *   short-staffed shift?).
 *
 * Drives:
 *   - Kitchen-board: "elapsed since cooking started" per order/item
 *   - Customer tracker: countdown base (timer starts here, not at
 *     order placement, so customer doesn't watch the queue wait)
 *   - Future: average-time-in-queue reports
 *
 * The existing `estimated_ready_at` column already exists on the orders
 * table — we just start filling it now via OrderTimingService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'prep_started_at')) {
                // Place after approved_at so the cluster of timing columns
                // stays grouped in MySQL's column order (DESCRIBE output).
                $table->timestamp('prep_started_at')
                    ->nullable()
                    ->after('approved_at')
                    ->comment('When the order entered "preparing" status — kitchen pickup time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'prep_started_at')) {
                $table->dropColumn('prep_started_at');
            }
        });
    }
};
