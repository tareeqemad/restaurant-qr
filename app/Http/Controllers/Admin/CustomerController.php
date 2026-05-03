<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin-side customer directory.
 *
 * Customers are GLOBAL — the screen lists every diner in the system,
 * regardless of which branch the operator is currently switched to.
 * The per-branch view shows up on the show page (their reservation /
 * review history is grouped by branch there).
 */
class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Customer::class);

        // Branch context drives the whole screen — same as everywhere else.
        // Topbar on a specific branch → only show customers tied to it
        // (their default branch, OR they have ≥1 reservation there).
        // Topbar on "كل الفروع" / no context → show every customer.
        $branchId = BranchContext::current();

        $query = Customer::query()
            ->with('defaultBranch')
            ->withCount(['reservations'])
            ->orderBy('created_at', 'desc');

        if ($branchId) {
            $query->where(function ($q) use ($branchId) {
                $q->where('default_branch_id', $branchId)
                  ->orWhereHas('reservations', fn ($r) =>
                      // BranchScope on Reservation already filters to current
                      // context, so any matching row guarantees this customer
                      // visited the active branch.
                      $r->whereNotNull('id')
                  );
            });
        }

        // Filters
        if ($search = trim((string) $request->get('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($request->get('new_today')) {
            $query->whereDate('created_at', today());
        }

        $customers = $query->paginate(25)->withQueryString();

        // KPIs — also branch-aware via the same query baseline.
        $statsBase = fn () => Customer::query()->when($branchId, fn ($q) =>
            $q->where(function ($qq) use ($branchId) {
                $qq->where('default_branch_id', $branchId)
                   ->orWhereHas('reservations');
            })
        );
        $stats = [
            'total'      => $statsBase()->count(),
            'new_today'  => $statsBase()->whereDate('created_at', today())->count(),
            'new_month'  => $statsBase()->whereDate('created_at', '>=', now()->startOfMonth())->count(),
            'active_30d' => $statsBase()->whereHas('reservations', fn ($q) =>
                $q->where('reserved_for', '>=', now()->subDays(30))
            )->count(),
        ];

        return view('admin.customers.index', [
            'customers'   => $customers,
            'stats'       => $stats,
            'activeBranch'=> $branchId ? Branch::find($branchId) : null,
        ]);
    }

    public function show(Customer $customer)
    {
        $this->authorize('view', $customer);

        // Reservations across ALL branches — load the branch eagerly so the
        // history table can show where each visit was. We bypass BranchScope
        // here because the customer screen is global by design.
        $reservations = BranchContext::unscoped(fn () =>
            $customer->reservations()
                ->with(['branch', 'table'])
                ->orderBy('reserved_for', 'desc')
                ->limit(50)
                ->get()
        );

        // Reviews — same pattern (Review model uses BelongsToBranch trait).
        $reviews = BranchContext::unscoped(fn () =>
            \App\Models\Review::where('customer_id', $customer->id)
                ->with('branch')
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->get()
        );

        // Per-branch breakdown of visits (for the "branches visited" widget).
        $byBranch = BranchContext::unscoped(fn () =>
            DB::table('reservations')
                ->where('customer_id', $customer->id)
                ->whereNull('deleted_at')
                ->select('branch_id', DB::raw('COUNT(*) as visits'))
                ->groupBy('branch_id')
                ->orderByDesc('visits')
                ->get()
                ->map(function ($row) {
                    $row->branch = Branch::find($row->branch_id);
                    return $row;
                })
        );

        return view('admin.customers.show', [
            'customer'     => $customer,
            'reservations' => $reservations,
            'reviews'      => $reviews,
            'byBranch'     => $byBranch,
        ]);
    }

    public function update(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $data = $request->validate([
            'name'              => ['required', 'string', 'max:120'],
            'email'             => ['nullable', 'email', 'max:150',
                Rule::unique('customers', 'email')->ignore($customer->id)->whereNull('deleted_at')],
            'phone'             => ['required', 'string', 'max:30',
                Rule::unique('customers', 'phone')->ignore($customer->id)->whereNull('deleted_at')],
            'gender'            => ['nullable', Rule::in(['male', 'female', 'unspecified'])],
            'default_branch_id' => ['nullable', Rule::exists('branches', 'id')],
            'birthday'          => ['nullable', 'date', 'before:today'],
        ]);

        $customer->update($data);

        ActivityLog::log('customer.updated',
            "تعديل بيانات العميل {$customer->name}",
            $customer
        );

        return back()->with('success', 'تم حفظ التعديلات.');
    }

    public function block(Request $request, Customer $customer)
    {
        $this->authorize('block', $customer);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $customer->update([
            'status'         => 'blocked',
            'blocked_reason' => $data['reason'],
        ]);

        ActivityLog::log('customer.blocked',
            "حظر العميل {$customer->name}: {$data['reason']}",
            $customer
        );

        return back()->with('success', 'تم حظر العميل.');
    }

    public function unblock(Customer $customer)
    {
        $this->authorize('block', $customer);

        $customer->update([
            'status'         => 'active',
            'blocked_reason' => null,
        ]);

        ActivityLog::log('customer.unblocked',
            "إلغاء حظر العميل {$customer->name}",
            $customer
        );

        return back()->with('success', 'تم إلغاء الحظر.');
    }

    public function destroy(Customer $customer)
    {
        $this->authorize('delete', $customer);

        $name = $customer->name;
        $customer->delete(); // soft delete

        ActivityLog::log('customer.deleted', "حذف العميل {$name}");

        return redirect()->route('admin.customers.index')
            ->with('success', 'تم حذف العميل.');
    }
}
