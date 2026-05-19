<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\BranchTransferItem;
use App\Models\Expense;
use App\Models\IngredientBatch;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PurchaseOrderItem;
use App\Models\Refund;
use App\Models\Shift;
use App\Models\StockCountItem;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountingService
{
    public const CASH = '1000';
    public const BANK = '1010';
    public const CARD_CLEARING = '1020';
    public const WALLET_CLEARING = '1030';
    public const CUSTOMER_CREDIT_CLEARING = '1040';
    public const ACCOUNTS_RECEIVABLE = '1100';
    public const INVENTORY_IN_TRANSIT = '1150';
    public const INVENTORY = '1200';
    public const INPUT_VAT = '1300';
    public const ACCOUNTS_PAYABLE = '2000';
    public const OUTPUT_VAT = '2100';
    public const TIPS_PAYABLE = '2200';
    public const GOODS_RECEIVED_NOT_INVOICED = '2300';
    public const OPENING_BALANCE_EQUITY = '3010';
    public const SALES_REVENUE = '4000';
    public const SERVICE_REVENUE = '4010';
    public const DELIVERY_REVENUE = '4020';
    public const SALES_DISCOUNTS = '4090';
    public const SALES_RETURNS = '4100';
    public const INVENTORY_ADJUSTMENT_GAIN = '4200';
    public const CASH_OVER_SHORT_INCOME = '4210';
    public const COST_OF_GOODS_SOLD = '5000';
    public const OPERATING_EXPENSES = '5100';
    public const BAD_DEBT_EXPENSE = '5200';
    public const BANK_AND_CARD_FEES = '5300';
    public const WASTE_EXPENSE = '5400';
    public const INVENTORY_SHRINKAGE_EXPENSE = '5410';
    public const PURCHASE_PRICE_VARIANCE = '5420';
    public const CASH_SHORTAGE_EXPENSE = '5510';

    private ?Collection $accounts = null;

    public function recordInvoiceIssued(Invoice $invoice): ?JournalEntry
    {
        $invoice->refresh();

        $lines = [
            $this->debit(self::ACCOUNTS_RECEIVABLE, (float) $invoice->total, 'إثبات إجمالي الفاتورة على الذمم المدينة'),
            $this->debit(self::SALES_DISCOUNTS, (float) $invoice->discount_total, 'خصومات ومسموحات مبيعات'),
            $this->credit(self::SALES_REVENUE, (float) $invoice->subtotal, 'إيراد المبيعات قبل الخصم'),
            $this->credit(self::OUTPUT_VAT, (float) $invoice->tax_total, 'ضريبة قيمة مضافة - مخرجات'),
            $this->credit(self::SERVICE_REVENUE, (float) $invoice->service_total, 'إيرادات رسوم الخدمة'),
            $this->credit(self::DELIVERY_REVENUE, (float) $invoice->delivery_fee, 'إيرادات التوصيل'),
            $this->credit(self::TIPS_PAYABLE, (float) $invoice->tip, 'إكراميات مستحقة للموظفين'),
        ];

        return $this->post(
            eventType: 'invoice_issued',
            source: $invoice,
            branchId: $invoice->branch_id,
            postedOn: $invoice->issued_at ?: $invoice->created_at ?: now(),
            description: "إثبات فاتورة {$invoice->number}",
            lines: $lines,
            createdBy: $invoice->issued_by_user_id,
        );
    }

    public function reverseInvoiceIssued(Invoice $invoice, ?int $userId = null, ?string $reason = null): ?JournalEntry
    {
        return $this->reverse(
            eventType: 'invoice_cancelled',
            source: $invoice,
            originalEventType: 'invoice_issued',
            postedOn: now(),
            description: "عكس فاتورة {$invoice->number}",
            createdBy: $userId,
            metadata: ['reason' => $reason],
        );
    }

    public function recordPaymentReceived(Payment $payment): ?JournalEntry
    {
        $payment->loadMissing('invoice');

        return $this->post(
            eventType: 'payment_received',
            source: $payment,
            branchId: $payment->branch_id ?: $payment->invoice?->branch_id,
            postedOn: $payment->paid_at ?: $payment->created_at ?: now(),
            description: "تحصيل دفعة على فاتورة {$payment->invoice?->number}",
            lines: [
                $this->debit($this->cashAccountForMethod($payment->method), (float) $payment->amount, 'تحصيل عبر '.$this->paymentMethodLabel($payment->method)),
                $this->credit(self::ACCOUNTS_RECEIVABLE, (float) $payment->amount, 'تسوية ذمم مدينة للعملاء'),
            ],
            createdBy: $payment->received_by_user_id,
        );
    }

    public function recordInvoiceWriteoff(Invoice $invoice, float $amount, ?int $userId = null, ?string $reason = null): ?JournalEntry
    {
        return $this->post(
            eventType: 'invoice_writeoff',
            source: $invoice,
            branchId: $invoice->branch_id,
            postedOn: now(),
            description: "شطب رصيد فاتورة {$invoice->number}",
            lines: [
                $this->debit(self::BAD_DEBT_EXPENSE, $amount, 'مصروف ديون معدومة'),
                $this->credit(self::ACCOUNTS_RECEIVABLE, $amount, 'شطب ذمم مدينة غير محصلة'),
            ],
            metadata: ['reason' => $reason],
            createdBy: $userId,
        );
    }

    public function recordRefundCompleted(Refund $refund): ?JournalEntry
    {
        $refund->loadMissing('invoice');

        return $this->post(
            eventType: 'refund_completed',
            source: $refund,
            branchId: $refund->branch_id ?: $refund->invoice?->branch_id,
            postedOn: $refund->refunded_at ?: $refund->updated_at ?: now(),
            description: "استرداد {$refund->number} على فاتورة {$refund->invoice?->number}",
            lines: [
                $this->debit(self::SALES_RETURNS, (float) $refund->amount, 'مردودات ومسموحات مبيعات'),
                $this->credit($this->cashAccountForMethod($refund->method), (float) $refund->amount, 'صرف استرداد عبر '.$this->paymentMethodLabel($refund->method)),
            ],
            createdBy: $refund->processed_by,
        );
    }

    public function recordExpenseApproved(Expense $expense): ?JournalEntry
    {
        return $this->post(
            eventType: 'expense_approved',
            source: $expense,
            branchId: $expense->branch_id,
            postedOn: $expense->expense_date ?: now(),
            description: "اعتماد مصروف {$expense->expense_number}",
            lines: [
                $this->debit(self::OPERATING_EXPENSES, (float) $expense->amount, $expense->description),
                $this->credit($this->cashAccountForMethod($expense->payment_method), (float) $expense->amount, 'سداد عبر '.$this->paymentMethodLabel($expense->payment_method)),
            ],
            createdBy: $expense->approved_by_user_id,
        );
    }

    public function recordSupplierInvoiceCreated(SupplierInvoice $invoice): ?JournalEntry
    {
        $invoice->loadMissing('items');

        $poItems = $invoice->items->whereNotNull('purchase_order_item_id');
        $poReceivedSubtotal = (float) $poItems->sum(fn ($item) => (float) ($item->received_total ?? $item->subtotal));
        $poInvoiceSubtotal = (float) $poItems->sum('subtotal');
        $priceVariance = $this->round($poInvoiceSubtotal - $poReceivedSubtotal);

        $inventorySubtotal = (float) $invoice->items
            ->whereNull('purchase_order_item_id')
            ->whereNotNull('ingredient_id')
            ->sum('subtotal');
        $expenseSubtotal = $invoice->items->isNotEmpty()
            ? (float) $invoice->items->whereNull('ingredient_id')->sum('subtotal')
            : (float) $invoice->subtotal;

        $lines = [
            $this->debit(self::GOODS_RECEIVED_NOT_INVOICED, $poReceivedSubtotal, 'تسوية استلامات مخزون غير مفوترة'),
            $priceVariance > 0
                ? $this->debit(self::PURCHASE_PRICE_VARIANCE, abs($priceVariance), 'فروقات أسعار مشتريات مدينة')
                : $this->credit(self::PURCHASE_PRICE_VARIANCE, abs($priceVariance), 'فروقات أسعار مشتريات دائنة'),
            $this->debit(self::INVENTORY, $inventorySubtotal, 'إضافة مشتريات مخزنية من فاتورة مورد'),
            $this->debit(self::OPERATING_EXPENSES, $expenseSubtotal, 'مصروفات غير مخزنية من فاتورة مورد'),
            $this->debit(self::INPUT_VAT, (float) $invoice->tax_total, 'ضريبة قيمة مضافة - مدخلات'),
            $this->credit(self::ACCOUNTS_PAYABLE, (float) $invoice->total, 'إثبات ذمم دائنة للمورد'),
        ];

        return $this->post(
            eventType: 'supplier_invoice_created',
            source: $invoice,
            branchId: $invoice->branch_id,
            postedOn: $invoice->invoice_date ?: $invoice->created_at ?: now(),
            description: "إثبات فاتورة مورد {$invoice->number}",
            lines: $lines,
            createdBy: $invoice->created_by,
        );
    }

    public function reverseSupplierInvoiceCreated(SupplierInvoice $invoice, ?int $userId = null, ?string $reason = null): ?JournalEntry
    {
        return $this->reverse(
            eventType: 'supplier_invoice_cancelled',
            source: $invoice,
            originalEventType: 'supplier_invoice_created',
            postedOn: now(),
            description: "عكس فاتورة مورد {$invoice->number}",
            createdBy: $userId,
            metadata: ['reason' => $reason],
        );
    }

    public function recordSupplierPayment(SupplierPayment $payment): ?JournalEntry
    {
        $payment->loadMissing('invoice');

        return $this->post(
            eventType: 'supplier_payment_recorded',
            source: $payment,
            branchId: $payment->invoice?->branch_id,
            postedOn: $payment->paid_on ?: $payment->created_at ?: now(),
            description: "سداد فاتورة مورد {$payment->invoice?->number}",
            lines: [
                $this->debit(self::ACCOUNTS_PAYABLE, (float) $payment->amount, 'تسوية ذمم دائنة للمورد'),
                $this->credit($this->cashAccountForMethod($payment->method), (float) $payment->amount, 'سداد عبر '.$this->paymentMethodLabel($payment->method)),
            ],
            createdBy: $payment->paid_by,
        );
    }

    public function recordInventoryMovement(InventoryMovement $movement): ?JournalEntry
    {
        $amount = abs((float) $movement->total_cost);
        if ($amount <= 0.0001) {
            return null;
        }

        $movement->loadMissing('ingredient', 'reference');

        $description = trim(sprintf(
            'حركة مخزون %s - %s',
            $movement->ingredient?->name ?? '#'.$movement->ingredient_id,
            $movement->reason ?: $movement->type
        ));

        return match ($movement->type) {
            'out' => $this->postInventoryOut($movement, $amount, $description),
            'return' => $this->postInventoryReturn($movement, $amount, $description),
            'waste' => $this->postInventoryWaste($movement, $amount, $description),
            'adjustment' => $this->postInventoryAdjustment($movement, $amount, $description),
            'in' => $this->postInventoryIn($movement, $amount, $description),
            default => null,
        };
    }

    public function recordShiftClosed(Shift $shift): ?JournalEntry
    {
        $variance = $this->round((float) $shift->cash_variance);
        if (abs($variance) <= 0.009) {
            return null;
        }

        $amount = abs($variance);
        $lines = $variance > 0
            ? [
                $this->debit(self::CASH, $amount, 'زيادة فعلية في صندوق الشفت'),
                $this->credit(self::CASH_OVER_SHORT_INCOME, $amount, 'فائض صندوق عند إغلاق الشفت'),
            ]
            : [
                $this->debit(self::CASH_SHORTAGE_EXPENSE, $amount, 'عجز صندوق عند إغلاق الشفت'),
                $this->credit(self::CASH, $amount, 'نقص فعلي في صندوق الشفت'),
            ];

        return $this->post(
            eventType: 'shift_cash_variance',
            source: $shift,
            branchId: $shift->branch_id,
            postedOn: $shift->closed_at ?: now(),
            description: "تسوية فرق صندوق الشفت #{$shift->id}",
            lines: $lines,
            createdBy: auth()->id(),
        );
    }

    public function post(
        string $eventType,
        Model $source,
        ?int $branchId,
        $postedOn,
        string $description,
        array $lines,
        array $metadata = [],
        ?int $createdBy = null,
    ): ?JournalEntry {
        $lines = $this->normalizeLines($lines);
        if ($lines === []) {
            return null;
        }

        return DB::transaction(function () use ($eventType, $source, $branchId, $postedOn, $description, $lines, $metadata, $createdBy) {
            $existing = JournalEntry::where('source_type', $source::class)
                ->where('source_id', $source->getKey())
                ->where('event_type', $eventType)
                ->first();

            if ($existing) {
                return $existing;
            }

            $this->assertBalanced($lines, $description);

            $entry = JournalEntry::create([
                'branch_id' => $branchId,
                'posted_on' => \Carbon\Carbon::parse($postedOn)->toDateString(),
                'description' => $description,
                'source_type' => $source::class,
                'source_id' => $source->getKey(),
                'event_type' => $eventType,
                'status' => 'posted',
                'metadata' => $metadata ?: null,
                'created_by' => $createdBy,
            ]);

            foreach ($lines as $index => $line) {
                $account = $this->account($line['account']);
                $entry->lines()->create([
                    'account_id' => $account->id,
                    'branch_id' => $branchId,
                    'line_no' => $index + 1,
                    'description' => $line['description'] ?? null,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ]);
            }

            return $entry->fresh('lines.account');
        });
    }

    public function reverse(
        string $eventType,
        Model $source,
        string $originalEventType,
        $postedOn,
        string $description,
        array $metadata = [],
        ?int $createdBy = null,
    ): ?JournalEntry {
        $original = JournalEntry::with('lines.account')
            ->where('source_type', $source::class)
            ->where('source_id', $source->getKey())
            ->where('event_type', $originalEventType)
            ->first();

        if (! $original) {
            return null;
        }

        $lines = $original->lines->map(fn ($line) => [
            'account' => $line->account->code,
            'debit' => (float) $line->credit,
            'credit' => (float) $line->debit,
            'description' => 'عكس: '.($line->description ?: $original->description),
        ])->all();

        return $this->post(
            eventType: $eventType,
            source: $source,
            branchId: $original->branch_id,
            postedOn: $postedOn,
            description: $description,
            lines: $lines,
            metadata: ['reverses_entry_id' => $original->id, ...$metadata],
            createdBy: $createdBy,
        );
    }

    private function postInventoryOut(InventoryMovement $movement, float $amount, string $description): ?JournalEntry
    {
        if ($movement->reference_type === BranchTransferItem::class) {
            return $this->postInventoryMovement(
                'inventory_transfer_sent',
                $movement,
                $description,
                [
                    $this->debit(self::INVENTORY_IN_TRANSIT, $amount, 'مخزون محول بين الفروع - قيد الطريق'),
                    $this->credit(self::INVENTORY, $amount, 'خروج مخزون من فرع المصدر'),
                ],
            );
        }

        if ($movement->reference_type === OrderItem::class) {
            return $this->postInventoryMovement(
                'inventory_cogs_recognized',
                $movement,
                $description,
                [
                    $this->debit(self::COST_OF_GOODS_SOLD, $amount, 'إثبات تكلفة البضاعة المباعة'),
                    $this->credit(self::INVENTORY, $amount, 'خروج مكونات مرتبطة ببيع'),
                ],
            );
        }

        return $this->postInventoryMovement(
            'inventory_manual_out',
            $movement,
            $description,
            [
                $this->debit(self::INVENTORY_SHRINKAGE_EXPENSE, $amount, 'صرف أو نقص مخزون يدوي'),
                $this->credit(self::INVENTORY, $amount, 'خروج مخزون'),
            ],
        );
    }

    private function postInventoryReturn(InventoryMovement $movement, float $amount, string $description): ?JournalEntry
    {
        if ($movement->reference_type === BranchTransferItem::class) {
            return $this->postInventoryMovement(
                'inventory_transfer_cancelled',
                $movement,
                $description,
                [
                    $this->debit(self::INVENTORY, $amount, 'إرجاع مخزون التحويل إلى فرع المصدر'),
                    $this->credit(self::INVENTORY_IN_TRANSIT, $amount, 'إلغاء مخزون محول بين الفروع'),
                ],
            );
        }

        if ($movement->reference_type === OrderItem::class) {
            return $this->postInventoryMovement(
                'inventory_cogs_reversed',
                $movement,
                $description,
                [
                    $this->debit(self::INVENTORY, $amount, 'إرجاع مكونات طلب ملغى إلى المخزون'),
                    $this->credit(self::COST_OF_GOODS_SOLD, $amount, 'عكس تكلفة بضاعة مباعة'),
                ],
            );
        }

        return $this->postInventoryMovement(
            'inventory_return_recorded',
            $movement,
            $description,
            [
                $this->debit(self::INVENTORY, $amount, 'إرجاع مخزون'),
                $this->credit(self::INVENTORY_ADJUSTMENT_GAIN, $amount, 'تسوية إرجاع مخزون'),
            ],
        );
    }

    private function postInventoryWaste(InventoryMovement $movement, float $amount, string $description): ?JournalEntry
    {
        return $this->postInventoryMovement(
            'inventory_waste_recognized',
            $movement,
            $description,
            [
                $this->debit(self::WASTE_EXPENSE, $amount, 'هدر أو تالف مخزون'),
                $this->credit(self::INVENTORY, $amount, 'تخفيض المخزون بسبب الهدر'),
            ],
        );
    }

    private function postInventoryAdjustment(InventoryMovement $movement, float $amount, string $description): ?JournalEntry
    {
        $signedValue = (float) $movement->total_cost;

        $lines = $signedValue >= 0
            ? [
                $this->debit(self::INVENTORY, $amount, 'زيادة جردية في المخزون'),
                $this->credit(self::INVENTORY_ADJUSTMENT_GAIN, $amount, 'فروقات جرد دائنة'),
            ]
            : [
                $this->debit(self::INVENTORY_SHRINKAGE_EXPENSE, $amount, 'عجز أو نقص جردي في المخزون'),
                $this->credit(self::INVENTORY, $amount, 'تخفيض المخزون بعجز جردي'),
            ];

        return $this->postInventoryMovement('inventory_adjustment_posted', $movement, $description, $lines);
    }

    private function postInventoryIn(InventoryMovement $movement, float $amount, string $description): ?JournalEntry
    {
        if ($movement->reference_type === BranchTransferItem::class) {
            return $this->postInventoryMovement(
                'inventory_transfer_received',
                $movement,
                $description,
                [
                    $this->debit(self::INVENTORY, $amount, 'استلام مخزون من فرع آخر'),
                    $this->credit(self::INVENTORY_IN_TRANSIT, $amount, 'إغلاق مخزون محول بين الفروع'),
                ],
            );
        }

        if ($movement->reference_type === PurchaseOrderItem::class) {
            return $this->postInventoryMovement(
                'inventory_goods_received',
                $movement,
                $description,
                [
                    $this->debit(self::INVENTORY, $amount, 'استلام مشتريات مخزنية'),
                    $this->credit(self::GOODS_RECEIVED_NOT_INVOICED, $amount, 'استلامات مخزون غير مفوترة'),
                ],
            );
        }

        $creditAccount = str_contains((string) $movement->reason, 'افتتاح')
            ? self::OPENING_BALANCE_EQUITY
            : self::INVENTORY_ADJUSTMENT_GAIN;

        return $this->postInventoryMovement(
            $movement->reference_type === IngredientBatch::class ? 'inventory_manual_batch_added' : 'inventory_manual_in',
            $movement,
            $description,
            [
                $this->debit(self::INVENTORY, $amount, 'إدخال مخزون'),
                $this->credit($creditAccount, $amount, $creditAccount === self::OPENING_BALANCE_EQUITY ? 'رصيد افتتاحي للمخزون' : 'زيادة مخزون غير مفوترة'),
            ],
        );
    }

    private function postInventoryMovement(string $eventType, InventoryMovement $movement, string $description, array $lines): ?JournalEntry
    {
        return $this->post(
            eventType: $eventType,
            source: $movement,
            branchId: $movement->branch_id,
            postedOn: $movement->occurred_at ?: $movement->created_at ?: now(),
            description: $description,
            lines: $lines,
            createdBy: $movement->user_id,
        );
    }

    private function debit(string $account, float $amount, ?string $description = null): array
    {
        return ['account' => $account, 'debit' => $this->round($amount), 'credit' => 0.0, 'description' => $description];
    }

    private function credit(string $account, float $amount, ?string $description = null): array
    {
        return ['account' => $account, 'debit' => 0.0, 'credit' => $this->round($amount), 'description' => $description];
    }

    private function normalizeLines(array $lines): array
    {
        return collect($lines)
            ->map(function (array $line) {
                $line['debit'] = $this->round((float) ($line['debit'] ?? 0));
                $line['credit'] = $this->round((float) ($line['credit'] ?? 0));

                return $line;
            })
            ->filter(fn (array $line) => $line['debit'] > 0.0001 || $line['credit'] > 0.0001)
            ->values()
            ->all();
    }

    private function assertBalanced(array $lines, string $description): void
    {
        $debit = $this->round(array_sum(array_column($lines, 'debit')));
        $credit = $this->round(array_sum(array_column($lines, 'credit')));

        if (abs($debit - $credit) > 0.0001) {
            throw new \RuntimeException("Unbalanced accounting entry [{$description}]: debit {$debit}, credit {$credit}.");
        }

        foreach ($lines as $line) {
            if ($line['debit'] > 0.0001 && $line['credit'] > 0.0001) {
                throw new \RuntimeException("Journal line cannot have both debit and credit [{$description}].");
            }
        }
    }

    private function account(string $code): Account
    {
        // Load EVERY account, not just active ones. `is_active` controls
        // visibility in the chart editor and trial balance — it must NOT
        // gate the accounting service itself. Features whose accounts have
        // been administratively deactivated (e.g. shift variance after the
        // operator opts out) still post correctly, and the trial balance
        // separately surfaces any inactive account that ends up with a
        // non-zero balance so the books stay mathematically complete.
        $this->accounts ??= Account::all()->keyBy('code');

        $account = $this->accounts->get($code);
        if (! $account) {
            throw new \RuntimeException("Accounting account {$code} is missing.");
        }

        return $account;
    }

    private function cashAccountForMethod(?string $method): string
    {
        // No clearing accounts in this restaurant's flow — card/transfer
        // both settle to the bank immediately and there are no platform
        // fees to defer. Legacy 'app' / 'credit' / 'credit_note' values
        // still resolve to the historical clearing accounts so old
        // journal lines reconcile against their original codes; the
        // active UI never produces them anymore (see CashierController
        // validation: cash|card|transfer only).
        return match ($method) {
            'cash'                                 => self::CASH,
            'card', 'transfer', 'bank_transfer',
            'cheque'                               => self::BANK,
            'app'                                  => self::WALLET_CLEARING,           // legacy
            'credit', 'credit_note'                => self::CUSTOMER_CREDIT_CLEARING,  // legacy
            default                                => self::BANK,
        };
    }

    private function paymentMethodLabel(?string $method): string
    {
        // Labels appear in journal-line descriptions. Card now reads
        // "البنك (فيزا)" so the accountant sees at a glance that the
        // line landed in 1010 even though the diner paid by card.
        return match ($method) {
            'cash' => 'الصندوق',
            'card' => 'البنك (فيزا)',
            'transfer', 'bank_transfer' => 'تحويل بنكي',
            'cheque' => 'شيك',
            'app' => 'محفظة إلكترونية',           // legacy
            'credit' => 'بيع آجل',                 // legacy
            'credit_note' => 'إشعار دائن',         // legacy
            default => $method ?: 'غير محدد',
        };
    }

    private function round(float $amount): float
    {
        return round($amount, 4);
    }
}
