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
        return view('admin.shifts.index', compact('shifts', 'activeShift'));
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

                $payments  = Payment::where('shift_id', $shift->id)->get();
                $cashSales = (float) $payments->where('method', 'cash')->sum('amount');
                $cardSales = (float) $payments->where('method', 'card')->sum('amount');
                $other     = (float) $payments->whereNotIn('method', ['cash', 'card'])->sum('amount');

                // Cash that left the drawer during the shift via refunds.
                // The original `expected_cash = opening + cash_sales` math
                // ignored refunds, so any cash returned to a customer mid-
                // shift would surface as a phantom shortage. Refund::method
                // tracks how the money LEFT the drawer (which is what we
                // care about here), independent of the original payment
                // method. NULL guard for the column rolls in NULL→0.
                $cashRefunds = (float) \App\Models\Refund::where('shift_id', $shift->id)
                    ->where('method', 'cash')
                    ->sum('amount');

                $expected  = (float) $shift->cash_opening + $cashSales - $cashRefunds;
                $variance  = (float) $data['cash_closing'] - $expected;

                $shift->update([
                    'cash_closing'   => $data['cash_closing'],
                    'cash_sales'     => $cashSales,
                    'card_sales'     => $cardSales,
                    'other_sales'    => $other,
                    'total_sales'    => $payments->sum('amount'),
                    'expected_cash'  => $expected,
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
                        'cash_opening'  => (float) $shift->cash_opening,
                        'cash_closing'  => (float) $data['cash_closing'],
                        'cash_sales'    => $cashSales,
                        'card_sales'    => $cardSales,
                        'other_sales'   => $other,
                        'cash_refunds'  => $cashRefunds,
                        'expected_cash' => $expected,
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
}
