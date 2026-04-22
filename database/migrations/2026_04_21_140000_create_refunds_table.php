<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds — money paid back to customers after a payment was made.
 *
 * A refund is linked to an invoice (always) and optionally to a specific
 * payment (when refunding a particular transaction). When issued:
 *   - invoice.paid_total is reduced by the refund amount
 *   - If fully refunded, invoice.status may change to 'refunded'
 *   - An activity log entry is created for audit trail
 *
 * Lifecycle:
 *   pending   → requested but not processed (e.g. card refund in-flight)
 *   completed → money returned to customer
 *   cancelled → request cancelled before processing
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique()->comment('Human-readable, e.g. REF-20260421-0001');
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete()
                  ->comment('The specific payment being refunded (optional)');

            $table->decimal('amount', 12, 4);
            $table->enum('method', ['cash', 'card', 'transfer', 'app', 'credit', 'other'])->default('cash');
            $table->string('reference', 100)->nullable()->comment('External txn id for card refunds');

            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('completed')->index();

            $table->text('reason');  // required — auditors need to know WHY
            $table->text('notes')->nullable();

            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();

            $table->timestamp('refunded_at')->useCurrent();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['invoice_id', 'status']);
            $table->index('refunded_at');
        });

        // Add "refunded" status to invoices enum — note SQL needs string column modify
        // which is DB-dependent. Since the existing column is likely enum-constrained,
        // we keep the refund status tracking in the refunds table itself and let
        // Invoice::isRefunded() compute it dynamically from related refunds.
        // If more flexibility is needed later, this is the place to add the column:
        //   Schema::table('invoices', fn(Blueprint $t) => $t->decimal('refunded_total', 12, 4)->default(0)->after('paid_total'));
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('refunded_total', 12, 4)->default(0)->after('paid_total')
                  ->comment('Denormalized sum of completed refunds — keeps reports fast');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('refunded_total');
        });
        Schema::dropIfExists('refunds');
    }
};
