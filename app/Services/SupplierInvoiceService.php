<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\PurchaseOrderItem;
use App\Models\Shift;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Supplier Invoices & Payments — the AP (accounts-payable) side.
 *
 * - Create an invoice when we receive a bill from a supplier (may link to a PO)
 * - Record payments against it
 * - Recompute balance/status after every change
 */
class SupplierInvoiceService
{
    public function create(array $data, ?int $userId = null): SupplierInvoice
    {
        return DB::transaction(function () use ($data, $userId) {
            $lines = collect($data['lines'] ?? [])
                ->filter(fn ($line) => trim((string) ($line['description'] ?? '')) !== '');

            $linesSubtotal = $lines->sum(fn ($line) =>
                (float) ($line['quantity'] ?? 0) * (float) ($line['unit_price'] ?? 0)
            );
            $linesTax = $lines->sum(fn ($line) => (float) ($line['tax_total'] ?? 0));

            $subtotal = $lines->isNotEmpty() ? $linesSubtotal : (float) ($data['subtotal'] ?? 0);
            $taxTotal = $lines->isNotEmpty() ? $linesTax : (float) ($data['tax_total'] ?? 0);
            $total = (float) ($data['total'] ?? ($subtotal + $taxTotal));
            $invoiceDate = $data['invoice_date'] ?? now()->toDateString();
            $dueDate = $data['due_date'] ?? null;
            if (! $dueDate && ! empty($data['supplier_id'])) {
                $terms = \App\Models\Supplier::whereKey($data['supplier_id'])->value('payment_terms_days');
                if ($terms !== null) {
                    $dueDate = \Carbon\Carbon::parse($invoiceDate)->addDays((int) $terms)->toDateString();
                }
            }

            $invoice = SupplierInvoice::create([
                'number'            => $data['number'],
                'supplier_id'       => $data['supplier_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'subtotal'          => $subtotal,
                'tax_total'         => $taxTotal,
                'total'             => $total,
                'balance'           => $total,
                'status'            => 'unpaid',
                'invoice_date'      => $invoiceDate,
                'due_date'          => $dueDate,
                'notes'             => $data['notes'] ?? null,
                'attachment_path'   => $data['attachment_path'] ?? null,
                'created_by'        => $userId,
            ]);

            if ($lines->isNotEmpty()) {
                $poItemIds = $lines->pluck('purchase_order_item_id')->filter()->map(fn ($id) => (int) $id)->all();
                $poItems = $poItemIds
                    ? PurchaseOrderItem::with('ingredient', 'unit')->whereIn('id', $poItemIds)->get()->keyBy('id')
                    : collect();

                // How much of each PO line has ALREADY been billed on earlier
                // (non-cancelled) invoices. The variance must compare this
                // invoice against the *still-uninvoiced* received quantity —
                // not the cumulative PO receipt total, which would make every
                // invoice after the first show a bogus negative variance when
                // a PO is received and billed in instalments.
                $alreadyInvoiced = $poItemIds
                    ? SupplierInvoiceItem::whereIn('purchase_order_item_id', $poItemIds)
                        ->whereHas('supplierInvoice', fn ($q) => $q->where('status', '!=', 'cancelled'))
                        ->groupBy('purchase_order_item_id')
                        ->selectRaw('purchase_order_item_id, SUM(quantity) as qty')
                        ->pluck('qty', 'purchase_order_item_id')
                    : collect();

                foreach ($lines as $line) {
                    $poItem = ! empty($line['purchase_order_item_id'])
                        ? $poItems->get((int) $line['purchase_order_item_id'])
                        : null;

                    $qty = (float) ($line['quantity'] ?? 0);
                    $unitPrice = (float) ($line['unit_price'] ?? 0);
                    $lineSubtotal = round($qty * $unitPrice, 4);
                    $lineTax = (float) ($line['tax_total'] ?? 0);
                    $lineTotal = $lineSubtotal + $lineTax;
                    $invoicedSoFar = $poItem ? (float) ($alreadyInvoiced[$poItem->id] ?? 0) : 0.0;
                    $receivedQty = $poItem
                        ? max(0, (float) $poItem->quantity_received - $invoicedSoFar)
                        : null;
                    $receivedTotal = $receivedQty !== null ? $receivedQty * (float) $poItem->unit_price : null;

                    $invoice->items()->create([
                        'purchase_order_item_id' => $poItem?->id,
                        'ingredient_id' => $poItem?->ingredient_id ?: ($line['ingredient_id'] ?? null),
                        'unit_id' => $poItem?->unit_id ?: ($line['unit_id'] ?? null),
                        'description' => $line['description'],
                        'quantity' => $qty,
                        'unit_price' => $unitPrice,
                        'subtotal' => $lineSubtotal,
                        'tax_total' => $lineTax,
                        'total' => $lineTotal,
                        'received_qty' => $receivedQty,
                        'received_total' => $receivedTotal,
                        'variance_qty' => $receivedQty !== null ? $qty - $receivedQty : null,
                        'variance_total' => $receivedTotal !== null ? $lineTotal - $receivedTotal : null,
                        'notes' => $line['notes'] ?? null,
                    ]);
                }
            }

            ActivityLog::log(
                'supplier_invoice.created',
                "فاتورة مورد #{$invoice->number} — قيمة: ".number_format($invoice->total, 2),
                $invoice
            );

            return $invoice->fresh('supplier', 'items');
        });
    }

    public function recordPayment(SupplierInvoice $invoice, array $data, int $userId): SupplierPayment
    {
        if ($invoice->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => 'لا يمكن تسديد فاتورة ملغاة.']);
        }
        $amount = (float) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'قيمة الدفعة يجب أن تكون أكبر من صفر.']);
        }
        $balance = (float) $invoice->balance;
        if ($amount > $balance + 0.0001) {
            throw ValidationException::withMessages([
                'amount' => "قيمة الدفعة ({$amount}) أكبر من المتبقي (".number_format($balance, 2).").",
            ]);
        }

        return DB::transaction(function () use ($invoice, $data, $amount, $userId) {
            $shiftId = $data['shift_id'] ?? Shift::where('user_id', $userId)
                ->whereNull('closed_at')
                ->latest('opened_at')
                ->value('id');

            $payment = $invoice->payments()->create([
                'amount'    => $amount,
                'method'    => $data['method']    ?? 'cash',
                'reference' => $data['reference'] ?? null,
                'paid_on'   => $data['paid_on']   ?? now()->toDateString(),
                'notes'     => $data['notes']     ?? null,
                'paid_by'   => $userId,
                'shift_id'  => $shiftId,
            ]);

            $invoice->recomputeBalance();

            ActivityLog::log(
                'supplier_payment.recorded',
                "دفعة {$payment->methodLabel()} بقيمة ".number_format($payment->amount, 2)." لفاتورة {$invoice->number}",
                $payment
            );

            return $payment;
        });
    }

    public function cancel(SupplierInvoice $invoice, string $reason, int $userId): SupplierInvoice
    {
        if ($invoice->payments()->exists()) {
            throw ValidationException::withMessages([
                'status' => 'لا يمكن إلغاء فاتورة عليها دفعات. احذف الدفعات أولاً.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $reason, $userId) {
            $invoice->update([
                'status'       => 'cancelled',
                'cancelled_at' => now(),
                'notes'        => trim(($invoice->notes ?? '').' | إلغاء: '.$reason),
            ]);
            ActivityLog::log('supplier_invoice.cancelled', "إلغاء فاتورة مورد {$invoice->number}: {$reason}", $invoice);
            return $invoice->fresh();
        });
    }
}
