<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Second pass of chart-of-accounts pruning, driven by what THIS restaurant
 * actually does:
 *
 *  Tips (2200)          — staff pocket cash tips directly; the system never
 *                         holds them as a liability.
 *  Bank fees (5300)     — no merchant commissions on cash/card/transfer;
 *                         every channel settles at face value.
 *  Stock-count variance (4200, 5410) — the operator chose not to use the
 *                         physical inventory-count workflow at all.
 *  Shift-cash variance  (4210, 5510) — same, no shift-end cash counting
 *                         reconciliation.
 *  Purchase-price var.  (5420) — supplier invoices aren't matched line-by
 *                         -line against receipts, so a price-variance
 *                         line never appears.
 *
 * KEPT (per operator confirmation):
 *  - 1150 inter-branch transit   (multi-branch operation)
 *  - 1300 / 2100 VAT             (VAT-registered)
 *
 * Rows are NOT deleted — just is_active=false. Historical journal lines
 * (if any later appear) still resolve. The trial-balance view also
 * surfaces inactive-with-balance rows so the books stay mathematically
 * complete even if a dormant account gets accidentally posted to.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('accounts')
            ->whereIn('code', ['2200', '5300', '4200', '5410', '4210', '5510', '5420'])
            ->update(['is_active' => false]);
    }

    public function down(): void
    {
        DB::table('accounts')
            ->whereIn('code', ['2200', '5300', '4200', '5410', '4210', '5510', '5420'])
            ->update(['is_active' => true]);
    }
};
