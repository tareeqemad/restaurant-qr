<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\InventoryMovement;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReceipt;
use App\Models\StockCountItem;
use App\Models\StorageLocation;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function dashboard()
    {
        $this->authorize('viewAny', Ingredient::class);

        $branchId = BranchContext::current();
        $branchName = $branchId ? \App\Models\Branch::find($branchId)?->name : null;

        $trackedIngredients = Ingredient::with('baseUnit', 'supplier')
            ->where('track_stock', true)
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Ingredient $ingredient) use ($branchId) {
                $stock = $branchId ? $ingredient->stockAtBranch($branchId) : (float) $ingredient->current_stock;
                $threshold = $branchId ? $ingredient->reorderThresholdAtBranch($branchId) : (float) $ingredient->reorder_threshold;
                $cost = $branchId ? $ingredient->costAtBranch($branchId) : (float) $ingredient->cost_per_unit;
                $targetStock = max($threshold * 2, $threshold + 1);

                $ingredient->setAttribute('dashboard_stock', $stock);
                $ingredient->setAttribute('dashboard_threshold', $threshold);
                $ingredient->setAttribute('dashboard_cost', $cost);
                $ingredient->setAttribute('dashboard_value', $stock * $cost);
                $ingredient->setAttribute('dashboard_need_qty', max(0, $targetStock - $stock));
                $ingredient->setAttribute('dashboard_need_cost', max(0, $targetStock - $stock) * $cost);
                $ingredient->setAttribute('dashboard_health_pct', $threshold > 0 ? ($stock / $threshold) * 100 : 100);

                return $ingredient;
            });

        $outOfStock = $trackedIngredients->filter(fn ($i) => $i->dashboard_stock <= 0)->values();
        $lowStock = $trackedIngredients
            ->filter(fn ($i) => $i->dashboard_stock > 0 && $i->dashboard_threshold > 0 && $i->dashboard_stock <= $i->dashboard_threshold)
            ->values();

        $reorderQueue = $trackedIngredients
            ->filter(fn ($i) => $i->dashboard_threshold > 0 && $i->dashboard_stock <= $i->dashboard_threshold)
            ->sortBy(fn ($i) => [
                $i->dashboard_stock > 0 ? 1 : 0,
                $i->dashboard_health_pct,
                -1 * $i->dashboard_need_cost,
            ])
            ->take(10)
            ->values();

        $expiringBatches = IngredientBatch::with('ingredient.baseUnit', 'storageLocation')
            ->where('remaining_qty', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays(7)->toDateString())
            ->orderBy('expiry_date')
            ->limit(10)
            ->get();

        $overduePurchaseOrders = PurchaseOrder::with('supplier')
            ->whereIn('status', ['sent', 'partially_received'])
            ->whereNotNull('expected_at')
            ->whereDate('expected_at', '<', now()->toDateString())
            ->orderBy('expected_at')
            ->limit(8)
            ->get();

        $openPurchaseOrders = PurchaseOrder::with('supplier')
            ->whereIn('status', ['draft', 'sent', 'partially_received'])
            ->latest()
            ->limit(8)
            ->get();

        $uninvoicedPurchaseOrders = PurchaseOrder::with('supplier')
            ->whereIn('status', ['received', 'partially_received'])
            ->whereDoesntHave('supplierInvoices', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->latest('received_at')
            ->limit(8)
            ->get();

        $supplierInvoiceQueue = SupplierInvoice::with('supplier', 'purchaseOrder')
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->where(function ($q) {
                $q->whereNull('due_date')
                  ->orWhereDate('due_date', '<=', now()->addDays(7)->toDateString());
            })
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('due_date')
            ->limit(8)
            ->get();

        $invoiceVarianceItems = SupplierInvoiceItem::with('supplierInvoice.supplier', 'ingredient.baseUnit')
            ->where(function ($query) {
                $query->whereRaw('ABS(COALESCE(variance_qty, 0)) > 0.0001')
                    ->orWhereRaw('ABS(COALESCE(variance_total, 0)) > 0.01');
            })
            ->latest()
            ->limit(8)
            ->get();

        $recentReceipts = PurchaseReceipt::with('purchaseOrder', 'supplier', 'receiver')
            ->latest('received_at')
            ->limit(8)
            ->get();

        $highWaste = InventoryMovement::query()
            ->join('ingredients', 'inventory_movements.ingredient_id', '=', 'ingredients.id')
            ->leftJoin('units', 'ingredients.base_unit_id', '=', 'units.id')
            ->where('inventory_movements.type', 'waste')
            ->where('inventory_movements.occurred_at', '>=', now()->subDays(7))
            ->selectRaw('
                ingredients.id as ingredient_id,
                ingredients.name,
                units.code as unit_code,
                COUNT(*) as events_count,
                SUM(inventory_movements.quantity_in_base) as qty,
                SUM(inventory_movements.total_cost) as total_cost
            ')
            ->groupBy('ingredients.id', 'ingredients.name', 'units.code')
            ->orderByDesc('total_cost')
            ->limit(8)
            ->get();

        $stats = [
            'tracked' => $trackedIngredients->count(),
            'healthy' => $trackedIngredients->count() - $lowStock->count() - $outOfStock->count(),
            'low_stock' => $lowStock->count(),
            'out_stock' => $outOfStock->count(),
            'stock_value' => (float) $trackedIngredients->sum('dashboard_value'),
            'reorder_cost' => (float) $reorderQueue->sum('dashboard_need_cost'),
            'expiring_batches' => IngredientBatch::where('remaining_qty', '>', 0)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<=', now()->addDays(7)->toDateString())
                ->count(),
            'overdue_pos' => PurchaseOrder::whereIn('status', ['sent', 'partially_received'])
                ->whereNotNull('expected_at')
                ->whereDate('expected_at', '<', now()->toDateString())
                ->count(),
            'uninvoiced_pos' => PurchaseOrder::whereIn('status', ['received', 'partially_received'])
                ->whereDoesntHave('supplierInvoices', fn ($q) => $q->where('status', '!=', 'cancelled'))
                ->count(),
            'invoice_variances' => SupplierInvoiceItem::where(function ($query) {
                $query->whereRaw('ABS(COALESCE(variance_qty, 0)) > 0.0001')
                    ->orWhereRaw('ABS(COALESCE(variance_total, 0)) > 0.01');
            })->count(),
            'receipts_7d' => PurchaseReceipt::where('received_at', '>=', now()->subDays(7))->count(),
            'ap_due' => (float) SupplierInvoice::whereNotIn('status', ['paid', 'cancelled'])->sum('balance'),
            'ap_overdue' => (float) SupplierInvoice::whereNotIn('status', ['paid', 'cancelled'])
                ->whereDate('due_date', '<', now()->toDateString())
                ->sum('balance'),
            'waste_7d' => (float) InventoryMovement::where('type', 'waste')
                ->where('occurred_at', '>=', now()->subDays(7))
                ->sum('total_cost'),
        ];

        $actionCount = $stats['low_stock']
            + $stats['out_stock']
            + $stats['expiring_batches']
            + $stats['overdue_pos']
            + $stats['uninvoiced_pos']
            + $stats['invoice_variances']
            + $supplierInvoiceQueue->count();

        return view('admin.inventory.dashboard', compact(
            'branchName',
            'stats',
            'actionCount',
            'reorderQueue',
            'expiringBatches',
            'overduePurchaseOrders',
            'openPurchaseOrders',
            'uninvoicedPurchaseOrders',
            'supplierInvoiceQueue',
            'invoiceVarianceItems',
            'recentReceipts',
            'highWaste'
        ));
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Ingredient::class);

        $referenceTypes = [
            PurchaseOrderItem::class => 'مشتريات',
            OrderItem::class => 'مبيعات وطلبات',
            StockCountItem::class => 'جرد',
            IngredientBatch::class => 'دفعات / هدر',
        ];

        $q = InventoryMovement::with([
            'ingredient.baseUnit',
            'user',
            'unit',
            'batch',
            'storageLocation',
            'reference',
        ]);

        if ($t = $request->get('type')) $q->where('type', $t);
        if ($i = $request->get('ingredient_id')) $q->where('ingredient_id', $i);
        if ($l = $request->get('storage_location_id')) $q->where('storage_location_id', $l);
        if ($r = $request->get('reference_type')) $q->where('reference_type', $r);
        if ($d = $request->get('from')) $q->whereDate('occurred_at', '>=', $d);
        if ($d = $request->get('to')) $q->whereDate('occurred_at', '<=', $d);

        $movements = $q->latest('occurred_at')->paginate(30)->withQueryString();
        $movements->getCollection()->loadMorph('reference', [
            PurchaseOrderItem::class => ['purchaseOrder'],
            OrderItem::class => ['order'],
            StockCountItem::class => ['stockCount'],
        ]);
        $movements->getCollection()->each(function (InventoryMovement $movement) {
            $movement->setAttribute('reference_meta', $this->referenceMeta($movement));
        });

        $today = InventoryMovement::whereDate('occurred_at', today());
        $stats = [
            'today'  => (clone $today)->count(),
            'in'     => (clone $today)->where('type', 'in')->count(),
            'out'    => (clone $today)->where('type', 'out')->count(),
            'waste'  => (clone $today)->where('type', 'waste')->count(),
            'return' => (clone $today)->where('type', 'return')->count(),
            'adjustment' => (clone $today)->where('type', 'adjustment')->count(),
        ];
        return view('admin.inventory.index', [
            'movements'   => $movements,
            'ingredients' => Ingredient::orderBy('name')->get(),
            'storageLocations' => StorageLocation::where('active', true)
                ->orderByDesc('is_default')
                ->orderBy('display_order')
                ->orderBy('name')
                ->get(),
            'referenceTypes' => $referenceTypes,
            'stats'       => $stats,
        ]);
    }

    protected function referenceMeta(InventoryMovement $movement): array
    {
        $reference = $movement->reference;

        if (! $reference) {
            return [
                'label' => $movement->reference_type ? class_basename($movement->reference_type).' #'.$movement->reference_id : 'يدوي',
                'url' => null,
                'icon' => 'bi-pencil-square',
            ];
        }

        if ($reference instanceof PurchaseOrderItem) {
            $po = $reference->purchaseOrder;

            return [
                'label' => $po ? 'أمر شراء '.$po->number : 'بند شراء #'.$reference->id,
                'url' => $po ? route('admin.purchase-orders.show', $po) : null,
                'icon' => 'bi-bag-check',
            ];
        }

        if ($reference instanceof OrderItem) {
            $order = $reference->order;

            return [
                'label' => $order ? 'طلب '.$order->number : 'بند طلب #'.$reference->id,
                'url' => $order ? route('admin.orders.show', $order) : null,
                'icon' => 'bi-receipt-cutoff',
            ];
        }

        if ($reference instanceof StockCountItem) {
            $count = $reference->stockCount;

            return [
                'label' => $count ? 'جرد '.$count->number : 'بند جرد #'.$reference->id,
                'url' => $count ? route('admin.stock-counts.show', $count) : null,
                'icon' => 'bi-clipboard2-check',
            ];
        }

        if ($reference instanceof IngredientBatch) {
            return [
                'label' => 'دفعة #'.($reference->batch_number ?: $reference->id),
                'url' => route('admin.batches.index', ['ingredient_id' => $reference->ingredient_id]),
                'icon' => 'bi-box2-heart',
            ];
        }

        return [
            'label' => class_basename($movement->reference_type).' #'.$movement->reference_id,
            'url' => null,
            'icon' => 'bi-link-45deg',
        ];
    }
}
