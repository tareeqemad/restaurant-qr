<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-credit ledger groundwork.
 *
 * Two related concepts get persisted here:
 *
 *  1. `customers.credit_limit` — optional ceiling on how much a regular
 *     customer can owe at any one time. Null = no ceiling (acceptable for
 *     trusted long-term customers); a positive value lets the cashier UI
 *     block (or warn on) a new settle-on-account that would push the
 *     running debt past it.
 *
 *  2. `invoices.settled_on_account_at` / `settled_on_account_by_user_id`
 *     — marks an invoice as "session closed, balance carried as customer
 *     debt". The invoice keeps its `balance > 0` and `partially_paid`
 *     status; the timestamp distinguishes "still at the table, balance
 *     unpaid" from "table freed, balance moved to the customer ledger".
 *
 * No `customer_debts` ledger table — invoices themselves ARE the ledger.
 * Outstanding debt for a customer = SUM(invoices.balance) WHERE
 * customer_id = ? AND settled_on_account_at IS NOT NULL. Keeps a single
 * source of truth and reuses every existing payment / refund hook.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Per-customer ceiling on outstanding debt. Null = unlimited
            // (the default; restaurants can opt-in per customer).
            $table->decimal('credit_limit', 12, 2)->nullable()->after('default_branch_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('settled_on_account_at')->nullable()->after('paid_at');
            $table->foreignId('settled_on_account_by_user_id')
                  ->nullable()
                  ->after('settled_on_account_at')
                  ->constrained('users')
                  ->nullOnDelete();

            // Composite index on the two columns that the debt-ledger query
            // filters by — keeps the "all customers with open debt" page
            // fast even after years of paid invoices accumulate.
            $table->index(['customer_id', 'settled_on_account_at'], 'invoices_customer_settled_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_customer_settled_idx');
            $table->dropConstrainedForeignId('settled_on_account_by_user_id');
            $table->dropColumn('settled_on_account_at');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('credit_limit');
        });
    }
};
