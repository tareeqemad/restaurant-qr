<?php

namespace App\Http\Controllers\Admin;

use App\Exports\ProfitLossPdf;
use App\Exports\ProfitLossXlsx;
use App\Helpers\Brand;
use App\Helpers\Money;
use App\Helpers\Qty;
use App\Helpers\QuantityFormatter;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\IngredientSupplierPrice;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Services\InventoryService;
use App\Services\PurchaseOrderService;
use App\Services\Reports\ProfitLossReport;
use App\Support\AdminShell;
use App\Support\BranchContext;
use App\Support\MarketProfile;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportController extends Controller
{
    /**
     * Inject the active-branch filter into a raw query builder. Eloquent
     * models with the BelongsToBranch trait scope automatically; raw
     * `DB::table(...)` queries bypass that scope, so this helper has to
     * stamp the WHERE clause explicitly.
     *
     * No-op when no branch is bound (Super Admin global view).
     */
    protected function scopeRaw(QueryBuilder $query, string $branchColumn): QueryBuilder
    {
        if ($branchId = BranchContext::current()) {
            $query->where($branchColumn, $branchId);
        }

        return $query;
    }

    /**
     * Resolve a from/to date range from the request (defaulting to the last
     * `$defaultDays` days) and return the four forms every report needs:
     * date strings + start/end datetime strings padded to the day boundaries.
     *
     * @return array{0:string,1:string,2:string,3:string} [from, to, start, end]
     */
    protected function dateRange(Request $request, int $defaultDays = 30): array
    {
        $from = $request->get('from', now()->subDays($defaultDays)->toDateString());
        $to = $request->get('to', now()->toDateString());

        return [$from, $to, $from.' 00:00:00', $to.' 23:59:59'];
    }

    /**
     * Base query: order_items joined to orders + menu_items, scoped to the
     * active branch and filtered to "really sold" items (non-cancelled item,
     * non-cancelled order in a status that means it actually went out).
     *
     * Centralizing this means the definition of "a sale" lives in one place;
     * if the status whitelist changes (e.g. a new "refunded" state), every
     * report inherits the fix.
     */
    protected function soldItemsQuery(string $start, string $end): QueryBuilder
    {
        return $this->scopeRaw(
            DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
                ->whereBetween('orders.created_at', [$start, $end])
                ->where('order_items.status', '!=', 'cancelled')
                ->whereIn('orders.status', ['approved', 'preparing', 'ready', 'delivered', 'completed']),
            'orders.branch_id'
        );
    }

    /**
     * The `currency` prop every migrated report page consumes as
     * `currency.symbol`. Resolved exactly the way App\Helpers\Money::format()
     * resolves it (DB setting first, config fallback) so a restaurant that
     * changed its symbol in Settings sees the same symbol on the Vue pages as
     * it does on the Blade ones. Never let a Vue file hardcode a symbol.
     */
    protected function currencyProp(): array
    {
        return [
            'symbol' => Setting::get('currency_symbol', config('restaurant.currency_symbol', '₪')),
            'decimals' => 2,
        ];
    }

    public function index(Request $request)
    {
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to = $request->get('to', now()->toDateString());
        [$start, $end] = [$from.' 00:00:00', $to.' 23:59:59'];

        $invoiceQuery = Invoice::whereBetween('issued_at', [$start, $end])
            ->whereIn('status', ['paid', 'partially_paid']);

        $revenue = (float) (clone $invoiceQuery)->sum('paid_total');
        $invoiceCount = (int) (clone $invoiceQuery)->count();

        $ordersQuery = Order::whereBetween('created_at', [$start, $end])
            ->whereNotIn('status', ['cancelled']);
        $ordersCount = (int) (clone $ordersQuery)->count();

        $cogs = (float) ($this->soldItemsQuery($start, $end)
            ->selectRaw('SUM(order_items.quantity * menu_items.cost) as cogs')
            ->value('cogs') ?? 0);

        $wasteCost = (float) InventoryMovement::whereBetween('occurred_at', [$start, $end])
            ->where('type', 'waste')
            ->sum('total_cost');

        $unpaidBalance = (float) Invoice::whereBetween('issued_at', [$start, $end])
            ->whereIn('status', ['issued', 'partially_paid'])
            ->sum('balance');

        $grossProfit = $revenue - $cogs;
        $marginPct = $revenue > 0 ? ($grossProfit / $revenue) * 100 : 0;
        $averageTicket = $invoiceCount > 0 ? $revenue / $invoiceCount : 0;
        $daysCount = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $dailyAverage = $daysCount > 0 ? $revenue / $daysCount : 0;

        $revenueByDay = (clone $invoiceQuery)
            ->selectRaw('DATE(issued_at) as day, SUM(paid_total) as revenue, COUNT(*) as invoices_count')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $trend = collect();
        for ($i = 0; $i < $daysCount; $i++) {
            $day = Carbon::parse($from)->addDays($i)->toDateString();
            $row = $revenueByDay->get($day);
            $trend->push((object) [
                'day' => $day,
                'label' => Carbon::parse($day)->format('d/m'),
                'revenue' => (float) ($row->revenue ?? 0),
                'invoices_count' => (int) ($row->invoices_count ?? 0),
            ]);
        }

        $topItems = $this->soldItemsQuery($start, $end)
            ->selectRaw('
                menu_items.name,
                SUM(order_items.quantity) as qty,
                SUM(order_items.subtotal) as revenue,
                SUM(order_items.subtotal - (order_items.quantity * menu_items.cost)) as profit
            ')
            ->groupBy('menu_items.id', 'menu_items.name')
            ->orderByDesc('profit')
            ->limit(6)
            ->get();

        $alerts = collect();
        if ($revenue > 0 && $marginPct < 30) {
            $alerts->push([
                'tone' => 'danger',
                'icon' => 'bi-exclamation-triangle-fill',
                'title' => 'هامش الربح منخفض',
                'body' => 'الهامش الحالي '.number_format($marginPct, 1).'%؛ راجع تكلفة الوصفات والأسعار في شاشة تفاصيل الصندوق.',
                'link' => route('admin.reports.profit-loss', compact('from', 'to')),
            ]);
        }
        if ($cogs > 0 && $wasteCost > ($cogs * 0.05)) {
            $alerts->push([
                'tone' => 'warning',
                'icon' => 'bi-trash3-fill',
                'title' => 'الهدر أعلى من الطبيعي',
                'body' => 'قيمة الهدر تمثل '.number_format(($wasteCost / $cogs) * 100, 1).'% من تكلفة المبيعات.',
                'link' => route('admin.reports.inventory', compact('from', 'to')),
            ]);
        }
        if ($unpaidBalance > 0) {
            $alerts->push([
                'tone' => 'info',
                'icon' => 'bi-wallet2',
                'title' => 'رصيد غير محصل',
                'body' => 'يوجد رصيد فواتير غير محصل بقيمة '.Money::format($unpaidBalance).'.',
                'link' => null,
            ]);
        }
        if ($alerts->isEmpty()) {
            $alerts->push([
                'tone' => 'success',
                'icon' => 'bi-check-circle-fill',
                'title' => 'المؤشرات مستقرة',
                'body' => 'لا توجد إشارات حرجة ضمن الفترة المختارة. استخدم البطاقات أدناه للتعمق.',
                'link' => null,
            ]);
        }

        // The 9-tile report map used to live in an @php block at the top of
        // index.blade.php. Routes belong in PHP, so it moves here verbatim.
        $tiles = [
            [
                'title' => 'نهاية اليوم',
                'desc' => 'إقفال اليوم: المقبوض حسب المستخدم، حالات الفواتير، أكثر الأصناف، واستهلاك المخزون.',
                'icon' => 'bi-calendar-check-fill',
                'href' => route('admin.reports.end-of-day'),
                'tone' => 'primary',
            ],
            [
                'title' => 'قائمة الدخل',
                'desc' => 'قراءة الربح الحقيقي بعد تكلفة المبيعات والهدر، مع أكثر الأصناف ربحية.',
                'icon' => 'bi-graph-up-arrow',
                'href' => route('admin.reports.profit-loss', compact('from', 'to')),
                'tone' => 'success',
            ],
            [
                'title' => 'ميزان المراجعة',
                'desc' => 'تحقق محاسبي يثبت أن مجموع المدين = مجموع الدائن لكل القيود اليومية.',
                'icon' => 'bi-columns-gap',
                'href' => route('admin.accounting.trial-balance'),
                'tone' => 'info',
            ],
            [
                'title' => 'هندسة المنيو',
                'desc' => 'صنّف الأصناف حسب الشعبية والربحية لتقرر التسعير، الترويج، أو الإيقاف.',
                'icon' => 'bi-diagram-3-fill',
                'href' => route('admin.reports.menu-engineering', compact('from', 'to')),
                'tone' => 'accent',
            ],
            [
                'title' => 'اقتراحات الشراء',
                'desc' => 'ماذا يجب شراؤه الآن، الكمية المقترحة، تكلفة الطلب، وأيام النفاد.',
                'icon' => 'bi-cart-plus-fill',
                'href' => route('admin.reports.reorder-suggestions'),
                'tone' => 'warning',
            ],
            [
                'title' => 'مخزون ومشتريات',
                'desc' => 'مركز إجراءات للنواقص، الاستلامات، فواتير الموردين، والفروقات بين الفاتورة والاستلام.',
                'icon' => 'bi-speedometer2',
                'href' => route('admin.inventory.dashboard'),
                'tone' => 'success',
            ],
            [
                'title' => 'تقييم المخزون',
                'desc' => 'قيمة المخزون الحالية أو التاريخية مع ABC لتحديد أولويات الجرد.',
                'icon' => 'bi-cash-stack',
                'href' => route('admin.reports.stock-valuation'),
                'tone' => 'danger',
            ],
            [
                'title' => 'المبيعات اليومية',
                'desc' => 'خط يومي للإيراد والفواتير والتحصيل ضمن الفترة المختارة.',
                'icon' => 'bi-bar-chart-line-fill',
                'href' => route('admin.reports.sales', compact('from', 'to')),
                'tone' => 'primary',
            ],
            [
                'title' => 'الأصناف والمخزون',
                'desc' => 'الأكثر بيعاً، استهلاك المكونات، والتحصيل حسب المستخدم في مكان واحد للمراجعة السريعة.',
                'icon' => 'bi-grid-1x2-fill',
                'href' => route('admin.reports.items', compact('from', 'to')),
                'tone' => 'muted',
            ],
        ];

        // Chart normaliser — was `max(1, $trend->max('revenue'))` in the template.
        $maxTrend = max(1, (float) $trend->max('revenue'));

        return AdminShell::render('Admin/Reports/Index', [
            'from' => $from,
            'to' => $to,

            'stats' => [
                'revenue' => $revenue,
                'grossProfit' => $grossProfit,
                'marginPct' => $marginPct,
                'averageTicket' => $averageTicket,
                'ordersCount' => $ordersCount,
                'wasteCost' => $wasteCost,
                'cogs' => $cogs,
                'invoiceCount' => $invoiceCount,
                'dailyAverage' => $dailyAverage,
                'unpaidBalance' => $unpaidBalance,
            ],

            'trend' => $trend->map(fn ($d) => [
                'day' => $d->day,
                'label' => $d->label,
                'revenue' => (float) $d->revenue,
                'invoicesCount' => (int) $d->invoices_count,
                // Bar height was computed in the template (index.blade.php:119).
                'heightPct' => max(6, ((float) $d->revenue / $maxTrend) * 100),
            ])->values()->all(),
            'maxTrend' => $maxTrend,

            'alerts' => $alerts->values()->all(),
            'tiles' => $tiles,

            'topItems' => $topItems->map(fn ($item) => [
                'name' => $item->name,
                'qty' => (float) $item->qty,
                'revenue' => (float) $item->revenue,
                'profit' => (float) $item->profit,
            ])->values()->all(),

            'currency' => $this->currencyProp(),
            'shortcuts' => [
                'today' => ['from' => now()->toDateString(),                'to' => now()->toDateString()],
                'week' => ['from' => now()->subDays(6)->toDateString(),    'to' => now()->toDateString()],
                'month' => ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()],
            ],
            'urls' => [
                'self' => route('admin.reports.index'),
            ],
        ]);
    }

    public function sales(Request $request)
    {
        [$from, $to, $start, $end] = $this->dateRange($request);

        $rows = Invoice::whereBetween('issued_at', [$start, $end])
            ->whereIn('status', ['paid', 'partially_paid', 'unpaid_writeoff'])
            ->select(DB::raw('DATE(issued_at) as day'),
                DB::raw('COUNT(*) as invoices_count'),
                DB::raw('SUM(subtotal) as subtotal'),
                DB::raw('SUM(tax_total) as tax'),
                DB::raw('SUM(service_total) as service'),
                DB::raw('SUM(total) as total'),
                DB::raw('SUM(paid_total) as paid'),
            )
            ->groupBy('day')
            ->orderBy('day', 'desc')
            ->get();

        $totals = [
            'invoices_count' => (int) $rows->sum('invoices_count'),
            'subtotal' => (float) $rows->sum('subtotal'),
            'tax' => (float) $rows->sum('tax'),
            'service' => (float) $rows->sum('service'),
            'total' => (float) $rows->sum('total'),
            'paid' => (float) $rows->sum('paid'),
        ];

        $daysCount = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $averageDaily = $daysCount > 0 ? $totals['paid'] / $daysCount : 0;
        $averageInvoice = $totals['invoices_count'] > 0 ? $totals['paid'] / $totals['invoices_count'] : 0;
        $collectionGap = max(0, $totals['total'] - $totals['paid']);
        $collectionRate = $totals['total'] > 0 ? ($totals['paid'] / $totals['total']) * 100 : 0;
        $bestDay = $rows->sortByDesc('paid')->first();
        $quietDay = $rows->where('paid', '>', 0)->sortBy('paid')->first();

        // Chart normaliser — was `max(1, $rows->max('paid'))` in the template.
        $maxPaid = max(1, (float) $rows->max('paid'));

        // ⚠ Cast trap: `subtotal` and `total` collide with Invoice::$casts
        // (decimal:2) and come back as STRINGS, while tax/service/paid do not.
        // Flatten every figure to float so Vue never string-concatenates.
        $mapRow = function ($r) {
            $total = (float) $r->total;
            $paid = (float) $r->paid;
            $rate = $total > 0 ? ($paid / $total) * 100 : 0;

            return [
                'day' => $r->day,
                // Chart caption was `Carbon::parse($r->day)->format('d/m')` (sales.blade.php:64).
                'dayLabel' => Carbon::parse($r->day)->format('d/m'),
                'invoicesCount' => (int) $r->invoices_count,
                'subtotal' => (float) $r->subtotal,
                'tax' => (float) $r->tax,
                'service' => (float) $r->service,
                'total' => $total,
                'paid' => $paid,
                // rate + tone were computed in the template (sales.blade.php:113,124).
                'rate' => $rate,
                'rateTone' => $rate >= 95 ? 'success' : ($rate >= 80 ? 'warning' : 'danger'),
            ];
        };

        return AdminShell::render('Admin/Reports/Sales', [
            'from' => $from,
            'to' => $to,

            // Table order: DESC by day (the controller's own ordering).
            'rows' => $rows->map($mapRow)->values()->all(),

            // Chart order: ASC by day — the template re-sorted (sales.blade.php:58).
            'chart' => $rows->sortBy('day')->values()->map(function ($r) use ($maxPaid) {
                $paid = (float) $r->paid;

                return [
                    'day' => $r->day,
                    'dayLabel' => Carbon::parse($r->day)->format('d/m'),
                    'paid' => $paid,
                    // Bar height was computed in the template (sales.blade.php:59).
                    'heightPct' => max(8, ($paid / $maxPaid) * 100),
                ];
            })->all(),
            'maxPaid' => $maxPaid,

            'totals' => [
                'invoicesCount' => $totals['invoices_count'],
                'subtotal' => $totals['subtotal'],
                'tax' => $totals['tax'],
                'service' => $totals['service'],
                'total' => $totals['total'],
                'paid' => $totals['paid'],
                // `$totals['tax'] + $totals['service']` was arithmetic in the
                // template (sales.blade.php:87).
                'taxPlusService' => $totals['tax'] + $totals['service'],
            ],

            'collectionRate' => $collectionRate,
            'collectionGap' => $collectionGap,
            'averageDaily' => $averageDaily,
            'averageInvoice' => $averageInvoice,

            'bestDay' => $bestDay ? ['day' => $bestDay->day,  'paid' => (float) $bestDay->paid] : null,
            'quietDay' => $quietDay ? ['day' => $quietDay->day, 'paid' => (float) $quietDay->paid] : null,

            'currency' => $this->currencyProp(),
            'shortcuts' => [
                'today' => ['from' => now()->toDateString(),                 'to' => now()->toDateString()],
                'week' => ['from' => now()->subDays(6)->toDateString(),     'to' => now()->toDateString()],
                'month' => ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()],
            ],
            'urls' => [
                'self' => route('admin.reports.sales'),
                'reportsIndex' => route('admin.reports.index'),
            ],
        ]);
    }

    public function items(Request $request)
    {
        [$from, $to, $start, $end] = $this->dateRange($request);

        // Items report keeps its own simpler join (no menu_items, no status whitelist) —
        // it counts everything that was sent to a station, including items still in flight.
        $rows = $this->scopeRaw(
            DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->whereBetween('orders.created_at', [$start, $end])
                ->where('order_items.status', '!=', 'cancelled'),
            'orders.branch_id'
        )
            ->select('order_items.name_snapshot',
                DB::raw('SUM(order_items.quantity) as qty'),
                DB::raw('SUM(order_items.subtotal) as total'),
            )
            ->groupBy('order_items.name_snapshot')
            ->orderByDesc('qty')
            ->paginate(50)
            ->withQueryString();

        // `rank` and `avgUnit` were computed in the template
        // (items.blade.php:46,50). `firstItem()` is null on an empty page.
        $firstItem = $rows->firstItem() ?? 0;
        $data = collect($rows->items())->values()->map(function ($r, $i) use ($firstItem) {
            $qty = (float) $r->qty;
            $total = (float) $r->total;

            return [
                'rank' => (int) ($firstItem + $i),
                'name' => $r->name_snapshot,
                'qty' => $qty,
                'total' => $total,
                'avgUnit' => $qty > 0 ? $total / $qty : 0,
            ];
        })->all();

        return AdminShell::render('Admin/Reports/Items', [
            'from' => $from,
            'to' => $to,
            'rows' => [
                'data' => $data,
                'links' => $rows->linkCollection()->toArray(),
                'total' => $rows->total(),
            ],
            'currency' => $this->currencyProp(),
            'shortcuts' => [
                'today' => ['from' => now()->toDateString(),                 'to' => now()->toDateString()],
                'week' => ['from' => now()->subDays(6)->toDateString(),     'to' => now()->toDateString()],
                'month' => ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()],
            ],
            'urls' => [
                'self' => route('admin.reports.items'),
                'reportsIndex' => route('admin.reports.index'),
            ],
        ]);
    }

    public function inventory(Request $request)
    {
        [$from, $to, $start, $end] = $this->dateRange($request);

        // `unit` was eager-loaded but `unit_id` is not selected, so the relation
        // could never resolve and nothing read it. `ingredient.baseUnit` was
        // lazy-loaded inside the template loop (N+1) — eager-load it instead.
        // Neither change touches a number or which rows are selected.
        $grouped = InventoryMovement::with('ingredient.baseUnit')
            ->whereBetween('occurred_at', [$start, $end])
            ->select('ingredient_id', 'type',
                DB::raw('SUM(quantity_in_base) as qty'),
                DB::raw('SUM(total_cost) as total_cost'),
            )
            ->groupBy('ingredient_id', 'type')
            ->get()
            ->groupBy('ingredient_id');

        // Flatten the group-of-groups. Every per-row lookup below lived in the
        // template (inventory.blade.php:62-75).
        $rows = $grouped->map(function ($byType, $ingredientId) {
            $ing = $byType->first()->ingredient;
            $base = $ing?->baseUnit?->code;

            $inQty = (float) ($byType->firstWhere('type', 'in')->qty ?? 0);
            $outQty = (float) ($byType->firstWhere('type', 'out')->qty ?? 0);
            $wasteQty = (float) ($byType->firstWhere('type', 'waste')->qty ?? 0);
            $outCost = (float) ($byType->firstWhere('type', 'out')->total_cost ?? 0);
            $wasteCost = (float) ($byType->firstWhere('type', 'waste')->total_cost ?? 0);

            return [
                'ingredientId' => (int) $ingredientId,
                'name' => $ing?->name ?? '',
                'unitCode' => $base,
                'inQty' => $inQty,
                'outQty' => $outQty,
                'wasteQty' => $wasteQty,
                'outCost' => $outCost,
                'wasteCost' => $wasteCost,
                // QuantityFormatter is a PHP-only unit ladder — there is no JS
                // twin, so both the body and the tooltip are pre-rendered here.
                'inDisplay' => QuantityFormatter::smart($inQty, $base),
                'outDisplay' => QuantityFormatter::smart($outQty, $base),
                'wasteDisplay' => QuantityFormatter::smart($wasteQty, $base),
                'inTitle' => number_format($inQty, 4).' '.($base ?? ''),
                'outTitle' => number_format($outQty, 4).' '.($base ?? ''),
                'wasteTitle' => number_format($wasteQty, 4).' '.($base ?? ''),
            ];
        })->values()->all();

        // All five stat-rail totals were computed in the template
        // (inventory.blade.php:3-7). ⚠ The three quantity totals add base units
        // of DIFFERENT ingredients together (grams + millilitres + pieces).
        // That is the shipped behaviour — reproduced, not fixed.
        $totals = [
            'in' => array_sum(array_column($rows, 'inQty')),
            'out' => array_sum(array_column($rows, 'outQty')),
            'waste' => array_sum(array_column($rows, 'wasteQty')),
            'outCost' => array_sum(array_column($rows, 'outCost')),
            'wasteCost' => array_sum(array_column($rows, 'wasteCost')),
        ];

        return AdminShell::render('Admin/Reports/Inventory', [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'rowCount' => $grouped->count(),
            'totals' => $totals,
            'currency' => $this->currencyProp(),
            'shortcuts' => [
                'today' => ['from' => now()->toDateString(),                 'to' => now()->toDateString()],
                'week' => ['from' => now()->subDays(6)->toDateString(),     'to' => now()->toDateString()],
                'month' => ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()],
            ],
            'urls' => [
                'self' => route('admin.reports.inventory'),
                'reportsIndex' => route('admin.reports.index'),
            ],
        ]);
    }

    /**
     * Theoretical vs Actual consumption.
     *
     * Theoretical = what SHOULD have been consumed based on recipes ×
     * sold quantities (sum across all approved order items in the
     * period). Composite ingredients are recursively expanded so the
     * comparison is always against raw inputs.
     *
     * Actual = what WAS actually consumed (sum of inventory_movements
     * with type='out' AND reference_type=OrderItem in the period).
     *
     * Variance = Actual − Theoretical.
     *   Positive → kitchen used MORE than the recipes call for. Possible
     *              over-portioning, theft, spoilage, or recipe under-spec.
     *   Negative → kitchen used LESS. Possible recipe over-spec or short
     *              pouring.
     *   Zero    → recipes match reality. Healthy.
     *
     * Waste (`type='waste'`) is reported alongside as a separate column
     * so the operator sees how much of the variance is explained by
     * documented spoilage vs. mystery loss.
     */
    public function consumptionVariance(Request $request)
    {
        [$from, $to, $start, $end] = $this->dateRange($request);

        // ── 1. Theoretical: walk every sold order item × its recipe ──
        // Expand composites via InventoryService::expandIngredient (the
        // same logic the live deduction uses, so theoretical and actual
        // share the same understanding of what a recipe means).
        $soldItems = OrderItem::query()
            ->whereIn('status', ['approved', 'preparing', 'ready', 'served'])
            ->whereHas('order', fn ($q) => $q
                ->whereBetween('approved_at', [$start, $end])
                ->whereNotIn('status', ['cancelled']))
            ->with(['menuItem.recipeItems.ingredient.baseUnit',
                'menuItem.recipeItems.unit',
                'modifiers.modifier.recipeItems.ingredient.baseUnit',
                'modifiers.modifier.recipeItems.unit'])
            ->get();

        $inv = app(InventoryService::class);
        $theoretical = [];   // ingredient_id => qty_base
        foreach ($soldItems as $oi) {
            if (! $oi->menuItem) {
                continue;
            }
            $modifierIds = $oi->modifiers->pluck('modifier_id')->filter()->all();
            $lines = $inv->previewDeductionForItem(
                $oi->menuItem,
                (float) $oi->quantity,
                $modifierIds,
            );
            foreach ($lines as $ln) {
                $iid = $ln['ingredient_id'];
                $theoretical[$iid] = ($theoretical[$iid] ?? 0) + (float) $ln['quantity_in_base'];
            }
        }

        // ── 2. Actual + Waste — aggregates from inventory_movements ─
        // "Actual" is KITCHEN CONSUMPTION only, i.e. sale deductions referencing
        // an OrderItem. A branch transfer, manual issue, or the 'out' leg of a
        // location move is also type='out' but is NOT consumption — counting it
        // would report phantom theft/over-portioning. Waste stays unfiltered.
        $movementAgg = InventoryMovement::query()
            ->whereBetween('occurred_at', [$start, $end])
            ->where(function ($q) {
                $q->where(fn ($w) => $w->where('type', 'out')
                    ->where('reference_type', OrderItem::class))
                    ->orWhere('type', 'waste');
            })
            ->select('ingredient_id', 'type',
                DB::raw('SUM(quantity_in_base) as qty'),
                DB::raw('SUM(total_cost) as total_cost'))
            ->groupBy('ingredient_id', 'type')
            ->get();

        $actual = [];        // ingredient_id => qty consumed via orders
        $waste = [];        // ingredient_id => qty written off as waste
        $actualCost = [];
        $wasteCost = [];

        foreach ($movementAgg as $row) {
            if ($row->type === 'out') {
                $actual[$row->ingredient_id] = (float) $row->qty;
                $actualCost[$row->ingredient_id] = (float) $row->total_cost;
            } else {
                $waste[$row->ingredient_id] = (float) $row->qty;
                $wasteCost[$row->ingredient_id] = (float) $row->total_cost;
            }
        }

        // ── 3. Stitch together — every ingredient that shows up in
        // either side gets a row. Sort by variance-cost descending so
        // the biggest leaks float to the top.
        $ingredientIds = array_unique(array_merge(
            array_keys($theoretical),
            array_keys($actual),
            array_keys($waste),
        ));
        $ingredients = Ingredient::with('baseUnit')
            ->whereIn('id', $ingredientIds)
            ->get()->keyBy('id');

        $rows = [];
        foreach ($ingredientIds as $iid) {
            $ing = $ingredients->get($iid);
            if (! $ing) {
                continue;
            }

            $theoQty = round((float) ($theoretical[$iid] ?? 0), 4);
            $actQty = round((float) ($actual[$iid] ?? 0), 4);
            $wQty = round((float) ($waste[$iid] ?? 0), 4);
            $variance = round($actQty - $theoQty, 4);
            $variancePct = $theoQty > 0 ? round(($variance / $theoQty) * 100, 1) : null;
            $varianceCost = round($variance * (float) $ing->cost_per_unit, 2);

            $rows[] = [
                'ingredient' => $ing,
                'theoretical' => $theoQty,
                'actual' => $actQty,
                'waste' => $wQty,
                'variance' => $variance,
                'variance_pct' => $variancePct,
                'variance_cost' => $varianceCost,
                'unit_code' => $ing->baseUnit?->code ?? '',
            ];
        }

        // Sort by absolute variance cost — surface the biggest dollar
        // gaps (in either direction) first.
        usort($rows, fn ($a, $b) => abs($b['variance_cost']) <=> abs($a['variance_cost']));

        $summary = [
            'total_theoretical_cost' => array_sum(array_map(
                fn ($r) => $r['theoretical'] * (float) $r['ingredient']->cost_per_unit,
                $rows,
            )),
            'total_actual_cost' => array_sum($actualCost),
            'total_waste_cost' => array_sum($wasteCost),
            'total_variance_cost' => array_sum(array_column($rows, 'variance_cost')),
            'orders_in_range' => $soldItems->pluck('order_id')->unique()->count(),
            'items_in_range' => $soldItems->count(),
        ];

        // Flatten to explicit fields (the row currently carries a whole
        // Ingredient model) and pre-render every string the template built:
        // the QuantityFormatter ladder has no JS twin, and the three dead-band
        // thresholds (0.5 currency units, 5%, 15%) must stay in PHP.
        $mappedRows = array_map(function ($r) {
            $ing = $r['ingredient'];
            $unit = $r['unit_code'];
            $variance = (float) $r['variance'];
            $vCost = (float) $r['variance_cost'];
            $pct = $r['variance_pct'];   // float|null, already round(.., 1)

            return [
                'ingredientId' => (int) $ing->id,
                'name' => $ing->name,
                'sku' => $ing->sku,
                'isComposite' => (bool) $ing->is_composite,
                'unitCode' => $unit,

                'theoretical' => (float) $r['theoretical'],
                'actual' => (float) $r['actual'],
                'waste' => (float) $r['waste'],
                'variance' => $variance,
                'variancePct' => $pct,
                'varianceCost' => $vCost,

                'theoreticalDisplay' => QuantityFormatter::smart((float) $r['theoretical'], $unit),
                'actualDisplay' => QuantityFormatter::smart((float) $r['actual'], $unit),
                'wasteDisplay' => QuantityFormatter::smart((float) $r['waste'], $unit),
                // Sign is rendered separately and the magnitude goes through
                // abs() BEFORE the ladder — a negative variance shows no minus,
                // only the amber colour (consumption-variance.blade.php:123).
                'varianceDisplay' => ($variance > 0 ? '+' : '')
                    .QuantityFormatter::smart(abs($variance), $unit),

                'theoreticalTitle' => number_format((float) $r['theoretical'], 4).' '.$unit,
                'actualTitle' => number_format((float) $r['actual'], 4).' '.$unit,
                'wasteTitle' => number_format((float) $r['waste'], 4).' '.$unit,
                'varianceTitle' => number_format($variance, 4).' '.$unit,   // SIGNED, not abs

                'variancePctText' => $pct === null ? null : (($pct > 0 ? '+' : '').$pct.'%'),
                'varianceCostText' => ($vCost >= 0 ? '+' : '').Money::format($vCost),

                'rowTone' => abs($vCost) < 0.5 ? null : ($vCost > 0 ? 'table-danger' : 'table-warning'),
                'varianceTone' => $variance > 0 ? 'text-danger' : ($variance < 0 ? 'text-warning' : 'text-muted'),
                'pctTone' => $pct === null
                    ? null
                    : (abs($pct) < 5 ? 'bg-success' : (abs($pct) < 15 ? 'bg-warning' : 'bg-danger')),
                'costTone' => $vCost > 0 ? 'text-danger' : ($vCost < 0 ? 'text-warning' : null),
            ];
        }, $rows);

        $totalVarianceCost = (float) $summary['total_variance_cost'];

        return AdminShell::render('Admin/Reports/ConsumptionVariance', [
            'from' => $from,
            'to' => $to,
            'rows' => $mappedRows,
            'rowCount' => count($mappedRows),
            'summary' => [
                'totalTheoreticalCost' => (float) $summary['total_theoretical_cost'],
                'totalActualCost' => (float) $summary['total_actual_cost'],
                'totalWasteCost' => (float) $summary['total_waste_cost'],
                'totalVarianceCost' => $totalVarianceCost,
                'ordersInRange' => (int) $summary['orders_in_range'],
                'itemsInRange' => (int) $summary['items_in_range'],
                // Same 0.5 dead-band the template used twice (card border + text).
                'varianceTone' => $totalVarianceCost > 0.5
                    ? 'danger'
                    : ($totalVarianceCost < -0.5 ? 'warning' : 'success'),
                'totalVarianceCostText' => ($totalVarianceCost >= 0 ? '+' : '')
                    .Money::format($totalVarianceCost),
            ],
            'currency' => $this->currencyProp(),
            'urls' => [
                'self' => route('admin.reports.consumption-variance'),
                'reportsIndex' => route('admin.reports.index'),
            ],
        ]);
    }

    /**
     * Menu Engineering — classifies menu items into a 2×2 matrix:
     *
     *              High margin        │ Low margin
     *   ─────────┼───────────────────┼────────────────
     *   High pop │ ⭐ Star            │ 🐎 Plowhorse
     *   Low pop  │ 🧩 Puzzle          │ 🐕 Dog
     *
     *   - Star:       keep as-is, highlight on menu
     *   - Plowhorse:  popular but low-margin → raise price or re-engineer recipe
     *   - Puzzle:     profitable but unpopular → market more, move to prominent place
     *   - Dog:        drop from menu or reinvent
     *
     * Uses MEDIAN split (not mean) so one viral bestseller doesn't skew the axis.
     */
    public function menuEngineering(Request $request)
    {
        [$from, $to, $start, $end] = $this->dateRange($request);

        $items = $this->soldItemsQuery($start, $end)
            ->selectRaw('
                menu_items.id,
                menu_items.name,
                menu_items.price,
                menu_items.cost,
                SUM(order_items.quantity) as qty_sold,
                SUM(order_items.subtotal) as revenue,
                SUM(order_items.quantity * menu_items.cost) as cogs
            ')
            ->groupBy('menu_items.id', 'menu_items.name', 'menu_items.price', 'menu_items.cost')
            ->get();

        // Crash guard, not a second screen: the median code below would fatal
        // on an empty collection ($sortedByQty[0] on a 0-row Collection).
        // `thresholds` is null IF AND ONLY IF the empty state is showing.
        if ($items->isEmpty()) {
            return AdminShell::render('Admin/Reports/MenuEngineering', [
                'from' => $from,
                'to' => $to,
                'rows' => [],
                'buckets' => ['star' => 0, 'plowhorse' => 0, 'puzzle' => 0, 'dog' => 0],
                'thresholds' => null,
                'topByClass' => ['star' => [], 'plowhorse' => [], 'puzzle' => [], 'dog' => []],
                'currency' => $this->currencyProp(),
                'shortcuts' => $this->menuEngineeringShortcuts(),
                'urls' => [
                    'self' => route('admin.reports.menu-engineering'),
                    'reportsIndex' => route('admin.reports.index'),
                ],
            ]);
        }

        foreach ($items as $it) {
            $it->profit = (float) $it->revenue - (float) $it->cogs;
            $it->margin_pct = (float) $it->revenue > 0
                ? ($it->profit / (float) $it->revenue) * 100
                : 0;
        }

        // Median split on both axes
        $sortedByQty = $items->sortBy('qty_sold')->values();
        $sortedByMargin = $items->sortBy('margin_pct')->values();

        $midIndex = intdiv($items->count(), 2);
        $medianQty = (float) $sortedByQty[$midIndex]->qty_sold;
        $medianMargin = (float) $sortedByMargin[$midIndex]->margin_pct;

        foreach ($items as $it) {
            $highPop = (float) $it->qty_sold >= $medianQty;
            $highMargin = (float) $it->margin_pct >= $medianMargin;

            $it->class = match (true) {
                $highPop && $highMargin => 'star',
                $highPop && ! $highMargin => 'plowhorse',
                ! $highPop && $highMargin => 'puzzle',
                default => 'dog',
            };
        }

        $buckets = [
            'star' => $items->where('class', 'star')->count(),
            'plowhorse' => $items->where('class', 'plowhorse')->count(),
            'puzzle' => $items->where('class', 'puzzle')->count(),
            'dog' => $items->where('class', 'dog')->count(),
        ];

        // ->values() is load-bearing: sortByDesc preserves the original keys,
        // and a non-sequential integer-keyed PHP array JSON-encodes as an
        // OBJECT whose keys JavaScript then re-orders numerically — silently
        // destroying the profit-DESC order.
        $sorted = $items->sortByDesc('profit')->values();

        return AdminShell::render('Admin/Reports/MenuEngineering', [
            'from' => $from,
            'to' => $to,

            'rows' => $sorted->map(fn ($it) => [
                'id' => (int) $it->id,
                'name' => $it->name,
                'qtySold' => (float) $it->qty_sold,
                'revenue' => (float) $it->revenue,
                'cogs' => (float) $it->cogs,
                'profit' => (float) $it->profit,
                'marginPct' => (float) $it->margin_pct,
                'klass' => $it->class,
            ])->all(),

            'buckets' => $buckets,
            'thresholds' => [
                'medianQty' => $medianQty,
                'medianMargin' => $medianMargin,
            ],

            // The four `$classified->where('class', X)->take(4)` calls the
            // template ran (menu-engineering.blade.php:93,111,129,147). Order
            // inherits the profit-DESC sort, so these are the 4 most profitable
            // items of each quadrant.
            'topByClass' => collect(['star', 'plowhorse', 'puzzle', 'dog'])
                ->mapWithKeys(fn ($k) => [
                    $k => $sorted->where('class', $k)->take(4)->pluck('name')->values()->all(),
                ])->all(),

            'currency' => $this->currencyProp(),
            'shortcuts' => $this->menuEngineeringShortcuts(),
            'urls' => [
                'self' => route('admin.reports.menu-engineering'),
                'reportsIndex' => route('admin.reports.index'),
            ],
        ]);
    }

    /**
     * The three range shortcuts menu-engineering.blade.php built inline with
     * now() (lines 22-24). Server-side so they follow the app timezone, and in
     * the Blade's original — slightly odd — on-screen order: 30 يوم، الشهر، 7 أيام.
     */
    protected function menuEngineeringShortcuts(): array
    {
        return [
            'd30' => ['from' => now()->subDays(30)->toDateString(),    'to' => now()->toDateString()],
            'month' => ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()],
            'd7' => ['from' => now()->subDays(7)->toDateString(),     'to' => now()->toDateString()],
        ];
    }

    /**
     * Reorder Suggestions — "what to order from whom, how much, and how
     * urgently".
     *
     * Picks ingredients at or below their reorder threshold, groups them by
     * supplier, and computes:
     *   - target_stock    = max(30-day consumption + safety threshold,
     *                           2 × threshold, 1)
     *   - suggested_qty   = max(target_stock - usable_stock, 0)
     *   - estimated_cost  = qty × cost_per_unit
     *   - daily_burn      = used_30d / 30
     *   - days_to_stockout = current_stock / daily_burn  (∞ if no usage)
     *   - urgency         = 'critical' (≤2 days) | 'high' (≤7) | 'medium' (≤14) | 'low' (>14)
     *
     * Days-to-stockout is the actionable number: it answers "do I order now
     * or can it wait until tomorrow's delivery run?".
     */
    public function reorderSuggestions()
    {
        // 30-day consumption per ingredient (out + waste from movements)
        // — already scoped to active branch by scopeRaw().
        $usage = $this->scopeRaw(
            DB::table('inventory_movements')
                ->where(function ($q) {
                    $q->where('type', 'out')
                        ->orWhere(function ($waste) {
                            $waste->where('type', 'waste')
                                ->whereRaw('ABS(stock_before - stock_after) > 0.0001');
                        });
                })
                ->where('occurred_at', '>=', now()->subDays(30)),
            'inventory_movements.branch_id'
        )
            ->selectRaw('ingredient_id, SUM(quantity_in_base) as used')
            ->groupBy('ingredient_id')
            ->pluck('used', 'ingredient_id');

        // Branch-aware candidate selection: when a branch is active, an
        // ingredient is a candidate iff its PER-BRANCH stock is at or below
        // its PER-BRANCH threshold. Otherwise fall back to global comparison.
        $branchId = BranchContext::current();

        if ($branchId) {
            // Filter in PHP using helpers — pivot+sum query is more complex
            // and the candidate set is small (only tracked ingredients).
            $tracked = Ingredient::with('baseUnit', 'supplier')
                ->where('track_stock', true)
                ->orderBy('supplier_id')
                ->orderBy('name')
                ->get();
            $candidates = $tracked->filter(fn ($i) => $i->isLowStockAtBranch($branchId))->values();
        } else {
            $candidates = Ingredient::with('baseUnit', 'supplier')
                ->where('track_stock', true)
                ->orderBy('supplier_id')
                ->orderBy('name')
                ->get()
                ->filter(fn ($i) => $i->trackedUsableStock() <= (float) $i->reorder_threshold)
                ->values();
        }

        foreach ($candidates as $ing) {
            $used30 = (float) ($usage[$ing->id] ?? 0);

            // Per-branch numbers when branch context is set.
            $current = $branchId ? $ing->usableStockAtBranch($branchId) : $ing->trackedUsableStock();
            $threshold = $branchId ? $ing->reorderThresholdAtBranch($branchId) : (float) $ing->reorder_threshold;
            $cpu = $branchId ? $ing->costAtBranch($branchId) : (float) $ing->cost_per_unit;

            $target = max($used30 + $threshold, 2 * $threshold, 1);
            $suggested = max($target - $current, 0);
            $dailyBurn = $used30 / 30;
            $days = $dailyBurn > 0 ? $current / $dailyBurn : INF;

            $ing->used_30d = $used30;
            $ing->suggested_qty = $suggested;
            $ing->estimated_cost = $suggested * $cpu;
            $ing->daily_burn = $dailyBurn;
            $ing->days_to_stockout = $days;
            $ing->urgency = $this->urgencyBucket($days, $current);

            // Override the model attributes for the view so it shows per-branch values
            $ing->setAttribute('current_stock', $current);
            $ing->setAttribute('reorder_threshold', $threshold);
            $ing->setAttribute('cost_per_unit', $cpu);
        }

        // Group by supplier for convenient PO-per-supplier creation
        $bySupplier = $candidates->groupBy(fn ($i) => $i->supplier_id ?? 0);

        $totalCost = (float) $candidates->sum('estimated_cost');

        // Aggregate urgency counts for the stat rail
        $urgencyCounts = [
            'critical' => $candidates->where('urgency', 'critical')->count(),
            'high' => $candidates->where('urgency', 'high')->count(),
            'medium' => $candidates->where('urgency', 'medium')->count(),
            'low' => $candidates->where('urgency', 'low')->count(),
        ];

        // Alternative suppliers per candidate — the observed price history
        // for this ingredient from every ACTIVE supplier that serves this
        // branch, cheapest first, with the ingredient's own supplier kept
        // as the default pick. Buying the same flour from a cheaper vendor
        // is the entire point of this screen.
        $prices = IngredientSupplierPrice::query()
            ->whereIn('ingredient_id', $candidates->pluck('id'))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereHas('supplier', fn ($q) => $q->where('active', true)
                ->when($branchId, fn ($s) => $s->servingBranch($branchId)))
            ->with(['supplier' => fn ($q) => $q->where('active', true)])
            ->latest('observed_at')->latest('id')
            ->get()
            ->filter(fn ($row) => $row->supplier)
            ->unique(fn ($row) => $row->ingredient_id.'@'.$row->supplier_id)
            ->groupBy('ingredient_id');

        $eligibleSuppliers = Supplier::where('active', true)
            ->when($branchId, fn ($q) => $q->servingBranch($branchId))
            ->get(['id', 'name'])->keyBy('id');

        $rows = $candidates->map(function ($ing) use ($prices, $eligibleSuppliers) {
            $options = collect($prices->get($ing->id, collect()))->map(fn ($row) => [
                'id' => (int) $row->supplier_id,
                'name' => $row->supplier->name,
                'price' => (float) $row->unit_price_in_base,
            ]);

            if ($ing->supplier_id
                && $eligibleSuppliers->has((int) $ing->supplier_id)
                && ! $options->contains('id', (int) $ing->supplier_id)) {
                $options->prepend([
                    'id' => (int) $ing->supplier_id,
                    'name' => $eligibleSuppliers->get((int) $ing->supplier_id)->name,
                    'price' => (float) $ing->cost_per_unit,
                ]);
            }

            $default = $options->firstWhere('id', (int) $ing->supplier_id)
                ?? $options->sortBy('price')->first();

            return [
                'id' => $ing->id,
                'name' => $ing->name,
                'unit' => $ing->baseUnit?->code ?? '',
                'supplierId' => $default['id'] ?? null,
                'supplierName' => $default['name'] ?? 'بدون مورّد محدد',
                'suppliers' => $options->sortBy('price')->values()->all(),
                'currentStock' => (float) $ing->current_stock,
                'threshold' => (float) $ing->reorder_threshold,
                'used30d' => (float) $ing->used_30d,
                'suggestedQty' => (float) $ing->suggested_qty,
                'costPerUnit' => (float) ($default['price'] ?? $ing->cost_per_unit),
                'unitId' => $ing->base_unit_id,
                'daysToStockout' => is_infinite($ing->days_to_stockout) ? null : round($ing->days_to_stockout, 1),
                'urgency' => $ing->urgency,
            ];
        })->values()->all();

        return AdminShell::render('Admin/Reports/ReorderSuggestions', [
            'rows' => $rows,
            'totals' => [
                'items' => $candidates->count(),
                'cost' => (float) $totalCost,
            ],
            'urgencyCounts' => $urgencyCounts,
            'currency' => config('restaurant.currency_symbol', '₪'),
            'canCreatePo' => auth()->user()->can('create', PurchaseOrder::class),
            'urls' => [
                'bulkCreate' => route('admin.reports.reorder-suggestions.bulk-create'),
                'purchaseOrders' => route('admin.purchase-orders.index'),
            ],
        ]);
    }

    protected function urgencyBucket(float $days, float $currentStock): string
    {
        // Already at/below zero — emergency.
        if ($currentStock <= 0) {
            return 'critical';
        }
        if ($days <= 2) {
            return 'critical';
        }
        if ($days <= 7) {
            return 'high';
        }
        if ($days <= 14) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Bulk-create draft POs from selected reorder candidates.
     *
     * The form posts:
     *   selections[] = "ingredientId:qty"   (one entry per checked row)
     *
     * We group by supplier, create one DRAFT PO per supplier (using the
     * existing PurchaseOrderService::create), and redirect to the PO list
     * with a per-supplier summary in the flash bag. The buyer can then open
     * each draft, tweak quantities/prices, and send.
     *
     * Items without a supplier are skipped with a clear message — they
     * must be assigned a supplier first via the ingredient edit page.
     */
    public function createBulkReorderPOs(Request $request, PurchaseOrderService $poService)
    {
        $this->authorize('create', PurchaseOrder::class);

        $data = $request->validate([
            'selections' => ['required', 'array', 'min:1'],
            // "id:qty" (legacy) or "id:qty:supplierId" — the screen lets the
            // buyer route a line to a CHEAPER supplier than the ingredient's
            // default, which is the whole reason the price column exists.
            'selections.*' => ['regex:/^\d+:[\d\.]+(:\d+)?$/'],
        ], [
            'selections.required' => 'لم يتم اختيار أي مكوّن.',
        ]);

        $picks = [];
        $chosenSupplier = [];
        foreach ($data['selections'] as $entry) {
            $parts = explode(':', $entry);
            $id = (int) $parts[0];
            $picks[$id] = (float) $parts[1];
            if (isset($parts[2]) && (int) $parts[2] > 0) {
                $chosenSupplier[$id] = (int) $parts[2];
            }
        }

        $ingredients = Ingredient::with('baseUnit', 'supplier')
            ->whereIn('id', array_keys($picks))
            ->get();

        // A chosen supplier must still be active and serve this branch —
        // never trust the id the client posted.
        if ($chosenSupplier !== []) {
            $branchId = BranchContext::current();
            $valid = Supplier::whereIn('id', array_values($chosenSupplier))
                ->where('active', true)
                ->when($branchId, fn ($q) => $q->servingBranch($branchId))
                ->pluck('id')
                ->all();
            $chosenSupplier = array_filter($chosenSupplier, fn ($sid) => in_array($sid, $valid, true));
        }

        // Group by the CHOSEN supplier when there is one, else the
        // ingredient's own. Null suppliers go into a "skipped" bucket.
        $bySupplier = $ingredients->groupBy(fn ($ing) => $chosenSupplier[$ing->id] ?? $ing->supplier_id);

        $createdPOs = [];
        $skipped = [];

        foreach ($bySupplier as $supplierId => $items) {
            if (! $supplierId) {
                $skipped = array_merge($skipped, $items->pluck('name')->toArray());

                continue;
            }

            $lines = $items->map(fn ($ing) => [
                'ingredient_id' => $ing->id,
                'unit_id' => $ing->base_unit_id,           // suggested in base unit
                'quantity_ordered' => $picks[$ing->id] ?? 0,
                'unit_price' => (float) $ing->cost_per_unit,  // last known cost — buyer will edit
                'notes' => 'مولّد آلياً من اقتراحات إعادة الطلب',
            ])->filter(fn ($l) => $l['quantity_ordered'] > 0)->values()->toArray();

            if (empty($lines)) {
                continue;
            }

            try {
                $po = $poService->create(
                    data: [
                        'supplier_id' => $supplierId,
                        'expected_at' => null,
                        'notes' => 'تم توليد هذا الـ PO آلياً من اقتراحات إعادة الطلب.'
                            .' راجع الكميات والأسعار قبل الإرسال.',
                    ],
                    lines: $lines,
                    userId: $request->user()->id,
                );
                $createdPOs[] = $po;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $msg = count($createdPOs) === 0
            ? 'لم يتم إنشاء أي أمر شراء.'
            : 'تم إنشاء '.count($createdPOs).' أمر شراء كمسودات. راجعها قبل الإرسال.';

        if (! empty($skipped)) {
            $msg .= ' تم تخطي مكوّنات بلا مورّد محدد: '.implode('، ', array_unique($skipped))
                .'. حدد مورّداً لها من شاشة المكونات أولاً.';
        }

        return redirect()->route('admin.purchase-orders.index', ['status' => 'draft'])
            ->with('success', $msg);
    }

    /**
     * P&L — the real profit picture.
     *
     *   Gross Revenue = Σ(paid_total) for invoices in range
     *   COGS          = Σ(order_item.quantity × menu_item.cost) for sold items
     *   Gross Profit  = Revenue − COGS
     *   Waste         = Σ(total_cost) of inventory_movements with type='waste'
     *   Purchases     = Σ(total_cost) of inventory_movements with type='in'
     *                   (cash paid out for stock in range)
     *   Net Operating = Gross Profit − Waste
     *
     * Note: Purchases are shown separately (cash flow) because they don't
     * reduce operating profit immediately — they become inventory until consumed.
     */
    public function profitLoss(Request $request)
    {
        $report = $this->buildProfitLossReport($request)->compute();
        $user = auth()->user();
        $canCreate = (bool) $user?->hasPermission('chart_of_accounts.create');
        $canUpdate = (bool) $user?->hasPermission('chart_of_accounts.update');
        $query = array_filter([
            'from' => $report['period']['from'],
            'to' => $report['period']['to'],
            'source' => ($report['period']['source'] ?? 'ledger') !== 'ledger' ? $report['period']['source'] : null,
        ]);

        return AdminShell::render('Admin/Accounting/ProfitLoss', [
            'report' => $report,
            'currency' => ['code' => MarketProfile::currency(), 'symbol' => MarketProfile::currencySymbol()],
            'exports' => [
                'xlsx' => route('admin.reports.profit-loss.export.xlsx', $query),
                'pdf' => route('admin.reports.profit-loss.export.pdf', $query),
            ],
            'urls' => [
                'home' => route('admin.accounting.index'),
                'guide' => route('admin.accounting.guide'),
                'journal' => route('admin.accounting.journal'),
                'ledger' => route('admin.accounting.ledger'),
                'trialBalance' => route('admin.accounting.trial-balance'),
                'profitLoss' => route('admin.reports.profit-loss'),
                'balanceSheet' => route('admin.accounting.balance-sheet'),
                'aging' => route('admin.accounting.aging'),
                'taxReport' => route('admin.accounting.tax-report'),
                'accounts' => route('admin.accounts.index'),
                'openingBalances' => $canCreate ? route('admin.accounting.opening-balances') : null,
                'manualEntry' => $canCreate ? route('admin.accounting.manual-entry.create') : null,
                'fiscalYears' => $canUpdate ? route('admin.accounting.fiscal-years') : null,
                'periods' => $canUpdate ? route('admin.accounting.periods') : null,
                'mappings' => $canUpdate ? route('admin.accounting.mappings') : null,
                'reconciliations' => $canUpdate ? route('admin.accounting.reconciliations') : null,
                'settlements' => $canUpdate ? route('admin.accounting.settlements') : null,
                'fixedAssets' => route('admin.accounting.fixed-assets.index'),
                'index' => route('admin.reports.profit-loss'),
            ],
        ]);
    }

    public function profitLossExportXlsx(Request $request)
    {
        $report = $this->buildProfitLossReport($request)->compute();

        return app(ProfitLossXlsx::class)->download($report);
    }

    public function profitLossExportPdf(Request $request)
    {
        $report = $this->buildProfitLossReport($request)->compute();

        return app(ProfitLossPdf::class)->download($report);
    }

    /**
     * Construct the P&L report service from the current request — the
     * single entry point used by the screen, the Excel export, and the
     * PDF export. Defaults to month-to-date because that's the cadence
     * owners actually have the conversation.
     */
    protected function buildProfitLossReport(Request $request): ProfitLossReport
    {
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to = $request->get('to', now()->toDateString());
        $branchId = BranchContext::current();
        $source = $request->get('source', 'ledger');
        $usePerBranchCost = $branchId && $request->boolean('per_branch_cost');

        return new ProfitLossReport($from, $to, $branchId, $usePerBranchCost, $source);
    }

    /**
     * Stock Valuation — point-in-time snapshot of inventory worth.
     *
     * "As of date X, what was each tracked SKU's stock × cost worth?"
     * Two modes:
     *   - `as_of` not set → use the live `current_stock` × `cost_per_unit`
     *     (cheap, current, what the kitchen actually holds right now)
     *   - `as_of` provided → "rewind" by replaying movements: start from
     *     current_stock and SUBTRACT every movement that occurred AFTER
     *     as_of (since they happened after our cutoff date). Cost per unit
     *     is taken as the weighted-average AT that time, derived from the
     *     last 'in' movement before the cutoff. This is the close-of-day
     *     valuation accountants ask for at month-end.
     *
     * Includes ABC analysis (Pareto): rank SKUs by value desc, mark top
     * 80% as A, next 15% as B, last 5% as C. Helps the buyer focus
     * negotiation and counts on the items that matter.
     *
     * CSV export available via `?export=csv`.
     */
    public function stockValuation(Request $request)
    {
        abort_unless($request->user()?->hasPermission('inventory.viewAny'), 403);

        if (in_array($request->get('export'), ['xlsx', 'csv'], true)) {
            abort_unless($request->user()?->hasPermission('reports.export'), 403);
        }

        $asOf = $request->get('as_of');                  // YYYY-MM-DD or null
        $asOfTs = $asOf ? Carbon::parse($asOf)->endOfDay() : null;

        // Branch-aware: when the user has a specific branch active, we
        // value ONLY that branch's stock at THAT branch's weighted-average
        // cost. When viewing "all branches" we sum across every branch
        // using each branch's own cost (avoids the classic gotcha of
        // multiplying total qty by a global blended cost).
        $branchId = BranchContext::current();
        $branchName = $branchId
            ? (Branch::find($branchId)?->name ?? '—')
            : 'كل الفروع';

        $ingredients = Ingredient::with('baseUnit', 'supplier')
            ->where('track_stock', true)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        // For historical mode: aggregate signed movements PER ingredient
        // AFTER the cutoff. We invert their effect to recover the qty as it
        // was at $asOfTs. Cheap because of the (ingredient_id, occurred_at)
        // index already on the table. Branch-scoped when active so the
        // rewind matches the snapshot we're computing for.
        $reverseDeltas = collect();
        if ($asOfTs) {
            $rowsQ = InventoryMovement::query()
                ->where('occurred_at', '>', $asOfTs);
            if ($branchId) {
                $rowsQ->where('branch_id', $branchId);
            }
            $rows = $rowsQ
                ->selectRaw("
                    ingredient_id,
                    SUM(CASE
                        WHEN type IN ('out','waste') THEN -quantity_in_base
                        ELSE quantity_in_base
                    END) as net_after_cutoff
                ")
                ->groupBy('ingredient_id')
                ->get();
            $reverseDeltas = $rows->keyBy('ingredient_id');
        }

        $valuationRows = collect();
        foreach ($ingredients as $ing) {
            // Live qty + cost — branch-aware via the helpers on Ingredient
            // (stockAtBranch / costAtBranch / valueAtBranch / trackedStock).
            if ($branchId) {
                $qty = (float) $ing->stockAtBranch($branchId);
                $unitCost = (float) $ing->costAtBranch($branchId);
                $value = (float) $ing->valueAtBranch($branchId);
            } else {
                $qty = (float) $ing->trackedStock();
                $value = (float) $ing->trackedValue();
                // Blended rate for the all-branches view so cost × qty == value.
                $unitCost = $qty > 0 ? $value / $qty : (float) $ing->cost_per_unit;
            }

            // Historical adjustments — rewind the qty by net movements
            // after the cutoff (already branch-filtered when applicable).
            if ($asOfTs && isset($reverseDeltas[$ing->id])) {
                $qty -= (float) $reverseDeltas[$ing->id]->net_after_cutoff;
            }
            if ($qty <= 0.0001 && ! $asOfTs) {
                $qty = max(0, $qty);
            }

            // Historical cost override: use the latest 'in' at-or-before the
            // cutoff (branch-scoped when active).
            if ($asOfTs) {
                $histQ = InventoryMovement::query()
                    ->where('ingredient_id', $ing->id)
                    ->where('type', 'in')
                    ->where('occurred_at', '<=', $asOfTs);
                if ($branchId) {
                    $histQ->where('branch_id', $branchId);
                }
                $hist = $histQ->orderByDesc('occurred_at')->value('unit_cost');
                if ($hist !== null) {
                    $unitCost = (float) $hist;
                }
                $value = $qty * $unitCost;
            }

            $valuationRows->push((object) [
                'ingredient_id' => $ing->id,
                'name' => $ing->name,
                'sku' => $ing->sku,
                'supplier' => $ing->supplier?->name,
                'unit_code' => $ing->baseUnit?->code,
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'value' => $value,
                'is_low_stock' => $branchId
                    ? $ing->isLowStockAtBranch($branchId)
                    : $ing->isLowStock(),
            ]);
        }

        // Sort by value desc and apply ABC classification (Pareto: 80/15/5)
        $sorted = $valuationRows->sortByDesc('value')->values();
        $totalValue = (float) $sorted->sum('value');

        $cumulative = 0.0;
        foreach ($sorted as $row) {
            $cumulative += $row->value;
            $sharePct = $totalValue > 0 ? ($cumulative / $totalValue) * 100 : 0;
            $row->cumulative_pct = $sharePct;
            $row->abc_class = match (true) {
                $sharePct <= 80 => 'A',
                $sharePct <= 95 => 'B',
                default => 'C',
            };
        }

        // Aggregates for stat rail + ABC summary
        $abcCounts = [
            'A' => $sorted->where('abc_class', 'A')->count(),
            'B' => $sorted->where('abc_class', 'B')->count(),
            'C' => $sorted->where('abc_class', 'C')->count(),
        ];
        $abcValues = [
            'A' => (float) $sorted->where('abc_class', 'A')->sum('value'),
            'B' => (float) $sorted->where('abc_class', 'B')->sum('value'),
            'C' => (float) $sorted->where('abc_class', 'C')->sum('value'),
        ];

        $lowStockValue = (float) $sorted->where('is_low_stock', true)->sum('value');
        $rowCount = $sorted->count();

        // Excel export — proper xlsx with multiple columns + sheets.
        if (in_array($request->get('export'), ['xlsx', 'csv'], true)) {
            return $this->exportValuationXlsx(
                $sorted, $totalValue, $abcCounts, $abcValues, $asOf, $branchId, $branchName
            );
        }

        // Flatten to explicit fields. sharePct, the two quantity strings, the
        // unit-cost string, the clamped bar width and the price-history URL
        // were all produced in the template (stock-valuation.blade.php:176,
        // 189-192, 200, 208).
        $mappedRows = $sorted->map(fn ($r) => [
            'ingredientId' => (int) $r->ingredient_id,
            'name' => $r->name,
            'sku' => $r->sku,
            'supplier' => $r->supplier,
            'unitCode' => $r->unit_code,
            'qty' => (float) $r->qty,
            'unitCost' => (float) $r->unit_cost,
            'value' => (float) $r->value,
            'isLowStock' => (bool) $r->is_low_stock,
            'cumulativePct' => (float) $r->cumulative_pct,
            'cumulativeBarPct' => min(100, (float) $r->cumulative_pct),
            'abcClass' => $r->abc_class,
            'sharePct' => $totalValue > 0 ? ((float) $r->value / $totalValue) * 100 : 0,
            // PHP-only formatters — no JS twin, so pre-render both.
            'qtyDisplay' => QuantityFormatter::smart((float) $r->qty, $r->unit_code),
            'qtyTitle' => Qty::format($r->qty).' '.($r->unit_code ?? ''),
            'unitCostDisplay' => Qty::format($r->unit_cost),
            'priceHistoryUrl' => route('admin.vendor-prices.ingredient', $r->ingredient_id),
        ])->values()->all();

        return AdminShell::render('Admin/Reports/StockValuation', [
            'rows' => $mappedRows,
            'totalValue' => $totalValue,
            'rowCount' => $rowCount,
            'lowStockValue' => $lowStockValue,
            'abcCounts' => $abcCounts,
            'abcValues' => $abcValues,
            // `$totalValue > 0 ? ($abcValues['A']/$totalValue)*100 : …` — the
            // template printed the literal '0%' on the divide-by-zero branch,
            // so ship null and let the page render '0%' for it (blade:86).
            'abcAPct' => $totalValue > 0 ? ($abcValues['A'] / $totalValue) * 100 : null,
            'asOf' => $asOf,
            'isHistorical' => (bool) $asOf,
            'branchId' => $branchId,
            'branchName' => $branchName,
            // The Blade rendered the export button unconditionally, so a user
            // without reports.export saw a button that 403s (:997-999).
            'canExport' => (bool) $request->user()?->hasPermission('reports.export'),
            'currency' => $this->currencyProp(),
            'urls' => [
                'self' => route('admin.reports.stock-valuation'),
                // Merges the CURRENT query string so `as_of` survives — a
                // hardcoded ?export=xlsx would silently export today's snapshot.
                'exportXlsx' => route('admin.reports.stock-valuation', array_merge($request->query(), ['export' => 'xlsx'])),
                'current' => route('admin.reports.stock-valuation'),
                'reportsIndex' => route('admin.reports.index'),
            ],
        ]);
    }

    /**
     * Multi-sheet xlsx export of the stock-valuation report.
     *
     * Why xlsx over CSV: previous CSV with UTF-8 BOM still opened as
     * Windows-1256 (mojibake) on Arabic Windows Excel. PhpSpreadsheet
     * writes proper Office Open XML which is UTF-8 by spec.
     *
     * Sheets:
     *   1. ملخص — branch + date + KPIs + ABC summary
     *   2. تفاصيل — every line with its own column for qty, unit cost,
     *               value, share %, cumulative %, ABC class, low-stock flag
     */
    protected function exportValuationXlsx(
        $rows,
        float $totalValue,
        array $abcCounts,
        array $abcValues,
        ?string $asOf,
        ?int $branchId,
        string $branchName
    ) {
        $currency = config('restaurant.currency_symbol', '₪');
        $effDate = $asOf ?: now()->toDateString();
        $stamp = now()->format('Y-m-d_H-i');
        $branchTag = preg_replace('/[^A-Za-z0-9_-]+/', '_', $branchName);
        $filename = "stock-valuation_{$branchTag}_{$effDate}_{$stamp}.xlsx";

        $book = new Spreadsheet;
        $book->getProperties()
            ->setCreator(config('restaurant.name', 'Relax'))
            ->setTitle('Stock Valuation')
            ->setSubject('تقرير تقييم المخزون');

        // ─── Sheet 1: Summary ──────────────────────────────────────────
        $cover = $book->getActiveSheet();
        $cover->setTitle('ملخص');
        $cover->setRightToLeft(true);

        $cover->setCellValue('A1', 'تقرير تقييم المخزون');
        $cover->mergeCells('A1:D1');
        $cover->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => '0F2D22']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $cover->getRowDimension(1)->setRowHeight(36);

        $meta = [
            ['الفرع',           $branchName],
            ['التاريخ',         $effDate.($asOf ? ' (وضع تاريخي)' : ' (الصورة الحالية)')],
            ['عدد الأصناف',     $rows->count()],
            ['إجمالي القيمة',   $totalValue],
            ['تاريخ التقرير',   now()->locale('ar')->isoFormat('D MMMM YYYY · HH:mm')],
        ];
        $row = 3;
        foreach ($meta as $pair) {
            $cover->setCellValue("A{$row}", $pair[0]);
            $cover->setCellValue("B{$row}", $pair[1]);
            $cover->mergeCells("B{$row}:D{$row}");
            $cover->getStyle("A{$row}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF6F1']],
            ]);
            $row++;
        }
        $cover->getStyle('B6')->getNumberFormat()->setFormatCode("#,##0.00 \"{$currency}\"");

        // ABC summary
        $row += 1;
        $cover->setCellValue("A{$row}", 'تحليل ABC (تحليل باريتو)');
        $cover->mergeCells("A{$row}:D{$row}");
        $cover->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'B97818']],
        ]);
        $row += 2;
        $cover->fromArray(['الفئة', 'الوصف', 'عدد الأصناف', 'إجمالي القيمة'], null, "A{$row}");
        $cover->getStyle("A{$row}:D{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4733']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $row++;

        $abcRows = [
            ['A', 'الأهم — أعلى 80% من القيمة (تحتاج أعلى تركيز إداري)', $abcCounts['A'], $abcValues['A']],
            ['B', 'متوسطة الأهمية — التالي 15%',                         $abcCounts['B'], $abcValues['B']],
            ['C', 'الأقل أهمية — آخر 5%',                                $abcCounts['C'], $abcValues['C']],
        ];
        foreach ($abcRows as $r) {
            $cover->fromArray($r, null, "A{$row}");
            $cover->getStyle("D{$row}")->getNumberFormat()->setFormatCode("#,##0.00 \"{$currency}\"");
            $row++;
        }

        // Footnote
        $row += 1;
        $cover->setCellValue("A{$row}", 'كيف نقرأ هذا التقرير؟');
        $cover->mergeCells("A{$row}:D{$row}");
        $cover->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'B97818']],
        ]);
        $row++;
        $cover->setCellValue("A{$row}",
            "• «الكمية» × «سعر الوحدة» = «قيمة الصنف» — المال المحبوس في كل مكوّن.\n"
          ."• «حصة %» = نسبة هذا الصنف من إجمالي قيمة المخزون.\n"
          ."• «تراكمي %» = مجموع الحصص من أعلى صنف لهذا الصنف. يساعد على رؤية: «أول كم صنف يشكلون 80% من قيمة المخزون؟»\n"
          ."• «فئة A»: أهم 20% من الأصناف اللي تشكل 80% من القيمة — جردها كل أسبوع.\n"
          ."• «فئة B»: الـ15% التالية — جردها كل شهر.\n"
          .'• «فئة C»: آخر 5% — جردها كل ربع سنة.'
        );
        $cover->mergeCells("A{$row}:D{$row}");
        $cover->getStyle("A{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $cover->getRowDimension($row)->setRowHeight(140);

        foreach (['A', 'B', 'C', 'D'] as $col) {
            $cover->getColumnDimension($col)->setWidth(25);
        }

        // ─── Sheet 2: Details ──────────────────────────────────────────
        $sheet = $book->createSheet();
        $sheet->setTitle('تفاصيل');
        $sheet->setRightToLeft(true);

        $headers = [
            '#',
            'SKU',
            'المكوّن',
            'المورّد',
            'الوحدة',
            'الكمية',
            "سعر الوحدة ({$currency})",
            "قيمة الصنف ({$currency})",
            'حصة %',
            'تراكمي %',
            'فئة ABC',
            'منخفض المخزون؟',
        ];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:L1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4733']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        // Tooltips on the trickier columns so the file is self-documenting.
        $sheet->getComment('I1')->getText()->createTextRun(
            'حصة هذا الصنف من إجمالي قيمة المخزون = القيمة ÷ الإجمالي × 100'
        );
        $sheet->getComment('J1')->getText()->createTextRun(
            "النسبة المتراكمة من الأعلى قيمة لهذا الصنف.\n"
          .'مثال: لو القيمة عند 80% فأنت في «نقطة باريتو» — كل ما فوق هذا الصنف يشكل 80% من رأس المال.'
        );

        $row = 2;
        foreach ($rows as $i => $r) {
            $sharePct = $totalValue > 0 ? ($r->value / $totalValue) * 100 : 0;

            $sheet->setCellValue("A{$row}", $i + 1);
            $sheet->setCellValue("B{$row}", $r->sku ?? '');
            $sheet->setCellValue("C{$row}", $r->name);
            $sheet->setCellValue("D{$row}", $r->supplier ?? '—');
            $sheet->setCellValue("E{$row}", $r->unit_code ?? '');
            $sheet->setCellValue("F{$row}", (float) $r->qty);
            $sheet->setCellValue("G{$row}", (float) $r->unit_cost);
            $sheet->setCellValue("H{$row}", (float) $r->value);
            $sheet->setCellValue("I{$row}", $sharePct / 100);
            $sheet->setCellValue("J{$row}", ((float) $r->cumulative_pct) / 100);
            $sheet->setCellValue("K{$row}", $r->abc_class);
            $sheet->setCellValue("L{$row}", $r->is_low_stock ? 'نعم' : 'لا');

            // Tint by ABC class (subtle background per class) + low-stock highlight
            $bg = match ($r->abc_class) {
                'A' => 'FEE2E2',  // light red
                'B' => 'FEF3C7',  // light amber
                'C' => 'D1FAE5',  // light green
                default => 'FFFFFF',
            };
            $sheet->getStyle("A{$row}:L{$row}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB($bg);

            if ($r->is_low_stock) {
                $sheet->getStyle("L{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'B91C1C']],
                ]);
            }

            $row++;
        }

        $lastRow = $row - 1;
        if ($lastRow >= 2) {
            // Number formats
            $sheet->getStyle("F2:F{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.0000');
            $sheet->getStyle("G2:G{$lastRow}")->getNumberFormat()->setFormatCode("#,##0.0000 \"{$currency}\"");
            $sheet->getStyle("H2:H{$lastRow}")->getNumberFormat()->setFormatCode("#,##0.00 \"{$currency}\"");
            $sheet->getStyle("I2:J{$lastRow}")->getNumberFormat()->setFormatCode('0.00%');
            $sheet->getStyle("K2:L{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Total row
            $totalRow = $lastRow + 1;
            $sheet->setCellValue("A{$totalRow}", 'الإجمالي');
            $sheet->mergeCells("A{$totalRow}:G{$totalRow}");
            $sheet->setCellValue("H{$totalRow}", "=SUM(H2:H{$lastRow})");
            $sheet->getStyle("H{$totalRow}")->getNumberFormat()->setFormatCode("#,##0.00 \"{$currency}\"");
            $sheet->getStyle("A{$totalRow}:L{$totalRow}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF6F1']],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '0F4731']]],
            ]);
            $sheet->getStyle("A{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            $sheet->setAutoFilter("A1:L{$lastRow}");
        }

        // Column widths
        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(14);
        $sheet->getColumnDimension('C')->setWidth(28);
        $sheet->getColumnDimension('D')->setWidth(20);
        foreach (['E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        $book->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($book) {
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Branch Comparison Report — side-by-side KPIs across every active branch.
     *
     * Restricted to owner-level users (Super Admin + Partner) since it
     * surfaces data from all branches at once. Branch admins/managers
     * already have their own per-branch reports.
     *
     * KPIs shown (rows) × Branches (columns):
     *   - Stock value (Σ qty × cost across branch's locations)
     *   - Low-stock SKUs count
     *   - 30-day waste cost
     *   - 30-day COGS (out movements × unit_cost)
     *   - 30-day purchases value (in movements × unit_cost)
     *   - 30-day revenue (paid invoices)
     *   - Active orders count
     *   - Active table sessions
     *   - Open POs count
     *   - Outstanding AP balance (supplier invoices)
     *
     * Each KPI row marks the best/worst branch with a green/red highlight
     * so an owner can spot patterns in seconds.
     */
    public function branchComparison(Request $request)
    {
        $user = $request->user();
        if (! $user || ! method_exists($user, 'isOwnerLevel') || ! $user->isOwnerLevel()) {
            abort(403, 'هذا التقرير متاح فقط لمستخدمي المستوى المالك (Super Admin / Partner).');
        }

        if ($request->get('export') === 'csv') {
            abort_unless($user->hasPermission('reports.export'), 403);
        }

        [$from, $to, $start, $end] = $this->dateRange($request);

        $branches = Branch::query()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        // Bypass BranchScope to read across all branches.
        $rows = BranchContext::unscoped(function () use ($branches, $start, $end) {
            return $branches->map(function ($branch) use ($start, $end) {
                $bid = $branch->id;

                // Stock value at branch = Σ over the branch's storage_locations
                // of (qty × ingredient.cost_per_unit). Used the global cpu here
                // intentionally — a branch-cost view for valuation is the
                // job of the dedicated stock-valuation report.
                $stockValue = (float) IngredientStock::query()
                    ->join('ingredients', 'ingredient_stock.ingredient_id', '=', 'ingredients.id')
                    ->join('storage_locations', 'ingredient_stock.storage_location_id', '=', 'storage_locations.id')
                    ->where('storage_locations.branch_id', $bid)
                    ->selectRaw('SUM(ingredient_stock.quantity * ingredients.cost_per_unit) as v')
                    ->value('v') ?? 0;

                // Tracked ingredients with stock at this branch ≤ branch threshold
                $lowCount = Ingredient::where('track_stock', true)
                    ->get()
                    ->filter(fn ($i) => $i->isLowStockAtBranch($bid)
                        && $i->stockAtBranch($bid) > 0)
                    ->count();

                $wasteCost = (float) InventoryMovement::query()
                    ->where('branch_id', $bid)
                    ->where('type', 'waste')
                    ->whereBetween('occurred_at', [$start, $end])
                    ->sum('total_cost');

                $cogs = (float) InventoryMovement::query()
                    ->where('branch_id', $bid)
                    ->where('type', 'out')
                    ->whereBetween('occurred_at', [$start, $end])
                    ->sum('total_cost');

                $purchases = (float) InventoryMovement::query()
                    ->where('branch_id', $bid)
                    ->where('type', 'in')
                    ->whereBetween('occurred_at', [$start, $end])
                    ->sum('total_cost');

                $revenue = (float) Invoice::query()
                    ->where('branch_id', $bid)
                    ->whereBetween('issued_at', [$start, $end])
                    ->whereIn('status', ['paid', 'partially_paid'])
                    ->sum('paid_total');

                $openPOs = PurchaseOrder::query()
                    ->where('branch_id', $bid)
                    ->whereIn('status', ['draft', 'sent', 'partially_received'])
                    ->count();

                $apBalance = (float) SupplierInvoice::query()
                    ->where('branch_id', $bid)
                    ->whereNotIn('status', ['paid', 'cancelled'])
                    ->sum('balance');

                $invoiceCount = Invoice::query()
                    ->where('branch_id', $bid)
                    ->whereBetween('issued_at', [$start, $end])
                    ->count();

                return (object) [
                    'branch' => $branch,
                    'stock_value' => $stockValue,
                    'low_count' => $lowCount,
                    'waste_cost' => $wasteCost,
                    'cogs' => $cogs,
                    'purchases' => $purchases,
                    'revenue' => $revenue,
                    'invoice_count' => $invoiceCount,
                    'open_pos' => $openPOs,
                    'ap_balance' => $apBalance,
                    'gross_profit' => $revenue - $cogs,
                    'profit_margin' => $revenue > 0 ? (($revenue - $cogs) / $revenue) * 100 : 0,
                    'waste_pct_of_cogs' => $cogs > 0 ? ($wasteCost / $cogs) * 100 : 0,
                ];
            });
        });

        // Decide which direction is "good" for each KPI so the heatmap
        // can highlight best (green) vs worst (red).
        // higher_is_better = true → max value is good
        // higher_is_better = false → min value is good (lower waste = better)
        $kpiDefs = [
            ['key' => 'revenue',          'label' => 'الإيراد',                 'fmt' => 'money',  'higher_is_better' => true],
            ['key' => 'cogs',             'label' => 'COGS',                     'fmt' => 'money',  'higher_is_better' => null],  // contextual
            ['key' => 'gross_profit',     'label' => 'الربح الإجمالي',           'fmt' => 'money',  'higher_is_better' => true],
            ['key' => 'profit_margin',    'label' => 'هامش الربح %',            'fmt' => 'percent', 'higher_is_better' => true],
            ['key' => 'waste_cost',       'label' => 'تكلفة الهدر',              'fmt' => 'money',  'higher_is_better' => false],
            ['key' => 'waste_pct_of_cogs', 'label' => 'الهدر كـ % من COGS',      'fmt' => 'percent', 'higher_is_better' => false],
            ['key' => 'purchases',        'label' => 'مشتريات الفترة',           'fmt' => 'money',  'higher_is_better' => null],
            ['key' => 'stock_value',      'label' => 'قيمة المخزون الحالي',     'fmt' => 'money',  'higher_is_better' => null],
            ['key' => 'low_count',        'label' => 'مكونات بمخزون منخفض',     'fmt' => 'int',    'higher_is_better' => false],
            ['key' => 'invoice_count',    'label' => 'عدد الفواتير',             'fmt' => 'int',    'higher_is_better' => true],
            ['key' => 'open_pos',         'label' => 'POs مفتوحة',              'fmt' => 'int',    'higher_is_better' => null],
            ['key' => 'ap_balance',       'label' => 'مستحقات للموردين',        'fmt' => 'money',  'higher_is_better' => false],
        ];

        // Pre-compute best/worst index per KPI for highlighting
        foreach ($kpiDefs as &$kpi) {
            if ($kpi['higher_is_better'] === null) {
                $kpi['best_idx'] = null;
                $kpi['worst_idx'] = null;

                continue;
            }
            $values = $rows->pluck($kpi['key'])->all();
            if (empty($values) || max($values) === min($values)) {
                $kpi['best_idx'] = null;
                $kpi['worst_idx'] = null;

                continue;
            }
            $kpi['best_idx'] = $kpi['higher_is_better']
                ? array_search(max($values), $values, true)
                : array_search(min($values), $values, true);
            $kpi['worst_idx'] = $kpi['higher_is_better']
                ? array_search(min($values), $values, true)
                : array_search(max($values), $values, true);
        }
        unset($kpi);

        // CSV export — same data, same shape, ready for Excel/Google Sheets.
        // The accountant just wants the matrix as a file.
        if ($request->get('export') === 'csv') {
            return $this->exportBranchComparisonCsv($branches, $rows, $kpiDefs, $from, $to);
        }

        // The $fmt closure lived at the TOP of branch-comparison.blade.php
        // (lines 4-14) and every number on the screen went through it.
        // ⚠ It hardcodes ' ₪' — it does NOT use Money::format / the
        // currency_symbol setting. Reproduced verbatim; unifying it would be a
        // visible behaviour change that needs the owner's call.
        $fmt = function ($value, string $type): string {
            if (is_null($value)) {
                return '—';
            }

            return match ($type) {
                'money' => number_format((float) $value, 2).' ₪',
                'percent' => number_format((float) $value, 2).'%',
                'int' => (string) (int) $value,
                default => (string) $value,
            };
        };

        // Flatten: each row currently carries a whole Branch Eloquent model
        // under ->branch, which Inertia would serialise in full.
        $flatRows = $rows->map(fn ($row) => [
            'branchId' => (int) $row->branch->id,
            'revenue' => (float) $row->revenue,
            'cogs' => (float) $row->cogs,
            'gross_profit' => (float) $row->gross_profit,
            'profit_margin' => (float) $row->profit_margin,
            'waste_cost' => (float) $row->waste_cost,
            'waste_pct_of_cogs' => (float) $row->waste_pct_of_cogs,
            'purchases' => (float) $row->purchases,
            'stock_value' => (float) $row->stock_value,
            'low_count' => (int) $row->low_count,
            'invoice_count' => (int) $row->invoice_count,
            'open_pos' => (int) $row->open_pos,
            'ap_balance' => (float) $row->ap_balance,
        ])->values()->all();

        // rows[] and branches[] are joined ONLY by integer position, and
        // best_idx/worst_idx are indices into rows. Pre-render the whole matrix
        // so no client-side re-order can land a highlight on the wrong branch.
        $cells = [];
        $kpiOut = [];
        foreach ($kpiDefs as $kpi) {
            $line = [];
            foreach ($rows as $idx => $row) {
                $line[] = [
                    'text' => $fmt($row->{$kpi['key']}, $kpi['fmt']),
                    'isBest' => $kpi['best_idx'] !== null && $kpi['best_idx'] === $idx,
                    'isWorst' => $kpi['worst_idx'] !== null && $kpi['worst_idx'] === $idx,
                ];
            }
            $cells[] = $line;
            $kpiOut[] = [
                'key' => $kpi['key'],
                'label' => $kpi['label'],
                'fmt' => $kpi['fmt'],
                'higher_is_better' => $kpi['higher_is_better'],
                'best_idx' => $kpi['best_idx'],
                'worst_idx' => $kpi['worst_idx'],
                // blade:79-83 — nothing rendered when higher_is_better is null.
                'directionHint' => $kpi['higher_is_better'] === false
                    ? '↓ الأقل أفضل'
                    : ($kpi['higher_is_better'] === true ? '↑ الأعلى أفضل' : null),
            ];
        }

        return AdminShell::render('Admin/Reports/BranchComparison', [
            'from' => $from,
            'to' => $to,
            // ⚠ raw ->name, not ->localizedName() — same as the Blade today.
            'branches' => $branches->map(fn ($b) => [
                'id' => (int) $b->id,
                'name' => $b->name,
                'code' => $b->code,
            ])->values()->all(),
            'rows' => $flatRows,
            'rowCount' => $rows->count(),
            'kpiDefs' => $kpiOut,
            'cells' => $cells,
            // Dead gate in practice: the owner-level abort above guarantees
            // hasPermission() is true here. Shipped for completeness only.
            'canExport' => (bool) $user->hasPermission('reports.export'),
            'exportUrl' => route('admin.reports.branch-comparison', array_merge($request->query(), ['export' => 'csv'])),
            'notesText' => [
                '«هامش الربح» يُحسب من (Revenue − COGS) / Revenue ضمن الفترة المختارة.',
                '«الهدر كـ % من COGS» مقياس صحة المطبخ — أقل من 3% ممتاز، 3-7% مقبول، فوق 7% يحتاج مراجعة.',
                'بعض الـ KPIs (المشتريات، POs، قيمة المخزون، COGS) لا تحمل اتجاه «أفضل» تلقائي لأنها تعتمد على حجم العمليات في كل فرع.',
            ],
            // This screen hardcodes ₪ in its own number strings (see $fmt);
            // the prop is here so nothing new hardcodes one.
            'currency' => $this->currencyProp(),
            'urls' => [
                'self' => route('admin.reports.branch-comparison'),
                'reportsIndex' => route('admin.reports.index'),
            ],
        ]);
    }

    /**
     * Stream the branch-comparison matrix as a CSV. The output matches
     * what the on-screen table shows: KPIs in rows, branches in columns,
     * preceded by a small header (date range + generated-at timestamp).
     *
     * UTF-8 BOM so Arabic labels render correctly when opened directly
     * in Excel without going through the import wizard.
     */
    protected function exportBranchComparisonCsv($branches, $rows, $kpiDefs, string $from, string $to)
    {
        $filename = "branch-comparison-{$from}-to-{$to}.csv";

        return response()->streamDownload(function () use ($branches, $rows, $kpiDefs, $from, $to) {
            $out = fopen('php://output', 'w');
            // BOM so Excel recognises UTF-8 (Arabic labels)
            fwrite($out, "\xEF\xBB\xBF");

            // Two-line header: scope + timestamp
            fputcsv($out, ['Branch Comparison Report', "From: {$from}", "To: {$to}", 'Generated: '.now()->toDateTimeString()]);
            fputcsv($out, []);

            // Column header row: "KPI" + each branch name
            $header = ['KPI', 'Direction'];
            foreach ($branches as $branch) {
                $header[] = $branch->name.($branch->code ? " ({$branch->code})" : '');
            }
            fputcsv($out, $header);

            // Each KPI gets its own row
            foreach ($kpiDefs as $kpi) {
                $direction = match ($kpi['higher_is_better']) {
                    true => 'higher = better',
                    false => 'lower = better',
                    null => '',
                };
                $line = [$kpi['label'], $direction];
                foreach ($rows as $row) {
                    $value = $row->{$kpi['key']};
                    if (is_null($value)) {
                        $line[] = '';
                    } elseif ($kpi['fmt'] === 'money' || $kpi['fmt'] === 'percent') {
                        $line[] = number_format((float) $value, 2, '.', '');
                    } else {
                        $line[] = (int) $value;
                    }
                }
                fputcsv($out, $line);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function endOfDay(Request $request)
    {
        $date = $request->get('date', now()->toDateString());
        $start = $date.' 00:00:00';
        $end = $date.' 23:59:59';

        $invoices = Invoice::whereBetween('issued_at', [$start, $end])->get();
        $payments = Payment::with('receiver')->whereBetween('paid_at', [$start, $end])->get();

        $byMethod = $payments->groupBy('method')->map(fn ($g) => [
            'count' => $g->count(),
            'total' => (float) $g->sum('amount'),
        ]);

        $byCollector = $payments->groupBy(fn ($payment) => $payment->received_by_user_id ?: 0)
            ->map(fn ($group) => [
                'name' => $group->first()?->receiver?->name ?? 'تحصيل ذاتي / غير محدد',
                'count' => $group->count(),
                'cash' => (float) $group->where('method', 'cash')->sum('amount'),
                'transfer' => (float) $group->where('method', 'transfer')->sum('amount'),
                'total' => (float) $group->sum('amount'),
            ])
            ->sortByDesc('total')
            ->values();

        $orders = Order::whereBetween('created_at', [$start, $end])->get();

        // EoD top items mirrors the simpler items() report (no menu_items join,
        // no status whitelist) — owners want to see "what went out today",
        // including items still on the line.
        $topItems = $this->scopeRaw(
            DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->whereBetween('orders.created_at', [$start, $end])
                ->where('order_items.status', '!=', 'cancelled'),
            'orders.branch_id'
        )
            ->select('order_items.name_snapshot', DB::raw('SUM(order_items.quantity) as qty'), DB::raw('SUM(order_items.subtotal) as total'))
            ->groupBy('order_items.name_snapshot')
            ->orderByDesc('qty')
            ->limit(15)
            ->get();

        // Pull the base-unit code alongside so the view can format
        // each row smartly (5,000,000 g → "5 طن") via
        // QuantityFormatter — operator no longer has to count commas.
        $inventoryUsage = $this->scopeRaw(
            DB::table('inventory_movements')
                ->join('ingredients', 'inventory_movements.ingredient_id', '=', 'ingredients.id')
                ->leftJoin('units', 'ingredients.base_unit_id', '=', 'units.id')
                ->whereBetween('inventory_movements.occurred_at', [$start, $end])
                ->whereIn('inventory_movements.type', ['out', 'waste']),
            'inventory_movements.branch_id'
        )
            ->select('ingredients.name', 'inventory_movements.type', 'units.code as base_unit_code',
                DB::raw('SUM(inventory_movements.quantity_in_base) as qty'),
                DB::raw('SUM(inventory_movements.total_cost) as cost'))
            ->groupBy('ingredients.name', 'inventory_movements.type', 'units.code')
            ->get()
            ->groupBy('name');

        $summary = [
            'invoices_count' => $invoices->count(),
            'invoices_paid' => $invoices->where('status', 'paid')->count(),
            'invoices_unpaid' => $invoices->whereIn('status', ['issued', 'partially_paid'])->count(),
            'invoices_writeoff' => $invoices->where('status', 'unpaid_writeoff')->count(),
            'orders_count' => $orders->count(),
            'orders_cancelled' => $orders->where('status', 'cancelled')->count(),
            'gross_sales' => (float) $invoices->sum('subtotal'),
            'tax_total' => (float) $invoices->sum('tax_total'),
            'service_total' => (float) $invoices->sum('service_total'),
            'discount_total' => (float) $invoices->sum('discount_total'),
            'total_billed' => (float) $invoices->sum('total'),
            'total_collected' => (float) $payments->sum('amount'),
        ];

        // Method → Arabic label was an @switch in the template (blade:53).
        // An unrecognised method rendered an EMPTY cell — preserved as ''.
        $methodLabels = [
            'cash' => 'كاش',
            'card' => 'كارد',
            'transfer' => 'حوالة',
            'app' => 'تطبيق',
            'credit' => 'دين',
        ];

        $byMethodRows = $byMethod->map(fn ($data, $method) => [
            'method' => (string) $method,
            'label' => $methodLabels[$method] ?? '',
            'count' => (int) $data['count'],
            'total' => (float) $data['total'],
        ])->values()->all();

        // tfoot aggregations over the ALREADY-GROUPED collection (blade:56).
        $byMethodTotals = [
            'count' => (int) $byMethod->sum('count'),
            'total' => (float) $byMethod->sum('total'),
        ];

        // Flatten the name-grouped inventory usage in the exact order the
        // template rendered it (group order, then row order within a group),
        // and accumulate the two cost totals that the @php block in the
        // template built while iterating the rendered rows (blade:108-121).
        $usageRows = [];
        $usageCost = 0.0;
        $wasteCost = 0.0;
        foreach ($inventoryUsage as $name => $group) {
            foreach ($group as $row) {
                $isWaste = $row->type === 'waste';
                $qty = (float) $row->qty;
                $cost = (float) $row->cost;

                if ($isWaste) {
                    $wasteCost += $cost;
                } else {
                    $usageCost += $cost;
                }

                $usageRows[] = [
                    'name' => $name,
                    'type' => $row->type,
                    'isWaste' => $isWaste,
                    'qty' => $qty,
                    'baseUnitCode' => $row->base_unit_code,
                    // PHP-only unit ladder — no JS twin, pre-rendered here.
                    'qtyText' => QuantityFormatter::smart($qty, $row->base_unit_code),
                    'qtyExact' => number_format($qty, 4).' '.($row->base_unit_code ?? ''),
                    'cost' => $cost,
                    // ⚠ 2 dp with NO currency symbol — deliberately unlike every
                    // other money cell on this page.
                    'costText' => number_format($cost, 2),
                ];
            }
        }

        return AdminShell::render('Admin/Reports/EndOfDay', [
            'date' => $date,
            'summary' => $summary,

            'byMethod' => $byMethodRows,
            'byMethodTotals' => $byMethodTotals,

            'byCollector' => $byCollector->map(fn ($c) => [
                'name' => $c['name'],
                'count' => (int) $c['count'],
                'cash' => (float) $c['cash'],
                'transfer' => (float) $c['transfer'],
                // ALL methods — card/app/credit live in `total` with no column
                // of their own, so cash + transfer < total is normal.
                'total' => (float) $c['total'],
            ])->values()->all(),

            'topItems' => $topItems->values()->map(fn ($r, $i) => [
                'rank' => $i + 1,
                'name' => $r->name_snapshot,
                'qty' => (float) $r->qty,
                'qtyText' => number_format((float) $r->qty, 1),   // ONE decimal
                'total' => (float) $r->total,
            ])->all(),

            'inventoryUsage' => $usageRows,
            'inventoryTotals' => [
                'usageCost' => $usageCost,
                'wasteCost' => $wasteCost,
                'grandTotal' => $usageCost + $wasteCost,
                'usageText' => number_format($usageCost, 2),
                'wasteText' => number_format($wasteCost, 2),
                'grandText' => number_format($usageCost + $wasteCost, 2),
                'showFooter' => ($usageCost + $wasteCost) > 0,
                'showWasteRow' => $wasteCost > 0,
            ],

            // StatRail expressions from the template (blade:41,42,43).
            'statTones' => [
                'orders' => $summary['orders_cancelled'] > 0 ? 'warning' : 'info',
                'discount' => $summary['discount_total'] > 0 ? 'warning' : 'muted',
            ],
            'invoicesPaidLabel' => $summary['invoices_paid'].' / '.$summary['invoices_count'],

            // Used only by the print-only header block (blade:18).
            'brandName' => Brand::name(),
            'currency' => $this->currencyProp(),
            'urls' => [
                'self' => route('admin.reports.end-of-day'),
                'reportsIndex' => route('admin.reports.index'),
            ],
        ]);
    }
}
