<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff meal allowance — a monthly tab each employee can use to eat at
 * the restaurant. Conceptually similar to the customer debt ledger but
 * with a key difference: it has a periodic LIMIT that refreshes each
 * month. Charges within the month draw from the limit; overflow gets
 * carried forward as personal debt.
 *
 * Three columns/tables:
 *
 *   1. users.monthly_meal_allowance — the per-month ceiling for that
 *      employee. Null = no allowance (employee can't eat on the house).
 *
 *   2. orders.staff_consumer_user_id — when set, this order is a staff
 *      meal, not a paying-customer order. No invoice gets issued; a
 *      `staff_meal_charges` row records the cost instead. Kitchen flow
 *      (KDS, inventory deduction, etc.) is unchanged.
 *
 *   3. staff_meal_charges — one row per charge. (user_id, order_id,
 *      amount, charged_at). `settled_at` marks the row as paid back by
 *      the employee (cash) or written off (manager-approved). Open
 *      rows = the employee's running tab.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Per-month cap. Null = employee has no allowance set up.
            $table->decimal('monthly_meal_allowance', 12, 2)->nullable()->after('status');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Distinct from customer_id (paying customer / loyalty
            // attribution) — this is the EMPLOYEE who consumed the
            // food on the house. Set on creation by the waiter UI's
            // "staff meal" mode.
            $table->foreignId('staff_consumer_user_id')->nullable()->after('customer_id')
                  ->constrained('users')->nullOnDelete();
        });

        Schema::create('staff_meal_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()
                  ->comment('The employee being charged.');
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete()
                  ->comment('Nullable because manual adjustments may exist later.');
            $table->decimal('amount', 12, 2)
                  ->comment('Order total at the moment of charging — frozen so later order edits do not drift the tab.');
            $table->timestamp('charged_at');
            $table->timestamp('settled_at')->nullable()
                  ->comment('Set when the employee paid back (cash) or the manager wrote it off.');
            $table->foreignId('settled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('settlement_method', 20)->nullable()
                  ->comment('cash | payroll_deduction | writeoff — for the month-end report.');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            // Hot path: "what's THIS employee's current tab?" → unsettled rows.
            $table->index(['user_id', 'settled_at'], 'staff_charges_user_settled_idx');
            $table->index('charged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_meal_charges');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('staff_consumer_user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('monthly_meal_allowance');
        });
    }
};
