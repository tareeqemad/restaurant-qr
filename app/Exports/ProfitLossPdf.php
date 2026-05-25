<?php

namespace App\Exports;

use App\Models\Branch;
use App\Models\Setting;

/**
 * Returns a printable HTML page (NOT a binary PDF). DomPDF can't render
 * Arabic typography at the level a luxury restaurant brand demands —
 * letter-shaping breaks, kerning is off, custom fonts are awkward. So we
 * give the manager a beautiful web view with print-optimised CSS instead;
 * one click on the browser's print button produces a clean PDF that uses
 * the OS's native font stack.
 *
 * Class name kept as `ProfitLossPdf` for backwards compatibility with the
 * existing route — the user-visible label says "تقرير للطباعة".
 */
class ProfitLossPdf
{
    public function download(array $r)
    {
        $branchName = $r['period']['branch_id']
            ? (Branch::find($r['period']['branch_id'])?->name ?? '—')
            : 'كل الفروع';

        $brand = [
            'name'     => \App\Helpers\Brand::name(),
            'currency' => Setting::get('currency_symbol', config('restaurant.currency_symbol', '₪')),
            'logo'     => Setting::get('brand_logo'),
            'tax'      => Setting::get('tax_number'),
            'legal'    => Setting::get('legal_name'),
            'footer'   => Setting::get('receipt_footer'),
        ];

        return response()->view('admin.reports.profit-loss-print', [
            'r'           => $r,
            'branchName'  => $branchName,
            'brand'       => $brand,
            'generatedAt' => now(),
        ]);
    }
}
