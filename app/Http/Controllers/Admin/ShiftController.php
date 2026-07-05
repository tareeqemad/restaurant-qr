<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\Shift;
use App\Services\Accounting\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShiftController extends Controller
{
    public function index()
    {
        // Add explicit authorization — used to be missing entirely.
        $this->authorize('viewAny', Shift::class);

        $shifts = Shift::with('user')->latest('opened_at')->paginate(20);
        $activeShift = auth()->user()->activeShift;
        // The last drawer close on this branch's till — shown as the expected
        // opening count so a cashier taking over can spot a hand-off mismatch.
        $lastClosedShift = Shift::with('user')->where('status', 'closed')->latest('closed_at')->first();
        return view('admin.shifts.index', compact('shifts', 'activeShift', 'lastClosedShift'));
    }

    /**
     * Open a new shift. Wrapped in a transaction with a row-level user lock
     * so a double-click can never create two open shifts for the same
     * cashier (which would split today's payments across phantom shifts).
     */
    public function store(Request $request)
    {
        $data = $request->validate(['cash_opening' => ['required', 'numeric', 'min:0']]);

        $userId = auth()->id();
        $shift  = null;

        try {
            DB::transaction(function () use ($data, $userId, &$shift) {
                // Lock this user's row for the duration. Concurrent
                // shift-open requests serialize on this lock; the second one
                // sees the first's open shift and bails out.
                \App\Models\User::whereKey($userId)->lockForUpdate()->first();

                if (Shift::where('user_id', $userId)->where('status', 'open')->exists()) {
                    throw new \RuntimeException('لديك شفت مفتوح بالفعل');
                }

                $shift = Shift::create([
                    'user_id'      => $userId,
                    'cash_opening' => $data['cash_opening'],
                    'status'       => 'open',
                    'opened_at'    => now(),
                ]);

                ActivityLog::log(
                    'shift.opened',
                    "فتح شفت — رصيد افتتاحي: " . number_format((float) $data['cash_opening'], 2),
                    $shift,
                    ['cash_opening' => (float) $data['cash_opening']]
                );

            });
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        // Drawer hand-off check: if the opening count doesn't match the last
        // shift's closing count on this till, flag it — a discrepancy created
        // between the outgoing and incoming cashier. The shift still opens; we
        // just make the mismatch impossible to miss (and audit it).
        $prev = Shift::where('status', 'closed')->latest('closed_at')->first();
        if ($prev && abs((float) $prev->cash_closing - (float) $data['cash_opening']) > 0.001) {
            $diff = (float) $data['cash_opening'] - (float) $prev->cash_closing;
            ActivityLog::log(
                'shift.handoff_variance',
                "فرق تسليم الدرج: افتتاح ".number_format((float) $data['cash_opening'], 2)
                    ." مقابل إغلاق سابق ".number_format((float) $prev->cash_closing, 2),
                $shift,
                ['opening' => (float) $data['cash_opening'], 'previous_closing' => (float) $prev->cash_closing, 'variance' => $diff]
            );

            return back()
                ->with('success', 'تم فتح الشفت')
                ->with('warning', 'انتبه: رصيد الافتتاح ('.number_format((float) $data['cash_opening'], 2)
                    .') لا يطابق إغلاق الشفت السابق ('.number_format((float) $prev->cash_closing, 2)
                    .'). الفرق '.number_format($diff, 2).' — تأكّد من تسليم الدرج قبل المتابعة.');
        }

        return back()->with('success', 'تم فتح الشفت');
    }

    /**
     * Close a shift. Wrapped in a transaction with `lockForUpdate` on the
     * shift row so payments landing concurrently can't change the cash_sales
     * sum mid-close (which would skew the variance).
     */
    public function close(Request $request, Shift $shift)
    {
        abort_unless($shift->user_id === auth()->id() || auth()->user()->isAdmin() || auth()->user()->isManager(), 403);
        $data = $request->validate([
            'cash_closing' => ['required', 'numeric', 'min:0'],
            'notes'        => ['nullable', 'string'],
        ]);

        try {
            DB::transaction(function () use ($shift, $data) {
                // Re-fetch under lock to get the canonical state. Anything
                // querying this shift (a payment crediting cash_sales) waits
                // until close commits.
                $shift = Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();

                if ($shift->status !== 'open') {
                    throw new \RuntimeException('الشفت مغلق مسبقاً.');
                }

                // Same live-aggregate math the mid-shift X-report uses — one
                // source of truth so the two can never drift.
                $b = $this->computeExpectedCash($shift);
                $variance = (float) $data['cash_closing'] - $b['expected_cash'];

                $shift->update([
                    'cash_closing'   => $data['cash_closing'],
                    'cash_sales'     => $b['cash_sales'],
                    'card_sales'     => $b['card_sales'],
                    'other_sales'    => $b['other_sales'],
                    'total_sales'    => $b['total_sales'],
                    'expected_cash'  => $b['expected_cash'],
                    'cash_variance'  => $variance,
                    'status'         => 'closed',
                    'closed_at'      => now(),
                    'notes'          => $data['notes'] ?? null,
                ]);

                ActivityLog::log(
                    'shift.closed',
                    "إغلاق شفت — فرق الكاش: " . number_format($variance, 2),
                    $shift,
                    [
                        'cash_opening'  => $b['cash_opening'],
                        'cash_closing'  => (float) $data['cash_closing'],
                        'cash_sales'    => $b['cash_sales'],
                        'card_sales'    => $b['card_sales'],
                        'other_sales'   => $b['other_sales'],
                        'cash_refunds'  => $b['cash_refunds'],
                        'cash_pay_ins'  => $b['cash_pay_ins'],
                        'cash_pay_outs' => $b['cash_pay_outs'],
                        'supplier_cash_payments' => $b['supplier_cash_payments'],
                        'expected_cash' => $b['expected_cash'],
                        'cash_variance' => $variance,
                    ]
                );

                app(AccountingService::class)->recordShiftClosed($shift->fresh());
            });
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        // Notify admins/managers — variance escalates severity inside the
        // notification class (>1 = warning, >50 = danger). Outside the
        // transaction so a notification failure can't roll back the close.
        app(\App\Services\NotifyService::class)
            ->shiftClosed($shift->fresh()->load('user'));

        return back()->with('success', 'تم إغلاق الشفت');
    }

    /**
     * Read-only "what should be in the drawer right now" snapshot — the same
     * expected-cash breakdown close() computes, WITHOUT ending the shift. Lets
     * an owner do a spot drawer count mid-shift, or a cashier sanity-check
     * before closing. Pure reads, zero writes / broadcasts / ledger effects.
     */
    public function xReport(Shift $shift)
    {
        $this->authorize('view', $shift);
        $breakdown = $this->computeExpectedCash($shift);
        return view('admin.shifts.x-report', compact('shift', 'breakdown'));
    }

    /**
     * Record an ad-hoc cash pay-in (drawer top-up from the safe) or pay-out
     * (petty cash spent) on the open shift, so the drawer reconciles at close.
     * GL-neutral by design — CashMovements never post to the ledger; they only
     * adjust the shift's expected-cash (close() already sums them).
     */
    public function cashMovement(Request $request, Shift $shift)
    {
        abort_unless($shift->user_id === auth()->id() || auth()->user()->isAdmin() || auth()->user()->isManager(), 403);

        if ($shift->status !== 'open') {
            return back()->with('error', 'لا يمكن تسجيل حركة نقدية على شفت مغلق.');
        }

        $data = $request->validate([
            'type'   => ['required', 'in:pay_in,pay_out'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $movement = \App\Models\CashMovement::create([
            // Stamp branch from the shift (not BranchContext — an owner in
            // "all branches" mode has no context), mirroring ExpenseController.
            'branch_id' => $shift->branch_id,
            'shift_id'  => $shift->id,
            'type'      => $data['type'],
            'amount'    => $data['amount'],
            'reason'    => $data['reason'],
            'user_id'   => auth()->id(),
        ]);

        $label = $data['type'] === 'pay_in' ? 'إيداع في الدرج' : 'صرف من الدرج';
        ActivityLog::log(
            'shift.cash_movement',
            "{$label} بمبلغ ".number_format((float) $data['amount'], 2)." — {$data['reason']}",
            $movement,
            ['type' => $data['type'], 'amount' => (float) $data['amount'], 'shift_id' => $shift->id]
        );

        return back()->with('success', "تم تسجيل الحركة: {$label}.");
    }

    /**
     * Live cash breakdown for a shift — the single source of truth shared by
     * close() (writes it) and xReport() (displays it). All reads, no writes.
     * expected = opening + cash_sales + pay_ins − pay_outs − cash_refunds
     *            − supplier_cash_payments.
     */
    protected function computeExpectedCash(Shift $shift): array
    {
        $payments  = Payment::where('shift_id', $shift->id)->get();
        $cashSales = (float) $payments->where('method', 'cash')->sum('amount');
        $cardSales = (float) $payments->where('method', 'card')->sum('amount');
        $other     = (float) $payments->whereNotIn('method', ['cash', 'card'])->sum('amount');

        // Only COMPLETED cash refunds actually left the drawer; pending/cancelled
        // move no cash. Refund::method tracks how the money left, not the
        // original payment method.
        $cashRefunds = (float) \App\Models\Refund::where('shift_id', $shift->id)
            ->where('method', 'cash')
            ->where('status', 'completed')
            ->sum('amount');

        $cashPayIns  = (float) $shift->cashMovements()->where('type', 'pay_in')->sum('amount');
        $cashPayOuts = (float) $shift->cashMovements()->where('type', 'pay_out')->sum('amount');

        $supplierCashPayments = (float) \App\Models\SupplierPayment::where('shift_id', $shift->id)
            ->where('method', 'cash')
            ->sum('amount');

        $expected = (float) $shift->cash_opening
            + $cashSales
            + $cashPayIns
            - $cashPayOuts
            - $cashRefunds
            - $supplierCashPayments;

        return [
            'cash_opening'           => (float) $shift->cash_opening,
            'cash_sales'             => $cashSales,
            'card_sales'             => $cardSales,
            'other_sales'            => $other,
            'total_sales'            => (float) $payments->sum('amount'),
            'cash_refunds'           => $cashRefunds,
            'cash_pay_ins'           => $cashPayIns,
            'cash_pay_outs'          => $cashPayOuts,
            'supplier_cash_payments' => $supplierCashPayments,
            'expected_cash'          => $expected,
        ];
    }
}
