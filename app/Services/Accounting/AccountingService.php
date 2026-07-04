<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\AccountingPeriod;
use App\Models\BranchTransferItem;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\FiscalYear;
use App\Models\IngredientBatch;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PurchaseOrderItem;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\StockCountItem;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Services\ExchangeRateService;
use App\Support\MarketProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
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
    public const FIXED_ASSETS = '1500';
    public const ACCUMULATED_DEPRECIATION = '1590';
    public const ACCOUNTS_PAYABLE = '2000';
    public const OUTPUT_VAT = '2100';
    public const TIPS_PAYABLE = '2200';
    public const GOODS_RECEIVED_NOT_INVOICED = '2300';
    public const OPENING_BALANCE_EQUITY = '3010';
    public const RETAINED_EARNINGS = '3020';
    public const SALES_REVENUE = '4000';
    public const SERVICE_REVENUE = '4010';
    public const DELIVERY_REVENUE = '4020';
    public const SALES_DISCOUNTS = '4090';
    public const SALES_RETURNS = '4100';
    public const INVENTORY_ADJUSTMENT_GAIN = '4200';
    public const CASH_OVER_SHORT_INCOME = '4210';
    public const FOREIGN_EXCHANGE_GAIN = '4220';
    public const FIXED_ASSET_DISPOSAL_GAIN = '4230';
    public const COST_OF_GOODS_SOLD = '5000';
    public const OPERATING_EXPENSES = '5100';
    public const BAD_DEBT_EXPENSE = '5200';
    public const BANK_AND_CARD_FEES = '5300';
    public const WASTE_EXPENSE = '5400';
    public const INVENTORY_SHRINKAGE_EXPENSE = '5410';
    public const PURCHASE_PRICE_VARIANCE = '5420';
    public const DEPRECIATION_EXPENSE = '5500';
    public const CASH_SHORTAGE_EXPENSE = '5510';
    public const FOREIGN_EXCHANGE_LOSS = '5520';
    public const FIXED_ASSET_DISPOSAL_LOSS = '5530';

    private ?Collection $accounts = null;
    private ?Collection $accountMappings = null;

    public static function postingRoleDefinitions(): array
    {
        return [
            'cash_account' => [
                'label' => 'الصندوق الافتراضي',
                'description' => 'الصندوق المستخدم في فروقات الشفت والدفع النقدي عند عدم وجود ربط خاص لطريقة الدفع.',
                'default' => self::CASH,
                'types' => ['asset'],
                'group' => 'الأصول والنقد',
            ],
            'bank_account' => [
                'label' => 'البنك الافتراضي',
                'description' => 'الحساب الافتراضي للبطاقات والتحويلات عند عدم وجود ربط خاص لطريقة الدفع.',
                'default' => self::BANK,
                'types' => ['asset'],
                'group' => 'الأصول والنقد',
            ],
            'wallet_clearing' => [
                'label' => 'محافظ وتطبيقات التحصيل',
                'description' => 'حساب التحصيل عبر تطبيقات أو محافظ قديمة عند استخدامها.',
                'default' => self::WALLET_CLEARING,
                'types' => ['asset'],
                'group' => 'الأصول والنقد',
            ],
            'customer_credit_clearing' => [
                'label' => 'مقاصة البيع الآجل',
                'description' => 'حساب تسوية طرق دفع آجلة قديمة عند استخدامها.',
                'default' => self::CUSTOMER_CREDIT_CLEARING,
                'types' => ['asset'],
                'group' => 'الأصول والنقد',
            ],
            'accounts_receivable' => [
                'label' => 'الذمم المدينة',
                'description' => 'الطرف المدين عند إصدار الفواتير والطرف الدائن عند التحصيل أو الشطب.',
                'default' => self::ACCOUNTS_RECEIVABLE,
                'types' => ['asset'],
                'group' => 'الذمم',
            ],
            'inventory_in_transit' => [
                'label' => 'مخزون بالطريق',
                'description' => 'مخزون التحويلات بين الفروع قبل إغلاق الاستلام.',
                'default' => self::INVENTORY_IN_TRANSIT,
                'types' => ['asset'],
                'group' => 'المخزون',
            ],
            'inventory' => [
                'label' => 'المخزون',
                'description' => 'حساب المخزون الرئيسي لحركات الاستلام والصرف والجرد.',
                'default' => self::INVENTORY,
                'types' => ['asset'],
                'group' => 'المخزون',
            ],
            'input_vat' => [
                'label' => 'ضريبة مدخلات',
                'description' => 'ضريبة مشتريات وفواتير موردين قابلة للاسترداد.',
                'default' => self::INPUT_VAT,
                'types' => ['asset'],
                'group' => 'الضرائب',
            ],
            'fixed_assets' => [
                'label' => 'Fixed assets',
                'description' => 'Capitalized restaurant assets such as equipment, furniture, and fixtures.',
                'default' => self::FIXED_ASSETS,
                'types' => ['asset'],
                'group' => 'Fixed assets',
            ],
            'accumulated_depreciation' => [
                'label' => 'Accumulated depreciation',
                'description' => 'Contra-asset account used to accumulate posted fixed-asset depreciation.',
                'default' => self::ACCUMULATED_DEPRECIATION,
                'types' => ['asset'],
                'group' => 'Fixed assets',
            ],
            'accounts_payable' => [
                'label' => 'الذمم الدائنة',
                'description' => 'حساب الموردين عند إنشاء فاتورة مورد وسدادها.',
                'default' => self::ACCOUNTS_PAYABLE,
                'types' => ['liability'],
                'group' => 'الذمم',
            ],
            'output_vat' => [
                'label' => 'ضريبة مخرجات',
                'description' => 'ضريبة المبيعات المستحقة على الفواتير.',
                'default' => self::OUTPUT_VAT,
                'types' => ['liability'],
                'group' => 'الضرائب',
            ],
            'tips_payable' => [
                'label' => 'إكراميات مستحقة',
                'description' => 'الإكراميات المحصلة والمستحقة للموظفين.',
                'default' => self::TIPS_PAYABLE,
                'types' => ['liability'],
                'group' => 'الالتزامات',
            ],
            'grni' => [
                'label' => 'استلامات غير مفوترة',
                'description' => 'استلام مخزون من أمر شراء قبل وصول فاتورة المورد.',
                'default' => self::GOODS_RECEIVED_NOT_INVOICED,
                'types' => ['liability'],
                'group' => 'المخزون',
            ],
            'opening_balance_equity' => [
                'label' => 'رأس مال/أرصدة افتتاحية',
                'description' => 'الطرف المقابل لإدخال مخزون افتتاحي.',
                'default' => self::OPENING_BALANCE_EQUITY,
                'types' => ['equity'],
                'group' => 'حقوق الملكية',
            ],
            'retained_earnings' => [
                'label' => 'أرباح محتجزة',
                'description' => 'الحساب الذي يستقبل صافي ربح أو خسارة الفترة عند الإقفال الرسمي.',
                'default' => self::RETAINED_EARNINGS,
                'types' => ['equity'],
                'group' => 'حقوق الملكية',
            ],
            'sales_revenue' => [
                'label' => 'إيراد المبيعات',
                'description' => 'إيراد الأصناف قبل الخصومات.',
                'default' => self::SALES_REVENUE,
                'types' => ['revenue'],
                'group' => 'الإيرادات',
            ],
            'service_revenue' => [
                'label' => 'إيراد رسوم الخدمة',
                'description' => 'رسوم الخدمة المحصلة على الفواتير.',
                'default' => self::SERVICE_REVENUE,
                'types' => ['revenue'],
                'group' => 'الإيرادات',
            ],
            'delivery_revenue' => [
                'label' => 'إيراد التوصيل',
                'description' => 'رسوم التوصيل المحصلة من العملاء.',
                'default' => self::DELIVERY_REVENUE,
                'types' => ['revenue'],
                'group' => 'الإيرادات',
            ],
            'sales_discounts' => [
                'label' => 'خصومات المبيعات',
                'description' => 'الخصومات والعروض ومسموحات المبيعات.',
                'default' => self::SALES_DISCOUNTS,
                'types' => ['contra_revenue'],
                'group' => 'مقابلات الإيراد',
            ],
            'sales_returns' => [
                'label' => 'مردودات المبيعات',
                'description' => 'الاستردادات ومردودات المبيعات.',
                'default' => self::SALES_RETURNS,
                'types' => ['contra_revenue'],
                'group' => 'مقابلات الإيراد',
            ],
            'inventory_adjustment_gain' => [
                'label' => 'فروقات جرد دائنة',
                'description' => 'الزيادات الجردية أو إدخال مخزون غير مفوتر.',
                'default' => self::INVENTORY_ADJUSTMENT_GAIN,
                'types' => ['revenue'],
                'group' => 'المخزون',
            ],
            'cash_over_short_income' => [
                'label' => 'فائض صندوق',
                'description' => 'فروقات الصندوق الموجبة عند إغلاق الشفت.',
                'default' => self::CASH_OVER_SHORT_INCOME,
                'types' => ['revenue'],
                'group' => 'الصندوق',
            ],
            'foreign_exchange_gain' => [
                'label' => 'Foreign exchange gain',
                'description' => 'Gain recognized when a foreign-currency receivable or payable is settled at a different exchange rate.',
                'default' => self::FOREIGN_EXCHANGE_GAIN,
                'types' => ['revenue'],
                'group' => 'Currencies',
            ],
            'fixed_asset_disposal_gain' => [
                'label' => 'Gain on fixed asset disposal',
                'description' => 'Gain recognized when proceeds exceed the book value of a disposed fixed asset.',
                'default' => self::FIXED_ASSET_DISPOSAL_GAIN,
                'types' => ['revenue'],
                'group' => 'Fixed assets',
            ],
            'cost_of_goods_sold' => [
                'label' => 'تكلفة البضاعة المباعة',
                'description' => 'تكلفة المكونات المصروفة عند بيع الأصناف.',
                'default' => self::COST_OF_GOODS_SOLD,
                'types' => ['expense'],
                'group' => 'التكاليف والمصاريف',
            ],
            'operating_expenses' => [
                'label' => 'مصروفات تشغيلية افتراضية',
                'description' => 'مصروف عام عند عدم وجود ربط لفئة المصروف أو بند المورد.',
                'default' => self::OPERATING_EXPENSES,
                'types' => ['expense'],
                'group' => 'التكاليف والمصاريف',
            ],
            'bad_debt_expense' => [
                'label' => 'ديون معدومة',
                'description' => 'شطب ذمم مدينة غير محصلة.',
                'default' => self::BAD_DEBT_EXPENSE,
                'types' => ['expense'],
                'group' => 'التكاليف والمصاريف',
            ],
            'bank_and_card_fees' => [
                'label' => 'رسوم بنكية وبطاقات',
                'description' => 'عمولات البنوك والبطاقات عند استخدامها في القيود.',
                'default' => self::BANK_AND_CARD_FEES,
                'types' => ['expense'],
                'group' => 'التكاليف والمصاريف',
            ],
            'waste_expense' => [
                'label' => 'هدر وتالف',
                'description' => 'تكلفة المواد الهالكة أو الملغاة أثناء التحضير.',
                'default' => self::WASTE_EXPENSE,
                'types' => ['expense'],
                'group' => 'المخزون',
            ],
            'inventory_shrinkage_expense' => [
                'label' => 'عجز مخزون',
                'description' => 'نقص أو صرف مخزون يدوي لا يرتبط ببيع.',
                'default' => self::INVENTORY_SHRINKAGE_EXPENSE,
                'types' => ['expense'],
                'group' => 'المخزون',
            ],
            'purchase_price_variance' => [
                'label' => 'فروقات أسعار الشراء',
                'description' => 'فرق السعر بين الاستلام وفاتورة المورد.',
                'default' => self::PURCHASE_PRICE_VARIANCE,
                'types' => ['expense'],
                'group' => 'المخزون',
            ],
            'depreciation_expense' => [
                'label' => 'Depreciation expense',
                'description' => 'Periodic depreciation expense posted for fixed assets.',
                'default' => self::DEPRECIATION_EXPENSE,
                'types' => ['expense'],
                'group' => 'Fixed assets',
            ],
            'cash_shortage_expense' => [
                'label' => 'عجز صندوق',
                'description' => 'فروقات الصندوق السالبة عند إغلاق الشفت.',
                'default' => self::CASH_SHORTAGE_EXPENSE,
                'types' => ['expense'],
                'group' => 'الصندوق',
            ],
            'foreign_exchange_loss' => [
                'label' => 'Foreign exchange loss',
                'description' => 'Loss recognized when a foreign-currency receivable or payable is settled at a different exchange rate.',
                'default' => self::FOREIGN_EXCHANGE_LOSS,
                'types' => ['expense'],
                'group' => 'Currencies',
            ],
            'fixed_asset_disposal_loss' => [
                'label' => 'Loss on fixed asset disposal',
                'description' => 'Loss recognized when proceeds are below the book value of a disposed fixed asset.',
                'default' => self::FIXED_ASSET_DISPOSAL_LOSS,
                'types' => ['expense'],
                'group' => 'Fixed assets',
            ],
        ];
    }

    public function recordInvoiceIssued(Invoice $invoice): ?JournalEntry
    {
        $invoice->refresh();

        // Per-item promo savings (sale_price / percent / fixed_off /
        // free_modifier) are silent in `invoice.subtotal` because they
        // already shrunk `order_item.unit_price`. To keep the income
        // statement honest, we GROSS the revenue up and also DEBIT the
        // SALES_DISCOUNTS account by the savings amount.
        //
        //   Without this: 100 burger sold at 25% off →
        //       Revenue 4000 = 75, Discount 4090 = 0
        //   With this:    same sale →
        //       Revenue 4000 = 100 (gross), Discount 4090 = 25 (visible)
        //
        // BXGY + cashier ad-hoc discounts live in `invoice.discount_total`
        // already and continue to debit 4090 — they're additive here.
        $promoSavings = $invoice->promoSavings();
        $grossSubtotal = (float) $invoice->subtotal + $promoSavings;
        $totalDiscount = (float) $invoice->discount_total + $promoSavings;

        $lines = [
            $this->debit($this->postingAccount('accounts_receivable'), (float) $invoice->total, 'إثبات إجمالي الفاتورة على الذمم المدينة'),
            $this->debit($this->postingAccount('sales_discounts'), $totalDiscount, 'خصومات ومسموحات مبيعات (يدوية + عروض الأصناف)'),
            $this->credit($this->postingAccount('sales_revenue'), $grossSubtotal, 'إيراد المبيعات قبل الخصم (بالقيمة الاسمية)'),
            $this->credit($this->postingAccount('output_vat'), (float) $invoice->tax_total, 'ضريبة قيمة مضافة - مخرجات'),
            $this->credit($this->postingAccount('service_revenue'), (float) $invoice->service_total, 'إيرادات رسوم الخدمة'),
            $this->credit($this->postingAccount('delivery_revenue'), (float) $invoice->delivery_fee, 'إيرادات التوصيل'),
            $this->credit($this->postingAccount('tips_payable'), (float) $invoice->tip, 'إكراميات مستحقة للموظفين'),
        ];

        return $this->post(
            eventType: 'invoice_issued',
            source: $invoice,
            branchId: $invoice->branch_id,
            postedOn: $invoice->issued_at ?: $invoice->created_at ?: now(),
            description: "إثبات فاتورة {$invoice->number}",
            lines: $lines,
            metadata: $promoSavings > 0 ? ['promo_savings' => $promoSavings] : [],
            createdBy: $invoice->issued_by_user_id,
        );
    }

    public function reverseInvoiceIssued(Invoice $invoice, ?int $userId = null, ?string $reason = null): ?JournalEntry
    {
        // Reverse the LIVE posting — the original `invoice_issued`, OR the latest
        // `invoice_reissued_N` when a post-issuance discount already re-posted the
        // invoice. Blindly reversing `invoice_issued` (the old behaviour) would
        // double-reverse an already-neutralised entry AND leave the repost
        // standing → phantom negative A/R (1100) and revenue (4000) on every
        // discounted-then-cancelled invoice.
        $live = $this->latestUnreversedInvoicePosting($invoice);
        if (! $live) {
            return null;   // never posted, or its live entry is already reversed
        }

        return $this->reverseEntry(
            original: $live,
            eventType: 'invoice_cancelled',
            postedOn: now(),
            description: "عكس فاتورة {$invoice->number}",
            metadata: ['reason' => $reason],
            createdBy: $userId,
        );
    }

    /**
     * The single LIVE (unreversed) posting for an invoice: its original
     * `invoice_issued` entry, or the latest `invoice_reissued_N` if the
     * totals were re-posted after a post-issuance discount. Returns null
     * when the invoice was never posted or its live posting is already
     * reversed. Shared by the cancel path and OrderDiscountService so both
     * always target the same entry.
     */
    public function latestUnreversedInvoicePosting(Invoice $invoice): ?JournalEntry
    {
        $entries = $invoice->journalEntries()->orderBy('id')->get();

        $reversedIds = $entries
            ->map(fn (JournalEntry $entry) => (int) ($entry->metadata['reverses_entry_id'] ?? 0))
            ->filter()
            ->all();

        return $entries
            ->filter(fn (JournalEntry $entry) => $entry->event_type === 'invoice_issued'
                || preg_match('/^invoice_reissued_\d+$/', (string) $entry->event_type))
            ->reject(fn (JournalEntry $entry) => in_array((int) $entry->id, $reversedIds, true))
            ->sortByDesc('id')
            ->first();
    }

    /**
     * Re-post an invoice's journal entry after its totals changed (a
     * cashier applied/removed a discount post-issuance). Uses a unique
     * event_type so the idempotency guard inside `post` doesn't no-op
     * us — the previous `invoice_issued` entry has already been reversed
     * by `OrderDiscountService::writeInvoiceTotals` before this call.
     *
     * Counted separately on the income statement (event_type starts with
     * 'invoice_reissued_'), so accountants can audit how many invoices
     * were touched mid-flight.
     */
    public function repostInvoiceWithDiscount(Invoice $invoice): ?JournalEntry
    {
        $invoice->refresh();

        $promoSavings  = $invoice->promoSavings();
        $grossSubtotal = (float) $invoice->subtotal + $promoSavings;
        $totalDiscount = (float) $invoice->discount_total + $promoSavings;

        $lines = [
            $this->debit($this->postingAccount('accounts_receivable'), (float) $invoice->total, 'إثبات إجمالي الفاتورة بعد تحديث الخصم'),
            $this->debit($this->postingAccount('sales_discounts'), $totalDiscount, 'خصومات ومسموحات مبيعات (محدّثة)'),
            $this->credit($this->postingAccount('sales_revenue'), $grossSubtotal, 'إيراد المبيعات (بالقيمة الاسمية)'),
            $this->credit($this->postingAccount('output_vat'), (float) $invoice->tax_total, 'ضريبة قيمة مضافة - مخرجات'),
            $this->credit($this->postingAccount('service_revenue'), (float) $invoice->service_total, 'إيرادات رسوم الخدمة'),
            $this->credit($this->postingAccount('delivery_revenue'), (float) $invoice->delivery_fee, 'إيرادات التوصيل'),
            $this->credit($this->postingAccount('tips_payable'), (float) $invoice->tip, 'إكراميات مستحقة للموظفين'),
        ];

        // Make the event_type unique by counting prior reposts on this
        // invoice — first repost = invoice_reissued_1, second = _2, etc.
        $reissueSeq = JournalEntry::where('source_type', $invoice::class)
            ->where('source_id', $invoice->id)
            ->where('event_type', 'like', 'invoice_reissued_%')
            ->count() + 1;

        return $this->post(
            eventType: "invoice_reissued_{$reissueSeq}",
            source: $invoice,
            branchId: $invoice->branch_id,
            postedOn: now(),
            description: "إعادة إثبات فاتورة {$invoice->number} (المحاولة #{$reissueSeq})",
            lines: $lines,
            metadata: $promoSavings > 0
                ? ['promo_savings' => $promoSavings, 'reason' => 'post_issuance_discount']
                : ['reason' => 'post_issuance_discount'],
            createdBy: $invoice->issued_by_user_id,
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
            lines: array_merge([
                $this->debit($this->cashAccountForMethod($payment->method), (float) $payment->amount, 'تحصيل عبر '.$this->paymentMethodLabel($payment->method)),
                $this->credit($this->postingAccount('accounts_receivable'), (float) $payment->amount, 'تسوية ذمم مدينة للعملاء'),
            ], $this->receivableSettlementAdjustmentLines($payment)),
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
                $this->debit($this->postingAccount('bad_debt_expense'), $amount, 'مصروف ديون معدومة'),
                $this->credit($this->postingAccount('accounts_receivable'), $amount, 'شطب ذمم مدينة غير محصلة'),
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
                $this->debit($this->postingAccount('sales_returns'), (float) $refund->amount, 'مردودات ومسموحات مبيعات'),
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
                $this->debit($this->expenseAccountFor($expense), (float) $expense->amount, $expense->description),
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
            $this->debit($this->postingAccount('grni'), $poReceivedSubtotal, 'تسوية استلامات مخزون غير مفوترة'),
            $priceVariance > 0
                ? $this->debit($this->postingAccount('purchase_price_variance'), abs($priceVariance), 'فروقات أسعار مشتريات مدينة')
                : $this->credit($this->postingAccount('purchase_price_variance'), abs($priceVariance), 'فروقات أسعار مشتريات دائنة'),
            $this->debit($this->postingAccount('inventory'), $inventorySubtotal, 'إضافة مشتريات مخزنية من فاتورة مورد'),
            $this->debit($this->postingAccount('operating_expenses'), $expenseSubtotal, 'مصروفات غير مخزنية من فاتورة مورد'),
            $this->debit($this->postingAccount('input_vat'), (float) $invoice->tax_total, 'ضريبة قيمة مضافة - مدخلات'),
            $this->credit($this->postingAccount('accounts_payable'), (float) $invoice->total, 'إثبات ذمم دائنة للمورد'),
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
            lines: array_merge([
                $this->debit($this->postingAccount('accounts_payable'), (float) $payment->amount, 'تسوية ذمم دائنة للمورد'),
                $this->credit($this->cashAccountForMethod($payment->method), (float) $payment->amount, 'سداد عبر '.$this->paymentMethodLabel($payment->method)),
            ], $this->payableSettlementAdjustmentLines($payment)),
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
                $this->debit($this->postingAccount('cash_account'), $amount, 'زيادة فعلية في صندوق الشفت'),
                $this->credit($this->postingAccount('cash_over_short_income'), $amount, 'فائض صندوق عند إغلاق الشفت'),
            ]
            : [
                $this->debit($this->postingAccount('cash_shortage_expense'), $amount, 'عجز صندوق عند إغلاق الشفت'),
                $this->credit($this->postingAccount('cash_account'), $amount, 'نقص فعلي في صندوق الشفت'),
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

    public function recordTaxPayment(float $outputTax, float $inputTax, string $paymentMethod, ?int $branchId, mixed $postedOn, ?int $createdBy = null, array $metadata = []): ?JournalEntry
    {
        $outputTax = $this->round(max(0, $outputTax));
        $inputTax = $this->round(max(0, $inputTax));
        $cashPaid = $this->round($outputTax - $inputTax);

        if ($outputTax <= 0 || $cashPaid <= 0) {
            throw new \RuntimeException('Tax payment requires a positive payable tax balance.');
        }

        $lines = [
            $this->baseDebit($this->postingAccount('output_vat'), $outputTax, 'Tax liability paid'),
        ];

        if ($inputTax > 0) {
            $lines[] = $this->baseCredit($this->postingAccount('input_vat'), $inputTax, 'Input tax applied against tax payable');
        }

        $lines[] = $this->baseCredit($this->cashAccountForMethod($paymentMethod), $cashPaid, 'Tax payment by '.$this->paymentMethodLabel($paymentMethod));

        return $this->post(
            eventType: 'tax_payment',
            source: null,
            branchId: $branchId,
            postedOn: $postedOn,
            description: 'Tax payment',
            lines: $lines,
            metadata: $metadata,
            createdBy: $createdBy,
        );
    }

    public function recordTipPayout(float $amount, string $paymentMethod, ?int $branchId, mixed $postedOn, ?int $createdBy = null, ?string $notes = null): ?JournalEntry
    {
        $amount = $this->round($amount);
        if ($amount <= 0) {
            throw new \RuntimeException('Tip payout amount must be greater than zero.');
        }

        return $this->post(
            eventType: 'tips_payout',
            source: null,
            branchId: $branchId,
            postedOn: $postedOn,
            description: 'Tips payout',
            lines: [
                $this->baseDebit($this->postingAccount('tips_payable'), $amount, 'Tips liability paid to staff'),
                $this->baseCredit($this->cashAccountForMethod($paymentMethod), $amount, 'Tips payout by '.$this->paymentMethodLabel($paymentMethod)),
            ],
            metadata: $notes ? ['notes' => $notes] : [],
            createdBy: $createdBy,
        );
    }

    public function recordPaymentClearingSettlement(Account $clearingAccount, Account $depositAccount, float $grossAmount, float $feeAmount, ?int $branchId, mixed $postedOn, ?int $createdBy = null, ?string $notes = null): ?JournalEntry
    {
        if ($clearingAccount->type !== 'asset' || $depositAccount->type !== 'asset') {
            throw new \RuntimeException('Payment clearing settlement requires asset accounts.');
        }

        $grossAmount = $this->round($grossAmount);
        $feeAmount = $this->round(max(0, $feeAmount));
        $netDeposit = $this->round($grossAmount - $feeAmount);

        if ($grossAmount <= 0 || $netDeposit < 0) {
            throw new \RuntimeException('Settlement gross amount must be greater than the fee amount.');
        }

        $lines = [];
        if ($netDeposit > 0) {
            $lines[] = $this->baseDebit($depositAccount->code, $netDeposit, 'Net payment processor deposit');
        }
        if ($feeAmount > 0) {
            $lines[] = $this->baseDebit($this->postingAccount('bank_and_card_fees'), $feeAmount, 'Payment processor fee');
        }
        $lines[] = $this->baseCredit($clearingAccount->code, $grossAmount, 'Clear payment processor receivable');

        return $this->post(
            eventType: 'payment_clearing_settlement',
            source: null,
            branchId: $branchId,
            postedOn: $postedOn,
            description: 'Payment clearing settlement',
            lines: $lines,
            metadata: [
                'clearing_account_id' => $clearingAccount->id,
                'deposit_account_id' => $depositAccount->id,
                'gross_amount' => $grossAmount,
                'fee_amount' => $feeAmount,
                'notes' => $notes,
            ],
            createdBy: $createdBy,
        );
    }

    public function recordFixedAssetAcquisition(FixedAsset $asset): ?JournalEntry
    {
        $asset->refresh();
        $foreignCost = $this->round((float) ($asset->foreign_cost ?: $asset->cost));
        if ($foreignCost <= 0 || (float) $asset->cost <= 0) {
            throw new \RuntimeException('Fixed asset acquisition requires a positive cost.');
        }

        $currencyCode = $this->normalizeCurrencyCode($asset->currency_code ?: $this->baseCurrencyCode());
        $exchangeRate = (float) ($asset->exchange_rate ?: 1);

        return $this->post(
            eventType: 'fixed_asset_acquired',
            source: $asset,
            branchId: $asset->branch_id,
            postedOn: $asset->acquisition_date ?: now(),
            description: "Fixed asset acquisition {$asset->asset_number} - {$asset->name}",
            lines: [
                $this->currencyDebit($this->postingAccount('fixed_assets'), $foreignCost, $currencyCode, $exchangeRate, 'Capitalize fixed asset cost'),
                $this->currencyCredit($this->fixedAssetFundingAccount($asset->payment_method), $foreignCost, $currencyCode, $exchangeRate, 'Fund fixed asset purchase'),
            ],
            metadata: [
                'asset_number' => $asset->asset_number,
                'currency_code' => $currencyCode,
                'exchange_rate' => $exchangeRate,
                'foreign_cost' => $foreignCost,
                'base_cost' => (float) $asset->cost,
            ],
            createdBy: $asset->created_by,
            currencyCode: $currencyCode,
            exchangeRate: $exchangeRate,
        );
    }

    public function recordFixedAssetDepreciation(FixedAssetDepreciation $depreciation): ?JournalEntry
    {
        $depreciation->loadMissing('fixedAsset');
        $asset = $depreciation->fixedAsset;
        $amount = $this->round((float) $depreciation->amount);

        if (! $asset || $amount <= 0) {
            throw new \RuntimeException('Fixed asset depreciation requires an asset and a positive amount.');
        }

        return $this->post(
            eventType: 'fixed_asset_depreciation',
            source: $depreciation,
            branchId: $depreciation->branch_id ?: $asset->branch_id,
            postedOn: $depreciation->posted_on ?: $depreciation->period_end ?: now(),
            description: "Depreciation {$asset->asset_number} - {$asset->name}",
            lines: [
                $this->baseDebit($this->postingAccount('depreciation_expense'), $amount, 'Fixed asset depreciation expense'),
                $this->baseCredit($this->postingAccount('accumulated_depreciation'), $amount, 'Accumulated depreciation'),
            ],
            metadata: [
                'fixed_asset_id' => $asset->id,
                'asset_number' => $asset->asset_number,
                'period_start' => $depreciation->period_start?->toDateString(),
                'period_end' => $depreciation->period_end?->toDateString(),
                'accumulated_after' => (float) $depreciation->accumulated_after,
            ],
            createdBy: $depreciation->created_by,
        );
    }

    public function recordFixedAssetDisposal(FixedAsset $asset, float $proceeds, ?string $paymentMethod, mixed $postedOn, ?int $createdBy = null, ?string $notes = null): ?JournalEntry
    {
        $asset->refresh();

        $cost = $this->round((float) $asset->cost);
        $accumulated = $this->round(min($cost, max(0, (float) $asset->accumulated_depreciation)));
        $proceeds = $this->round(max(0, $proceeds));
        $bookValue = $this->round(max(0, $cost - $accumulated));
        $gain = $this->round(max(0, $proceeds - $bookValue));
        $loss = $this->round(max(0, $bookValue - $proceeds));

        if ($cost <= 0) {
            throw new \RuntimeException('Fixed asset disposal requires a capitalized cost.');
        }

        $lines = [];
        if ($accumulated > 0) {
            $lines[] = $this->baseDebit($this->postingAccount('accumulated_depreciation'), $accumulated, 'Remove accumulated depreciation');
        }
        if ($proceeds > 0) {
            $lines[] = $this->baseDebit($this->fixedAssetFundingAccount($paymentMethod), $proceeds, 'Disposal proceeds received');
        }
        if ($loss > 0) {
            $lines[] = $this->baseDebit($this->postingAccount('fixed_asset_disposal_loss'), $loss, 'Loss on fixed asset disposal');
        }

        $lines[] = $this->baseCredit($this->postingAccount('fixed_assets'), $cost, 'Remove fixed asset cost');

        if ($gain > 0) {
            $lines[] = $this->baseCredit($this->postingAccount('fixed_asset_disposal_gain'), $gain, 'Gain on fixed asset disposal');
        }

        return $this->post(
            eventType: 'fixed_asset_disposal',
            source: $asset,
            branchId: $asset->branch_id,
            postedOn: $postedOn,
            description: "Fixed asset disposal {$asset->asset_number} - {$asset->name}",
            lines: $lines,
            metadata: [
                'asset_number' => $asset->asset_number,
                'cost' => $cost,
                'accumulated_depreciation' => $accumulated,
                'book_value' => $bookValue,
                'proceeds' => $proceeds,
                'gain' => $gain,
                'loss' => $loss,
                'notes' => $notes,
            ],
            createdBy: $createdBy,
        );
    }

    public function post(
        string $eventType,
        ?Model $source,
        ?int $branchId,
        $postedOn,
        string $description,
        array $lines,
        array $metadata = [],
        ?int $createdBy = null,
        ?string $currencyCode = null,
        ?float $exchangeRate = null,
        ?string $baseCurrencyCode = null,
    ): ?JournalEntry {
        $postedDate = Carbon::parse($postedOn)->toDateString();
        $baseCurrencyCode = $this->normalizeCurrencyCode($baseCurrencyCode ?: $this->baseCurrencyCode());
        $currencyCode = $this->normalizeCurrencyCode($currencyCode ?: $this->entryCurrencyCode($lines, $baseCurrencyCode));
        $exchangeRate = $exchangeRate ?: $this->entryExchangeRate($lines, $currencyCode, $baseCurrencyCode, $postedDate);
        $lines = $this->normalizeLines($lines, $currencyCode, $exchangeRate, $baseCurrencyCode, $postedDate);
        if ($lines === []) {
            return null;
        }

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return DB::transaction(function () use ($eventType, $source, $branchId, $postedOn, $description, $lines, $metadata, $createdBy, $baseCurrencyCode, $currencyCode, $exchangeRate) {
                    $this->assertPostingPeriodOpen($postedOn, $branchId);

                    $existing = $source
                        ? JournalEntry::where('source_type', $source::class)
                            ->where('source_id', $source->getKey())
                            ->where('event_type', $eventType)
                            ->first()
                        : null;

                    if ($existing) {
                        return $existing;
                    }

                    $this->assertBalanced($lines, $description);

                    $entry = JournalEntry::create([
                        'branch_id' => $branchId,
                        'posted_on' => \Carbon\Carbon::parse($postedOn)->toDateString(),
                        'description' => $description,
                        'base_currency_code' => $baseCurrencyCode,
                        'currency_code' => $currencyCode,
                        'exchange_rate' => $exchangeRate,
                        'source_type' => $source ? $source::class : null,
                        'source_id' => $source?->getKey(),
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
                            'currency_code' => $line['currency_code'],
                            'exchange_rate' => $line['exchange_rate'],
                            'foreign_debit' => $line['foreign_debit'],
                            'foreign_credit' => $line['foreign_credit'],
                        ]);
                    }

                    return $entry->fresh('lines.account');
                });
            } catch (QueryException $e) {
                if (! $this->isEntryNumberCollision($e) || $attempt === 3) {
                    throw $e;
                }

                usleep(10000);
            }
        }

        return null;
    }

    private function assertPostingPeriodOpen($postedOn, ?int $branchId): void
    {
        $date = Carbon::parse($postedOn)->toDateString();

        $closedPeriod = AccountingPeriod::query()
            ->where('status', 'closed')
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');
                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->orderByRaw('branch_id is null')
            ->first();

        if ($closedPeriod) {
            throw new \RuntimeException("الفترة المحاسبية {$closedPeriod->name} مقفلة. لا يمكن ترحيل قيود بتاريخ {$date}.");
        }

        $closedFiscalYear = FiscalYear::query()
            ->where('status', 'closed')
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');
                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->orderByRaw('branch_id is null')
            ->first();

        if ($closedFiscalYear) {
            throw new \RuntimeException("السنة المالية {$closedFiscalYear->name} مقفلة. لا يمكن ترحيل قيود بتاريخ {$date}.");
        }
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

        return $this->reverseEntry($original, $eventType, $postedOn, $description, $metadata, $createdBy);
    }

    public function reverseEntry(
        JournalEntry $original,
        string $eventType,
        $postedOn,
        string $description,
        array $metadata = [],
        ?int $createdBy = null,
    ): ?JournalEntry {
        $original->loadMissing('lines.account');

        $source = null;
        if ($original->source_type && $original->source_id && is_subclass_of($original->source_type, Model::class)) {
            $source = $original->source_type::find($original->source_id);
        }

        $lines = $original->lines->map(fn ($line) => [
            'account' => $line->account->code,
            'debit' => (float) $line->credit,
            'credit' => (float) $line->debit,
            'currency_code' => $line->currency_code ?: $original->base_currency_code,
            'exchange_rate' => (float) ($line->exchange_rate ?: 1),
            'foreign_debit' => (float) $line->foreign_credit ?: (float) $line->credit,
            'foreign_credit' => (float) $line->foreign_debit ?: (float) $line->debit,
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

    private function isEntryNumberCollision(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'entry_no')
            && (str_contains($message, 'unique') || str_contains($message, 'duplicate'));
    }

    private function postInventoryOut(InventoryMovement $movement, float $amount, string $description): ?JournalEntry
    {
        if ($movement->reference_type === BranchTransferItem::class) {
            return $this->postInventoryMovement(
                'inventory_transfer_sent',
                $movement,
                $description,
                [
                    $this->debit($this->postingAccount('inventory_in_transit'), $amount, 'مخزون محول بين الفروع - قيد الطريق'),
                    $this->credit($this->postingAccount('inventory'), $amount, 'خروج مخزون من فرع المصدر'),
                ],
            );
        }

        if ($movement->reference_type === OrderItem::class) {
            return $this->postInventoryMovement(
                'inventory_cogs_recognized',
                $movement,
                $description,
                [
                    $this->debit($this->postingAccount('cost_of_goods_sold'), $amount, 'إثبات تكلفة البضاعة المباعة'),
                    $this->credit($this->postingAccount('inventory'), $amount, 'خروج مكونات مرتبطة ببيع'),
                ],
            );
        }

        return $this->postInventoryMovement(
            'inventory_manual_out',
            $movement,
            $description,
            [
                $this->debit($this->postingAccount('inventory_shrinkage_expense'), $amount, 'صرف أو نقص مخزون يدوي'),
                $this->credit($this->postingAccount('inventory'), $amount, 'خروج مخزون'),
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
                    $this->debit($this->postingAccount('inventory'), $amount, 'إرجاع مخزون التحويل إلى فرع المصدر'),
                    $this->credit($this->postingAccount('inventory_in_transit'), $amount, 'إلغاء مخزون محول بين الفروع'),
                ],
            );
        }

        if ($movement->reference_type === OrderItem::class) {
            return $this->postInventoryMovement(
                'inventory_cogs_reversed',
                $movement,
                $description,
                [
                    $this->debit($this->postingAccount('inventory'), $amount, 'إرجاع مكونات طلب ملغى إلى المخزون'),
                    $this->credit($this->postingAccount('cost_of_goods_sold'), $amount, 'عكس تكلفة بضاعة مباعة'),
                ],
            );
        }

        return $this->postInventoryMovement(
            'inventory_return_recorded',
            $movement,
            $description,
            [
                $this->debit($this->postingAccount('inventory'), $amount, 'إرجاع مخزون'),
                $this->credit($this->postingAccount('inventory_adjustment_gain'), $amount, 'تسوية إرجاع مخزون'),
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
                $this->debit($this->postingAccount('waste_expense'), $amount, 'هدر أو تالف مخزون'),
                $this->credit($this->postingAccount('inventory'), $amount, 'تخفيض المخزون بسبب الهدر'),
            ],
        );
    }

    /**
     * Post a "convert COGS to waste" reclassification when a sold order
     * item is cancelled mid-prep and the inventory cost should move
     * from COGS to the Waste line.
     *
     *   DR 5400 WASTE_EXPENSE  — recognise as waste
     *   CR 5000 COGS           — back out the original sale cost
     *
     * Inventory is left alone (the physical decrement already happened
     * at the original `out` movement and the convert-to-waste path
     * deliberately doesn't move stock again). Net P&L impact: zero
     * change to total expense, just a category shift.
     *
     * Idempotent per (movement, event_type) via AccountingService::post.
     */
    public function recordWasteReclassification(InventoryMovement $movement, string $description): ?JournalEntry
    {
        $amount = abs((float) $movement->total_cost);
        if ($amount <= 0.0001) return null;

        return $this->post(
            eventType: 'inventory_waste_reclassified',
            source: $movement,
            branchId: $movement->branch_id,
            postedOn: $movement->occurred_at ?: now(),
            description: $description,
            lines: [
                $this->debit($this->postingAccount('waste_expense'), $amount, 'إعادة تصنيف تكلفة البيع كهدر'),
                $this->credit($this->postingAccount('cost_of_goods_sold'), $amount, 'عكس تكلفة البضاعة المباعة'),
            ],
            createdBy: $movement->user_id,
        );
    }

    private function postInventoryAdjustment(InventoryMovement $movement, float $amount, string $description): ?JournalEntry
    {
        $signedValue = (float) $movement->total_cost;

        $lines = $signedValue >= 0
            ? [
                $this->debit($this->postingAccount('inventory'), $amount, 'زيادة جردية في المخزون'),
                $this->credit($this->postingAccount('inventory_adjustment_gain'), $amount, 'فروقات جرد دائنة'),
            ]
            : [
                $this->debit($this->postingAccount('inventory_shrinkage_expense'), $amount, 'عجز أو نقص جردي في المخزون'),
                $this->credit($this->postingAccount('inventory'), $amount, 'تخفيض المخزون بعجز جردي'),
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
                    $this->debit($this->postingAccount('inventory'), $amount, 'استلام مخزون من فرع آخر'),
                    $this->credit($this->postingAccount('inventory_in_transit'), $amount, 'إغلاق مخزون محول بين الفروع'),
                ],
            );
        }

        if ($movement->reference_type === PurchaseOrderItem::class) {
            return $this->postInventoryMovement(
                'inventory_goods_received',
                $movement,
                $description,
                [
                    $this->debit($this->postingAccount('inventory'), $amount, 'استلام مشتريات مخزنية'),
                    $this->credit($this->postingAccount('grni'), $amount, 'استلامات مخزون غير مفوترة'),
                ],
            );
        }

        $openingBalanceAccount = $this->postingAccount('opening_balance_equity');
        $creditAccount = str_contains((string) $movement->reason, 'افتتاح')
            ? $openingBalanceAccount
            : $this->postingAccount('inventory_adjustment_gain');

        return $this->postInventoryMovement(
            $movement->reference_type === IngredientBatch::class ? 'inventory_manual_batch_added' : 'inventory_manual_in',
            $movement,
            $description,
            [
                $this->debit($this->postingAccount('inventory'), $amount, 'إدخال مخزون'),
                $this->credit($creditAccount, $amount, $creditAccount === $openingBalanceAccount ? 'رصيد افتتاحي للمخزون' : 'زيادة مخزون غير مفوترة'),
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
        return $this->transactionLine($account, $amount, 'debit', $description);
    }

    private function credit(string $account, float $amount, ?string $description = null): array
    {
        return $this->transactionLine($account, $amount, 'credit', $description);
    }

    private function baseDebit(string $account, float $amount, ?string $description = null): array
    {
        return $this->baseLine($account, $amount, 'debit', $description);
    }

    private function baseCredit(string $account, float $amount, ?string $description = null): array
    {
        return $this->baseLine($account, $amount, 'credit', $description);
    }

    private function currencyDebit(string $account, float $amount, string $currencyCode, float $exchangeRate, ?string $description = null): array
    {
        return $this->currencyLine($account, $amount, 'debit', $currencyCode, $exchangeRate, $description);
    }

    private function currencyCredit(string $account, float $amount, string $currencyCode, float $exchangeRate, ?string $description = null): array
    {
        return $this->currencyLine($account, $amount, 'credit', $currencyCode, $exchangeRate, $description);
    }

    private function baseLine(string $account, float $amount, string $side, ?string $description = null): array
    {
        $amount = $this->round(abs($amount));
        $baseCurrencyCode = $this->baseCurrencyCode();

        return [
            'account' => $account,
            'debit' => 0.0,
            'credit' => 0.0,
            'currency_code' => $baseCurrencyCode,
            'exchange_rate' => 1.0,
            'foreign_debit' => $side === 'debit' ? $amount : 0.0,
            'foreign_credit' => $side === 'credit' ? $amount : 0.0,
            'description' => $description,
        ];
    }

    private function currencyLine(string $account, float $amount, string $side, string $currencyCode, float $exchangeRate, ?string $description = null): array
    {
        $amount = $this->round(abs($amount));

        return [
            'account' => $account,
            'debit' => 0.0,
            'credit' => 0.0,
            'currency_code' => $this->normalizeCurrencyCode($currencyCode),
            'exchange_rate' => $exchangeRate,
            'foreign_debit' => $side === 'debit' ? $amount : 0.0,
            'foreign_credit' => $side === 'credit' ? $amount : 0.0,
            'description' => $description,
        ];
    }

    private function receivableSettlementAdjustmentLines(Payment $payment): array
    {
        $payment->loadMissing('invoice');
        if (! $payment->invoice || (float) $payment->amount <= 0) {
            return [];
        }

        $baseCurrencyCode = $this->baseCurrencyCode();
        $currencyCode = $this->transactionCurrencyCode();
        if ($currencyCode === $baseCurrencyCode) {
            return [];
        }

        $postedDate = Carbon::parse($payment->paid_at ?: $payment->created_at ?: now())->toDateString();
        $currentBase = $this->round((float) $payment->amount * $this->exchangeRateForCurrency($currencyCode, $baseCurrencyCode, $postedDate));
        $targetBase = $this->receivableBaseClearAmount($payment);
        if ($targetBase <= 0) {
            return [];
        }

        $difference = $this->round($currentBase - $targetBase);
        if (abs($difference) <= 0.0001) {
            return [];
        }

        return $difference > 0
            ? [
                $this->baseDebit($this->postingAccount('accounts_receivable'), $difference, 'Foreign exchange adjustment on customer receivable'),
                $this->baseCredit($this->postingAccount('foreign_exchange_gain'), $difference, 'Foreign exchange gain on customer payment'),
            ]
            : [
                $this->baseDebit($this->postingAccount('foreign_exchange_loss'), abs($difference), 'Foreign exchange loss on customer payment'),
                $this->baseCredit($this->postingAccount('accounts_receivable'), abs($difference), 'Foreign exchange adjustment on customer receivable'),
            ];
    }

    private function payableSettlementAdjustmentLines(SupplierPayment $payment): array
    {
        $payment->loadMissing('invoice');
        if (! $payment->invoice || (float) $payment->amount <= 0) {
            return [];
        }

        $baseCurrencyCode = $this->baseCurrencyCode();
        $currencyCode = $this->transactionCurrencyCode();
        if ($currencyCode === $baseCurrencyCode) {
            return [];
        }

        $postedDate = Carbon::parse($payment->paid_on ?: $payment->created_at ?: now())->toDateString();
        $currentBase = $this->round((float) $payment->amount * $this->exchangeRateForCurrency($currencyCode, $baseCurrencyCode, $postedDate));
        $targetBase = $this->payableBaseClearAmount($payment);
        if ($targetBase <= 0) {
            return [];
        }

        $difference = $this->round($currentBase - $targetBase);
        if (abs($difference) <= 0.0001) {
            return [];
        }

        return $difference > 0
            ? [
                $this->baseDebit($this->postingAccount('foreign_exchange_loss'), $difference, 'Foreign exchange loss on supplier payment'),
                $this->baseCredit($this->postingAccount('accounts_payable'), $difference, 'Foreign exchange adjustment on supplier payable'),
            ]
            : [
                $this->baseDebit($this->postingAccount('accounts_payable'), abs($difference), 'Foreign exchange adjustment on supplier payable'),
                $this->baseCredit($this->postingAccount('foreign_exchange_gain'), abs($difference), 'Foreign exchange gain on supplier payment'),
            ];
    }

    private function receivableBaseClearAmount(Payment $payment): float
    {
        $invoice = $payment->invoice;
        if (! $invoice) {
            return 0.0;
        }

        $openBase = $this->documentBaseBalanceBeforePayment(
            documentSourceType: Invoice::class,
            documentId: (int) $invoice->id,
            paymentSourceType: Payment::class,
            paymentTable: 'payments',
            paymentDocumentColumn: 'invoice_id',
            currentPaymentId: (int) $payment->id,
            role: 'accounts_receivable',
            normalBalance: 'debit',
        );

        return $this->proportionalBaseClearAmount(
            openBase: $openBase,
            documentTotal: (float) $invoice->total,
            paidBefore: (float) Payment::where('invoice_id', $invoice->id)
                ->whereKeyNot($payment->id)
                ->sum('amount'),
            currentPaymentAmount: (float) $payment->amount,
        );
    }

    private function payableBaseClearAmount(SupplierPayment $payment): float
    {
        $invoice = $payment->invoice;
        if (! $invoice) {
            return 0.0;
        }

        $openBase = $this->documentBaseBalanceBeforePayment(
            documentSourceType: SupplierInvoice::class,
            documentId: (int) $invoice->id,
            paymentSourceType: SupplierPayment::class,
            paymentTable: 'supplier_payments',
            paymentDocumentColumn: 'supplier_invoice_id',
            currentPaymentId: (int) $payment->id,
            role: 'accounts_payable',
            normalBalance: 'credit',
        );

        return $this->proportionalBaseClearAmount(
            openBase: $openBase,
            documentTotal: (float) $invoice->total,
            paidBefore: (float) SupplierPayment::where('supplier_invoice_id', $invoice->id)
                ->whereKeyNot($payment->id)
                ->sum('amount'),
            currentPaymentAmount: (float) $payment->amount,
        );
    }

    private function proportionalBaseClearAmount(float $openBase, float $documentTotal, float $paidBefore, float $currentPaymentAmount): float
    {
        $openBase = $this->round($openBase);
        if ($openBase <= 0 || $currentPaymentAmount <= 0) {
            return 0.0;
        }

        $foreignOpenBefore = max(0.0, $documentTotal - $paidBefore);
        if ($foreignOpenBefore <= 0) {
            return min($openBase, $this->round($currentPaymentAmount));
        }

        $ratio = min(1.0, $currentPaymentAmount / $foreignOpenBefore);

        return min($openBase, $this->round($openBase * $ratio));
    }

    private function documentBaseBalanceBeforePayment(
        string $documentSourceType,
        int $documentId,
        string $paymentSourceType,
        string $paymentTable,
        string $paymentDocumentColumn,
        int $currentPaymentId,
        string $role,
        string $normalBalance,
    ): float {
        $accountCodes = collect([$this->postingAccount($role), $this->postingRoleDefaultCode($role)])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $direct = $this->ledgerBalanceForSource($documentSourceType, $documentId, $accountCodes, $normalBalance);

        $paymentRows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join($paymentTable, "{$paymentTable}.id", '=', 'journal_entries.source_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.source_type', $paymentSourceType)
            ->where("{$paymentTable}.{$paymentDocumentColumn}", $documentId)
            ->where("{$paymentTable}.id", '<>', $currentPaymentId)
            ->whereIn('accounts.code', $accountCodes)
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as debit, COALESCE(SUM(journal_lines.credit), 0) as credit')
            ->first();

        $payments = $this->normalBalanceAmount($paymentRows, $normalBalance);

        return $this->round($direct + $payments);
    }

    private function ledgerBalanceForSource(string $sourceType, int $sourceId, array $accountCodes, string $normalBalance): float
    {
        $row = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.source_type', $sourceType)
            ->where('journal_entries.source_id', $sourceId)
            ->whereIn('accounts.code', $accountCodes)
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as debit, COALESCE(SUM(journal_lines.credit), 0) as credit')
            ->first();

        return $this->normalBalanceAmount($row, $normalBalance);
    }

    private function normalBalanceAmount(?object $row, string $normalBalance): float
    {
        $debit = (float) ($row->debit ?? 0);
        $credit = (float) ($row->credit ?? 0);

        return $normalBalance === 'credit'
            ? $this->round($credit - $debit)
            : $this->round($debit - $credit);
    }

    private function postingRoleDefaultCode(string $role): ?string
    {
        return self::postingRoleDefinitions()[$role]['default'] ?? null;
    }

    private function transactionLine(string $account, float $amount, string $side, ?string $description = null): array
    {
        $currencyCode = $this->transactionCurrencyCode();
        $foreignAmount = $this->round(abs($amount));

        return [
            'account' => $account,
            'debit' => 0.0,
            'credit' => 0.0,
            'currency_code' => $currencyCode,
            'foreign_debit' => $side === 'debit' ? $foreignAmount : 0.0,
            'foreign_credit' => $side === 'credit' ? $foreignAmount : 0.0,
            'description' => $description,
        ];
    }

    private function normalizeLines(array $lines, string $entryCurrencyCode, float $entryExchangeRate, string $baseCurrencyCode, string $postedDate): array
    {
        return collect($lines)
            ->map(function (array $line) use ($entryCurrencyCode, $entryExchangeRate, $baseCurrencyCode, $postedDate) {
                $currencyCode = $this->normalizeCurrencyCode($line['currency_code'] ?? $line['currency'] ?? $entryCurrencyCode);
                $exchangeRate = (float) ($line['exchange_rate'] ?? ($currencyCode === $entryCurrencyCode
                    ? $entryExchangeRate
                    : $this->exchangeRateForCurrency($currencyCode, $baseCurrencyCode, $postedDate)));
                $hasForeignAmounts = array_key_exists('foreign_debit', $line) || array_key_exists('foreign_credit', $line);
                $foreignDebit = $this->round((float) ($line['foreign_debit'] ?? 0));
                $foreignCredit = $this->round((float) ($line['foreign_credit'] ?? 0));

                if ($exchangeRate <= 0) {
                    throw new \RuntimeException('Exchange rate must be greater than zero.');
                }

                if ($hasForeignAmounts) {
                    $debit = $this->round($foreignDebit * $exchangeRate);
                    $credit = $this->round($foreignCredit * $exchangeRate);
                } else {
                    $debit = $this->round((float) ($line['debit'] ?? 0));
                    $credit = $this->round((float) ($line['credit'] ?? 0));
                    $foreignDebit = $currencyCode === $baseCurrencyCode ? $debit : $this->round($debit / $exchangeRate);
                    $foreignCredit = $currencyCode === $baseCurrencyCode ? $credit : $this->round($credit / $exchangeRate);
                }

                if ($debit < -0.0001 || $credit < -0.0001) {
                    throw new \RuntimeException('لا يمكن أن تكون مبالغ سطر القيد سالبة.');
                }

                if ($foreignDebit < -0.0001 || $foreignCredit < -0.0001) {
                    throw new \RuntimeException('Foreign-currency journal amounts cannot be negative.');
                }

                $line['debit'] = $debit;
                $line['credit'] = $credit;
                $line['currency_code'] = $currencyCode;
                $line['exchange_rate'] = $exchangeRate;
                $line['foreign_debit'] = $foreignDebit;
                $line['foreign_credit'] = $foreignCredit;

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

    private function expenseAccountFor(Expense $expense): string
    {
        $expense->loadMissing('category');
        $keys = array_values(array_filter([
            AccountMapping::keyForLookup($expense->category),
            $expense->category?->code ? 'code:'.$expense->category->code : null,
            $expense->expense_category_id ? 'id:'.$expense->expense_category_id : null,
            $expense->expense_category_id ? (string) $expense->expense_category_id : null,
        ]));

        foreach ($keys as $key) {
            $mapped = $this->mappedAccountCode(
                AccountMapping::CONTEXT_EXPENSE_CATEGORY,
                $key,
                '',
                ['expense'],
            );

            if ($mapped !== '') {
                return $mapped;
            }
        }

        return $this->postingAccount('operating_expenses');
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
        $fallback = match ($method) {
            'cash'                                 => $this->postingAccount('cash_account'),
            'card', 'transfer', 'bank_transfer',
            'cheque'                               => $this->postingAccount('bank_account'),
            'app'                                  => $this->postingAccount('wallet_clearing'),           // legacy
            'credit', 'credit_note'                => $this->postingAccount('customer_credit_clearing'),  // legacy
            default                                => $this->postingAccount('bank_account'),
        };

        if (! $method) {
            return $fallback;
        }

        return $this->mappedAccountCode(
            AccountMapping::CONTEXT_PAYMENT_METHOD,
            $method,
            $fallback,
            ['asset'],
        );
    }

    private function fixedAssetFundingAccount(?string $method): string
    {
        return match ($method) {
            'cash' => $this->postingAccount('cash_account'),
            'accounts_payable' => $this->postingAccount('accounts_payable'),
            'owner_capital' => $this->postingAccount('opening_balance_equity'),
            'card', 'transfer', 'bank_transfer', 'cheque', 'other' => $this->postingAccount('bank_account'),
            default => $this->postingAccount('bank_account'),
        };
    }

    private function postingAccount(string $role): string
    {
        $definition = self::postingRoleDefinitions()[$role] ?? null;
        if (! $definition) {
            throw new \RuntimeException("Unknown accounting posting role [{$role}].");
        }

        return $this->mappedAccountCode(
            AccountMapping::CONTEXT_POSTING_ROLE,
            $role,
            $definition['default'],
            $definition['types'],
        );
    }

    private function mappedAccountCode(string $context, string $key, string $fallback, array $allowedTypes): string
    {
        $this->accountMappings ??= AccountMapping::with('account')->get()
            ->keyBy(fn (AccountMapping $mapping) => $mapping->context.'|'.$mapping->key);

        $mapping = $this->accountMappings->get($context.'|'.$key);
        $account = $mapping?->account;

        if (! $account || ! $account->is_active || ! in_array($account->type, $allowedTypes, true)) {
            return $fallback;
        }

        return $account->code;
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

    private function baseCurrencyCode(): string
    {
        return $this->normalizeCurrencyCode(
            Setting::get('accounting_base_currency', Currency::base()?->code ?? MarketProfile::currency())
        );
    }

    private function transactionCurrencyCode(): string
    {
        return $this->normalizeCurrencyCode(
            Setting::get('sales_currency', config('restaurant.currency', $this->baseCurrencyCode()))
        );
    }

    private function exchangeRateForCurrency(string $currencyCode, ?string $baseCurrencyCode = null, mixed $postedOn = null): float
    {
        $currencyCode = $this->normalizeCurrencyCode($currencyCode);
        $baseCurrencyCode = $this->normalizeCurrencyCode($baseCurrencyCode ?: $this->baseCurrencyCode());

        if ($currencyCode === $baseCurrencyCode) {
            return 1.0;
        }

        return app(ExchangeRateService::class)->rateFor($currencyCode, $baseCurrencyCode, $postedOn);
    }

    private function entryCurrencyCode(array $lines, string $baseCurrencyCode): string
    {
        foreach ($lines as $line) {
            $currencyCode = $line['currency_code'] ?? $line['currency'] ?? null;
            if ($currencyCode) {
                return $this->normalizeCurrencyCode($currencyCode);
            }
        }

        return $baseCurrencyCode;
    }

    private function entryExchangeRate(array $lines, string $currencyCode, string $baseCurrencyCode, mixed $postedOn): float
    {
        if ($currencyCode === $baseCurrencyCode) {
            return 1.0;
        }

        foreach ($lines as $line) {
            $lineCurrency = $this->normalizeCurrencyCode($line['currency_code'] ?? $line['currency'] ?? $currencyCode);
            $lineRate = (float) ($line['exchange_rate'] ?? 0);

            if ($lineCurrency === $currencyCode && $lineRate > 0) {
                return $lineRate;
            }
        }

        return $this->exchangeRateForCurrency($currencyCode, $baseCurrencyCode, $postedOn);
    }

    private function normalizeCurrencyCode(mixed $code): string
    {
        $code = strtoupper(trim((string) $code));

        return $code !== '' ? $code : 'USD';
    }

    private function round(float $amount): float
    {
        return round($amount, 4);
    }
}
