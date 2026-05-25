<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier's free-text notes captured at verification time. Lives next
 * to `rejection_reason` so the report can show "what the cashier said
 * about this transfer" in one column regardless of outcome.
 *
 * The actual verified amount (in case the cashier corrects what the
 * waiter recorded) is intentionally NOT a column here — it lives on
 * the linked Payment row, which is the single source of truth for
 * money. `pending_transfers.amount` stays the original claim, and
 * `pending_transfers.payment_id → payments.amount` is the verified
 * amount. The report joins both so the discrepancy is visible.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('pending_transfers', function (Blueprint $table) {
            $table->string('verification_notes', 500)->nullable()->after('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('pending_transfers', function (Blueprint $table) {
            $table->dropColumn('verification_notes');
        });
    }
};
