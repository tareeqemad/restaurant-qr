<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\Money;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Employee;
use App\Models\OrderItem;
use App\Models\StaffMealCharge;
use App\Models\StaffMealMonthClosure;
use App\Models\User;
use App\Services\StaffMealService;
use App\Support\AdminShell;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Staff meal allowance dashboard — per-employee monthly tab status,
 * settlement (cash / payroll deduction / writeoff), and history.
 */
class StaffMealController extends Controller
{
    public function __construct(protected StaffMealService $service) {}

    public function index(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('staff_meals.viewAny'), 403);

        $month = $request->filled('month')
            ? Carbon::parse($request->get('month').'-01')
            : now()->startOfMonth();
        $branchId = BranchContext::current();

        Employee::importLegacyMealUsers();

        $employees = Employee::query()
            ->whereNotNull('monthly_meal_allowance')
            ->where('monthly_meal_allowance', '>', 0)
            ->when($branchId, fn ($query) => $query->whereHas('branches', fn ($branch) => $branch->where('branches.id', $branchId)))
            ->orderBy('name')
            ->get();

        $rows = $employees->map(function (Employee $u) use ($month, $branchId) {
            $summary = $this->service->monthSummary($u, $month, $branchId);

            return [
                'employee' => $u,
                'summary' => $summary,
            ];
        });

        $totals = [
            'staff_count' => $employees->count(),
            'total_allowance' => (float) $employees->sum('monthly_meal_allowance'),
            'total_used' => (float) $rows->sum(fn ($r) => $r['summary']['used']),
            'total_covered' => (float) $rows->sum(fn ($r) => $r['summary']['covered'] ?? 0),
            'total_gifted' => (float) $rows->sum(fn ($r) => $r['summary']['gifted'] ?? 0),
            'total_outstanding' => (float) $rows->sum(fn ($r) => $r['summary']['outstanding']),
            'over_limit_count' => $rows->filter(fn ($r) => $r['summary']['overflow'] > 0)->count(),
        ];

        $actor = $request->user();

