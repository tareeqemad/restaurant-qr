<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff meal debt control — three things, one migration:
 *
 *   1. `users.meal_debt_ceiling` — per-employee HARD cap on total
 *      outstanding (across all months). Null = no extra cap beyond
 *      the monthly allowance. With a value set, the over-limit policy
 *      kicks in (block / require approval / warn).
 *
 *   2. `staff_meal_month_closures` — one row per month-end batch
 *      settlement (per branch + month). Records who closed the month,
 *      which method was used, totals, and the printable report
 *      reference. The dashboard's "إقفال الشهر" button creates one.
 *
 *   3. `staff_meal_charges.month_closure_id` — when a charge is
 *      settled as part of a batch closure, this FK links it back so
 *      we can re-print last month's payroll deduction list.
 *
 * The over-limit POLICY itself lives in the `settings` table as
 * `staff_meal_over_limit_policy` (allow_log | warn | require_approval
 * | block). Default = warn. Seeded by SettingController if missing.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Per-employee HARD ceiling on total outstanding (across all
            // months). Null = no extra cap; the monthly allowance is the
            // only soft guard. With a value, the over_limit policy kicks
            // in when the next charge would push outstanding past it.
            $table->decimal('meal_debt_ceiling', 12, 2)->nullable()->after('monthly_meal_allowance');
        });

        Schema::create('staff_meal_month_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->date('month')->comment('First day of the month being closed.');
            $table->string('method', 30)->comment('payroll_deduction | cash | writeoff — applied to every charge in the batch.');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->unsignedInteger('staff_count')->default(0);
            $table->unsignedInteger('charge_count')->default(0);
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at');
            $table->string('notes', 1000)->nullable();
            $table->timestamps();

            // One closure per branch+month — re-running the close just
            // returns the existing closure (idempotent). Branch is
            // nullable to allow cross-branch closures (owner-level).
            $table->unique(['branch_id', 'month'], 'staff_closures_branch_month_uq');
            $table->index('month');
        });

        Schema::table('staff_meal_charges', function (Blueprint $table) {
            // Links a settled charge back to its closure batch so we
            // can re-print "last month's payroll deductions" without
            // re-aggregating from individual rows.
            $table->foreignId('month_closure_id')->nullable()->after('settlement_method')
                  ->constrained('staff_meal_month_closures')->nullOnDelete();
        });

        // Seed the default policy if not already set. `warn` = manager
        // sees a warning at order time but isn't blocked — matches the
        // "control without slowing service" preference.
        if (Schema::hasTable('settings')) {
            $exists = DB::table('settings')->where('key', 'staff_meal_over_limit_policy')->exists();
            if (! $exists) {
                DB::table('settings')->insert([
                    'key'        => 'staff_meal_over_limit_policy',
                    'value'      => 'warn',
                    'group'      => 'staff_meals',
                    'type'       => 'string',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Seed two new accounting accounts for the staff-meal cycle:
        //   5050 — مصاريف وجبات الموظفين (expense, debit)
        //   1110 — مستحقات على الموظفين (asset, debit)
        if (Schema::hasTable('accounts')) {
            foreach ([
                [
                    'code' => '1110',
                    'name' => 'مستحقات على الموظفين - بدل الوجبات',
                    'type' => 'asset',
                    'normal_balance' => 'debit',
                    'description' => 'مبالغ بدل الوجبات المسحوبة من الموظفين قبل التسوية بالنقد أو خصم الرواتب.',
                ],
                [
                    'code' => '5050',
                    'name' => 'مصاريف وجبات الموظفين',
                    'type' => 'expense',
                    'normal_balance' => 'debit',
                    'description' => 'تكلفة الوجبات المقدّمة للموظفين كميزة عينية ضمن بدل الوجبات الشهري.',
                ],
                [
                    'code' => '2110',
                    'name' => 'خصومات الرواتب المستحقة',
                    'type' => 'liability',
                    'normal_balance' => 'credit',
                    'description' => 'مبالغ تم خصمها على الموظفين ضمن إقفال شهري وستُحسم من راتب الشهر التالي.',
                ],
            ] as $account) {
                DB::table('accounts')->updateOrInsert(
                    ['code' => $account['code']],
                    [
                        ...$account,
                        'is_system' => true,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        Schema::table('staff_meal_charges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('month_closure_id');
        });

        Schema::dropIfExists('staff_meal_month_closures');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('meal_debt_ceiling');
        });

        // Leave the policy setting and accounts in place — accounting
        // rows already posted reference them. Removing 5050/1110/2110
        // would orphan historical journal lines.
    }
};
