<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\IngredientSupplierPrice;
use App\Models\Supplier;
use App\Helpers\Money;
use App\Helpers\Qty;
use App\Support\AdminShell;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Vendor Price History — three lenses on the same data:
 *
 *   • forIngredient($ingredient) — chart + table of every observed price for
 *     ONE SKU across ALL its suppliers, so the buyer can see "Supplier A
 *     keeps creeping up; Supplier B has been flat" at a glance.
 *
 *   • forSupplier($supplier) — every SKU we've bought from this supplier,
 *     with its latest price and 30-day delta. Supplier scorecard surface.
 *
 *   • compare() — pivot table: ingredients × suppliers, latest prices side-by-
 *     side. Lets the buyer spot "we're paying X more at Supplier A for the
 *     exact same item."
 */
class VendorPriceController extends Controller
{
    public function forIngredient(Ingredient $ingredient)
    {
        $this->authorize('viewAny', Supplier::class);

        $rows = IngredientSupplierPrice::with(['supplier', 'unit', 'recordedBy'])
            ->forIngredient($ingredient->id)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        // Group by supplier for the chart series.
        $bySupplier = $rows->groupBy('supplier_id')
            ->map(fn ($group, $supplierId) => [
                'supplier' => $group->first()->supplier,
                'points'   => $group->sortBy('observed_at')->map(fn ($r) => [
                    'date'  => $r->observed_at?->toDateString(),
                    'price' => (float) $r->unit_price_in_base,
                ])->values(),
                'latest'   => $group->sortByDesc('observed_at')->first(),
            ])
            ->values();

        // Headline numbers
        $latest = $rows->first();
        $minPrice = (float) $rows->min('unit_price_in_base');
        $maxPrice = (float) $rows->max('unit_price_in_base');

        $ingredient->load('baseUnit');

        return AdminShell::render('Admin/VendorPrices/Ingredient', [
            'ingredient' => [
                'id' => $ingredient->id,
                'name' => $ingredient->name,
                'unit' => $ingredient->baseUnit?->code ?? '—',
            ],
            'stats' => [
                'latest' => $latest ? Money::format($latest->unit_price_in_base) : '—',
                'min' => $rows->isEmpty() ? '—' : Money::format($minPrice),
                'max' => $rows->isEmpty() ? '—' : Money::format($maxPrice),
                'suppliers' => $bySupplier->count(),
                'observations' => $rows->count(),
            ],
            'supplierCards' => $bySupplier->map(fn ($entry) => [
                'id' => $entry['supplier']?->id,
                'name' => $entry['supplier']?->name ?? 'مورد محذوف',
                'count' => $entry['points']->count(),
                'latest' => Money::format($entry['latest']->unit_price_in_base),
                'changeLabel' => $entry['latest']->changeLabel(),
                'changeColor' => $entry['latest']->changeColor(),
                'updatedAgo' => $entry['latest']->observed_at?->diffForHumans(),
            ])->values(),
            'history' => $rows->map(fn (IngredientSupplierPrice $row) => [
                'id' => $row->id,
                'date' => $row->observed_at?->format('Y-m-d H:i'),
                'ago' => $row->observed_at?->diffForHumans(),
                'supplier' => $row->supplier?->name ?? '—',
                'paidPrice' => Qty::format($row->unit_price).' / '.($row->unit?->code ?? '—'),
                'basePrice' => Money::format($row->unit_price_in_base),
                'changeLabel' => $row->changeLabel(),
                'changeColor' => $row->changeColor(),
                'source' => $row->purchase_order_id ? 'أمر شراء #'.$row->purchase_order_id : $row->source,
                'sourceUrl' => $row->purchase_order_id ? route('admin.purchase-orders.show', $row->purchase_order_id) : null,
                'recordedBy' => $row->recordedBy?->name ?? '—',
            ])->values(),
            'urls' => [
                'ingredients' => route('admin.ingredients.index'),
                'compare' => route('admin.vendor-prices.compare'),
            ],
        ]);
    }

