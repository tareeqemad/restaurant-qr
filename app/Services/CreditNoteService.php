<?php

namespace App\Services;

use App\Helpers\Money;
use App\Models\ActivityLog;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Scopes\BranchScope;
use App\Services\Accounting\AccountingService;
use App\Support\BranchContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreditNoteService
{
    public function __construct(private readonly AccountingService $accounting) {}

    /**
     * Issue an immutable credit note. For a paid sale RefundService settles
     * the resulting customer credit separately; for debt adjustments this
     * method alone reduces A/R and the invoice balance.
     */
    public function issue(
        Invoice $invoice,
        float $amount,
        string $kind,
        string $reason,
        int $userId,
        array $options = [],
    ): CreditNote {
        $amount = Money::round($amount);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'قيمة الإشعار الدائن يجب أن تكون أكبر من صفر.']);
        }
        if (! in_array($kind, ['refund', 'debt_adjustment', 'allowance'], true)) {
            throw new \InvalidArgumentException('Unsupported credit-note kind.');
        }

        return DB::transaction(function () use ($invoice, $amount, $kind, $reason, $userId, $options) {
            $invoice = Invoice::withoutGlobalScope(BranchScope::class)
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            return BranchContext::forBranch($invoice->branch_id, function () use ($invoice, $amount, $kind, $reason, $userId, $options) {
                if (in_array($invoice->status, ['cancelled', 'unpaid_writeoff'], true)) {
                    throw ValidationException::withMessages(['amount' => 'لا يمكن إصدار إشعار دائن على فاتورة ملغاة أو مشطوبة.']);
                }
                if ($kind === 'debt_adjustment') {
                    if (! $invoice->settled_on_account_at) {
                        throw ValidationException::withMessages(['invoice_id' => 'تخفيض الدين متاح فقط لفاتورة مؤجلة على حساب الزبون.']);
                    }
                    if ($amount - (float) $invoice->balance > 0.01) {
                        throw ValidationException::withMessages(['amount' => 'لا يمكن أن يتجاوز التخفيض رصيد الدين الحالي.']);
                    }
                }

                $remainingSale = Money::round((float) $invoice->total - (float) $invoice->credited_total);
                if ($amount - $remainingSale > 0.01) {
                    throw ValidationException::withMessages([
                        'amount' => 'الحد المتبقي القابل للتخفيض هو '.number_format(max(0, $remainingSale), 2).'.',
                    ]);
                }

                $breakdown = $this->breakdown($invoice, $amount, $options['lines'] ?? []);
                $creditNote = CreditNote::create([
                    'branch_id' => $invoice->branch_id,
                    'number' => CreditNote::generateNumber(),
                    'invoice_id' => $invoice->id,
                    'kind' => $kind,
                    'status' => 'posted',
                    'revenue_total' => $breakdown['revenue'],
                    'tax_total' => $breakdown['tax'],
                    'service_total' => $breakdown['service'],
                    'delivery_total' => $breakdown['delivery'],
                    'tip_total' => $breakdown['tip'],
                    'total' => $amount,
                    'reason' => trim($reason),
                    'notes' => $options['notes'] ?? null,
                    'metadata' => $options['metadata'] ?? null,
                    'issued_by' => $userId,
                    'issued_at' => now(),
                ]);

                foreach ($breakdown['lines'] as $line) {
                    $creditNote->lines()->create($line);
                }

                $invoice->increment('credited_total', $amount);
                $invoice->fresh()->recomputeBalanceAfterRefund();
                $this->accounting->recordCreditNoteIssued($creditNote->fresh('lines'));

                ActivityLog::log(
                    'credit_note.issued',
                    "إصدار إشعار دائن {$creditNote->number} على {$invoice->number} بقيمة ".number_format($amount, 2),
                    $creditNote,
                    ['invoice_id' => $invoice->id, 'kind' => $kind, 'amount' => $amount],
                    causerId: $userId,
                );

                app(LoyaltyService::class)->reverseForRefund($invoice->fresh(), $amount, $userId);

                return $creditNote->fresh(['lines', 'invoice']);
            });
        });
    }

    public function reverse(CreditNote $creditNote, int $userId, string $reason): CreditNote
    {
        return DB::transaction(function () use ($creditNote, $userId, $reason) {
            $creditNote = CreditNote::withoutGlobalScope(BranchScope::class)
                ->whereKey($creditNote->id)
                ->with('invoice')
                ->lockForUpdate()
                ->firstOrFail();
            $invoice = Invoice::withoutGlobalScope(BranchScope::class)
                ->whereKey($creditNote->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($creditNote->status !== 'posted') {
                throw ValidationException::withMessages(['status' => 'تم عكس هذا الإشعار الدائن مسبقاً.']);
            }

            return BranchContext::forBranch($creditNote->branch_id, function () use ($creditNote, $invoice, $userId, $reason) {
                $creditNote->update([
                    'status' => 'reversed',
                    'reversed_by' => $userId,
                    'reversed_at' => now(),
                    'reversal_reason' => trim($reason),
                ]);

                $invoice->update([
                    'credited_total' => max(0, Money::round((float) $invoice->credited_total - (float) $creditNote->total)),
                ]);
                $invoice->fresh()->recomputeBalanceAfterRefund();
                $this->accounting->reverseCreditNoteIssued($creditNote, $userId, $reason);
                app(LoyaltyService::class)->reverseForRefund($invoice->fresh(), (float) $creditNote->total, $userId);

                ActivityLog::log(
                    'credit_note.reversed',
                    "عكس الإشعار الدائن {$creditNote->number}: {$reason}",
                    $creditNote,
                    ['invoice_id' => $invoice->id, 'amount' => (float) $creditNote->total],
                    causerId: $userId,
                );

                return $creditNote->fresh();
            });
        });
    }

    /** Item payload used by the cashier to build an exact return document. */
    public function refundableItems(Invoice $invoice): array
    {
        $items = $this->invoiceItems($invoice);
        $credited = DB::table('credit_note_lines')
            ->join('credit_notes', 'credit_notes.id', '=', 'credit_note_lines.credit_note_id')
            ->where('credit_notes.invoice_id', $invoice->id)
            ->where('credit_notes.status', 'posted')
            ->whereNotNull('credit_note_lines.order_item_id')
            ->groupBy('credit_note_lines.order_item_id')
            ->selectRaw('credit_note_lines.order_item_id, SUM(credit_note_lines.quantity) as credited_quantity')
            ->pluck('credited_quantity', 'credit_note_lines.order_item_id');

        return $items->map(function (OrderItem $item) use ($credited) {
            $available = max(0, (float) $item->quantity - (float) ($credited[$item->id] ?? 0));
            $parts = $this->itemParts($item, 1.0);

            return [
                'id' => (int) $item->id,
                'name' => $item->name_snapshot,
                'ordered_quantity' => (float) $item->quantity,
                'available_quantity' => round($available, 2),
                'unit_total' => round(array_sum($parts), 2),
                'order_number' => $item->order?->number,
            ];
        })->filter(fn (array $item) => $item['available_quantity'] > 0.001)->values()->all();
    }

    private function breakdown(Invoice $invoice, float $amount, array $requestedLines): array
    {
        if ($requestedLines === []) {
            return $this->proportionalBreakdown($invoice, $amount);
        }

        $items = $this->invoiceItems($invoice)->keyBy('id');
        $creditedQty = DB::table('credit_note_lines')
            ->join('credit_notes', 'credit_notes.id', '=', 'credit_note_lines.credit_note_id')
            ->where('credit_notes.invoice_id', $invoice->id)
            ->where('credit_notes.status', 'posted')
            ->whereNotNull('credit_note_lines.order_item_id')
            ->groupBy('credit_note_lines.order_item_id')
            ->selectRaw('credit_note_lines.order_item_id, SUM(credit_note_lines.quantity) as credited_quantity')
            ->pluck('credited_quantity', 'credit_note_lines.order_item_id');

        $totals = ['revenue' => 0.0, 'tax' => 0.0, 'service' => 0.0, 'delivery' => 0.0, 'tip' => 0.0];
        $lines = [];
        foreach ($requestedLines as $requestLine) {
            $itemId = (int) ($requestLine['order_item_id'] ?? 0);
            $quantity = round((float) ($requestLine['quantity'] ?? 0), 2);
            $item = $items->get($itemId);
            if (! $item || $quantity <= 0) {
                throw ValidationException::withMessages(['lines' => 'أحد بنود الإرجاع لا يعود لهذه الفاتورة أو كميته غير صحيحة.']);
            }
            $available = (float) $item->quantity - (float) ($creditedQty[$itemId] ?? 0);
            if ($quantity - $available > 0.001) {
                throw ValidationException::withMessages([
                    'lines' => "الكمية المتاحة من {$item->name_snapshot} هي ".number_format(max(0, $available), 2).'.',
                ]);
            }

            $parts = $this->itemParts($item, $quantity);
            foreach ($totals as $key => $_) {
                $totals[$key] += $parts[$key];
            }
            $lineTotal = round(array_sum($parts), 4);
            $lines[] = [
                'order_item_id' => $item->id,
                'description' => $item->name_snapshot,
                'quantity' => $quantity,
                'revenue_amount' => $parts['revenue'],
                'tax_amount' => $parts['tax'],
                'service_amount' => $parts['service'],
                'delivery_amount' => 0,
                'tip_amount' => 0,
                'total' => $lineTotal,
                'disposition' => in_array(($requestLine['disposition'] ?? 'none'), ['none', 'waste', 'restock'], true)
                    ? $requestLine['disposition'] : 'none',
            ];
        }

        $computed = round(array_sum($totals), 2);
        if (abs($computed - $amount) > 0.02) {
            throw ValidationException::withMessages([
                'amount' => 'قيمة البنود المختارة هي '.number_format($computed, 2).' وليست '.number_format($amount, 2).'. حدّث المبلغ ثم راجع العملية.',
            ]);
        }

        // The request amount is authoritative to two decimals; absorb any
        // sub-cent allocation residue in the return-revenue component.
        $totals['revenue'] = round($totals['revenue'] + ($amount - array_sum($totals)), 4);

        return $totals + ['lines' => $lines];
    }

    private function proportionalBreakdown(Invoice $invoice, float $amount): array
    {
        $posted = $invoice->creditNotes()->where('status', 'posted');
        $remaining = [
            'tax' => max(0, (float) $invoice->tax_total - (float) (clone $posted)->sum('tax_total')),
            'service' => max(0, (float) $invoice->service_total - (float) (clone $posted)->sum('service_total')),
            'delivery' => max(0, (float) $invoice->delivery_fee - (float) (clone $posted)->sum('delivery_total')),
            'tip' => max(0, (float) $invoice->tip - (float) (clone $posted)->sum('tip_total')),
        ];
        $remainingTotal = max(0.0001, (float) $invoice->total - (float) $invoice->credited_total);
        $ratio = min(1, $amount / $remainingTotal);
        foreach ($remaining as $key => $value) {
            $remaining[$key] = round($value * $ratio, 4);
        }
        $remaining['revenue'] = round($amount - array_sum($remaining), 4);

        return $remaining + ['lines' => [[
            'order_item_id' => null,
            'description' => 'تخفيض على الفاتورة '.$invoice->number,
            'quantity' => 1,
            'revenue_amount' => $remaining['revenue'],
            'tax_amount' => $remaining['tax'],
            'service_amount' => $remaining['service'],
            'delivery_amount' => $remaining['delivery'],
            'tip_amount' => $remaining['tip'],
            'total' => $amount,
            'disposition' => 'none',
        ]]];
    }

    private function invoiceItems(Invoice $invoice): Collection
    {
        $orders = $invoice->order_id
            ? Order::withoutGlobalScope(BranchScope::class)->whereKey($invoice->order_id)->with('items')->get()
            : Order::withoutGlobalScope(BranchScope::class)->where('table_session_id', $invoice->table_session_id)->with('items')->get();

        return $orders->flatMap(function (Order $order) {
            $order->items->each->setRelation('order', $order);

            return $order->items->where('status', '!=', 'cancelled');
        })->values();
    }

    /** Components charged for a quantity of one order item. */
    private function itemParts(OrderItem $item, float $quantity): array
    {
        $order = $item->order;
        $activeGross = max(0.0001, (float) $order->items->where('status', '!=', 'cancelled')->sum('subtotal'));
        $share = (float) $item->subtotal / $activeGross;
        $quantityRatio = $quantity / max(0.0001, (float) $item->quantity);

        return [
            'revenue' => round(max(0, (float) $order->subtotal - (float) $order->discount_total) * $share * $quantityRatio, 4),
            'tax' => round((float) $order->tax_total * $share * $quantityRatio, 4),
            'service' => round((float) $order->service_total * $share * $quantityRatio, 4),
            'delivery' => 0.0,
            'tip' => 0.0,
        ];
    }
}
