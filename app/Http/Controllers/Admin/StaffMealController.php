<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StaffMealCharge;
use App\Models\User;
use App\Services\StaffMealService;
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
        abort_unless(auth()->user()?->hasAnyRole(['super_admin', 'admin', 'manager']), 403);

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
        abort_unless(auth()->user()?->hasAnyRole(['super_admin', 'admin', 'manager']), 403);

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
        abort_unless(auth()->user()?->hasAnyRole(['super_admin', 'admin', 'manager', 'cashier']), 403);

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
        abort_unless(auth()->user()?->hasAnyRole(['super_admin', 'admin', 'manager', 'cashier']), 403);

        $data = $request->validate([
            'user_id'           => ['required', 'integer', 'exists:users,id'],
            'lines'             => ['required', 'array', 'min:1'],
            'lines.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'lines.*.quantity'  => ['required', 'numeric', 'min:1', 'max:99'],
            'notes'             => ['nullable', 'string', 'max:500'],
        ], [], [
            'user_id' => 'الموظف',
            'lines'   => 'الأصناف',
        ]);

        $staff = User::findOrFail($data['user_id']);
        if ($staff->monthly_meal_allowance === null) {
            return back()->with('error', 'الموظف المختار ليس له بدل وجبات مفعّل.');
        }

        try {
            $charge = $this->service->quickConsume(
                staff: $staff,
                lines: $data['lines'],
                recordedByUserId: auth()->id(),
                notes: $data['notes'] ?? null,
            );
            return redirect()
                ->route('admin.staff-meals.show', $staff)
                ->with('success', "تم تسجيل استهلاك بقيمة "
                    .number_format((float) $charge->amount, 2).
                    " ش.إ على الموظف {$staff->name}.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function settle(Request $request, User $user)
    {
        abort_unless(auth()->user()?->hasAnyRole(['super_admin', 'admin', 'manager']), 403);

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
}