    public function forSupplier(Supplier $supplier)
    {
        $this->authorize('view', $supplier);

        // Latest price per ingredient from THIS supplier, plus a 30-day-ago
        // anchor for delta. One window query per side keeps it cheap.
        $latestPerIng = IngredientSupplierPrice::with('ingredient.baseUnit', 'unit')
            ->forSupplier($supplier->id)
            ->orderBy('ingredient_id')
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('ingredient_id')
            ->map(fn ($group) => $group->first());

        $cutoff = now()->subDays(30);
        $anchorPerIng = IngredientSupplierPrice::forSupplier($supplier->id)
            ->where('observed_at', '<', $cutoff)
            ->orderBy('ingredient_id')
            ->orderByDesc('observed_at')
            ->get()
            ->groupBy('ingredient_id')
            ->map(fn ($group) => $group->first());

        $rows = $latestPerIng->map(function ($latest) use ($anchorPerIng) {
            $anchor = $anchorPerIng[$latest->ingredient_id] ?? null;
            $delta30 = null;
            if ($anchor && (float) $anchor->unit_price_in_base > 0) {
                $delta30 = (((float) $latest->unit_price_in_base
                    - (float) $anchor->unit_price_in_base)
                    / (float) $anchor->unit_price_in_base) * 100;
            }
            return [
                'latest'   => $latest,
                'delta30'  => $delta30,
            ];
        })->values();

        return AdminShell::render('Admin/VendorPrices/Supplier', [
            'supplier' => ['id' => $supplier->id, 'name' => $supplier->name],
            'rows' => $rows->map(function ($row) {
                $latest = $row['latest'];
                $delta = $row['delta30'];

                return [
                    'id' => $latest->id,
                    'ingredient' => $latest->ingredient?->name,
                    'sku' => $latest->ingredient?->sku,
                    'paidPrice' => Qty::format($latest->unit_price).' / '.($latest->unit?->code ?? '—'),
                    'basePrice' => Money::format($latest->unit_price_in_base).' / '.($latest->ingredient?->baseUnit?->code ?? '—'),
                    'changeLabel' => $latest->changeLabel(),
                    'changeColor' => $latest->changeColor(),
                    'delta30' => $delta === null ? null : round($delta, 2),
                    'date' => $latest->observed_at?->toDateString(),
                    'ago' => $latest->observed_at?->diffForHumans(),
                    'url' => route('admin.vendor-prices.ingredient', $latest->ingredient),
                ];
            })->values(),
            'urls' => [
                'supplier' => route('admin.suppliers.show', $supplier),
                'compare' => route('admin.vendor-prices.compare'),
            ],
        ]);
    }

    public function compare(Request $request)
    {
        $this->authorize('viewAny', Supplier::class);

        // Ingredients we have at least one observation for (filters out junk).
        $ingredientIds = IngredientSupplierPrice::query()
            ->select('ingredient_id')
            ->distinct()
            ->pluck('ingredient_id');

        if ($ingredientIds->isEmpty()) {
            return AdminShell::render('Admin/VendorPrices/Compare', [
                'suppliers' => [], 'rows' => [],
            ]);
        }

        // Latest price per (ingredient, supplier) trio. Subquery → one row per pair.
        $latestIds = DB::table('ingredient_supplier_prices')
            ->selectRaw('MAX(id) as max_id')
            ->whereIn('ingredient_id', $ingredientIds)
            ->groupBy('ingredient_id', 'supplier_id')
            ->pluck('max_id');

        $latest = IngredientSupplierPrice::with('supplier', 'unit')
            ->whereIn('id', $latestIds)
            ->get();

        // Build matrix: matrix[ingredient_id][supplier_id] = price row
        $matrix = [];
        foreach ($latest as $row) {
            $matrix[$row->ingredient_id][$row->supplier_id] = $row;
        }

        $ingredients = Ingredient::with('baseUnit')->whereIn('id', $ingredientIds)->orderBy('name')->get();
        $suppliers   = Supplier::whereIn('id', $latest->pluck('supplier_id')->unique())->orderBy('name')->get();

        // Annotate each ingredient with cheapest+priciest suppliers for highlight.
        foreach ($ingredients as $ing) {
            $row = collect($matrix[$ing->id] ?? []);
            if ($row->isEmpty()) continue;
            $sorted = $row->sortBy(fn ($r) => (float) $r->unit_price_in_base);
            $ing->cheapest_supplier_id = $sorted->first()->supplier_id;
            $ing->priciest_supplier_id = $sorted->last()->supplier_id;
            $ing->price_range_pct = (float) $sorted->first()->unit_price_in_base > 0
                ? (((float) $sorted->last()->unit_price_in_base
                    - (float) $sorted->first()->unit_price_in_base)
                    / (float) $sorted->first()->unit_price_in_base) * 100
                : 0;
        }

        return AdminShell::render('Admin/VendorPrices/Compare', [
            'suppliers' => $suppliers->map(fn (Supplier $supplier) => [
                'id' => $supplier->id, 'name' => $supplier->name,
                'url' => route('admin.vendor-prices.supplier', $supplier),
            ])->values(),
            'rows' => $ingredients->map(function (Ingredient $ingredient) use ($suppliers, $matrix) {
                return [
                    'id' => $ingredient->id,
                    'name' => $ingredient->name,
                    'unit' => $ingredient->baseUnit?->code,
                    'range' => round((float) ($ingredient->price_range_pct ?? 0), 1),
                    'historyUrl' => route('admin.vendor-prices.ingredient', $ingredient),
                    'prices' => $suppliers->map(function (Supplier $supplier) use ($ingredient, $matrix) {
                        $price = $matrix[$ingredient->id][$supplier->id] ?? null;

                        return [
                            'supplierId' => $supplier->id,
                            'value' => $price ? Money::format($price->unit_price_in_base) : null,
                            'ago' => $price?->observed_at?->diffForHumans(),
                            'cheapest' => ($ingredient->cheapest_supplier_id ?? null) === $supplier->id,
                            'priciest' => ($ingredient->priciest_supplier_id ?? null) === $supplier->id
                                && ($ingredient->cheapest_supplier_id ?? null) !== $supplier->id,
                        ];
                    })->values(),
                ];
            })->values(),
        ]);
    }
}
