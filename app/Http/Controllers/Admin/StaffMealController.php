<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StaffMealCharge;
use App\Models\StaffMealMonthClosure;
use App\Models\User;
use App\Services\StaffMealService;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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

        $employees = User::query()
            ->whereNotNull('monthly_meal_allowance')
            ->where('monthly_meal_allowance', '>', 0)
            ->orderBy('name')
            ->get();

        $rows = $employees->map(function (User $u) use ($month) {
            $summary = $this->service->monthSummary($u, $month);
            return [
                'user'    => $u,
                'summary' => $summary,
            ];
        });

        $totals = [
            'staff_count'      => $employees->count(),
            'total_allowance'  => (float) $employees->sum('monthly_meal_allowance'),
            'total_used'       => (float) $rows->sum(fn ($r) => $r['summary']['used']),
            'total_gifted'     => (float) $rows->sum(fn ($r) => $r['summary']['gifted'] ?? 0),
            'total_outstanding'=> (float) $rows->sum(fn ($r) => $r['summary']['outstanding']),
            'over_limit_count' => $rows->filter(fn ($r) => $r['summary']['overflow'] > 0)->count(),
        ];

        return view('admin.staff-meals.index', [
            'rows'   => $rows,
            'month'  => $month,
            'totals' => $totals,
        ]);
    }

    public function show(User $user)
    {
        abort_unless(auth()->user()?->hasPermission('staff_meals.viewAny'), 403);

        $summary = $this->service->monthSummary($user);
        $charges = StaffMealCharge::with('order.items')
            ->where('user_id', $user->id)
            ->latest('charged_at')
            ->limit(60)
            ->get();

        // Per-item breakdown for the current month — aggregates
        // `order_items` from every open + settled charge in the
        // current month. Uses `unit_price` from the snapshot so
        // historical prices are honored even if the menu changed.
        $monthStart = now()->startOfMonth();
        $monthEnd   = now()->endOfMonth();

        $itemAggregate = \App\Models\OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.staff_consumer_user_id', $user->id)
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

        return view('admin.staff-meals.show', [
            'user'          => $user,
            'summary'       => $summary,
            'charges'       => $charges,
            'itemAggregate' => $itemAggregate,
            'monthStart'    => $monthStart,
        ]);
    }

    /**
     * Quick-consume form — the cashier/manager picks an employee and
     * a handful of items to log against their tab. Used for the
     * end-of-shift "I had 2 colas and a water" reconciliation.
     */
    public function quickConsumeForm()
    {
        abort_unless(auth()->user()?->hasPermission('staff_meals.quick_consume'), 403);

        $employees = User::query()
            ->whereNotNull('monthly_meal_allowance')
            ->where('monthly_meal_allowance', '>', 0)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'monthly_meal_allowance']);

        $categories = \App\Models\Category::where('active', true)
            ->with(['menuItems' => fn ($q) => $q
                ->where('is_available', true)
                ->orderBy('display_order')
                ->with('recipeItems.ingredient')])
            ->orderBy('display_order')
            ->get()
            ->filter(fn ($c) => $c->menuItems->count() > 0)
            ->values();

        return view('admin.staff-meals.quick-consume', compact('employees', 'categories'));
    }

    public function quickConsumeStore(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('staff_meals.quick_consume'), 403);

        $data = $request->validate([
            'user_id'           => ['required', 'integer', 'exists:users,id'],
            'lines'             => ['required', 'array', 'min:1'],
            'lines.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'lines.*.quantity'  => ['required', 'numeric', 'min:1', 'max:99'],
            'notes'             => ['nullable', 'string', 'max:500'],
            'is_gift'           => ['sometimes', 'boolean'],
            'gift_reason'       => ['nullable', 'string', 'max:300'],
        ], [], [
            'user_id' => 'الموظف',
            'lines'   => 'الأصناف',
        ]);

        $staff = User::findOrFail($data['user_id']);
        $isGift = (bool) ($data['is_gift'] ?? false);
        // For regular consumption we still require the employee to be
        // enrolled in the program; gifts bypass that (a gift can go to
        // anyone — birthday meals, new-hire welcome, etc.).
        if (! $isGift && $staff->monthly_meal_allowance === null) {
            return back()->with('error', 'الموظف المختار ليس له بدل وجبات مفعّل.');
        }

        try {
            $charge = $this->service->quickConsume(
                staff:            $staff,
                lines:            $data['lines'],
                recordedByUserId: auth()->id(),
                notes:            $data['notes'] ?? null,
                isGift:           $isGift,
                giftReason:       $data['gift_reason'] ?? null,
            );
            $msg = $isGift
                ? "تم تسجيل وجبة مجانية بقيمة ".number_format((float) $charge->amount, 2)." ش.إ للموظف {$staff->name} (لا تُحسب على بدله)."
                : "تم تسجيل استهلاك بقيمة ".number_format((float) $charge->amount, 2)." ش.إ على الموظف {$staff->name}.";
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
            'amount'  => ['required', 'numeric', 'min:0.01', 'max:'.((float) $charge->amount)],
            'method'  => ['required', 'in:gift,writeoff'],
            'reason'  => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->waiveCharge(
                charge:  $charge,
                amount:  (float) $data['amount'],
                userId:  auth()->id(),
                reason:  $data['reason'] ?? null,
                asGift:  $data['method'] === 'gift',
            );
            $label = $data['method'] === 'gift' ? 'هدية' : 'شطب';
            return back()->with('success',
                "تم تسجيل {$label} بقيمة "
                .number_format((float) $data['amount'], 2)." ش.إ من الحركة.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function settle(Request $request, User $user)
    {
        abort_unless(auth()->user()?->hasPermission('staff_meals.settle'), 403);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,payroll_deduction,writeoff'],
            'notes'  => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->settle(
                staff:             $user,
                amount:            (float) $data['amount'],
                method:            $data['method'],
                settledByUserId:   auth()->id(),
                notes:             $data['notes'] ?? null,
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
            'month'  => ['required', 'date_format:Y-m'],
            'method' => ['required', 'in:payroll_deduction,cash,writeoff'],
            'notes'  => ['nullable', 'string', 'max:1000'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
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
                month:          $month,
                branchId:       $branchId,
                method:         $data['method'],
                closedByUserId: auth()->id(),
                notes:          $data['notes'] ?? null,
            );
            $msg = $closure->charge_count > 0
                ? "تم إقفال شهر {$month->format('Y-m')}: {$closure->charge_count} حركة بإجمالي "
                    .number_format((float) $closure->total_amount, 2)." ش.إ."
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

        $closures = StaffMealMonthClosure::with(['branch:id,name', 'closedBy:id,name'])
            ->orderByDesc('month')
            ->orderByDesc('closed_at')
            ->paginate(20);

        return view('admin.staff-meals.closures', compact('closures'));
    }

    /**
     * Printable payroll sheet for a single closure — one row per
     * employee with the deductible total. Stylesheet supports
     * "طباعة" so the accountant can attach it to the payslip batch.
     */
    public function closureShow(StaffMealMonthClosure $closure)
    {
        abort_unless(auth()->user()?->hasPermission('staff_meals.viewAny'), 403);

        $sheet = $this->service->payrollSheet($closure);

        return view('admin.staff-meals.closure-show', [
            'closure' => $closure->load(['branch', 'closedBy']),
            'sheet'   => $sheet,
        ]);
    }
}
