<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\Money;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Refund;
use App\Services\RefundService;
use App\Support\AdminShell;
use App\Support\CollectionWorkspace;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RefundController extends Controller
{
    public function __construct(protected RefundService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Refund::class);

        $query = Refund::query()
            ->with(['invoice.tableSession.table', 'invoice.branch', 'processor', 'creditNote', 'allocations.payment'])
            ->latest('refunded_at');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($method = $request->get('method')) {
            $query->where('method', $method);
        }
        if ($from = $request->get('from')) {
            $query->whereDate('refunded_at', '>=', $from);
        }
        if ($to = $request->get('to')) {
            $query->whereDate('refunded_at', '<=', $to);
        }
        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(function ($nested) use ($search) {
                $nested->where('number', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhereHas('invoice', fn ($invoice) => $invoice->where('number', 'like', "%{$search}%"));
            });
        }

        $filteredQuery = clone $query;
        $filteredStats = [
            'count' => (clone $filteredQuery)->count(),
            'amount' => (float) (clone $filteredQuery)->sum('amount'),
            'pending' => (clone $filteredQuery)->where('status', 'pending')->count(),
            'completed' => (clone $filteredQuery)->where('status', 'completed')->count(),
        ];

        $refunds = $query->paginate(20)->withQueryString();
        $refunds->through(function (Refund $refund) {
            $branch = $refund->invoice?->branch;

            return [
                'id' => $refund->id,
                'number' => $refund->number,
                'reference' => $refund->reference,
                'invoiceNumber' => $refund->invoice?->number,
                'customer' => $refund->invoice?->customer_name ?: $refund->invoice?->customer_phone,
                'tableNumber' => $refund->invoice?->tableSession?->table?->number,
                'amount' => (float) $refund->amount,
                'amountFormatted' => Money::format($refund->amount),
                'method' => $refund->method,
                'methodLabel' => $refund->methodLabel(),
                'status' => $refund->status,
                'statusLabel' => $refund->statusLabel(),
                'statusColor' => $refund->statusColor(),
                'reason' => $refund->reason,
                'notes' => $refund->notes,
                'processor' => $refund->processor?->name,
                'creditNote' => $refund->creditNote?->number,
                'allocations' => $refund->allocations->map(fn ($allocation) => [
                    'method' => $allocation->method,
                    'methodLabel' => Refund::METHODS[$allocation->method] ?? $allocation->method,
                    'amount' => (float) $allocation->amount,
                    'amountFormatted' => Money::format($allocation->amount),
                ])->values(),
                'refundedAt' => $refund->refunded_at?->format('Y-m-d H:i'),
                'refundedAtHuman' => $refund->refunded_at?->diffForHumans(),
                'branch' => $branch ? [
                    'name' => $branch->localizedName(),
                    'hue' => ($branch->id * 47) % 360,
                ] : null,
                'can' => [
                    'complete' => auth()->user()->can('complete', $refund),
                    'cancel' => auth()->user()->can('cancel', $refund),
                    'reverse' => auth()->user()->can('reverse', $refund),
                ],
                'urls' => [
                    'complete' => route('admin.refunds.complete', $refund),
                    'cancel' => route('admin.refunds.cancel', $refund),
                    'reverse' => route('admin.refunds.reverse', $refund),
                ],
            ];
        });

        $today = today();
        $statsBase = Refund::query();
        $stats = [
            'todayCount' => (clone $statsBase)->whereDate('refunded_at', $today)->where('status', 'completed')->count(),
            'todayAmount' => (float) (clone $statsBase)->whereDate('refunded_at', $today)->where('status', 'completed')->sum('amount'),
            'pending' => (clone $statsBase)->where('status', 'pending')->count(),
            'monthAmount' => (float) (clone $statsBase)->whereMonth('refunded_at', $today->month)
                ->whereYear('refunded_at', $today->year)
                ->where('status', 'completed')
                ->sum('amount'),
        ];

        return AdminShell::render('Admin/Refunds/Index', [
            'refunds' => $refunds,
            'stats' => $stats + [
                'todayAmountFormatted' => Money::format($stats['todayAmount']),
                'monthAmountFormatted' => Money::format($stats['monthAmount']),
            ],
            'filteredStats' => $filteredStats + [
                'amountFormatted' => Money::format($filteredStats['amount']),
            ],
            'filters' => [
                'search' => trim((string) $request->get('search', '')),
                'status' => (string) $request->get('status', ''),
                'method' => (string) $request->get('method', ''),
                'from' => (string) $request->get('from', ''),
                'to' => (string) $request->get('to', ''),
            ],
            'methods' => collect(Refund::METHODS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'showBranch' => (bool) session('view_all_branches'),
            'collectionNav' => CollectionWorkspace::navigation(),
            'urls' => ['index' => route('admin.refunds.index')],
        ]);
    }

    public function store(Request $request, Invoice $invoice)
    {
        $this->authorize('create', Refund::class);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(Refund::ACTIVE_METHODS)],
            'reason' => ['required', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'payment_id' => ['nullable', 'exists:payments,id'],
            'status' => ['nullable', 'in:pending,completed'],
            'lines' => ['nullable', 'array', 'max:50'],
            'lines.*.order_item_id' => ['required', 'integer', 'exists:order_items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'lines.*.disposition' => ['nullable', Rule::in(['none', 'waste', 'restock'])],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $refund = $this->service->issue(
                invoice: $invoice,
                amount: (float) $data['amount'],
                method: $data['method'],
                reason: $data['reason'],
                userId: auth()->id(),
                opts: [
                    'reference' => $data['reference'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'payment_id' => $data['payment_id'] ?? null,
                    'status' => $data['status'] ?? 'completed',
                    'lines' => $data['lines'] ?? [],
                    'idempotency_key' => $data['idempotency_key'] ?? null,
                ]
            );

            return back()->with('success', "تم تسجيل الاسترداد {$refund->number} بقيمة ".Money::format($refund->amount));
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }
    }

    public function complete(Request $request, Refund $refund)
    {
        $this->authorize('complete', $refund);
        $data = $request->validate(['reference' => ['nullable', 'string', 'max:100']]);

        try {
            $this->service->complete($refund, auth()->id(), $data['reference'] ?? null);

            return back()->with('success', 'تم إتمام الاسترداد');
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function cancel(Request $request, Refund $refund)
    {
        $this->authorize('cancel', $refund);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $this->service->cancel($refund, auth()->id(), $data['reason']);

            return back()->with('success', 'تم إلغاء طلب الاسترداد');
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function reverse(Request $request, Refund $refund)
    {
        $this->authorize('reverse', $refund);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $this->service->reverse($refund, auth()->id(), trim($data['reason']));

            return back()->with('success', 'تم عكس الاسترداد والإشعار الدائن مع الاحتفاظ بكامل سجل التدقيق.');
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }
}
