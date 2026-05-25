<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending bank-transfer payments.
 *
 * Captures the customer's claim that they wired the bill from their
 * banking app BEFORE the cashier can confirm the funds actually
 * landed. Lives in its own table (not `payments`) so the existing
 * invoice-balance arithmetic — which sums every Payment row — keeps
 * treating "verified money" and "claimed money" as two different
 * universes. Once the cashier ticks "verified" we create the real
 * Payment via BillingService and store its id in `payment_id` here,
 * so reconciliation can trace each verified row back to its journal.
 *
 *   pending  → verified  (cashier confirmed in the bank app)
 *           → rejected  (cashier couldn't find the transfer)
 *
 * Linked to `table_session` rather than `invoice` because the waiter
 * may record the claim BEFORE the cashier issues an invoice — the
 * verification step issues the invoice on demand. `invoice_id` is
 * populated once verification fires.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pending_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('table_session_id')->constrained()->cascadeOnDelete();
            // Filled in at verification time. Nullable up front because the
            // waiter can record the claim before an invoice exists.
            $table->foreignId('invoice_id')->nullable()
                ->constrained()->nullOnDelete();
            // Real Payment row created when verified. Lets reports trace
            // pending → verified → journal line in one join.
            $table->foreignId('payment_id')->nullable()
                ->constrained('payments')->nullOnDelete();
            // The diner profile, if the waiter looked them up by phone.
            // Walk-ins leave this null — `*_snapshot` carries display info.
            $table->foreignId('customer_id')->nullable()
                ->constrained('customers')->nullOnDelete();

            $table->decimal('amount', 12, 2);
            // Whoever's name actually appears in the bank statement —
            // often a relative or friend who has online banking when the
            // diner doesn't. Free text, the cashier matches it visually.
            $table->string('sender_name', 120);
            // Snapshot of who ATE so the cashier sees identity even if
            // the customer_id row gets soft-deleted later.
            $table->string('customer_name_snapshot', 120)->nullable();
            $table->string('customer_phone_snapshot', 32)->nullable();
            $table->string('notes', 500)->nullable();

            $table->enum('status', ['pending', 'verified', 'rejected'])
                ->default('pending');
            $table->foreignId('recorded_by_user_id')->constrained('users');
            $table->foreignId('verified_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();

            $table->timestamps();

            // Fast lookup of "what's still pending in my branch?" — the
            // cashier badge polls on this every 30s.
            $table->index(['branch_id', 'status']);
            $table->index('table_session_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_transfers');
    }
};
