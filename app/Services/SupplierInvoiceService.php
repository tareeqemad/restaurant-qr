<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\IngredientUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReceiptItem;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\SupplierPayment;
use App\Services\Accounting\AccountingService;
use App\Support\BranchContext;
use Carbon\Carbon;
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
            $invoiceDate = $data['invoice_date'] ?? now()->toDateString();
            $purchaseOrder = ! empty($data['purchase_order_id'])
                ? PurchaseOrder::withoutGlobalScopes()->findOrFail($data['purchase_order_id'])
                : null;

            $branchId = $purchaseOrder?->branch_id
                ?: ($data['branch_id'] ?? BranchContext::current());
            $user = $userId ? \App\Models\User::find($userId) : null;
            if (! $branchId && $user) {
                $branchId = $user->primaryBranch()?->id
                    ?? $user->branches()->value('branches.id');
                if (! $branchId && $user->isOwnerLevel()) {
                    $branchId = \App\Models\Branch::active()->orderBy('display_order')->value('id');
                }
            }
            $branchId = (int) $branchId;
            if (! $branchId || ! \App\Models\Branch::active()->whereKey($branchId)->exists()) {
                throw ValidationException::withMessages([
                    'branch_id' => 'اختر فرعاً نشطاً لتسجيل فاتورة المورد عليه.',
                ]);
            }
            if ($user && ! $user->belongsToBranch($branchId)) {
                throw ValidationException::withMessages([
                    'branch_id' => 'لا تملك صلاحية تسجيل فاتورة مورد على هذا الفرع.',
                ]);
            }

            $supplier = Supplier::whereKey($data['supplier_id'])->where('active', true)->firstOrFail();
            if (! $supplier->branches()->where('branches.id', $branchId)->exists()) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'المورد المختار غير مرتبط بهذا الفرع.',
                ]);
            }
            if ($purchaseOrder) {
                if ((int) $purchaseOrder->branch_id !== $branchId
                    || (int) $purchaseOrder->supplier_id !== (int) $supplier->id) {
                    throw ValidationException::withMessages([
                        'purchase_order_id' => 'أمر الشراء لا يطابق المورد والفرع المحددين.',
                    ]);
                }
                if (! in_array($purchaseOrder->status, ['received', 'partially_received'], true)) {
                    throw ValidationException::withMessages([
                        'purchase_order_id' => 'لا يمكن فوترة أمر شراء قبل تسجيل استلام فعلي عليه.',
                    ]);
                }
            }
            $exchangeRates = app(ExchangeRateService::class);
            $baseCurrency = $exchangeRates->baseCurrencyCode();
            $currencyCode = $exchangeRates->normalizeCode(
                $data['currency_code'] ?? $purchaseOrder?->currency_code ?? $baseCurrency
            );

            if ($purchaseOrder
                && $purchaseOrder->currency_code
                && $exchangeRates->normalizeCode($purchaseOrder->currency_code) !== $currencyCode) {
                throw ValidationException::withMessages([
                    'currency_code' => 'عملة فاتورة المورد يجب أن تطابق عملة أمر الشراء المرتبط.',
                ]);
            }

            $exchangeRate = $currencyCode === $baseCurrency
                ? 1.0
                : (float) ($data['exchange_rate'] ?? $exchangeRates->rateFor($currencyCode, $baseCurrency, $invoiceDate));

            if ($exchangeRate <= 0) {
                throw ValidationException::withMessages(['exchange_rate' => 'سعر الصرف يجب أن يكون أكبر من صفر.']);
            }

            $lines = collect($data['lines'] ?? [])
                ->filter(fn ($line) => trim((string) ($line['description'] ?? '')) !== '');

            foreach ($lines as $line) {
                if ((float) ($line['quantity'] ?? 0) <= 0
                    || (float) ($line['unit_price'] ?? 0) < 0
                    || (float) ($line['tax_total'] ?? 0) < 0) {
                    throw ValidationException::withMessages([
                        'lines' => 'كل بند فاتورة يحتاج كمية موجبة وسعراً وضريبة غير سالبين.',
                    ]);
                }
                if (! empty($line['ingredient_id']) && empty($line['purchase_order_item_id'])) {
                    throw ValidationException::withMessages([
                        'lines' => 'البند المخزني يجب أن يرتبط ببند مستلم من أمر شراء؛ الفاتورة وحدها لا تضيف مخزوناً.',
                    ]);
                }
            }

            $linesSubtotal = $lines->sum(fn ($line) => round(
                (float) ($line['quantity'] ?? 0) * (float) ($line['unit_price'] ?? 0),
                4
            ));
            $linesTax = $lines->sum(fn ($line) => (float) ($line['tax_total'] ?? 0));

            $subtotal = round($lines->isNotEmpty() ? $linesSubtotal : (float) ($data['subtotal'] ?? 0), 4);
            $taxTotal = round($lines->isNotEmpty() ? $linesTax : (float) ($data['tax_total'] ?? 0), 4);
            $total = round($subtotal + $taxTotal, 4);
            if ($subtotal < 0 || $taxTotal < 0 || $total <= 0) {
                throw ValidationException::withMessages([
                    'total' => 'إجمالي فاتورة المورد يجب أن يكون أكبر من صفر ومطابقاً لمجموع البنود والضريبة.',
                ]);
            }
            $dueDate = $data['due_date'] ?? null;
            if (! $dueDate && ! empty($data['supplier_id'])) {
                $terms = $supplier->payment_terms_days;
                if ($terms !== null) {
                    $dueDate = Carbon::parse($invoiceDate)->addDays((int) $terms)->toDateString();
                }
            }

            $invoice = new SupplierInvoice([
                'number' => $data['number'],
                'supplier_id' => $data['supplier_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'currency_code' => $currencyCode,
                'exchange_rate' => $exchangeRate,
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'total' => $total,
                'balance' => $total,
                'status' => 'unpaid',
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'notes' => $data['notes'] ?? null,
                'attachment_path' => $data['attachment_path'] ?? null,
                'created_by' => $userId,
            ]);
            $invoice->branch_id = $branchId;
            $invoice->save();

            if ($lines->isNotEmpty()) {
                $poItemIds = $lines->pluck('purchase_order_item_id')->filter()->map(fn ($id) => (int) $id)->all();
                $poItems = $poItemIds
                    ? PurchaseOrderItem::with('ingredient', 'unit', 'ingredientUnit', 'purchaseOrder')
                        ->whereIn('id', $poItemIds)->get()->keyBy('id')
                    : collect();

                if ($purchaseOrder && count($poItemIds) !== $lines->count()) {
                    throw ValidationException::withMessages([
                        'lines' => 'كل بند في فاتورة أمر الشراء يجب أن يرتبط ببند من الأمر نفسه.',
                    ]);
                }
                if (count($poItemIds) !== $poItems->count()) {
                    throw ValidationException::withMessages([
                        'lines' => 'أحد بنود أمر الشراء غير موجود أو غير متاح.',
                    ]);
                }
                foreach ($poItems as $poItem) {
                    if (! $purchaseOrder || (int) $poItem->purchase_order_id !== (int) $purchaseOrder->id) {
                        throw ValidationException::withMessages([
                            'lines' => 'لا يمكن خلط بنود من أوامر شراء مختلفة في فاتورة واحدة.',
                        ]);
                    }
                }

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
                        // received_qty is stored in the PO line's natural unit,
                        // unlike quantity which may be cartons/cans on the bill.
                        ->selectRaw('purchase_order_item_id, SUM(COALESCE(received_qty, quantity)) as qty')
                        ->pluck('qty', 'purchase_order_item_id')
                    : collect();

                // GRNI (2300) was CREDITED at receipt using the ACTUAL delivered
                // price (the receiver can override the PO price). To clear it we
                // must DEBIT at that same actual value — not the notional PO price
                // — or 2300 keeps a permanent residue and 5420 shows a purchase
                // price variance that never happened. receipt_item.subtotal =
                // quantity_received × actual_unit_price, so total ÷ qty is the
                // actual weighted-average delivered price per PO unit.
                $receiptActuals = $poItemIds
                    ? PurchaseReceiptItem::whereIn('purchase_order_item_id', $poItemIds)
                        ->groupBy('purchase_order_item_id')
                        ->selectRaw('purchase_order_item_id, SUM(subtotal) as total, SUM(quantity_received) as qty, SUM(quantity_in_base * unit_price_in_base) as base_total')
                        ->get()
                        ->keyBy('purchase_order_item_id')
                    : collect();

                foreach ($lines as $line) {
                    $poItem = ! empty($line['purchase_order_item_id'])
                        ? $poItems->get((int) $line['purchase_order_item_id'])
                        : null;

                    if (! empty($line['purchase_order_item_id']) && ! $poItem) {
                        throw ValidationException::withMessages([
                            'lines' => 'بند الفاتورة لا ينتمي إلى أمر الشراء المحدد.',
                        ]);
                    }

                    $qty = (float) ($line['quantity'] ?? 0);
                    $unitPrice = (float) ($line['unit_price'] ?? 0);
                    $lineSubtotal = round($qty * $unitPrice, 4);
                    $lineTax = (float) ($line['tax_total'] ?? 0);
                    $lineTotal = $lineSubtotal + $lineTax;
                    $invoicedSoFar = $poItem ? (float) ($alreadyInvoiced[$poItem->id] ?? 0) : 0.0;
                    $uninvoicedReceived = $poItem
                        ? max(0, (float) $poItem->quantity_received - $invoicedSoFar)
                        : null;

                    // Unit factors — the PO line and the invoice line may use
                    // different pack sizes (24-can cartons vs single cans), so
                    // every quantity comparison below normalizes to base units.
                    $poFactor = $poItem && $poItem->ingredient_unit_id && $poItem->ingredientUnit
                        ? (float) $poItem->ingredientUnit->factor_to_base
                        : 1.0;
                    $invFactor = ! empty($line['ingredient_unit_id'])
                        ? (float) (IngredientUnit::find($line['ingredient_unit_id'])?->factor_to_base ?? 1.0)
                        : 1.0;

                    // You can only bill (and clear GRNI 2300 for) goods that were
                    // actually received and not yet invoiced. Billing before
                    // receipt or over-billing would dump the excess value into
                    // purchase-price-variance (5420) as a phantom and leave a
                    // permanent 2300 residue. Compared in BASE units so mixed
                    // pack sizes (cartons vs cans) don't false-trigger.
                    if ($poItem && (($qty * $invFactor) - ($uninvoicedReceived * $poFactor)) > 0.0001) {
                        throw ValidationException::withMessages([
                            'lines' => "الكمية المفوترة ({$qty}) للصنف «{$line['description']}» تتجاوز المستلَم غير المفوتَر. استلم البضاعة أولاً أو صحّح الكمية.",
                        ]);
                    }

                    // GRNI clears exactly THIS line's billed qty (in PO units),
                    // capped at the uninvoiced-received qty — NOT the whole
                    // receipt (which would let the first partial invoice
                    // over-clear the entire GRNI).
                    $billedInPoUnits = $poFactor > 0 ? ($qty * $invFactor) / $poFactor : (float) $qty;
                    $receivedQty = $uninvoicedReceived !== null
                        ? min($billedInPoUnits, $uninvoicedReceived)
                        : null;

                    // Clear GRNI at the actual weighted-average delivered price;
                    // fall back to the PO price only when nothing was received yet
                    // (which preserves the original behaviour for un-overridden POs).
                    $actualUnitValue = $poItem ? (float) $poItem->unit_price : 0.0;
                    $actualUnitBaseValue = $actualUnitValue * (float) ($poItem?->purchaseOrder?->exchange_rate ?: 1);
                    if ($poItem && ($ra = $receiptActuals->get($poItem->id)) && (float) $ra->qty > 0.0001) {
                        $actualUnitValue = (float) $ra->total / (float) $ra->qty;
                        $actualUnitBaseValue = (float) $ra->base_total / (float) $ra->qty;
                    }
                    $receivedTotal = $receivedQty !== null ? round($receivedQty * $actualUnitValue, 4) : null;
                    $receivedBaseTotal = $receivedQty !== null ? round($receivedQty * $actualUnitBaseValue, 4) : null;

                    // Quantity variance for display — in the natural unit when
                    // both sides match, else normalized to base units.
                    $varianceQty = null;
                    if ($poItem && $receivedQty !== null) {
                        $varianceQty = abs($poFactor - $invFactor) < 0.0001
                            ? $qty - $receivedQty
                            : ($qty * $invFactor) - ($receivedQty * $poFactor);
                    }

                    $invoice->items()->create([
                        'purchase_order_item_id' => $poItem?->id,
                        'ingredient_id' => $poItem?->ingredient_id ?: ($line['ingredient_id'] ?? null),
                        'unit_id' => $poItem?->unit_id ?: ($line['unit_id'] ?? null),
                        // Capture the invoice line's pack-size when
                        // supplied, so future audits can see exactly
                        // how the supplier itemised this row.
                        'ingredient_unit_id' => ! empty($line['ingredient_unit_id']) ? (int) $line['ingredient_unit_id'] : null,
                        'description' => $line['description'],
                        'quantity' => $qty,
                        'unit_price' => $unitPrice,
                        'subtotal' => $lineSubtotal,
                        'tax_total' => $lineTax,
                        'total' => $lineTotal,
                        'received_qty' => $receivedQty,
                        'received_total' => $receivedTotal,
                        'received_base_total' => $receivedBaseTotal,
                        'variance_qty' => $varianceQty,
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

            app(AccountingService::class)->recordSupplierInvoiceCreated($invoice);

            return $invoice->fresh('supplier', 'items');
        });
    }

    public function recordPayment(SupplierInvoice $invoice, array $data, int $userId): SupplierPayment
    {
        $amount = (float) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'قيمة الدفعة يجب أن تكون أكبر من صفر.']);
        }

        $method = $data['method'] ?? 'cash';
        if (! in_array($method, ['cash', 'bank_transfer'], true)) {
            throw ValidationException::withMessages([
                'method' => 'الطرق المتاحة لسداد المورد هي النقد أو التحويل البنكي المباشر فقط.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $data, $amount, $userId, $method) {
            // Re-read the live balance under a row lock. Two cashiers using
            // stale screens can no longer overpay the same supplier invoice.
            $invoice = SupplierInvoice::withoutGlobalScopes()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($invoice->status === 'cancelled') {
                throw ValidationException::withMessages(['status' => 'لا يمكن تسديد فاتورة ملغاة.']);
            }
            $balance = (float) $invoice->balance;
            if ($amount > $balance + 0.0001) {
                throw ValidationException::withMessages([
                    'amount' => "قيمة الدفعة ({$amount}) أكبر من المتبقي (".number_format($balance, 2).').',
                ]);
            }

            $paidOn = $data['paid_on'] ?? now()->toDateString();
            $exchangeRates = app(ExchangeRateService::class);
            $baseCurrency = $exchangeRates->baseCurrencyCode();
            $currencyCode = $exchangeRates->normalizeCode($invoice->currency_code ?: $baseCurrency);
            $exchangeRate = $currencyCode === $baseCurrency
                ? 1.0
                : (float) ($data['exchange_rate'] ?? $exchangeRates->rateFor($currencyCode, $baseCurrency, $paidOn));
            if ($exchangeRate <= 0) {
                throw ValidationException::withMessages([
                    'exchange_rate' => 'سعر صرف دفعة المورد يجب أن يكون أكبر من صفر.',
                ]);
            }

            $payment = $invoice->payments()->create([
                'amount' => $amount,
                'currency_code' => $currencyCode,
                'exchange_rate' => $exchangeRate,
                'method' => $method,
                'reference' => $data['reference'] ?? null,
                'paid_on' => $paidOn,
                'notes' => $data['notes'] ?? null,
                'paid_by' => $userId,
            ]);

            $invoice->recomputeBalance();

            ActivityLog::log(
                'supplier_payment.recorded',
                "دفعة {$payment->methodLabel()} بقيمة ".number_format($payment->amount, 2)." لفاتورة {$invoice->number}",
                $payment
            );

            app(AccountingService::class)->recordSupplierPayment($payment);

            return $payment;
        });
    }

    public function cancel(SupplierInvoice $invoice, string $reason, int $userId): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice, $reason, $userId) {
            $invoice = SupplierInvoice::withoutGlobalScopes()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($invoice->status === 'cancelled') {
                throw ValidationException::withMessages(['status' => 'الفاتورة ملغاة مسبقاً.']);
            }
            if ($invoice->payments()->lockForUpdate()->first()) {
                throw ValidationException::withMessages([
                    'status' => 'لا يمكن إلغاء فاتورة عليها دفعات. اعكس الدفعات أولاً من مسار محاسبي معتمد.',
                ]);
            }

            $invoice->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'notes' => trim(($invoice->notes ?? '').' | إلغاء: '.$reason),
            ]);
            ActivityLog::log('supplier_invoice.cancelled', "إلغاء فاتورة مورد {$invoice->number}: {$reason}", $invoice);
            if ($invoice->is_opening_balance) {
                app(AccountingService::class)->reverse(
                    eventType: 'supplier_opening_debt_cancelled',
                    source: $invoice,
                    originalEventType: 'supplier_opening_debt',
                    postedOn: now(),
                    description: "عكس رصيد افتتاحي لمورد {$invoice->number}",
                    createdBy: $userId,
                    metadata: ['reason' => $reason],
                );
            } else {
                app(AccountingService::class)->reverseSupplierInvoiceCreated($invoice, $userId, $reason);
            }

            return $invoice->fresh();
        });
    }
}
