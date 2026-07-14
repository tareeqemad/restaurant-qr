<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Same MariaDB trap as table_sessions.opened_at (see the previous
 * migration): the first timestamp column of each of these tables carries
 * an implicit `ON UPDATE current_timestamp()`, so ANY later update to the
 * row silently rewrites a business timestamp:
 *
 *   payments.paid_at            — voiding/flagging a payment moved its date
 *   shifts.opened_at            — any shift update reset the X-report window
 *   inventory_movements.occurred_at — audit trail dates drifted on edit
 *   purchase_receipts.received_at   — GRN date drifted
 *   staff_meal_charges.charged_at / staff_meal_month_closures.closed_at
 *   license_payments.paid_at
 *
 * Redefine each with an explicit default and NO on-update clause. All were
 * NOT NULL DEFAULT current_timestamp(), which is preserved.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'inventory_movements' => 'occurred_at',
        'license_payments' => 'paid_at',
        'payments' => 'paid_at',
        'purchase_receipts' => 'received_at',
        'shifts' => 'opened_at',
        'staff_meal_charges' => 'charged_at',
        'staff_meal_month_closures' => 'closed_at',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return; // sqlite (tests) has no ON UPDATE semantics to fix.
        }

        foreach (self::COLUMNS as $table => $column) {
            DB::statement(
                "ALTER TABLE {$table}
                 MODIFY {$column} TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::COLUMNS as $table => $column) {
            DB::statement(
                "ALTER TABLE {$table}
                 MODIFY {$column} TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
            );
        }
    }
};
