<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\Lookup;
use App\Models\Shift;
use App\Models\Supplier;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Branch-scoped expense management.
 *
 *   index   — list with filter rail + KPIs (this-month + today + pending)
 *   create  — manual entry (defaults today's date + open shift if any)
 *   store   — validate + persist; auto-numbered EXP-YYYYMMDD-NNNN
 *   edit    — only while pending
 *   approve — flips to approved + (if cash) creates a CashMovement
 *             on the active shift, so the till close-out matches
 *   reject  — with reason; record stays for audit, no cash effect
 *   destroy — admin-only soft delete; refuses if cash already posted
 */
class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Expense::class);

        $query = Expense::with(['creator', 'approver', 'shift', 'supplier', 'branch', 'category'])
            ->orderBy('expense_date', 'desc')
            ->orderBy('id', 'desc');

        // ─── Filters ───────────────────────────────────────────────
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($categoryId = $request->get('category_id')) {
            $query->forCategory((int) $categoryId);
        }
        if ($method = $request->get('payment_method')) {
            $query->where('payment_method', $method);
        }
        if ($from = $request->get('from')) {
            $query->whereDate('expense_date', '>=', $from);
        }
        if ($to = $request->get('to')) {
            $query->whereDate('expense_date', '<=', $to);
        }
        if ($search = trim((string) $request->get('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('expense_number', 'like', "%{$search}%")
                  ->orWhere('vendor_name',   'like', "%{$search}%")
                  ->orWhere('payment_reference', 'like', "%{$search}%");
            });
        }

        $filteredQuery = clone $query;

        $filteredStats = [
            'count'    => (clone $filteredQuery)->count(),
            'total'    => (float) (clone $filteredQuery)->sum('amount'),
            'pending'  => (clone $filteredQuery)->where('status', 'pending_approval')->count(),
            'approved' => (clone $filteredQuery)->where('status', 'approved')->count(),
        ];

        $expenses = $query->paginate(25)->withQueryString();

        // ─── KPIs (computed on the same branch-scoped baseline) ────
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $stats = [
            'pending'      => Expense::pending()->count(),
            'today_total'  => (float) Expense::approved()->whereDate('expense_date', $today)->sum('amount'),
            'month_total'  => (float) Expense::approved()->whereDate('expense_date', '>=', $monthStart)->sum('amount'),
            'rejected_30d' => Expense::rejected()->where('updated_at', '>=', now()->subDays(30))->count(),
        ];

        // ─── Category breakdown for the active filter window ──────
        // Built from scratch (not cloned from the main query) so the
        // ORDER BY / SELECT of the main listing don't collide with the
        // GROUP BY here. Re-applies the same filters explicitly.
        $catQuery = DB::table('expenses')
            ->leftJoin('lookups', 'lookups.id', '=', 'expenses.expense_category_id')
            ->whereNull('expenses.deleted_at')
            ->where('expenses.status', 'approved');

        if ($branchId = BranchContext::current()) {
            $catQuery->where('expenses.branch_id', $branchId);
        }
        if ($categoryId = $request->get('category_id')) {
            $catQuery->where('expenses.expense_category_id', (int) $categoryId);
        }
        if ($method = $request->get('payment_method')) {
            $catQuery->where('expenses.payment_method', $method);
        }
        if ($from = $request->get('from')) {
            $catQuery->whereDate('expenses.expense_date', '>=', $from);
        }
        if ($to = $request->get('to')) {
            $catQuery->whereDate('expenses.expense_date', '<=', $to);
        }
        if ($search = trim((string) $request->get('search'))) {
            $catQuery->where(function ($q) use ($search) {
                $q->where('expenses.description', 'like', "%{$search}%")
                  ->orWhere('expenses.expense_number', 'like', "%{$search}%")
                  ->orWhere('expenses.vendor_name', 'like', "%{$search}%")
                  ->orWhere('expenses.payment_reference', 'like', "%{$search}%");
            });
        }

        $byCategory = $catQuery
            ->select(
                'lookups.id as category_id',
                'lookups.label as category_label',
                'lookups.icon as category_icon',
                'lookups.color as category_color',
                DB::raw('SUM(expenses.amount) as total'),
                DB::raw('COUNT(*) as cnt'),
            )
            ->groupBy('lookups.id', 'lookups.label', 'lookups.icon', 'lookups.color')
            ->orderByDesc('total')
            ->get();

        return view('admin.expenses.index', [
            'expenses'   => $expenses,
            'stats'      => $stats,
            'filteredStats' => $filteredStats,
            'byCategory' => $byCategory,
            'categories' => Lookup::for('expense_categories'),
            'paymentMethods' => Expense::PAYMENT_METHODS,
        ]);
    }

    public function create()
    {
        $this->authorize('create', Expense::class);

        return view('admin.expenses.create', [
            'expense'    => new Expense(['expense_date' => now()->toDateString(), 'payment_method' => 'cash']),
            'suppliers'  => Supplier::orderBy('name')->get(['id', 'name']),
            'categories' => Lookup::for('expense_categories'),
            'paymentMethods' => Expense::PAYMENT_METHODS,
            'openShift'  => $this->openShiftForCurrentBranch(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Expense::class);

        $data = $this->validateData($request);

        if (! BranchContext::current()) {
            return back()->withInput()->with('error',
                'اختر فرعاً قبل إضافة المصروف — لا يمكن التسجيل في وضع «كل الفروع».');
        }

        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('expenses', 'public');
        }

        $expense = Expense::create([
            ...$data,
            'created_by_user_id' => auth()->id(),
            'status'             => 'pending_approval',
        ]);

        ActivityLog::log('expense.created',
            "تسجيل مصروف {$expense->expense_number} ({$expense->description}) — {$expense->amount}",
            $expense
        );

        return redirect()->route('admin.expenses.index')
            ->with('success', "تم تسجيل المصروف {$expense->expense_number} وهو بانتظار الاعتماد.");
    }

    public function edit(Expense $expense)
    {
        $this->authorize('update', $expense);

        return view('admin.expenses.edit', [
            'expense'    => $expense,
            'suppliers'  => Supplier::orderBy('name')->get(['id', 'name']),
            'categories' => Lookup::for('expense_categories'),
            'paymentMethods' => Expense::PAYMENT_METHODS,
            'openShift'  => $this->openShiftForCurrentBranch(),
        ]);
    }

    public function update(Request $request, Expense $expense)
    {
        $this->authorize('update', $expense);

        $data = $this->validateData($request);

        if ($request->hasFile('attachment')) {
            // Drop the previous attachment if we're replacing it.
            if ($expense->attachment_path) {
                Storage::disk('public')->delete($expense->attachment_path);
            }
            $data['attachment_path'] = $request->file('attachment')->store('expenses', 'public');
        }

        $expense->update($data);

        ActivityLog::log('expense.updated',
            "تعديل مصروف {$expense->expense_number}",
            $expense
        );

        return redirect()->route('admin.expenses.index')
            ->with('success', 'تم حفظ التعديلات.');
    }

    public function approve(Expense $expense)
    {
        $this->authorize('approve', $expense);

        DB::transaction(function () use ($expense) {
            $expense->update([
                'status'              => 'approved',
                'approved_by_user_id' => auth()->id(),
                'approved_at'         => now(),
            ]);

            // Bridge to the cashier's till — only when paid in cash AND
            // there's an open shift in the same branch. Otherwise the
            // expense is recorded but doesn't touch the drawer (e.g. a
            // bank transfer paid by the manager from the company account).
            if ($expense->isCash()) {
                $shift = $this->openShiftForBranch($expense->branch_id, $expense->shift_id);

                if ($shift) {
                    $movement = CashMovement::create([
                        // Stamp the branch from the shift (which itself is
                        // branch-scoped). Don't rely on BranchContext —
                        // expense approval can be done by an owner-level
                        // user in "all branches" mode.
                        'branch_id' => $shift->branch_id,
                        'shift_id' => $shift->id,
                        'type'     => 'pay_out',
                        'amount'   => $expense->amount,
                        'reason'   => "مصروف {$expense->expense_number} — {$expense->description}",
                        'user_id'  => auth()->id(),
                    ]);

                    $expense->update([
                        'cash_movement_id' => $movement->id,
                        'shift_id'         => $shift->id,
                    ]);
                }
            }
        });

        $tail = $expense->cash_movement_id
            ? ' وتمّ خصم المبلغ من درج الكاشير الحالي.'
            : ($expense->isCash() ? ' (لا توجد وردية مفتوحة لربط الحركة بها).' : '');

        ActivityLog::log('expense.approved',
            "اعتماد مصروف {$expense->expense_number} ({$expense->amount})",
            $expense
        );

        return back()->with('success', "تم اعتماد المصروف.{$tail}");
    }

    public function reject(Request $request, Expense $expense)
    {
        $this->authorize('reject', $expense);

        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:255'],
        ]);

        $expense->update([
            'status'              => 'rejected',
            'rejection_reason'    => $data['rejection_reason'],
            'approved_by_user_id' => auth()->id(),
            'approved_at'         => now(),
        ]);

        ActivityLog::log('expense.rejected',
            "رفض مصروف {$expense->expense_number}: {$data['rejection_reason']}",
            $expense
        );

        return back()->with('success', 'تم رفض المصروف.');
    }

    public function destroy(Expense $expense)
    {
        $this->authorize('delete', $expense);

        $number = $expense->expense_number;

        if ($expense->attachment_path) {
            Storage::disk('public')->delete($expense->attachment_path);
        }
        $expense->delete();

        ActivityLog::log('expense.deleted', "حذف مصروف {$number}");

        return redirect()->route('admin.expenses.index')
            ->with('success', 'تم حذف المصروف.');
    }

    // ─── Helpers ───────────────────────────────────────────────────

    protected function validateData(Request $request): array
    {
        return $request->validate([
            'expense_category_id' => ['required', Rule::exists('lookups', 'id')->where(fn ($q) =>
                $q->where('group', 'expense_categories')->where('is_active', true)
            )],
            'description'       => ['required', 'string', 'max:255'],
            'amount'            => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'payment_method'    => ['required', Rule::in(array_keys(Expense::PAYMENT_METHODS))],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'vendor_name'       => ['nullable', 'string', 'max:150'],
            'supplier_id'       => ['nullable', Rule::exists('suppliers', 'id')],
            'expense_date'      => ['required', 'date', 'before_or_equal:today'],
            'shift_id'          => ['nullable', Rule::exists('shifts', 'id')],
            'notes'             => ['nullable', 'string', 'max:1000'],
            'attachment'        => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ]);
    }

    /** Look up the most recently opened shift in the active branch, if any. */
    protected function openShiftForCurrentBranch(): ?Shift
    {
        $branchId = BranchContext::current();
        if (! $branchId) {
            return null;
        }
        return $this->openShiftForBranch($branchId);
    }

    protected function openShiftForBranch(int $branchId, ?int $preferredShiftId = null): ?Shift
    {
        return BranchContext::forBranch($branchId, function () use ($preferredShiftId) {
            // If the staffer picked a specific shift on the form and it's
            // still open, honour that; otherwise grab the latest open one.
            if ($preferredShiftId) {
                $picked = Shift::where('id', $preferredShiftId)->where('status', 'open')->first();
                if ($picked) {
                    return $picked;
                }
            }
            return Shift::where('status', 'open')->latest('opened_at')->first();
        });
    }
}
