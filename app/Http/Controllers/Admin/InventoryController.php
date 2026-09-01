<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\Money;
use App\Helpers\Qty;
use App\Helpers\QuantityFormatter;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientUnit;
use App\Models\InventoryMovement;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReceipt;
use App\Models\StockCountItem;
use App\Models\StorageLocation;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Services\Accounting\AccountingService;
use App\Services\ExchangeRateService;
use App\Support\AdminShell;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function dashboard()
    {
        $this->authorize('viewAny', Ingredient::class);

        $branchId = BranchContext::current();
        $branchName = $branchId ? Branch::find($branchId)?->name : null;
        $user = auth()->user();
        $baseCurrency = app(ExchangeRateService::class)->baseCurrencyCode();

        $trackedIngredients = Ingredient::with('baseUnit', 'supplier')
            ->where('track_stock', true)
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Ingredient $ingredient) use ($branchId) {
                $stock = $branchId ? $ingredient->usableStockAtBranch($branchId) : $ingredient->trackedUsableStock();
                $threshold = $branchId ? $ingredient->reorderThresholdAtBranch($branchId) : (float) $ingredient->reorder_threshold;

                // Inventory value = SUM(per-branch stock × per-branch cost) for
                // the all-branches view, so this dashboard's totals match the
                // ingredients page. The cost column then becomes the blended
                // rate (value/stock) so cost × stock = value still holds.
                $value = $branchId ? $ingredient->valueAtBranch($branchId) : $ingredient->trackedValue();
                $cost = $branchId
                    ? $ingredient->costAtBranch($branchId)
                    : ($stock > 0 ? $value / $stock : (float) $ingredient->cost_per_unit);

                $targetStock = max($threshold * 2, $threshold + 1);

                $ingredient->setAttribute('dashboard_stock', $stock);
                $ingredient->setAttribute('dashboard_threshold', $threshold);
                $ingredient->setAttribute('dashboard_cost', $cost);
                $ingredient->setAttribute('dashboard_value', $value);
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

        // Keep partially invoiced receipts visible until every received unit is
        // covered by a live supplier invoice.
        $invoiceablePurchaseOrders = PurchaseOrder::with([
            'supplier',
            'items.receiptItems',
            'items.supplierInvoiceItems.supplierInvoice',
        ])
            ->whereIn('status', ['received', 'partially_received'])
            ->latest('received_at')
            ->get()
            ->reject(fn (PurchaseOrder $order) => $order->isFullyInvoiced())
            ->values();
        $uninvoicedPurchaseOrders = $invoiceablePurchaseOrders->take(8);

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
            'open_pos' => PurchaseOrder::whereIn('status', ['draft', 'sent', 'partially_received'])->count(),
            'awaiting_receipt' => PurchaseOrder::whereIn('status', ['sent', 'partially_received'])->count(),
            'uninvoiced_pos' => $invoiceablePurchaseOrders->count(),
            'invoice_variances' => SupplierInvoiceItem::where(function ($query) {
                $query->whereRaw('ABS(COALESCE(variance_qty, 0)) > 0.0001')
                    ->orWhereRaw('ABS(COALESCE(variance_total, 0)) > 0.01');
            })->count(),
            'receipts_7d' => PurchaseReceipt::where('received_at', '>=', now()->subDays(7))->count(),
            'ap_due' => (float) SupplierInvoice::whereNotIn('status', ['paid', 'cancelled'])
                ->sum(DB::raw('balance * exchange_rate')),
            'ap_overdue' => (float) SupplierInvoice::whereNotIn('status', ['paid', 'cancelled'])
                ->whereDate('due_date', '<', now()->toDateString())
                ->sum(DB::raw('balance * exchange_rate')),
            'waste_7d' => (float) InventoryMovement::where('type', 'waste')
                ->where('occurred_at', '>=', now()->subDays(7))
                ->sum('total_cost'),
        ];

        $uninvoicedBaseValue = (float) $invoiceablePurchaseOrders
            ->sum(fn (PurchaseOrder $order) => $this->uninvoicedReceivedValue($order));
        $ledger = [
            'inventory' => $this->postingRoleBalance('inventory', $branchId, 'debit'),
            'grni' => $this->postingRoleBalance('grni', $branchId, 'credit'),
            'payables' => $this->postingRoleBalance('accounts_payable', $branchId, 'credit'),
        ];
        $reconciliation = [
            'inventory' => round($stats['stock_value'] - $ledger['inventory'], 2),
            'grni' => round($uninvoicedBaseValue - $ledger['grni'], 2),
            'payables' => round($stats['ap_due'] - $ledger['payables'], 2),
        ];
        $accountingNeedsReview = collect($reconciliation)
            ->contains(fn (float $difference) => abs($difference) > 0.01);

        $actionCount = $stats['low_stock']
            + $stats['out_stock']
            + $stats['expiring_batches']
            + $stats['overdue_pos']
            + $stats['uninvoiced_pos']
            + $stats['invoice_variances']
            + $supplierInvoiceQueue->count();

        $urls = [
            'lowStockIngredients' => route('admin.ingredients.index', ['low_stock' => 1]),
            'reorderSuggestions' => route('admin.reports.reorder-suggestions'),
            'batches' => route('admin.batches.index'),
            'sentPurchaseOrders' => route('admin.purchase-orders.index', ['status' => 'sent']),
            'overdueInvoices' => route('admin.supplier-invoices.index', ['overdue' => 1]),
            'createInvoice' => route('admin.supplier-invoices.create'),
            'supplierInvoices' => route('admin.supplier-invoices.index'),
            'purchaseOrders' => route('admin.purchase-orders.index'),
            'createPurchaseOrder' => route('admin.purchase-orders.create'),
            'stockValuation' => route('admin.reports.stock-valuation'),
            'journal' => $user?->hasPermission('chart_of_accounts.viewAny')
                ? route('admin.accounting.journal') : null,
        ];

        return AdminShell::render('Admin/Inventory/Dashboard', [
            'branchName' => $branchName,
            'baseCurrency' => $baseCurrency,
            // The Blade built this sentence inline in the breadcrumb.
            'subtitle' => $branchName
                ? 'مركز قرار سريع لفرع '.$branchName
                : 'مركز قرار سريع للمخزون والمشتريات عبر النظام',
            'actionCount' => (int) $actionCount,

            'stats' => [
                'tracked' => (int) $stats['tracked'],
                'healthy' => (int) $stats['healthy'],
                'lowStock' => (int) $stats['low_stock'],
                'outStock' => (int) $stats['out_stock'],
                'stockValue' => (float) $stats['stock_value'],
                'stockValueLabel' => Money::format($stats['stock_value']),
                'reorderCost' => (float) $stats['reorder_cost'],
                'reorderCostLabel' => Money::format($stats['reorder_cost']),
                'expiringBatches' => (int) $stats['expiring_batches'],
                'overduePos' => (int) $stats['overdue_pos'],
                'openPos' => (int) $stats['open_pos'],
                'awaitingReceipt' => (int) $stats['awaiting_receipt'],
                'uninvoicedPos' => (int) $stats['uninvoiced_pos'],
                'uninvoicedValue' => $uninvoicedBaseValue,
                'uninvoicedValueLabel' => Money::format($uninvoicedBaseValue),
                'invoiceVariances' => (int) $stats['invoice_variances'],
                'receipts7d' => (int) $stats['receipts_7d'],
                'apDue' => (float) $stats['ap_due'],
                'apDueLabel' => Money::format($stats['ap_due']),
                'apOverdue' => (float) $stats['ap_overdue'],
                'apOverdueLabel' => Money::format($stats['ap_overdue']),
                'waste7d' => (float) $stats['waste_7d'],
                'waste7dLabel' => Money::format($stats['waste_7d']),
            ],

            // The rail's four conditional tints were PHP in the template.
            'workflow' => [
                [
                    'step' => '01', 'key' => 'need', 'title' => 'حدد الاحتياج',
                    'count' => $stats['low_stock'] + $stats['out_stock'],
                    'metric' => Money::format($stats['reorder_cost']),
                    'hint' => 'نواقص محسوبة من الرصيد وحد إعادة الطلب.',
                    'icon' => 'bi-clipboard-data', 'tone' => $stats['out_stock'] > 0 ? 'danger' : 'warning',
                    'url' => $urls['reorderSuggestions'],
                ],
                [
                    'step' => '02', 'key' => 'order', 'title' => 'اعتمد التوريد',
                    'count' => $stats['open_pos'],
                    'metric' => $stats['overdue_pos'].' متأخر',
                    'hint' => 'مسودة، اعتماد، ثم إرسال للمورد.',
                    'icon' => 'bi-bag-check', 'tone' => $stats['overdue_pos'] > 0 ? 'danger' : 'primary',
                    'url' => $urls['purchaseOrders'],
                ],
                [
                    'step' => '03', 'key' => 'receive', 'title' => 'استلم للمخزن',
                    'count' => $stats['awaiting_receipt'],
                    'metric' => $stats['receipts_7d'].' استلام هذا الأسبوع',
                    'hint' => 'الكمية والموقع والدفعة والصلاحية والسعر الفعلي.',
                    'icon' => 'bi-box-arrow-in-down', 'tone' => $stats['awaiting_receipt'] > 0 ? 'primary' : 'success',
                    'url' => $urls['sentPurchaseOrders'],
                ],
                [
                    'step' => '04', 'key' => 'invoice', 'title' => 'سجل الفاتورة والسداد',
                    'count' => $stats['uninvoiced_pos'],
                    'metric' => Money::format($stats['ap_due']),
                    'hint' => 'إغلاق الاستلام غير المفوتر ثم متابعة ذمة المورد.',
                    'icon' => 'bi-receipt', 'tone' => $stats['uninvoiced_pos'] > 0 || $stats['ap_overdue'] > 0 ? 'warning' : 'success',
                    'url' => $urls['supplierInvoices'],
                ],
            ],
            'accountingBridge' => [
                'status' => $accountingNeedsReview ? 'review' : 'balanced',
                'statusLabel' => $accountingNeedsReview ? 'تحتاج مراجعة' : 'متطابقة محاسبياً',
                'journalUrl' => $urls['journal'],
                'rows' => [
                    [
                        'key' => 'inventory', 'label' => 'المخزون التشغيلي / الدفتر',
                        'operationalLabel' => Money::format($stats['stock_value']),
                        'ledgerLabel' => Money::format($ledger['inventory']),
                        'difference' => $reconciliation['inventory'],
                        'differenceLabel' => Money::format(abs($reconciliation['inventory'])),
                    ],
                    [
                        'key' => 'grni', 'label' => 'استلامات غير مفوترة / حساب GRNI',
                        'operationalLabel' => Money::format($uninvoicedBaseValue),
                        'ledgerLabel' => Money::format($ledger['grni']),
                        'difference' => $reconciliation['grni'],
                        'differenceLabel' => Money::format(abs($reconciliation['grni'])),
                    ],
                    [
                        'key' => 'payables', 'label' => 'ذمم الموردين / الدفتر',
                        'operationalLabel' => Money::format($stats['ap_due']),
                        'ledgerLabel' => Money::format($ledger['payables']),
                        'difference' => $reconciliation['payables'],
                        'differenceLabel' => Money::format(abs($reconciliation['payables'])),
                    ],
                ],
            ],
            'can' => [
                'createPurchaseOrder' => (bool) $user?->can('create', PurchaseOrder::class),
                'createSupplierInvoice' => (bool) $user?->can('create', SupplierInvoice::class),
                'viewAccounting' => (bool) $user?->hasPermission('chart_of_accounts.viewAny'),
            ],

            'statRail' => [
                ['label' => 'قيمة المخزون', 'value' => Money::format($stats['stock_value']), 'icon' => 'bi-cash-stack', 'color' => 'primary'],
                ['label' => 'يحتاج إجراء', 'value' => (string) $actionCount, 'icon' => 'bi-lightning-charge-fill', 'color' => $actionCount > 0 ? 'danger' : 'success'],
                ['label' => 'مخزون منخفض', 'value' => (string) $stats['low_stock'], 'icon' => 'bi-exclamation-triangle-fill', 'color' => $stats['low_stock'] > 0 ? 'accent' : 'muted'],
                ['label' => 'نافد', 'value' => (string) $stats['out_stock'], 'icon' => 'bi-x-octagon-fill', 'color' => $stats['out_stock'] > 0 ? 'danger' : 'muted'],
                ['label' => 'تكلفة طلب مقترحة', 'value' => Money::format($stats['reorder_cost']), 'icon' => 'bi-cart-plus-fill', 'color' => 'success'],
                ['label' => 'مستحقات الموردين', 'value' => Money::format($stats['ap_due']), 'icon' => 'bi-receipt', 'color' => $stats['ap_overdue'] > 0 ? 'danger' : 'info'],
                ['label' => 'فروقات فواتير', 'value' => (string) $stats['invoice_variances'], 'icon' => 'bi-slash-circle', 'color' => $stats['invoice_variances'] > 0 ? 'danger' : 'muted'],
            ],

            // "قائمة الإجراء المطلوبة" — seven links whose headline was a
            // template-built string. Same order, same wording, same tones.
            'actions' => [
                [
                    'tone' => 'danger', 'icon' => 'bi-exclamation-triangle-fill',
                    'title' => ($stats['low_stock'] + $stats['out_stock']).' مكون منخفض أو نافد',
                    'hint' => 'ابدأ منها قبل ساعات الذروة حتى لا يتوقف صنف من المنيو.',
                    'url' => $urls['lowStockIngredients'],
                ],
                [
                    'tone' => 'success', 'icon' => 'bi-cart-plus-fill',
                    'title' => Money::format($stats['reorder_cost']).' طلب مقترح',
                    'hint' => 'حوّل النواقص إلى أوامر شراء مجمعة حسب المورد.',
                    'url' => $urls['reorderSuggestions'],
                ],
                [
                    'tone' => 'warning', 'icon' => 'bi-calendar-x-fill',
                    'title' => $stats['expiring_batches'].' دفعة قريبة الانتهاء',
                    'hint' => 'راجع FIFO والعروض اليومية قبل تسجيل هدر.',
                    'url' => $urls['batches'],
                ],
                [
                    'tone' => 'info', 'icon' => 'bi-truck',
                    'title' => $stats['overdue_pos'].' أمر شراء متأخر',
                    'hint' => 'تابع الموردين قبل ما تتحول النواقص إلى نفاد فعلي.',
                    'url' => $urls['sentPurchaseOrders'],
                ],
                [
                    'tone' => 'danger', 'icon' => 'bi-alarm-fill',
                    'title' => Money::format($stats['ap_overdue']).' مستحقات متأخرة',
                    'hint' => 'رتب الدفع حسب الاستحقاق حتى لا يتعطل التوريد.',
                    'url' => $urls['overdueInvoices'],
                ],
                [
                    'tone' => 'muted', 'icon' => 'bi-file-earmark-plus-fill',
                    'title' => $stats['uninvoiced_pos'].' أمر مستلم بلا فاتورة',
                    'hint' => 'أغلق الحلقة بين الاستلام والحسابات.',
                    'url' => $urls['createInvoice'],
                ],
                [
                    'tone' => 'warning', 'icon' => 'bi-slash-circle-fill',
                    'title' => $stats['invoice_variances'].' فرق فاتورة/استلام',
                    'hint' => 'راجع البنود التي لا تطابق الكمية أو القيمة المستلمة.',
                    'url' => $urls['supplierInvoices'],
                ],
            ],

            'reorderQueue' => $reorderQueue->map(function (Ingredient $i) {
                $base = $i->baseUnit?->code;
                $stock = (float) $i->dashboard_stock;
                $need = (float) $i->dashboard_need_qty;

                return [
                    'id' => $i->id,
                    'name' => $i->name,
                    'supplierName' => $i->supplier?->name ?? 'بدون مورد افتراضي',
                    'stock' => $stock,
                    'stockDisplay' => QuantityFormatter::smart($stock, $base),
                    'stockTitle' => number_format($stock, 4).' '.$base,
                    // Nothing left at all reads red; merely below threshold, amber.
                    'stockTone' => $stock <= 0 ? 'text-danger fw-bold' : 'text-warning fw-bold',
                    'needQty' => $need,
                    'needDisplay' => QuantityFormatter::smart($need, $base),
                    'needTitle' => number_format($need, 4).' '.$base,
                    'needCost' => (float) $i->dashboard_need_cost,
                    'needCostLabel' => Money::format($i->dashboard_need_cost),
                ];
            })->values()->all(),

            'expiringBatches' => $expiringBatches->map(function (IngredientBatch $b) {
                $days = $b->daysUntilExpiry();

                return [
                    'id' => $b->id,
                    'ingredientName' => $b->ingredient?->name,
                    'locationName' => $b->storageLocation?->name ?? 'موقع عام',
                    'batchLabel' => (string) ($b->batch_number ?: $b->id),
                    'days' => $days,
                    'daysLabel' => $days < 0 ? 'منتهية' : 'بعد '.$days.' يوم',
                    'tone' => $days < 0 ? 'text-danger' : ($days <= 2 ? 'text-warning' : 'text-muted'),
                    'url' => route('admin.batches.index', ['ingredient_id' => $b->ingredient_id]),
                ];
            })->values()->all(),

            'overduePurchaseOrders' => $overduePurchaseOrders->map(fn (PurchaseOrder $po) => [
                'id' => $po->id,
                'number' => $po->number,
                'supplierName' => $po->supplier?->name ?? '—',
                'totalLabel' => Money::format($po->total),
                'expectedLabel' => $po->expected_at?->diffForHumans(),
                'url' => route('admin.purchase-orders.show', $po),
            ])->values()->all(),

            'uninvoicedPurchaseOrders' => $uninvoicedPurchaseOrders->map(function (PurchaseOrder $po) {
                $pendingLines = $po->items->filter(function ($line) {
                    $invoiced = (float) $line->supplierInvoiceItems
                        ->filter(fn ($item) => $item->supplierInvoice?->status !== 'cancelled')
                        ->sum(fn ($item) => (float) ($item->received_qty ?? $item->quantity));

                    return (float) $line->quantity_received - $invoiced > 0.0001;
                })->count();
                $pendingValue = $this->uninvoicedReceivedValue($po);

                return [
                    'id' => $po->id,
                    'number' => $po->number,
                    'supplierName' => $po->supplier?->name ?? '—',
                    'pendingLines' => $pendingLines,
                    'pendingValue' => $pendingValue,
                    'pendingValueLabel' => Money::format($pendingValue),
                    'receivedAtLabel' => $po->received_at?->format('Y-m-d') ?? $po->updated_at?->format('Y-m-d'),
                    'url' => route('admin.purchase-orders.show', $po),
                    'createInvoiceUrl' => route('admin.supplier-invoices.create', ['po' => $po->id]),
                ];
            })->values()->all(),

            'openPurchaseOrders' => $openPurchaseOrders->map(fn (PurchaseOrder $po) => [
                'id' => $po->id,
                'number' => $po->number,
                'supplierName' => $po->supplier?->name ?? '—',
                'totalLabel' => Money::format($po->total),
                'statusLabel' => $po->statusLabel(),
                'statusColor' => $po->statusColor(),
                'url' => route('admin.purchase-orders.show', $po),
            ])->values()->all(),

            'supplierInvoiceQueue' => $supplierInvoiceQueue->map(fn (SupplierInvoice $inv) => [
                'id' => $inv->id,
                'number' => $inv->number,
                'supplierName' => $inv->supplier?->name ?? '—',
                'statusLabel' => $inv->statusLabel(),
                'balanceLabel' => Money::format($inv->balance),
                'tone' => $inv->isOverdue() ? 'text-danger' : 'text-warning',
                'url' => route('admin.supplier-invoices.show', $inv),
            ])->values()->all(),

            'invoiceVarianceItems' => $invoiceVarianceItems->map(function (SupplierInvoiceItem $item) {
                $invoice = $item->supplierInvoice;

                return [
                    'id' => $item->id,
                    'invoiceNumber' => $invoice?->number,
                    'invoiceUrl' => $invoice ? route('admin.supplier-invoices.show', $invoice) : null,
                    'supplierName' => $invoice ? ($invoice->supplier?->name ?? '—') : null,
                    'description' => $item->description,
                    // The tone is computed on the CAST value, so a null variance
                    // falls to 0.0 and reads muted — same as the Blade did.
                    'varianceQtyLabel' => $item->variance_qty !== null ? Qty::format($item->variance_qty) : '—',
                    'varianceQtyTone' => abs((float) $item->variance_qty) > 0.0001 ? 'text-danger fw-bold' : 'text-muted',
                    'varianceTotalLabel' => $item->variance_total !== null ? Money::format($item->variance_total) : '—',
                    'varianceTotalTone' => abs((float) $item->variance_total) > 0.01 ? 'text-danger fw-bold' : 'text-muted',
                ];
            })->values()->all(),

            'recentReceipts' => $recentReceipts->map(fn (PurchaseReceipt $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'poNumber' => $r->purchaseOrder?->number,
                'poUrl' => $r->purchaseOrder ? route('admin.purchase-orders.show', $r->purchaseOrder) : null,
                'supplierName' => $r->supplier?->name ?? '—',
                'receivedAtLabel' => $r->received_at?->format('Y-m-d H:i'),
            ])->values()->all(),

            'highWaste' => $highWaste->map(fn ($row) => [
                'ingredientId' => (int) $row->ingredient_id,
                'name' => $row->name,
                'eventsCount' => (int) $row->events_count,
                'qty' => (float) $row->qty,
                'qtyDisplay' => QuantityFormatter::smart((float) $row->qty, $row->unit_code),
                'qtyTitle' => number_format((float) $row->qty, 4).' '.$row->unit_code,
                'totalCostLabel' => Money::format($row->total_cost),
            ])->values()->all(),

            'urls' => $urls,
        ]);
    }

    /**
     * Base-currency value received on a PO but not yet covered by a live
     * supplier invoice. Receipt prices are the source of truth because the
     * delivered price may differ from the original order price.
     */
    private function uninvoicedReceivedValue(PurchaseOrder $order): float
    {
        return round((float) $order->items->sum(function ($line) use ($order) {
            $received = (float) $line->quantity_received;
            $invoiced = (float) $line->supplierInvoiceItems
                ->filter(fn ($item) => $item->supplierInvoice?->status !== 'cancelled')
                ->sum(fn ($item) => (float) ($item->received_qty ?? $item->quantity));
            $pending = max(0, $received - $invoiced);
            if ($pending <= 0.0001) {
                return 0;
            }

            $receiptQty = (float) $line->receiptItems->sum('quantity_received');
            $receiptValue = (float) $line->receiptItems->sum('subtotal');
            $actualUnitPrice = $receiptQty > 0.0001
                ? $receiptValue / $receiptQty
                : (float) $line->unit_price;

            return $pending * $actualUnitPrice * (float) ($order->exchange_rate ?: 1);
        }), 4);
    }

    /**
     * Resolve the accountant-configurable posting role before reading its
     * ledger balance. Dashboard reconciliation must follow the same mapping
     * used by AccountingService, not assume the seeded fallback account code.
     */
    private function postingRoleBalance(string $role, ?int $branchId, string $normalBalance): float
    {
        $definition = AccountingService::postingRoleDefinitions()[$role] ?? null;
        if (! $definition) {
            return 0;
        }

        $mapped = AccountMapping::with('account')
            ->where('context', AccountMapping::CONTEXT_POSTING_ROLE)
            ->where('key', $role)
            ->first()?->account;
        $account = $mapped
            && $mapped->is_active
            && in_array($mapped->type, $definition['types'], true)
                ? $mapped
                : Account::where('code', $definition['default'])->where('is_active', true)->first();
        if (! $account) {
            return 0;
        }

        $row = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_lines.account_id', $account->id)
            ->when($branchId, fn ($query, $id) => $query->where('journal_entries.branch_id', $id))
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as debit, COALESCE(SUM(journal_lines.credit), 0) as credit')
            ->first();

        $debit = (float) ($row->debit ?? 0);
        $credit = (float) ($row->credit ?? 0);

        return round($normalBalance === 'credit' ? $credit - $debit : $debit - $credit, 4);
    }

    /**
     * Return the ingredient and pack-size for a scanned barcode. Keeping the
     * endpoint stateless lets receiving screens and future mobile scanners use
     * the same lookup without coupling it to a specific form.
     */
    public function lookupByBarcode(Request $request)
    {
        $this->authorize('viewAny', Ingredient::class);

        $data = $request->validate([
            'barcode' => ['required', 'string', 'max:64'],
        ]);

        $unit = IngredientUnit::with('ingredient.baseUnit')
            ->where('barcode', trim($data['barcode']))
            ->first();

        if ($unit) {
            return response()->json([
                'ok' => true,
                'kind' => 'ingredient_unit',
                'unit' => [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'factor_to_base' => (float) $unit->factor_to_base,
                    'purchase_price' => $unit->purchase_price !== null ? (float) $unit->purchase_price : null,
                ],
                'ingredient' => [
                    'id' => $unit->ingredient->id,
                    'name' => $unit->ingredient->name,
                    'base_unit_id' => $unit->ingredient->base_unit_id,
                    'base_unit_code' => $unit->ingredient->baseUnit?->code,
                ],
            ]);
        }

        return response()->json([
            'ok' => false,
            'error' => 'not_found',
            'message' => "لم نجد عبوة بهذا الباركود ({$data['barcode']}). أضف الوحدة من صفحة المكوّن أولاً.",
        ], 404);
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

        if ($t = $request->get('type')) {
            $q->where('type', $t);
        }
        if ($i = $request->get('ingredient_id')) {
            $q->where('ingredient_id', $i);
        }
        if ($l = $request->get('storage_location_id')) {
            $q->where('storage_location_id', $l);
        }
        if ($r = $request->get('reference_type')) {
            $q->where('reference_type', $r);
        }
        if ($d = $request->get('from')) {
            $q->whereDate('occurred_at', '>=', $d);
        }
        if ($d = $request->get('to')) {
            $q->whereDate('occurred_at', '<=', $d);
        }

        $movements = $q->latest('occurred_at')->paginate(30)->withQueryString();
        $movements->getCollection()->loadMorph('reference', [
            PurchaseOrderItem::class => ['purchaseOrder'],
            OrderItem::class => ['order'],
            StockCountItem::class => ['stockCount'],
        ]);

        $today = InventoryMovement::whereDate('occurred_at', today());
        $stats = [
            'today' => (clone $today)->count(),
            'in' => (clone $today)->where('type', 'in')->count(),
            'out' => (clone $today)->where('type', 'out')->count(),
            'waste' => (clone $today)->where('type', 'waste')->count(),
            'return' => (clone $today)->where('type', 'return')->count(),
            'adjustment' => (clone $today)->where('type', 'adjustment')->count(),
        ];

        return AdminShell::render('Admin/Inventory/Index', [
            'movements' => [
                'data' => $movements->getCollection()
                    ->map(fn (InventoryMovement $m) => $this->movementRow($m))
                    ->all(),
                'links' => $movements->linkCollection()->toArray(),
                'total' => $movements->total(),
            ],
            'stats' => [
                'today' => (int) $stats['today'],
                'in' => (int) $stats['in'],
                'out' => (int) $stats['out'],
                'waste' => (int) $stats['waste'],
                'return' => (int) $stats['return'],
                'adjustment' => (int) $stats['adjustment'],
            ],
            // snake_case on purpose — these keys ARE the query string.
            'filters' => [
                'type' => $request->get('type'),
                'ingredient_id' => $request->get('ingredient_id'),
                'storage_location_id' => $request->get('storage_location_id'),
                'reference_type' => $request->get('reference_type'),
                'from' => $request->get('from'),
                'to' => $request->get('to'),
            ],
            'hasFilters' => $request->hasAny(['type', 'ingredient_id', 'storage_location_id', 'reference_type', 'from', 'to']),
            'options' => [
                'types' => [
                    ['value' => 'in',         'label' => 'إضافة'],
                    ['value' => 'out',        'label' => 'خصم'],
                    ['value' => 'waste',      'label' => 'هدر'],
                    ['value' => 'adjustment', 'label' => 'تعديل'],
                    ['value' => 'return',     'label' => 'إرجاع'],
                ],
                'ingredients' => Ingredient::orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Ingredient $i) => ['id' => $i->id, 'name' => $i->name])
                    ->all(),
                'storageLocations' => StorageLocation::where('active', true)
                    ->orderByDesc('is_default')
                    ->orderBy('display_order')
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (StorageLocation $l) => ['id' => $l->id, 'name' => $l->name])
                    ->all(),
                'referenceTypes' => collect($referenceTypes)
                    ->map(fn (string $label, string $class) => ['value' => $class, 'label' => $label])
                    ->values()
                    ->all(),
            ],
            'urls' => [
                'index' => route('admin.inventory.index'),
            ],
        ]);
    }

    /**
     * One movement row, flattened. Every badge label/colour, both quantity
     * ladders and their 4-decimal tooltips were `@switch`/helper calls in the
     * Blade; they are resolved here so the table renders strings only.
     */
    protected function movementRow(InventoryMovement $m): array
    {
        $base = $m->ingredient?->baseUnit?->code ?? '';
        $qty = (float) $m->quantity_in_base;
        $after = (float) $m->stock_after;
        $reason = $m->waste_reason ? $m->wasteReasonEnum() : null;

        return [
            'id' => $m->id,
            'occurredAtLabel' => $m->occurred_at?->format('Y-m-d H:i'),
            'ingredientName' => $m->ingredient?->name,
            'type' => $m->type,
            'typeLabel' => match ($m->type) {
                'in' => 'إضافة',
                'out' => 'خصم',
                'waste' => 'هدر',
                'return' => 'إرجاع',
                'adjustment' => 'تعديل',
                default => $m->type,
            },
            'typeColor' => match ($m->type) {
                'in' => 'success',
                'out' => 'warning',
                'waste' => 'danger',
                'return' => 'info',
                default => 'secondary',
            },
            'wasteReason' => $reason ? ['label' => $reason->label(), 'color' => $reason->color()] : null,
            'locationName' => $m->storageLocation?->name,
            'batchLabel' => $m->batch ? '#'.($m->batch->batch_number ?: $m->batch_id) : null,
            'qtyDisplay' => QuantityFormatter::smart($qty, $base),
            'qtyTitle' => number_format($qty, 4).' '.$base,
            'afterDisplay' => QuantityFormatter::smart($after, $base),
            'afterTitle' => number_format($after, 4).' '.$base,
            // Qty::format, NOT Money::format — the shipped Blade renders this
            // cost unit-less. Reproduced deliberately; flagged in the report.
            'costLabel' => Qty::format($m->total_cost),
            'reference' => $this->referenceMeta($m),
            'reason' => $m->reason,
            'userName' => $m->user?->name ?? '—',
        ];
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
