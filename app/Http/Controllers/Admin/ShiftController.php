<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Shift;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    public function index()
    {
        $shifts = Shift::with('user')->latest('opened_at')->paginate(20);
        $activeShift = auth()->user()->activeShift;
        return view('admin.shifts.index', compact('shifts', 'activeShift'));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['cash_opening' => ['required', 'numeric', 'min:0']]);
        if (Shift::where('user_id', auth()->id())->where('status', 'open')->exists()) {
            return back()->with('error', 'لديك شفت مفتوح بالفعل');
        }
        Shift::create([
            'user_id' => auth()->id(),
            'cash_opening' => $data['cash_opening'],
            'status' => 'open',
            'opened_at' => now(),
        ]);
        return back()->with('success', 'تم فتح الشفت');
    }

    public function close(Request $request, Shift $shift)
    {
        abort_unless($shift->user_id === auth()->id() || auth()->user()->isAdmin() || auth()->user()->isManager(), 403);
        $data = $request->validate([
            'cash_closing' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);
        $payments = Payment::where('shift_id', $shift->id)->get();
        $cashSales = (float) $payments->where('method', 'cash')->sum('amount');
        $cardSales = (float) $payments->where('method', 'card')->sum('amount');
        $other = (float) $payments->whereNotIn('method', ['cash', 'card'])->sum('amount');
        $expected = (float) $shift->cash_opening + $cashSales;

        $shift->update([
            'cash_closing' => $data['cash_closing'],
            'cash_sales' => $cashSales,
            'card_sales' => $cardSales,
            'other_sales' => $other,
            'total_sales' => $payments->sum('amount'),
            'expected_cash' => $expected,
            'cash_variance' => (float) $data['cash_closing'] - $expected,
            'status' => 'closed',
            'closed_at' => now(),
            'notes' => $data['notes'] ?? null,
        ]);
        return back()->with('success', 'تم إغلاق الشفت');
    }
}
