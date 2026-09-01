<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Helpers\Money;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Review;
use App\Services\LoyaltyService;
use App\Services\SmsService;
use App\Support\AdminShell;
use App\Support\BranchContext;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            ->withCount(['reservations', 'orders', 'invoices'])
            ->orderBy('created_at', 'desc');

        if ($branchId) {
            $query->where(function ($q) use ($branchId) {
                $q->where('default_branch_id', $branchId)
                    ->orWhereHas('orders', fn ($order) => $order->where('branch_id', $branchId))
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
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('blocked_reason', 'like', "%{$search}%");
            });
        }
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($request->get('new_today')) {
            $query->whereDate('created_at', today());
        }
        if ($request->get('active_30d')) {
            $query->whereHas('reservations', fn ($q) => $q->where('reserved_for', '>=', now()->subDays(30))
            );
        }
        $filteredQuery = clone $query;
        $filteredStats = [
            'count' => (clone $filteredQuery)->count(),
            'active' => (clone $filteredQuery)->where('status', 'active')->count(),
            'blocked' => (clone $filteredQuery)->where('status', 'blocked')->count(),
        ];

        $customers = $query->paginate(25)->withQueryString();

        // KPIs — also branch-aware via the same query baseline.
        $statsBase = fn () => Customer::query()->when($branchId, fn ($q) => $q->where(function ($qq) use ($branchId) {
            $qq->where('default_branch_id', $branchId)
                ->orWhereHas('orders', fn ($order) => $order->where('branch_id', $branchId))
                ->orWhereHas('reservations');
        })
        );
        $stats = [
            'total' => $statsBase()->count(),
            'new_today' => $statsBase()->whereDate('created_at', today())->count(),
            'new_month' => $statsBase()->whereDate('created_at', '>=', now()->startOfMonth())->count(),
            'active_30d' => $statsBase()->whereHas('reservations', fn ($q) => $q->where('reserved_for', '>=', now()->subDays(30))
            )->count(),
        ];

        $customers->through(fn (Customer $customer) => [
            'id' => $customer->id,
            'name' => $customer->name,
            'initial' => $customer->initial,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'status' => $customer->status,
            'isBlocked' => $customer->isBlocked(),
            'blockedReason' => $customer->blocked_reason,
            'branch' => $customer->defaultBranch ? [
                'id' => $customer->defaultBranch->id,
                'name' => $customer->defaultBranch->localizedName(),
                'hue' => ($customer->defaultBranch->id * 47) % 360,
            ] : null,
            'activity' => [
                'reservations' => $customer->reservations_count,
                'orders' => $customer->orders_count,
                'invoices' => $customer->invoices_count,
            ],
            'createdAt' => $customer->created_at->format('Y/m/d'),
            'createdAgo' => $customer->created_at->diffForHumans(),
            'url' => route('admin.customers.show', $customer),
        ]);

        $activeBranch = $branchId ? Branch::find($branchId) : null;

        return AdminShell::render('Admin/Customers/Index', [
            'customers' => $customers,
            'stats' => $stats,
            'filteredStats' => $filteredStats,
            'filters' => [
                'search' => (string) $request->get('search', ''),
                'status' => (string) $request->get('status', ''),
                'newToday' => $request->boolean('new_today'),
                'active30d' => $request->boolean('active_30d'),
            ],
            'scope' => [
                'branchName' => $activeBranch?->localizedName(),
                'label' => $activeBranch
                    ? 'زبائن فرع «'.$activeBranch->localizedName().'» ممن تعاملوا معه أو اختاروه كفرع مفضّل'
                    : 'كل الزبائن في جميع الفروع',
            ],
            'urls' => [
                'index' => route('admin.customers.index'),
                'debts' => route('admin.customers.debts.index'),
            ],
        ]);
    }

    public function show(Customer $customer)
    {
        $this->authorize('view', $customer);

        // Reservations across ALL branches — load the branch eagerly so the
        // history table can show where each visit was. We bypass BranchScope
        // here because the customer screen is global by design.
        $reservations = BranchContext::unscoped(fn () => $customer->reservations()
            ->with(['branch', 'table'])
            ->orderBy('reserved_for', 'desc')
            ->limit(50)
            ->get()
        );

        // Reviews — same pattern (Review model uses BelongsToBranch trait).
        $reviews = BranchContext::unscoped(fn () => Review::where('customer_id', $customer->id)
            ->with('branch')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
        );

        // Per-branch breakdown of visits (for the "branches visited" widget).
        $byBranch = BranchContext::unscoped(fn () => DB::table('reservations')
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

        $customer->load(['defaultBranch', 'loyaltyCustomer'])
            ->loadCount(['reservations', 'orders', 'invoices']);
        $debt = $customer->outstandingDebt();
        $user = auth()->user();

        return AdminShell::render('Admin/Customers/Show', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'initial' => $customer->initial,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'gender' => $customer->gender ?: 'unspecified',
                'birthday' => $customer->birthday?->format('Y-m-d'),
                'status' => $customer->status,
                'isBlocked' => $customer->isBlocked(),
                'blockedReason' => $customer->blocked_reason,
                'createdAt' => $customer->created_at->format('Y/m/d'),
                'createdAgo' => $customer->created_at->diffForHumans(),
                'defaultBranchId' => $customer->default_branch_id,
                'defaultBranchName' => $customer->defaultBranch?->localizedName(),
                'counts' => [
                    'reservations' => $customer->reservations_count,
                    'orders' => $customer->orders_count,
                    'invoices' => $customer->invoices_count,
                ],
                'loyalty' => $customer->loyaltyCustomer ? [
                    'points' => (int) $customer->loyaltyCustomer->points_balance,
                    'tier' => $customer->loyaltyCustomer->tierLabel(),
                    'totalVisits' => (int) $customer->loyaltyCustomer->total_visits,
                    'totalSpent' => Money::format((float) $customer->loyaltyCustomer->total_spent),
                ] : null,
                'debt' => [
                    'amount' => $debt,
                    'formatted' => Money::format($debt),
                    'invoiceCount' => $customer->openDebtInvoices()->count(),
                    'creditLimit' => $customer->credit_limit !== null
                        ? Money::format((float) $customer->credit_limit)
                        : null,
                ],
                'advance' => [
                    'amount' => (float) $customer->advance_balance,
                    'formatted' => Money::format((float) $customer->advance_balance),
                ],
            ],
            'branches' => Branch::query()->where('is_active', true)
                ->orderBy('display_order')->orderBy('name')
                ->get()->map(fn (Branch $branch) => [
                    'id' => $branch->id,
                    'name' => $branch->localizedName(),
                ])->values(),
            'visitedBranches' => $byBranch->filter(fn ($row) => $row->branch)->map(fn ($row) => [
                'id' => $row->branch->id,
                'name' => $row->branch->localizedName(),
                'visits' => (int) $row->visits,
                'hue' => ($row->branch->id * 47) % 360,
            ])->values(),
            'reservations' => $reservations->map(function ($reservation) {
                $status = $reservation->status;

                return [
                    'id' => $reservation->id,
                    'reference' => $reservation->reference,
                    'branch' => $reservation->branch?->localizedName(),
                    'table' => $reservation->table?->number,
                    'reservedFor' => $reservation->reserved_for?->format('Y/m/d H:i'),
                    'relative' => $reservation->reserved_for?->diffForHumans(),
                    'partySize' => $reservation->party_size,
                    'status' => $status instanceof ReservationStatus ? $status->label() : (string) $status,
                    'statusColor' => $status instanceof ReservationStatus ? $status->color() : 'secondary',
                ];
            })->values(),
            'reviews' => $reviews->map(fn ($review) => [
                'id' => $review->id,
                'rating' => $review->rating,
                'title' => $review->title,
                'body' => $review->body,
                'branch' => $review->branch?->localizedName(),
                'createdAgo' => $review->created_at->diffForHumans(),
            ])->values(),
            'can' => [
                'update' => (bool) $user?->can('update', $customer),
                'notify' => (bool) $user?->can('notify', $customer),
                'block' => (bool) $user?->can('block', $customer),
                'delete' => (bool) $user?->can('delete', $customer),
            ],
            'urls' => [
                'index' => route('admin.customers.index'),
                'update' => route('admin.customers.update', $customer),
                'sms' => route('admin.customers.sms', $customer),
                'block' => route('admin.customers.block', $customer),
                'unblock' => route('admin.customers.unblock', $customer),
                'destroy' => route('admin.customers.destroy', $customer),
                'debt' => route('admin.customers.debts.show', $customer),
            ],
        ]);
    }

    public function update(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $request->merge(['phone' => PhoneNumber::normalize($request->input('phone'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:150',
                Rule::unique('customers', 'email')->ignore($customer->id)->whereNull('deleted_at')],
            'phone' => ['required', 'string', 'max:30',
                Rule::unique('customers', 'phone')->ignore($customer->id)->whereNull('deleted_at')],
            'gender' => ['nullable', Rule::in(['male', 'female', 'unspecified'])],
            'default_branch_id' => ['nullable', Rule::exists('branches', 'id')],
            'birthday' => ['nullable', 'date', 'before:today'],
        ]);

        if (! PhoneNumber::isValid($data['phone'])) {
            throw ValidationException::withMessages([
                'phone' => 'أدخل رقم جوال صحيحاً من 7 إلى 15 رقماً.',
            ]);
        }

        $customer->update($data);
        $loyalty = app(LoyaltyService::class)->ensureForCustomer($customer->fresh());
        if ($loyalty->phone !== $customer->phone) {
            $loyalty->update(['phone' => $customer->phone]);
        }

        ActivityLog::log('customer.updated',
            "تعديل بيانات العميل {$customer->name}",
            $customer
        );

        return back()->with('success', 'تم حفظ التعديلات.');
    }

    public function sendSms(Request $request, Customer $customer, SmsService $sms)
    {
        $this->authorize('notify', $customer);

        $data = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:500'],
        ]);

        try {
            $smsId = $sms->send(
                $customer->phone,
                trim($data['message']),
                "رسالة إلى الزبون {$customer->name}",
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        ActivityLog::log('customer.sms_sent', "إرسال رسالة إلى {$customer->name}", $customer, [
            'sms_id' => $smsId,
            'phone' => $customer->phone,
            'message_length' => mb_strlen($data['message']),
        ]);

        return back()->with('success', 'تم إرسال الرسالة إلى '.$customer->name.'.');
    }

    public function block(Request $request, Customer $customer)
    {
        $this->authorize('block', $customer);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $customer->update([
            'status' => 'blocked',
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
            'status' => 'active',
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
