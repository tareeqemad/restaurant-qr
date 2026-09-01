<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\BranchTransferItem;
use App\Models\Currency;
use App\Models\CreditNote;
use App\Models\CustomerAdvanceTransaction;
use App\Models\DebtWriteoff;
use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\IngredientBatch;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PurchaseOrderItem;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Services\ExchangeRateService;
use App\Support\MarketProfile;
use App\Support\PaymentMethods;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountingService
{
    public const CASH = '1000';

    public const BANK = '1010';

    public const PALPAY_WALLET = '1020';

    public const JAWWAL_PAY_WALLET = '1030';

    public const ACCOUNTS_RECEIVABLE = '1100';

    public const STAFF_MEAL_RECEIVABLE = '1110';

    public const INVENTORY_IN_TRANSIT = '1150';

    public const INTERBRANCH_CURRENT = '1160';

    public const INVENTORY = '1200';

    public const INPUT_VAT = '1300';

    public const FIXED_ASSETS = '1500';

    public const ACCUMULATED_DEPRECIATION = '1590';

    public const ACCOUNTS_PAYABLE = '2000';

    public const CUSTOMER_ADVANCES = '2150';

    public const OUTPUT_VAT = '2100';

    public const PAYROLL_DEDUCTIONS = '2110';

    public const TIPS_PAYABLE = '2200';

    public const GOODS_RECEIVED_NOT_INVOICED = '2300';

    public const OPENING_BALANCE_EQUITY = '3010';

    public const RETAINED_EARNINGS = '3020';

    public const SALES_REVENUE = '4000';

    public const SERVICE_REVENUE = '4010';

    public const DELIVERY_REVENUE = '4020';

    public const STAFF_MEAL_RECOVERY_REVENUE = '4030';

    public const SALES_DISCOUNTS = '4090';

    public const SALES_RETURNS = '4100';

    public const INVENTORY_ADJUSTMENT_GAIN = '4200';

    public const FOREIGN_EXCHANGE_GAIN = '4220';

    public const FIXED_ASSET_DISPOSAL_GAIN = '4230';

    public const COST_OF_GOODS_SOLD = '5000';

    public const OPERATING_EXPENSES = '5100';

    public const STAFF_MEAL_BENEFIT_EXPENSE = '5060';

    public const BAD_DEBT_EXPENSE = '5200';

    public const WASTE_EXPENSE = '5400';

    public const INVENTORY_SHRINKAGE_EXPENSE = '5410';

    public const PURCHASE_PRICE_VARIANCE = '5420';

    public const DEPRECIATION_EXPENSE = '5500';

    public const FOREIGN_EXCHANGE_LOSS = '5520';

    public const FIXED_ASSET_DISPOSAL_LOSS = '5530';

    private ?Collection $accounts = null;

    private ?Collection $accountMappings = null;

    public static function postingRoleDefinitions(): array
    {
        return [
            'cash_account' => [
                'label' => 'الصندوق الافتراضي',
                'description' => 'الحساب الافتراضي للمبالغ المقبوضة والمدفوعة نقداً عند عدم وجود ربط خاص لطريقة الدفع.',
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
            'accounts_receivable' => [
                'label' => 'الذمم المدينة',
                'description' => 'الطرف المدين عند إصدار الفواتير والطرف الدائن عند التحصيل أو الشطب.',
                'default' => self::ACCOUNTS_RECEIVABLE,
                'types' => ['asset'],
                'group' => 'الذمم',
            ],
            'staff_meal_receivable' => [
                'label' => 'مستحقات وجبات الموظفين',
                'description' => 'الجزء المتجاوز فقط من بدل الوجبة الشهري والمستحق فعلياً على الموظف.',
                'default' => self::STAFF_MEAL_RECEIVABLE,
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
            'interbranch_current' => [
                'label' => 'الحساب الجاري بين الفروع',
                'description' => 'حساب نظامي يقابل قيمة المخزون المنقول بين الفروع ويتعادل تلقائياً في العرض المجمع.',
                'default' => self::INTERBRANCH_CURRENT,
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
                'label' => 'ضريبة فاتورة المورد',
                'description' => 'ضريبة مشتريات تُدخل من فاتورة المورد إن وجدت؛ مستقلة عن فاتورة الزبون.',
                'default' => self::INPUT_VAT,
                'types' => ['asset'],
                'group' => 'الضرائب',
            ],
            'fixed_assets' => [
                'label' => 'الأصول الثابتة',
                'description' => 'معدات وأثاث وتجهيزات المطعم التي تُرسمل بدلاً من تحميلها كمصروف فوري.',
                'default' => self::FIXED_ASSETS,
                'types' => ['asset'],
                'group' => 'الأصول الثابتة',
            ],
            'accumulated_depreciation' => [
                'label' => 'مجمع الإهلاك',
                'description' => 'حساب مقابل للأصول يجمع الإهلاك المرحّل للأصول الثابتة.',
                'default' => self::ACCUMULATED_DEPRECIATION,
                'types' => ['asset'],
                'group' => 'الأصول الثابتة',
            ],
            'accounts_payable' => [
                'label' => 'الذمم الدائنة',
                'description' => 'حساب الموردين عند إنشاء فاتورة مورد وسدادها.',
                'default' => self::ACCOUNTS_PAYABLE,
                'types' => ['liability'],
                'group' => 'الذمم',
            ],
            'customer_advances' => [
                'label' => 'أرصدة الزبائن المقدمة',
                'description' => 'التزام المطعم تجاه الزبائن عن مبالغ استلمها قبل استخدامها في فاتورة.',
                'default' => self::CUSTOMER_ADVANCES,
                'types' => ['liability'],
                'group' => 'الذمم',
            ],
            'output_vat' => [
                'label' => 'ضريبة فاتورة الزبون',
                'description' => 'ضريبة اختيارية على الفاتورة الصادرة من المطعم حسب تاريخ السريان.',
                'default' => self::OUTPUT_VAT,
                'types' => ['liability'],
                'group' => 'الضرائب',
            ],
            'payroll_deductions' => [
                'label' => 'خصومات الرواتب المستحقة',
                'description' => 'الحساب المقابل عند تنفيذ خصم مستحق وجبة من راتب الموظف.',
                'default' => self::PAYROLL_DEDUCTIONS,
                'types' => ['liability'],
                'group' => 'الالتزامات',
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
            'staff_meal_recovery_revenue' => [
                'label' => 'استرداد تكلفة وجبات الموظفين',
                'description' => 'الجزء الذي يتجاوز بدل الموظف ويصبح مستحقاً عليه؛ لا يشمل الجزء الذي يتحمله المطعم.',
                'default' => self::STAFF_MEAL_RECOVERY_REVENUE,
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
            'foreign_exchange_gain' => [
                'label' => 'أرباح فروقات العملة',
                'description' => 'ربح ينتج عند تحصيل ذمة أو سداد التزام أجنبي بسعر صرف مختلف.',
                'default' => self::FOREIGN_EXCHANGE_GAIN,
                'types' => ['revenue'],
                'group' => 'العملات',
            ],
            'fixed_asset_disposal_gain' => [
                'label' => 'ربح بيع أصل ثابت',
                'description' => 'الفرق الموجب عندما تكون حصيلة بيع الأصل أعلى من قيمته الدفترية.',
                'default' => self::FIXED_ASSET_DISPOSAL_GAIN,
                'types' => ['revenue'],
                'group' => 'الأصول الثابتة',
            ],
            'cost_of_goods_sold' => [
                'label' => 'تكلفة البضاعة المباعة',
                'description' => 'تكلفة المكونات المصروفة عند بيع الأصناف.',
                'default' => self::COST_OF_GOODS_SOLD,
                'types' => ['expense'],
                'group' => 'التكاليف والمصاريف',
            ],
            'staff_meal_benefit_expense' => [
                'label' => 'تكلفة وجبات ومنافع الموظفين',
                'description' => 'التكلفة الفعلية للمكونات المصروفة في وجبات الموظفين، وفق حركة المخزون.',
                'default' => self::STAFF_MEAL_BENEFIT_EXPENSE,
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
                'label' => 'مصروف الإهلاك',
                'description' => 'مصروف الإهلاك الدوري المرحّل للأصول الثابتة.',
                'default' => self::DEPRECIATION_EXPENSE,
                'types' => ['expense'],
                'group' => 'الأصول الثابتة',
            ],
            'foreign_exchange_loss' => [
                'label' => 'خسائر فروقات العملة',
                'description' => 'خسارة تنتج عند تحصيل ذمة أو سداد التزام أجنبي بسعر صرف مختلف.',
                'default' => self::FOREIGN_EXCHANGE_LOSS,
                'types' => ['expense'],
                'group' => 'العملات',
            ],
            'fixed_asset_disposal_loss' => [
                'label' => 'خسارة بيع أصل ثابت',
                'description' => 'الفرق السالب عندما تكون حصيلة بيع الأصل أقل من قيمته الدفترية.',
                'default' => self::FIXED_ASSET_DISPOSAL_LOSS,
                'types' => ['expense'],
                'group' => 'الأصول الثابتة',
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

        $promoSavings = $invoice->promoSavings();
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

        $lines = $payment->method === PaymentMethods::CUSTOMER_ADVANCE
            ? [
                $this->debit($this->postingAccount('customer_advances'), (float) $payment->amount, 'استخدام رصيد مقدم للزبون'),
                $this->credit($this->postingAccount('accounts_receivable'), (float) $payment->amount, 'تسوية ذمم الفاتورة من الرصيد المقدم'),
            ]
            : array_merge([
                $this->debit($this->cashAccountForMethod($payment->method), (float) $payment->amount, 'تحصيل عبر '.$this->paymentMethodLabel($payment->method)),
                $this->credit($this->postingAccount('accounts_receivable'), (float) $payment->amount, 'تسوية ذمم مدينة للعملاء'),
            ], $this->receivableSettlementAdjustmentLines($payment));

        return $this->post(
            eventType: $payment->method === PaymentMethods::CUSTOMER_ADVANCE
                ? 'customer_advance_redeemed'
                : 'payment_received',
            source: $payment,
            branchId: $payment->branch_id ?: $payment->invoice?->branch_id,
            postedOn: $payment->paid_at ?: $payment->created_at ?: now(),
            description: "تحصيل دفعة على فاتورة {$payment->invoice?->number}",
            lines: $lines,
            createdBy: $payment->received_by_user_id,
        );
    }

    public function recordCustomerAdvanceDeposit(CustomerAdvanceTransaction $transaction): ?JournalEntry
    {
        $transaction->loadMissing('customer');

        return $this->post(
            eventType: 'customer_advance_deposited',
            source: $transaction,
            branchId: $transaction->branch_id,
            postedOn: $transaction->occurred_at ?: now(),
            description: 'إيداع رصيد مقدم للزبون '.$transaction->customer?->name,
            lines: [
                $this->debit($this->cashAccountForMethod($transaction->payment_method), (float) $transaction->amount, 'استلام الرصيد عبر '.$this->paymentMethodLabel($transaction->payment_method)),
                $this->credit($this->postingAccount('customer_advances'), (float) $transaction->amount, 'التزام رصيد مقدم للزبون'),
            ],
            metadata: ['customer_id' => $transaction->customer_id],
            createdBy: $transaction->created_by_user_id,
        );
    }

    public function recordCustomerAdvanceOpeningBalance(CustomerAdvanceTransaction $transaction): ?JournalEntry
    {
        $transaction->loadMissing('customer');

        return $this->post(
            eventType: 'customer_advance_opening',
            source: $transaction,
            branchId: $transaction->branch_id,
            postedOn: $transaction->occurred_at,
            description: 'رصيد مقدم افتتاحي للزبون '.$transaction->customer?->name,
            lines: [
                $this->debit($this->postingAccount('opening_balance_equity'), (float) $transaction->amount, 'مقابل الرصيد المقدم الافتتاحي'),
                $this->credit($this->postingAccount('customer_advances'), (float) $transaction->amount, 'التزام رصيد مقدم افتتاحي للزبون'),
            ],
            metadata: ['customer_id' => $transaction->customer_id],
            createdBy: $transaction->created_by_user_id,
        );
    }

    public function reverseCustomerAdvanceDeposit(
        CustomerAdvanceTransaction $deposit,
        CustomerAdvanceTransaction $reversal,
        int $userId,
        string $reason,
    ): ?JournalEntry {
        $original = JournalEntry::with('lines.account')
            ->where('source_type', $deposit::class)
            ->where('source_id', $deposit->id)
            ->where('event_type', 'customer_advance_deposited')
            ->first();

        if (! $original) {
            return null;
        }

        return $this->reverseEntry(
            original: $original,
            eventType: 'customer_advance_deposit_reversed_'.$reversal->id,
            postedOn: $reversal->occurred_at ?: now(),
            description: 'عكس إيداع رصيد مقدم — '.$reason,
            metadata: ['reversal_transaction_id' => $reversal->id],
            createdBy: $userId,
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

    public function recordDebtWriteoff(DebtWriteoff $writeoff): ?JournalEntry
    {
        return $this->post(
            eventType: 'debt_writeoff_posted',
            source: $writeoff,
            branchId: $writeoff->branch_id,
            postedOn: $writeoff->written_off_at ?: now(),
            description: "شطب دين {$writeoff->number} على فاتورة {$writeoff->invoice?->number}",
            lines: [
                $this->debit($this->postingAccount('bad_debt_expense'), (float) $writeoff->amount, 'مصروف ديون معدومة'),
                $this->credit($this->postingAccount('accounts_receivable'), (float) $writeoff->amount, 'تخفيض ذمم مدينة بالشطب'),
            ],
            metadata: ['reason' => $writeoff->reason],
            createdBy: $writeoff->written_off_by,
        );
    }

    public function reverseDebtWriteoff(DebtWriteoff $writeoff, int $userId, string $reason): ?JournalEntry
    {
        return $this->reverse(
            eventType: 'debt_writeoff_reversed',
            source: $writeoff,
            originalEventType: 'debt_writeoff_posted',
            postedOn: now(),
            description: "عكس شطب دين {$writeoff->number}",
            metadata: ['reason' => $reason],
            createdBy: $userId,
        );
    }

    public function recordRefundCompleted(Refund $refund): ?JournalEntry
    {
        $refund->loadMissing('invoice', 'allocations');
        $invoice = $refund->invoice;
        $amount = (float) $refund->amount;

        // The credit note has already reduced revenue/VAT and credited A/R.
        // This second document only settles that customer credit through the
        // actual payout channels, preserving a clean invoice sub-ledger.
        $lines = [
            $this->debit($this->postingAccount('accounts_receivable'), $amount, 'تسوية رصيد الإشعار الدائن للعميل'),
        ];
        $allocations = $refund->allocations;
        if ($allocations->isEmpty()) {
            $allocations = collect([(object) ['method' => $refund->method, 'amount' => $amount]]);
        }
        foreach ($allocations as $allocation) {
            $method = (string) $allocation->method;
            $allocationAmount = (float) $allocation->amount;
            $account = $method === PaymentMethods::CUSTOMER_ADVANCE
                ? $this->postingAccount('customer_advances')
                : $this->cashAccountForMethod($method);
            $lines[] = $this->credit($account, $allocationAmount, 'صرف استرداد عبر '.$this->paymentMethodLabel($method));
        }

        return $this->post(
            eventType: 'refund_completed',
            source: $refund,
            branchId: $refund->branch_id ?: $invoice?->branch_id,
            postedOn: $refund->refunded_at ?: $refund->updated_at ?: now(),
            description: "استرداد {$refund->number} على فاتورة {$invoice?->number}",
            lines: $lines,
            createdBy: $refund->processed_by,
        );
    }

    public function recordCreditNoteIssued(CreditNote $creditNote): ?JournalEntry
    {
        $lines = [
            $this->debit($this->postingAccount('sales_returns'), (float) $creditNote->revenue_total, 'مردودات ومسموحات مبيعات'),
        ];
        if ((float) $creditNote->tax_total > 0.0001) {
            $lines[] = $this->debit($this->postingAccount('output_vat'), (float) $creditNote->tax_total, 'عكس ضريبة مخرجات بإشعار دائن');
        }
        if ((float) $creditNote->service_total > 0.0001) {
            $lines[] = $this->debit($this->postingAccount('service_revenue'), (float) $creditNote->service_total, 'عكس رسوم خدمة بإشعار دائن');
        }
        if ((float) $creditNote->delivery_total > 0.0001) {
            $lines[] = $this->debit($this->postingAccount('delivery_revenue'), (float) $creditNote->delivery_total, 'عكس رسوم توصيل بإشعار دائن');
        }
        if ((float) $creditNote->tip_total > 0.0001) {
            $lines[] = $this->debit($this->postingAccount('tips_payable'), (float) $creditNote->tip_total, 'عكس إكرامية بإشعار دائن');
        }
        $lines[] = $this->credit($this->postingAccount('accounts_receivable'), (float) $creditNote->total, 'تخفيض ذمة العميل بالإشعار الدائن');

        return $this->post(
            eventType: 'credit_note_issued',
            source: $creditNote,
            branchId: $creditNote->branch_id,
            postedOn: $creditNote->issued_at ?: now(),
            description: "إشعار دائن {$creditNote->number} على فاتورة {$creditNote->invoice?->number}",
            lines: $lines,
            metadata: ['kind' => $creditNote->kind, 'reason' => $creditNote->reason],
            createdBy: $creditNote->issued_by,
        );
    }

    public function reverseCreditNoteIssued(CreditNote $creditNote, int $userId, string $reason): ?JournalEntry
    {
        return $this->reverse(
            eventType: 'credit_note_reversed',
            source: $creditNote,
            originalEventType: 'credit_note_issued',
            postedOn: now(),
            description: "عكس إشعار دائن {$creditNote->number}",
            metadata: ['reason' => $reason],
            createdBy: $userId,
        );
    }

    public function reverseRefundCompleted(Refund $refund, int $userId, string $reason): ?JournalEntry
    {
        return $this->reverse(
            eventType: 'refund_reversed',
            source: $refund,
            originalEventType: 'refund_completed',
            postedOn: now(),
            description: "عكس صرف استرداد {$refund->number}",
            metadata: ['reason' => $reason],
            createdBy: $userId,
        );
    }

    public function recordExpenseApproved(Expense $expense): ?JournalEntry
    {
        $currencyCode = $this->normalizeCurrencyCode($expense->currency_code ?: $this->baseCurrencyCode());
        $exchangeRate = (float) ($expense->exchange_rate ?: 1);

        return $this->post(
            eventType: 'expense_approved',
            source: $expense,
            branchId: $expense->branch_id,
            postedOn: $expense->expense_date ?: now(),
            description: "اعتماد مصروف {$expense->expense_number}",
            lines: [
                $this->currencyDebit($this->expenseAccountFor($expense), (float) $expense->amount, $currencyCode, $exchangeRate, $expense->description),
                $this->currencyCredit($this->cashAccountForMethod($expense->payment_method), (float) $expense->amount, $currencyCode, $exchangeRate, 'سداد عبر '.$this->paymentMethodLabel($expense->payment_method)),
            ],
            createdBy: $expense->approved_by_user_id,
            currencyCode: $currencyCode,
            exchangeRate: $exchangeRate,
        );
    }

    public function recordSupplierInvoiceCreated(SupplierInvoice $invoice): ?JournalEntry
    {
        $invoice->loadMissing('items', 'purchaseOrder');

        $currencyCode = $this->normalizeCurrencyCode($invoice->currency_code ?: $this->transactionCurrencyCode());
        $exchangeRate = $invoice->currency_code
            ? (float) ($invoice->exchange_rate ?: 1)
            : $this->exchangeRateForCurrency($currencyCode, $this->baseCurrencyCode(), $invoice->invoice_date);

        $poItems = $invoice->items->whereNotNull('purchase_order_item_id');
        $poReceivedBaseSubtotal = (float) $poItems->sum(fn ($item) => (float) (
            $item->received_base_total
            ?? ((float) ($item->received_total ?? $item->subtotal) * (float) ($invoice->purchaseOrder?->exchange_rate ?: $exchangeRate))
        ));
        $poInvoiceBaseSubtotal = (float) $poItems->sum(fn ($item) => (float) $item->subtotal * $exchangeRate);
        $priceVariance = $this->round($poInvoiceBaseSubtotal - $poReceivedBaseSubtotal);

        $inventorySubtotal = (float) $invoice->items
            ->whereNull('purchase_order_item_id')
            ->whereNotNull('ingredient_id')
            ->sum('subtotal');
        $expenseSubtotal = $invoice->items->isNotEmpty()
            ? (float) $invoice->items->whereNull('ingredient_id')->sum('subtotal')
            : (float) $invoice->subtotal;

        $lines = [
            $this->baseDebit($this->postingAccount('grni'), $poReceivedBaseSubtotal, 'تسوية استلامات مخزون غير مفوترة'),
            $priceVariance > 0
                ? $this->baseDebit($this->postingAccount('purchase_price_variance'), abs($priceVariance), 'فرق سعر شراء/صرف مدينة')
                : $this->baseCredit($this->postingAccount('purchase_price_variance'), abs($priceVariance), 'فرق سعر شراء/صرف دائنة'),
            $this->currencyDebit($this->postingAccount('inventory'), $inventorySubtotal, $currencyCode, $exchangeRate, 'إضافة مشتريات مخزنية من فاتورة مورد'),
            $this->currencyDebit($this->postingAccount('operating_expenses'), $expenseSubtotal, $currencyCode, $exchangeRate, 'مصروفات غير مخزنية من فاتورة مورد'),
            $this->currencyDebit($this->postingAccount('input_vat'), (float) $invoice->tax_total, $currencyCode, $exchangeRate, 'ضريبة قيمة مضافة - مدخلات'),
            $this->currencyCredit($this->postingAccount('accounts_payable'), (float) $invoice->total, $currencyCode, $exchangeRate, 'إثبات ذمم دائنة للمورد'),
        ];

        return $this->post(
            eventType: 'supplier_invoice_created',
            source: $invoice,
            branchId: $invoice->branch_id,
            postedOn: $invoice->invoice_date ?: $invoice->created_at ?: now(),
            description: "إثبات فاتورة مورد {$invoice->number}",
            lines: $lines,
            createdBy: $invoice->created_by,
            currencyCode: $currencyCode,
            exchangeRate: $exchangeRate,
        );
    }

    public function recordCustomerOpeningDebt(Invoice $invoice, ?int $userId = null): ?JournalEntry
    {
        $amount = (float) $invoice->total;

        return $this->post(
            eventType: 'customer_opening_debt',
            source: $invoice,
            branchId: $invoice->branch_id,
            postedOn: $invoice->issued_at ?: now(),
            description: "رصيد افتتاحي على الزبون {$invoice->customer_name}",
            lines: [
                $this->baseDebit($this->postingAccount('accounts_receivable'), $amount, 'ذمة عميل افتتاحية'),
                $this->baseCredit($this->postingAccount('opening_balance_equity'), $amount, 'مقابل الرصيد الافتتاحي'),
            ],
            metadata: ['opening_balance' => true, 'customer_id' => $invoice->customer_id],
            createdBy: $userId,
        );
    }

    public function recordSupplierOpeningDebt(SupplierInvoice $invoice, ?int $userId = null): ?JournalEntry
    {
        $currencyCode = $this->normalizeCurrencyCode($invoice->currency_code ?: $this->baseCurrencyCode());
        $exchangeRate = (float) ($invoice->exchange_rate ?: 1);
        $amount = (float) $invoice->total;

        return $this->post(
            eventType: 'supplier_opening_debt',
            source: $invoice,
            branchId: $invoice->branch_id,
            postedOn: $invoice->invoice_date ?: now(),
            description: "رصيد افتتاحي لمورد {$invoice->supplier?->name}",
            lines: [
                $this->currencyDebit($this->postingAccount('opening_balance_equity'), $amount, $currencyCode, $exchangeRate, 'مقابل الرصيد الافتتاحي'),
                $this->currencyCredit($this->postingAccount('accounts_payable'), $amount, $currencyCode, $exchangeRate, 'ذمة مورد افتتاحية'),
            ],
            metadata: ['opening_balance' => true, 'supplier_id' => $invoice->supplier_id],
            createdBy: $userId,
            currencyCode: $currencyCode,
            exchangeRate: $exchangeRate,
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

        $currencyCode = $this->normalizeCurrencyCode($payment->currency_code ?: $payment->invoice?->currency_code ?: $this->transactionCurrencyCode());
        $exchangeRate = $payment->currency_code
            ? (float) ($payment->exchange_rate ?: 1)
            : $this->exchangeRateForCurrency($currencyCode, $this->baseCurrencyCode(), $payment->paid_on);

        return $this->post(
            eventType: 'supplier_payment_recorded',
            source: $payment,
            branchId: $payment->invoice?->branch_id,
            postedOn: $payment->paid_on ?: $payment->created_at ?: now(),
            description: "سداد فاتورة مورد {$payment->invoice?->number}",
            lines: array_merge([
                $this->currencyDebit($this->postingAccount('accounts_payable'), (float) $payment->amount, $currencyCode, $exchangeRate, 'تسوية ذمم دائنة للمورد'),
                $this->currencyCredit($this->cashAccountForMethod($payment->method), (float) $payment->amount, $currencyCode, $exchangeRate, 'سداد عبر '.$this->paymentMethodLabel($payment->method)),
            ], $this->payableSettlementAdjustmentLines($payment)),
            createdBy: $payment->paid_by,
            currencyCode: $currencyCode,
            exchangeRate: $exchangeRate,
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

    public function paymentAccountCode(string $paymentMethod): string
    {
        return $this->cashAccountForMethod($paymentMethod);
    }

    public function availableWalletBalance(string $walletMethod, ?int $branchId, mixed $asOf): float
    {
        if (! in_array($walletMethod, ['palpay', 'jawwal_pay'], true)) {
            throw new \RuntimeException('طريقة المحفظة غير صالحة.');
        }

        $accountCode = $this->cashAccountForMethod($walletMethod);
        $account = Account::query()->where('code', $accountCode)->first();
        if (! $account) {
            return 0.0;
        }
        $totals = JournalLine::query()
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as debit_total, COALESCE(SUM(journal_lines.credit), 0) as credit_total')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->whereDate('journal_entries.posted_on', '<=', Carbon::parse($asOf)->toDateString())
            ->when($branchId, fn ($query) => $query->where('journal_lines.branch_id', $branchId))
            ->first();

        return $this->round(max(0, (float) $totals->debit_total - (float) $totals->credit_total));
    }

    public function recordWalletTransfer(string $walletMethod, float $amount, ?int $branchId, mixed $postedOn, ?int $createdBy = null, ?string $notes = null): ?JournalEntry
    {
        if (! in_array($walletMethod, ['palpay', 'jawwal_pay'], true)) {
            throw new \RuntimeException('طريقة المحفظة غير صالحة للتحويل إلى البنك.');
        }

        $amount = $this->round($amount);
        if ($amount <= 0) {
            throw new \RuntimeException('مبلغ تحويل المحفظة يجب أن يكون أكبر من صفر.');
        }

        $walletAccount = $this->cashAccountForMethod($walletMethod);
        $bankAccount = $this->postingAccount('bank_account');
        if ($walletAccount === $bankAccount) {
            throw new \RuntimeException('المحفظة مرتبطة بحساب البنك نفسه؛ لا يوجد رصيد مستقل يحتاج إلى تحويل.');
        }

        return DB::transaction(function () use ($walletMethod, $walletAccount, $bankAccount, $amount, $branchId, $postedOn, $createdBy, $notes) {
            // Serializing transfers on the wallet account prevents two accountant
            // tabs from spending the same ledger balance at the same time.
            $account = Account::query()->where('code', $walletAccount)->lockForUpdate()->first();
            if (! $account) {
                throw new \RuntimeException('حساب المحفظة غير مهيأ في شجرة الحسابات. راجع ربط العمليات أو بيانات التأسيس.');
            }
            $available = $this->availableWalletBalance($walletMethod, $branchId, $postedOn);
            if ($amount > $available + 0.0001) {
                throw new \RuntimeException('مبلغ التحويل أكبر من رصيد المحفظة المتاح.');
            }

            $walletLabel = $this->paymentMethodLabel($walletMethod);

            return $this->post(
                eventType: 'wallet_to_bank',
                source: null,
                branchId: $branchId,
                postedOn: $postedOn,
                description: 'تحويل '.$walletLabel.' إلى البنك',
                lines: [
                    $this->baseDebit($bankAccount, $amount, 'إيداع رصيد المحفظة في البنك'),
                    $this->baseCredit($walletAccount, $amount, 'تحويل رصيد '.$walletLabel),
                ],
                metadata: array_filter([
                    'wallet_method' => $walletMethod,
                    'notes' => $notes,
                ]),
                createdBy: $createdBy,
            );
        });
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
                    $this->assertPostingPeriodOpen($postedOn, $branchId, $eventType);

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

    /** System closing/reversal entries — exempt from the period lock (see below). */
    private const CLOSING_EVENT_TYPES = [
        'period_closing', 'fiscal_year_closing',
        'period_closing_reversal', 'fiscal_year_closing_reversal',
    ];

    private function assertPostingPeriodOpen($postedOn, ?int $branchId, ?string $eventType = null): void
    {
        // The closing machinery must be able to post AT period/year boundaries
        // even when a period/year is (or is being) closed — otherwise a
        // fiscal-year close dated Dec 31 is rejected by an already-closed
        // December period, and reopening is rejected by the closed year.
        // Operational postings stay locked; only these system entries pass.
        if ($eventType !== null && in_array($eventType, self::CLOSING_EVENT_TYPES, true)) {
            return;
        }

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
            if ($this->isStaffMealMovement($movement)) {
                return $this->postInventoryMovement(
                    'inventory_staff_meal_consumed',
                    $movement,
                    $description,
                    [
                        $this->debit($this->postingAccount('staff_meal_benefit_expense'), $amount, 'تكلفة فعلية لوجبة موظف'),
                        $this->credit($this->postingAccount('inventory'), $amount, 'صرف مكونات لوجبة موظف'),
                    ],
                );
            }

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
            if ($this->isStaffMealMovement($movement)) {
                return $this->postInventoryMovement(
                    'inventory_staff_meal_reversed',
                    $movement,
                    $description,
                    [
                        $this->debit($this->postingAccount('inventory'), $amount, 'إرجاع مكونات وجبة موظف إلى المخزون'),
                        $this->credit($this->postingAccount('staff_meal_benefit_expense'), $amount, 'عكس تكلفة وجبة موظف'),
                    ],
                );
            }

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
        if ($amount <= 0.0001) {
            return null;
        }

        $sourceExpense = $this->isStaffMealMovement($movement)
            ? $this->postingAccount('staff_meal_benefit_expense')
            : $this->postingAccount('cost_of_goods_sold');

        return $this->post(
            eventType: 'inventory_waste_reclassified',
            source: $movement,
            branchId: $movement->branch_id,
            postedOn: $movement->occurred_at ?: now(),
            description: $description,
            lines: [
                $this->debit($this->postingAccount('waste_expense'), $amount, 'إعادة تصنيف تكلفة البيع كهدر'),
                $this->credit($sourceExpense, $amount, 'عكس تصنيف التكلفة الأصلي قبل إثبات الهدر'),
            ],
            createdBy: $movement->user_id,
        );
    }

    private function isStaffMealMovement(InventoryMovement $movement): bool
    {
        if ($movement->reference_type !== OrderItem::class || ! $movement->reference instanceof OrderItem) {
            return false;
        }

        $movement->reference->loadMissing('order:id,staff_consumer_employee_id,staff_consumer_user_id');

        return (bool) ($movement->reference->order?->staff_consumer_employee_id
            || $movement->reference->order?->staff_consumer_user_id);
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
            $item = BranchTransferItem::with('transfer')->find($movement->reference_id);
            $sourceBranchId = $item?->transfer?->from_branch_id;

            // Close the source branch's in-transit asset, then recognize the
            // destination stock against the reciprocal current account. The
            // current account nets to zero in the consolidated restaurant view,
            // while each branch keeps a truthful standalone balance sheet.
            if ($sourceBranchId) {
                $this->post(
                    eventType: 'inventory_transfer_source_closed',
                    source: $movement,
                    branchId: (int) $sourceBranchId,
                    postedOn: $movement->occurred_at ?: now(),
                    description: 'إغلاق مخزون محول بعد تأكيد الاستلام',
                    lines: [
                        $this->debit($this->postingAccount('interbranch_current'), $amount, 'رصيد جاري على فرع الوجهة'),
                        $this->credit($this->postingAccount('inventory_in_transit'), $amount, 'إغلاق مخزون بالطريق'),
                    ],
                    createdBy: $movement->user_id,
                );
            }

            return $this->postInventoryMovement(
                'inventory_transfer_received',
                $movement,
                $description,
                [
                    $this->debit($this->postingAccount('inventory'), $amount, 'استلام مخزون من فرع آخر'),
                    $this->credit($this->postingAccount('interbranch_current'), $amount, 'رصيد جاري لصالح فرع المصدر'),
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
        $currencyCode = $this->normalizeCurrencyCode($payment->currency_code ?: $payment->invoice->currency_code ?: $this->transactionCurrencyCode());
        if ($currencyCode === $baseCurrencyCode) {
            return [];
        }

        $postedDate = Carbon::parse($payment->paid_on ?: $payment->created_at ?: now())->toDateString();
        $currentRate = $payment->currency_code
            ? (float) ($payment->exchange_rate ?: 1)
            : $this->exchangeRateForCurrency($currencyCode, $baseCurrencyCode, $postedDate);
        $currentBase = $this->round((float) $payment->amount * $currentRate);
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
        // been administratively deactivated still post correctly, and the trial balance
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
        // Bank transfer and Visa settle directly. Each wallet remains a real
        // asset until the accountant records its later transfer to the bank.
        $fallback = match ($method) {
            'cash' => $this->postingAccount('cash_account'),
            'palpay' => self::PALPAY_WALLET,
            'jawwal_pay' => self::JAWWAL_PAY_WALLET,
            'transfer', 'bank_transfer', 'card', 'app', 'credit',
            'credit_note', 'cheque' => $this->postingAccount('bank_account'),
            default => $this->postingAccount('bank_account'),
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

    public function accountForPostingRole(string $role): string
    {
        return $this->postingAccount($role);
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
            'palpay' => 'محفظة PalPay',
            'jawwal_pay' => 'محفظة Jawwal Pay',
            'cheque' => 'شيك',
            'app' => 'محفظة إلكترونية',           // legacy
            'credit' => 'بيع آجل',                 // legacy
            'customer_advance' => 'رصيد مقدم للزبون',
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