        return AdminShell::render('Admin/StaffMeals/Index', [
            'month' => ['value' => $month->format('Y-m'), 'label' => $month->translatedFormat('F Y')],
            'rows' => $rows->map(fn (array $row) => $this->presentEmployeeSummary($row['employee'], $row['summary']))->values(),
            'totals' => [
                'staffCount' => $totals['staff_count'],
                'allowance' => Money::format($totals['total_allowance']),
                'used' => Money::format($totals['total_used']),
                'covered' => Money::format($totals['total_covered']),
                'gifted' => Money::format($totals['total_gifted']),
                'outstanding' => Money::format($totals['total_outstanding']),
                'overLimitCount' => $totals['over_limit_count'],
            ],
            'branches' => $actor?->isOwnerLevel()
                ? Branch::where('is_active', true)->orderBy('display_order')->get(['id', 'name'])
                    ->map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->localizedName()])->values()
                : [],
            'selectedBranchId' => $branchId,
            'can' => [
                'quickConsume' => (bool) $actor?->hasPermission('staff_meals.quick_consume'),
                'closeMonth' => (bool) $actor?->hasPermission('staff_meals.close_month'),
                'manageEmployees' => (bool) ($actor?->hasPermission('staff_meals.quick_consume') || $actor?->hasPermission('users.create')),
            ],
            'urls' => [
                'index' => route('admin.staff-meals.index'),
                'quickConsume' => route('admin.staff-meals.quick_consume'),
                'closures' => route('admin.staff-meals.closures'),
                'closeMonth' => route('admin.staff-meals.close_month'),
                'employeesStore' => route('admin.staff-meals.employees.store'),
            ],
            'employeeForm' => $this->employeeFormOptions($actor),
        ]);
    }

    public function show(Employee $employee)
    {
        abort_unless(auth()->user()?->hasPermission('staff_meals.viewAny'), 403);

        $branchId = BranchContext::current();
        $summary = $this->service->monthSummary($employee, null, $branchId);
        $charges = StaffMealCharge::with('order.items')
            ->where('employee_id', $employee->id)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->latest('charged_at')
            ->limit(60)
            ->get();

        // Per-item breakdown for the current month — aggregates
        // `order_items` from every open + settled charge in the
        // current month. Uses `unit_price` from the snapshot so
        // historical prices are honored even if the menu changed.
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $itemAggregate = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.staff_consumer_employee_id', $employee->id)
            ->when($branchId, fn ($query) => $query->where('orders.branch_id', $branchId))
            ->whereBetween('orders.completed_at', [$monthStart, $monthEnd])
            ->whereNotIn('order_items.status', ['cancelled'])
            ->selectRaw('
                order_items.menu_item_id,
                MAX(order_items.name_snapshot) as name,
                SUM(order_items.quantity)     as total_qty,
                SUM(order_items.subtotal)     as total_value,
                AVG(order_items.unit_price)   as avg_unit_price
            ')
            ->groupBy('order_items.menu_item_id')
            ->orderByDesc('total_value')
            ->get();

        $actor = auth()->user();
        $settlementLabels = [
            'cash' => 'نقدي', 'payroll_deduction' => 'خصم راتب',
            'writeoff' => 'شطب', 'gift' => 'هدية', 'allowance' => 'ضمن البدل',
        ];

        return AdminShell::render('Admin/StaffMeals/Show', [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'role' => $employee->roleLabel(),
                'access' => $employee->accessLabel(),
            ],
            'summary' => [
                ...$summary,
                'allowanceFormatted' => Money::format($summary['allowance'] ?? 0),
                'usedFormatted' => Money::format($summary['used']),
                'coveredFormatted' => Money::format($summary['covered'] ?? 0),
                'giftedFormatted' => Money::format($summary['gifted'] ?? 0),
                'remainingFormatted' => Money::format($summary['remaining'] ?? 0),
                'outstandingFormatted' => Money::format($summary['outstanding']),
            ],
            'monthLabel' => $monthStart->translatedFormat('F Y'),
            'items' => $itemAggregate->map(fn ($row) => [
                'name' => $row->name,
                'quantity' => rtrim(rtrim(number_format($row->total_qty, 2), '0'), '.'),
                'averagePrice' => Money::format($row->avg_unit_price),
                'total' => Money::format($row->total_value),
            ])->values(),
            'itemsTotal' => Money::format($itemAggregate->sum('total_value')),
            'charges' => $charges->map(fn (StaffMealCharge $charge) => [
                'id' => $charge->id,
                'date' => $charge->charged_at?->format('Y-m-d H:i'),
                'orderNumber' => $charge->order?->number,
                'amount' => (float) $charge->amount,
                'amountFormatted' => Money::format($charge->amount),
                'consumptionAmount' => $charge->consumptionAmount(),
                'consumptionFormatted' => Money::format($charge->consumptionAmount()),
                'coveredAmount' => $charge->coveredAmount(),
                'coveredFormatted' => Money::format($charge->coveredAmount()),
                'settled' => (bool) $charge->settled_at,
                'method' => $charge->settlement_method,
                'methodLabel' => $settlementLabels[$charge->settlement_method] ?? $charge->settlement_method,
                'notes' => $charge->notes,
                'waiveUrl' => route('admin.staff-meals.charges.waive', $charge),
            ])->values(),
            'can' => [
                'settle' => (bool) $actor?->hasPermission('staff_meals.settle'),
                'waive' => (bool) $actor?->hasPermission('staff_meals.waive'),
            ],
            'urls' => [
                'index' => route('admin.staff-meals.index'),
                'settle' => route('admin.staff-meals.settle', $employee),
                'quickConsume' => route('admin.staff-meals.quick_consume'),
            ],
        ]);
    }

    /**
     * Quick-consume form — the cashier/manager picks an employee and
     * a handful of items to log against their tab. Used for the
     * end-of-day "I had 2 colas and a water" reconciliation.
     */
    public function quickConsumeForm()
    {
        abort_unless(auth()->user()?->hasPermission('staff_meals.quick_consume'), 403);

        $branchId = BranchContext::current();
        Employee::importLegacyMealUsers();
        $employees = Employee::query()
            ->whereNotNull('monthly_meal_allowance')
            ->where('monthly_meal_allowance', '>', 0)
            ->where('status', 'active')
            ->when($branchId, fn ($query) => $query->whereHas('branches', fn ($branch) => $branch->where('branches.id', $branchId)))
            ->orderBy('name')
            ->get(['id', 'name', 'job_title', 'user_id', 'monthly_meal_allowance']);

        $categories = Category::where('active', true)
            ->with(['menuItems' => fn ($q) => $q
                ->where('is_available', true)
                ->orderBy('display_order')
                ->with('recipeItems.ingredient')])
            ->orderBy('display_order')
            ->get()
            ->filter(fn ($c) => $c->menuItems->count() > 0)
            ->values();

        return AdminShell::render('Admin/StaffMeals/QuickConsume', [
            'employees' => $employees->map(function (Employee $employee) use ($branchId) {
                $summary = $this->service->monthSummary($employee, null, $branchId);

                return [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'jobTitle' => $employee->roleLabel(),
                    'hasLogin' => (bool) $employee->user_id,
                    'allowance' => Money::format($employee->monthly_meal_allowance),
                    'remaining' => max(0, (float) ($summary['remaining'] ?? 0)),
                    'remainingFormatted' => Money::format(max(0, (float) ($summary['remaining'] ?? 0))),
                    'outstandingFormatted' => Money::format($summary['outstanding'] ?? 0),
                ];
            })->values(),
            'categories' => $categories->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'items' => $category->menuItems->map(function ($item) {
                    $available = empty($item->stockShortages(1.0));

                    return [
                        'id' => $item->id,
                        'name' => $item->name,
                        'price' => (float) $item->price,
                        'priceFormatted' => Money::format($item->price),
                        'available' => $available,
                    ];
                })->values(),
            ])->values(),
            'urls' => [
                'index' => route('admin.staff-meals.index'),
                'store' => route('admin.staff-meals.quick_consume.store'),
                'employeesStore' => route('admin.staff-meals.employees.store'),
            ],
            'employeeForm' => $this->employeeFormOptions(auth()->user()),
        ]);
    }

    public function quickConsumeStore(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('staff_meals.quick_consume'), 403);

        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:1', 'max:99'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_gift' => ['sometimes', 'boolean'],
            'gift_reason' => ['nullable', 'string', 'max:300'],
        ], [], [
            'employee_id' => 'الموظف',
            'lines' => 'الأصناف',
        ]);

        $staff = Employee::findOrFail($data['employee_id']);
        $isGift = (bool) ($data['is_gift'] ?? false);
        // For regular consumption we still require the employee to be
        // enrolled in the program; gifts bypass that (a gift can go to
        // anyone — birthday meals, new-hire welcome, etc.).
        if (! $isGift && $staff->monthly_meal_allowance === null) {
            return back()->with('error', 'الموظف المختار ليس له بدل وجبات مفعّل.');
        }

        try {
            $charge = $this->service->quickConsume(
                staff: $staff,
                lines: $data['lines'],
                recordedByUserId: auth()->id(),
                notes: $data['notes'] ?? null,
                isGift: $isGift,
                giftReason: $data['gift_reason'] ?? null,
            );
            $consumption = $charge->consumptionAmount();
            $msg = $isGift
                ? 'تم تسجيل وجبة مجانية بقيمة '.number_format($consumption, 2)." ش.إ للموظف {$staff->name}."
                : 'تم تسجيل وجبة بقيمة '.number_format($consumption, 2).' ش.إ؛ المستحق على الموظف '
                    .number_format((float) $charge->amount, 2).' ش.إ والباقي ضمن البدل.';

            return redirect()
                ->route('admin.staff-meals.show', $staff)
                ->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Waive part (or all) of a specific OPEN charge. Used by the
     * manager-facing "إعفاء" button on the staff detail page when
     * the employee deserves a discount on a particular order — say
     * the kitchen messed up, or it's their work anniversary.
     */
    public function waiveCharge(Request $request, StaffMealCharge $charge)
    {
        abort_unless(auth()->user()?->hasPermission('staff_meals.waive'), 403);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.((float) $charge->amount)],
            'method' => ['required', 'in:gift,writeoff'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->waiveCharge(
                charge: $charge,
                amount: (float) $data['amount'],
                userId: auth()->id(),
                reason: $data['reason'] ?? null,
                asGift: $data['method'] === 'gift',
            );
            $label = $data['method'] === 'gift' ? 'هدية' : 'شطب';

            return back()->with('success',
                "تم تسجيل {$label} بقيمة "
                .number_format((float) $data['amount'], 2).' ش.إ من الحركة.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function settle(Request $request, Employee $employee)
    {
        abort_unless(auth()->user()?->hasPermission('staff_meals.settle'), 403);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,payroll_deduction,writeoff'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->settle(
                staff: $employee,
                amount: (float) $data['amount'],
                method: $data['method'],
                settledByUserId: auth()->id(),
                notes: $data['notes'] ?? null,
            );

            return back()->with('success', 'تم تسوية بدل الوجبات بنجاح.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Month-end batch settlement. Picks every open charge in the
     * chosen month + branch and settles them as one batch, marking
     * each linked back to a `StaffMealMonthClosure` row so the
     * printable payroll sheet can re-render anytime.
     */
    public function closeMonth(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('staff_meals.close_month'), 403);

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'method' => ['required', 'in:payroll_deduction,writeoff'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'branch_id' => [Rule::requiredIf(BranchContext::current() === null), 'nullable', 'integer', 'exists:branches,id'],
        ]);

        $month = Carbon::parse($data['month'].'-01');
        // Non-owner users are confined to their active branch — they
        // can't close the month for another branch even if the dropdown
        // gives them the option.
        $branchId = $data['branch_id'] ?? BranchContext::current();
        if (! auth()->user()?->isOwnerLevel() && $branchId !== BranchContext::current()) {
            return back()->with('error', 'لا تملك صلاحية إقفال شهر فرع آخر.');
        }

        try {
            $closure = $this->service->closeMonth(
                month: $month,
                branchId: $branchId,
                method: $data['method'],
                closedByUserId: auth()->id(),
                notes: $data['notes'] ?? null,
            );
            $msg = $closure->charge_count > 0
                ? "تم إقفال شهر {$month->format('Y-m')}: {$closure->charge_count} حركة بإجمالي "
                    .number_format((float) $closure->total_amount, 2).' ش.إ.'
                : "لا توجد حركات مفتوحة لشهر {$month->format('Y-m')}.";

            return redirect()
                ->route('admin.staff-meals.closures.show', $closure)
                ->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * List of every month-end closure. Manager can re-open the
     * printable sheet from here.
     */
    public function closures(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('staff_meals.viewAny'), 403);

        $branchId = BranchContext::current();
        $closures = StaffMealMonthClosure::with(['branch:id,name', 'closedBy:id,name'])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderByDesc('month')
            ->orderByDesc('closed_at')
            ->paginate(20);

        $closures->through(fn (StaffMealMonthClosure $closure) => [
            'id' => $closure->id,
            'month' => $closure->month->format('Y-m'),
            'branch' => $closure->branch?->localizedName() ?? 'كل الفروع',
            'method' => $closure->method,
            'methodLabel' => $this->settlementMethodLabel($closure->method),
            'staffCount' => $closure->staff_count,
            'chargeCount' => $closure->charge_count,
            'total' => Money::format($closure->total_amount),
            'closedBy' => $closure->closedBy?->name ?? '—',
            'closedAt' => $closure->closed_at?->format('Y-m-d H:i'),
            'showUrl' => route('admin.staff-meals.closures.show', $closure),
        ]);

        return AdminShell::render('Admin/StaffMeals/Closures', [
            'closures' => $closures,
            'urls' => ['index' => route('admin.staff-meals.index')],
        ]);
    }

    /**
     * Printable payroll sheet for a single closure — one row per
     * employee with the deductible total. Stylesheet supports
     * "طباعة" so the accountant can attach it to the payslip batch.
     */
    public function closureShow(StaffMealMonthClosure $closure)
    {
        abort_unless(auth()->user()?->hasPermission('staff_meals.viewAny'), 403);
        if (! auth()->user()?->isOwnerLevel()) {
            abort_unless((int) $closure->branch_id === (int) BranchContext::current(), 403);
        }

        $sheet = $this->service->payrollSheet($closure);

        $closure->load(['branch', 'closedBy']);

        return AdminShell::render('Admin/StaffMeals/ClosureShow', [
            'closure' => [
                'month' => $closure->month->translatedFormat('F Y'),
                'monthValue' => $closure->month->format('Y-m'),
                'branch' => $closure->branch?->localizedName() ?? 'كل الفروع',
                'method' => $this->settlementMethodLabel($closure->method),
                'staffCount' => $closure->staff_count,
                'chargeCount' => $closure->charge_count,
                'total' => Money::format($closure->total_amount),
                'notes' => $closure->notes,
                'closedBy' => $closure->closedBy?->name ?? '—',
                'closedAt' => $closure->closed_at?->format('Y-m-d H:i'),
            ],
            'sheet' => $sheet->map(fn (array $row) => [
                'name' => $row['employee']?->name ?? '—',
                'role' => $row['employee']?->roleLabel() ?? '—',
                'allowance' => Money::format($row['allowance'] ?? 0),
                'consumption' => Money::format($row['consumption'] ?? 0),
                'covered' => Money::format($row['covered'] ?? 0),
                'total' => Money::format($row['total']),
                'overflow' => (float) $row['overflow'],
                'overflowFormatted' => Money::format($row['overflow']),
                'chargesCount' => $row['charges_count'],
            ])->values(),
            'overflowTotal' => Money::format($sheet->sum('overflow')),
            'urls' => [
                'index' => route('admin.staff-meals.index'),
                'closures' => route('admin.staff-meals.closures'),
            ],
        ]);
    }

    protected function presentEmployeeSummary(Employee $employee, array $summary): array
    {
        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'role' => $employee->roleLabel(),
            'hasLogin' => (bool) $employee->user_id,
            'access' => $employee->accessLabel(),
            'allowance' => Money::format($summary['allowance'] ?? 0),
            'used' => Money::format($summary['used']),
            'covered' => Money::format($summary['covered'] ?? 0),
            'coveredValue' => (float) ($summary['covered'] ?? 0),
            'gifted' => Money::format($summary['gifted'] ?? 0),
            'giftedValue' => (float) ($summary['gifted'] ?? 0),
            'usagePct' => $summary['usage_pct'],
            'overflow' => Money::format($summary['overflow']),
            'overflowValue' => (float) $summary['overflow'],
            'ceiling' => $summary['ceiling'] !== null ? Money::format($summary['ceiling']) : null,
            'ceilingHeadroom' => $summary['ceiling_headroom'] !== null ? Money::format(max(0, $summary['ceiling_headroom'])) : null,
            'hardOver' => $summary['ceiling_headroom'] !== null && $summary['ceiling_headroom'] < 0,
            'outstanding' => Money::format($summary['outstanding']),
            'outstandingValue' => (float) $summary['outstanding'],
            'showUrl' => route('admin.staff-meals.show', $employee),
        ];
    }

    public function storeEmployee(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor?->hasPermission('staff_meals.quick_consume') || $actor?->hasPermission('users.create'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'phone' => ['nullable', 'regex:/^0(?:56|59)\d{7}$/', 'max:10'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'monthly_meal_allowance' => ['required', 'numeric', 'min:0', 'max:99999'],
            'meal_debt_ceiling' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'user_id' => ['nullable', 'integer', 'exists:users,id', Rule::unique('employees', 'user_id')],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ], [], [
            'name' => 'اسم الموظف',
            'phone' => 'رقم الجوال',
            'monthly_meal_allowance' => 'البدل الشهري',
            'meal_debt_ceiling' => 'سقف الدين',
        ]);

        $branchIds = BranchContext::current()
            ? [BranchContext::current()]
            : array_values(array_unique(array_map('intval', $data['branch_ids'] ?? [])));
        if (! $branchIds) {
            return back()->withErrors(['branch_ids' => 'اختر فرعاً واحداً على الأقل.']);
        }

        $employee = DB::transaction(function () use ($data, $branchIds): Employee {
            $employee = Employee::create([
                'name' => trim($data['name']),
                'phone' => $data['phone'] ?: null,
                'job_title' => $data['job_title'] ?: null,
                'monthly_meal_allowance' => $data['monthly_meal_allowance'],
                'meal_debt_ceiling' => $data['meal_debt_ceiling'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'status' => 'active',
            ]);
            $employee->branches()->attach(collect($branchIds)->mapWithKeys(fn ($id, $index) => [
                $id => ['is_primary' => $index === 0, 'joined_at' => now()],
            ])->all());

            return $employee;
        });

        return back()->with('success', "تمت إضافة {$employee->name} كسجل موظف بدون إنشاء حساب دخول إجباري.");
    }

    protected function employeeFormOptions(?User $actor): array
    {
        $branchId = BranchContext::current();

        return [
            'branches' => Branch::active()
                ->when($branchId, fn ($query) => $query->whereKey($branchId))
                ->orderBy('display_order')->get(['id', 'name'])
                ->map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->localizedName()])->values(),
            'users' => User::query()->where('status', 'active')->whereDoesntHave('employee')
                ->when($branchId, fn ($query) => $query->whereHas('branches', fn ($branch) => $branch->where('branches.id', $branchId)))
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->values(),
            'canLinkLogin' => (bool) $actor?->hasPermission('users.update'),
        ];
    }

    protected function settlementMethodLabel(string $method): string
    {
        return match ($method) {
            'payroll_deduction' => 'خصم من الرواتب',
            'cash' => 'تحصيل نقدي',
            'writeoff' => 'شطب إداري',
            'gift' => 'هدية',
            'allowance' => 'ضمن البدل الشهري',
            default => $method,
        };
    }
}
