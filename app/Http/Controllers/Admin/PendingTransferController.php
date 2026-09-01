<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\Money;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PendingTransfer;
use App\Models\TableSession;
use App\Services\BillingService;
use App\Services\PendingTransferService;
use App\Support\AdminShell;
use App\Support\CollectionWorkspace;
use App\Support\SidebarBadges;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PendingTransferController extends Controller
{
    public function __construct(
        protected BillingService $billing,
        protected PendingTransferService $transfers,
    ) {}

    protected function backToCashier(Request $request, string $status, string $message)
    {
        $sessionId = $request->integer('return_session');

        if ($sessionId > 0 && TableSession::whereKey($sessionId)->exists()) {
            return redirect()->route('admin.cashier.index', ['session' => $sessionId])->with($status, $message);
        }

        return back()->with($status, $message);
    }

    public function store(Request $request, TableSession $session)
    {
        abort_unless(
            auth()->user()?->can('create', Order::class)
            || auth()->user()?->can('create', Payment::class),
            403
        );

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'sender_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
            'proof' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $proofPath = $request->file('proof')?->store('pending-transfer-proofs', 'local');

        try {
            $this->transfers->record(
                session: $session,
                amount: (float) $data['amount'],
                senderName: $data['sender_name'],
                recordedByUserId: auth()->id(),
                notes: $data['notes'] ?? null,
                phone: $data['customer_phone'] ?? null,
                typedName: $data['customer_name'] ?? null,
                proofPath: $proofPath ?: null,
            );
        } catch (\App\Exceptions\DuplicatePendingTransferException $exception) {
            if ($proofPath) {
                Storage::disk('local')->delete($proofPath);
            }

            return $this->backToCashier($request, 'info', 'يوجد تحويل معلّق لهذه الطاولة بالفعل — أكّده أو ارفضه أولاً.');
        } catch (\Throwable $exception) {
            if ($proofPath) {
                Storage::disk('local')->delete($proofPath);
            }
            throw $exception;
        }

        return $this->backToCashier($request, 'success', 'تم تسجيل التحويل. الكاشير سيتأكد من البنك ويغلق الطلب.');
    }

    public function proof(PendingTransfer $transfer)
    {
        $this->authorize('create', Payment::class);
        abort_unless(
            $transfer->proof_path
                && str_starts_with($transfer->proof_path, 'pending-transfer-proofs/')
                && Storage::disk('local')->exists($transfer->proof_path),
            404
        );

        $extension = pathinfo($transfer->proof_path, PATHINFO_EXTENSION) ?: 'jpg';

        return Storage::disk('local')->response(
            $transfer->proof_path,
            'transfer-proof-'.$transfer->id.'.'.$extension,
            [],
            'inline'
        );
    }

    public function queue(Request $request)
    {
        $this->authorize('create', Payment::class);

        $search = trim((string) $request->get('q', ''));
        $pendingQuery = PendingTransfer::with(['tableSession.table', 'invoice:id,number', 'customer', 'recordedBy'])
            ->pending()
            ->latest();

        if ($search !== '') {
            $pendingQuery->where(function ($query) use ($search) {
                $query->where('sender_name', 'like', "%{$search}%")
                    ->orWhere('customer_name_snapshot', 'like', "%{$search}%")
                    ->orWhere('customer_phone_snapshot', 'like', "%{$search}%");
                if (is_numeric($search)) {
                    $query->orWhere('amount', '=', (float) $search)
                        ->orWhereHas('tableSession.table', fn ($table) => $table->where('number', $search));
                }
            });
        }

        $pending = $pendingQuery->get();
        $recentlyClosed = PendingTransfer::with(['tableSession.table', 'invoice:id,number', 'verifiedBy', 'payment'])
            ->where('status', '!=', PendingTransfer::STATUS_PENDING)
            ->whereDate('updated_at', today())
            ->latest('updated_at')
            ->limit(20)
            ->get();

        return AdminShell::render('Admin/Transfers/Queue', [
            'pending' => $pending->map(fn (PendingTransfer $transfer) => $this->payload($transfer))->values(),
            'recentlyClosed' => $recentlyClosed->map(fn (PendingTransfer $transfer) => $this->payload($transfer))->values(),
            'stats' => [
                'pendingCount' => $pending->count(),
                'pendingAmount' => (float) $pending->sum('amount'),
                'pendingAmountFormatted' => Money::format($pending->sum('amount')),
                'oldestWaiting' => $pending->last()?->created_at?->diffForHumans(),
                'closedToday' => $recentlyClosed->count(),
            ],
            'filters' => ['search' => $search],
            'collectionNav' => CollectionWorkspace::navigation(),
            'urls' => [
                'index' => route('admin.cashier.transfers.queue'),
                'report' => route('admin.cashier.transfers.report'),
                'cashier' => route('admin.cashier.index'),
            ],
        ]);
    }

    public function verify(Request $request, PendingTransfer $transfer)
    {
        $this->authorize('create', Payment::class);
        abort_unless($transfer->isPending(), 422, 'هذا التحويل لم يعد بانتظار التأكيد.');

        $data = $request->validate([
            'verified_amount' => ['nullable', 'numeric', 'min:0.01'],
            'verification_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $verifiedAmount = isset($data['verified_amount'])
            ? (float) $data['verified_amount']
            : (float) $transfer->amount;

        try {
            $result = $this->transfers->verify(
                $transfer,
                $verifiedAmount,
                auth()->id(),
                $data['verification_notes'] ?? null,
            );
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $remainingBalance = $result['remaining_balance'];
        $invoiceNumber = $result['invoice']->number;
        if ($remainingBalance > 0.001) {
            return $this->backToCashier(
                $request,
                'warning',
                'تم تأكيد التحويل بمبلغ '.Money::format($verifiedAmount).'. لا يزال متبقٍ '.Money::format($remainingBalance).' على الفاتورة '.$invoiceNumber.' — حصّله أو أجّله كدين قبل إغلاق الطاولة.'
            );
        }

        return $this->backToCashier($request, 'success', 'تم تأكيد التحويل وتسجيله كدفعة.');
    }

    public function reopen(Request $request, PendingTransfer $transfer)
    {
        $this->authorize('create', Payment::class);
        abort_unless(
            $transfer->status === PendingTransfer::STATUS_REJECTED,
            422,
            'فقط التحويلات المرفوضة يمكن إعادة فتحها. التحويل المؤكد فيه دفعة فعلية — استخدم استرجاع.'
        );

        $transfer->update([
            'status' => PendingTransfer::STATUS_PENDING,
            'rejected_at' => null,
            'rejection_reason' => null,
        ]);

        ActivityLog::log(
            'pending_transfer.reopened',
            "إعادة فتح التحويل #{$transfer->id}",
            $transfer,
            ['amount' => (float) $transfer->amount]
        );

        SidebarBadges::bust();

        return back()->with('success', 'تم إعادة التحويل لقائمة الانتظار.');
    }

    public function reject(Request $request, PendingTransfer $transfer)
    {
        $this->authorize('create', Payment::class);
        abort_unless($transfer->isPending(), 422, 'هذا التحويل لم يعد بانتظار التأكيد.');

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $this->transfers->reject($transfer, $data['reason'], auth()->id());
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $this->backToCashier($request, 'success', 'تم رفض التحويل وتسجيل السبب.');
    }

    public function report(Request $request)
    {
        $this->authorize('create', Payment::class);

        $date = $request->date('date') ?? today();
        $rows = PendingTransfer::with(['tableSession.table', 'invoice:id,number', 'recordedBy', 'verifiedBy', 'payment'])
            ->whereDate('created_at', $date)
            ->orderBy('created_at')
            ->get();

        $groups = [
            PendingTransfer::STATUS_PENDING => $rows->where('status', PendingTransfer::STATUS_PENDING),
            PendingTransfer::STATUS_VERIFIED => $rows->where('status', PendingTransfer::STATUS_VERIFIED),
            PendingTransfer::STATUS_REJECTED => $rows->where('status', PendingTransfer::STATUS_REJECTED),
        ];
        $verifiedActual = (float) $groups[PendingTransfer::STATUS_VERIFIED]
            ->sum(fn (PendingTransfer $transfer) => (float) ($transfer->payment?->amount ?? $transfer->amount));

        return AdminShell::render('Admin/Transfers/Report', [
            'rows' => $rows->map(fn (PendingTransfer $transfer) => $this->payload($transfer))->values(),
            'date' => $date->format('Y-m-d'),
            'summary' => [
                'verified' => $this->summary($groups[PendingTransfer::STATUS_VERIFIED], $verifiedActual),
                'pending' => $this->summary($groups[PendingTransfer::STATUS_PENDING]),
                'rejected' => $this->summary($groups[PendingTransfer::STATUS_REJECTED]),
            ],
            'totals' => [
                'claimsFormatted' => Money::format($rows->sum('amount')),
                'verifiedActualFormatted' => Money::format($verifiedActual),
            ],
            'collectionNav' => CollectionWorkspace::navigation(),
            'urls' => [
                'index' => route('admin.cashier.transfers.report'),
                'queue' => route('admin.cashier.transfers.queue'),
            ],
        ]);
    }

    private function payload(PendingTransfer $transfer): array
    {
        $actual = $transfer->status === PendingTransfer::STATUS_VERIFIED
            ? (float) ($transfer->payment?->amount ?? $transfer->amount)
            : null;
        $handledAt = $transfer->verified_at ?? $transfer->rejected_at ?? $transfer->updated_at;

        return [
            'id' => $transfer->id,
            'status' => $transfer->status,
            'statusLabel' => match ($transfer->status) {
                PendingTransfer::STATUS_VERIFIED => 'مؤكد',
                PendingTransfer::STATUS_REJECTED => 'مرفوض',
                default => 'بانتظار التأكيد',
            },
            'senderName' => $transfer->sender_name,
            'customerName' => $transfer->customer_name_snapshot ?: 'زبون بدون ملف',
            'customerPhone' => $transfer->customer_phone_snapshot,
            'tableNumber' => $transfer->tableSession?->table?->number,
            'invoiceNumber' => $transfer->invoice?->number,
            'amount' => (float) $transfer->amount,
            'amountFormatted' => Money::format($transfer->amount),
            'actualAmount' => $actual,
            'actualAmountFormatted' => $actual !== null ? Money::format($actual) : null,
            'hasAmountDifference' => $actual !== null && abs($actual - (float) $transfer->amount) > 0.001,
            'notes' => $transfer->notes,
            'decisionNote' => $transfer->status === PendingTransfer::STATUS_REJECTED
                ? $transfer->rejection_reason
                : ($transfer->verification_notes ?: $transfer->notes),
            'recordedBy' => $transfer->recordedBy?->name ?: 'الزبون',
            'verifiedBy' => $transfer->verifiedBy?->name,
            'createdAt' => $transfer->created_at?->format('Y-m-d H:i:s'),
            'createdTime' => $transfer->created_at?->format('H:i'),
            'age' => $transfer->created_at?->diffForHumans(),
            'handledAt' => $handledAt?->format('H:i'),
            'hasProof' => (bool) $transfer->proof_path,
            'urls' => [
                'proof' => $transfer->proof_path ? route('admin.cashier.transfers.proof', $transfer) : null,
                'verify' => route('admin.cashier.transfers.verify', $transfer),
                'reject' => route('admin.cashier.transfers.reject', $transfer),
                'reopen' => route('admin.cashier.transfers.reopen', $transfer),
            ],
        ];
    }

    private function summary($rows, ?float $actual = null): array
    {
        $claimed = (float) $rows->sum('amount');

        return [
            'count' => $rows->count(),
            'amount' => $actual ?? $claimed,
            'amountFormatted' => Money::format($actual ?? $claimed),
            'claimedFormatted' => Money::format($claimed),
        ];
    }
}
