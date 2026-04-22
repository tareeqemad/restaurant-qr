<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Refund;
use App\Services\RefundService;
use Illuminate\Http\Request;

class RefundController extends Controller
{
    public function __construct(protected RefundService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Refund::class);

        $q = Refund::query()->with(['invoice.tableSession.table', 'processor'])->latest('refunded_at');
        if ($s = $request->get('status')) $q->where('status', $s);
        if ($d = $request->get('from'))   $q->whereDate('refunded_at', '>=', $d);
        if ($d = $request->get('to'))     $q->whereDate('refunded_at', '<=', $d);
        if ($s = $request->get('search')) {
            $q->where(function ($qq) use ($s) {
                $qq->where('number', 'like', "%$s%")
                   ->orWhereHas('invoice', fn($i) => $i->where('number', 'like', "%$s%"));
            });
        }
        $refunds = $q->paginate(20)->withQueryString();

        $today = today();
        $stats = [
            'today_count'  => Refund::whereDate('refunded_at', $today)->where('status', 'completed')->count(),
            'today_amount' => (float) Refund::whereDate('refunded_at', $today)->where('status', 'completed')->sum('amount'),
            'pending'      => Refund::where('status', 'pending')->count(),
            'month_amount' => (float) Refund::whereMonth('refunded_at', $today->month)
                                            ->whereYear('refunded_at', $today->year)
                                            ->where('status', 'completed')
                                            ->sum('amount'),
        ];

        return view('admin.refunds.index', compact('refunds', 'stats'));
    }

    /** Store a new refund against an invoice (called from cashier's invoice page) */
    public function store(Request $request, Invoice $invoice)
    {
        $this->authorize('create', Refund::class);

        $data = $request->validate([
            'amount'     => ['required', 'numeric', 'min:0.01'],
            'method'     => ['required', 'in:cash,card,transfer,app,credit,other'],
            'reason'     => ['required', 'string', 'max:500'],
            'reference'  => ['nullable', 'string', 'max:100'],
            'notes'      => ['nullable', 'string', 'max:1000'],
            'payment_id' => ['nullable', 'exists:payments,id'],
            'status'     => ['nullable', 'in:pending,completed'],
        ]);

        try {
            $refund = $this->service->issue(
                invoice:  $invoice,
                amount:   (float) $data['amount'],
                method:   $data['method'],
                reason:   $data['reason'],
                userId:   auth()->id(),
                opts:     [
                    'reference'  => $data['reference']  ?? null,
                    'notes'      => $data['notes']      ?? null,
                    'payment_id' => $data['payment_id'] ?? null,
                    'status'     => $data['status']     ?? 'completed',
                ]
            );
            return back()->with('success', "تم تسجيل الاسترداد {$refund->number} بقيمة ".number_format($refund->amount, 2));
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function complete(Request $request, Refund $refund)
    {
        $this->authorize('complete', $refund);
        $data = $request->validate(['reference' => ['nullable', 'string', 'max:100']]);
        try {
            $this->service->complete($refund, auth()->id(), $data['reference'] ?? null);
            return back()->with('success', 'تم إتمام الاسترداد');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, Refund $refund)
    {
        $this->authorize('cancel', $refund);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->service->cancel($refund, auth()->id(), $data['reason']);
            return back()->with('success', 'تم إلغاء طلب الاسترداد');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
