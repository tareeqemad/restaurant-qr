<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\ActivityLog;
use App\Models\CashReconciliation;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerSalesTaxRate;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Lookup;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\TableSession;
use App\Services\Accounting\AccountingService;
use App\Services\CustomerAdvanceService;
use App\Services\ExchangeRateService;
use App\Support\AccountingGuide;
use App\Support\AccountingWorkspace;
use App\Support\AdminShell;
use App\Support\BranchContext;
use App\Support\MarketProfile;
use App\Support\PaymentMethods;
use App\Support\TaxConfiguration;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AccountingController extends Controller
{
    /**
     * One calm entry point for the accountant. Daily operations post their
     * own journal entries; this page only exposes the handful of review and
     * month-end tasks that a human normally needs.
     */
    public function index()
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.viewAny'), 403);

        $canCreate = auth()->user()->hasPermission('chart_of_accounts.create');
        $canUpdate = auth()->user()->hasPermission('chart_of_accounts.update');
        $today = now()->toDateString();
        $eventLabels = $this->eventLabels();
        $currentPeriod = $this->periodForDate($today);

        $latestEntries = JournalEntry::query()
            ->with(['lines:id,journal_entry_id,debit,credit'])
            ->orderByDesc('posted_on')
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(fn (JournalEntry $entry) => [
                'id' => $entry->id,
                'number' => $entry->entry_no,
                'date' => $entry->posted_on?->toDateString(),
                'description' => $entry->description,
                'eventType' => $entry->event_type,
                'eventLabel' => $eventLabels[$entry->event_type] ?? Str::headline($entry->event_type),
                'amount' => round((float) $entry->lines->sum('debit'), 2),
                'adjustUrl' => $canCreate ? route('admin.accounting.journal.adjust.create', $entry) : null,
            ])
            ->values();

        $taxSchedules = TaxConfiguration::schedules();
        $nextSchedule = TaxConfiguration::nextSchedule();
        $baseCurrencyCode = $this->accountingBaseCurrencyCode();
        $baseCurrency = Currency::query()->where('code', $baseCurrencyCode)->first();
        $attention = $this->accountingAttention($today, $currentPeriod);

        return AdminShell::render('Admin/Accounting/Index', [
            'can' => [
                'create' => $canCreate,
                'update' => $canUpdate,
            ],
            'baseCurrency' => [
                'code' => $baseCurrencyCode,
                'symbol' => $baseCurrency?->symbol ?: $baseCurrencyCode,
            ],
            'today' => $today,
            'health' => [
                'currentPeriod' => $currentPeriod ? [
                    'id' => $currentPeriod->id,
                    'name' => $currentPeriod->name,
                    'startsOn' => $currentPeriod->starts_on?->toDateString(),
                    'endsOn' => $currentPeriod->ends_on?->toDateString(),
                    'status' => $currentPeriod->status,
                ] : null,
                'journalEntries' => JournalEntry::query()->count(),
                'customerDebt' => round((float) Invoice::query()->where('balance', '>', 0)->sum('balance'), 2),
                'supplierDebt' => round((float) SupplierInvoice::query()->where('balance', '>', 0)->sum('balance'), 2),
                'accounts' => Account::query()->where('is_active', true)->count(),
            ],
            'attention' => $attention,
            'latestEntries' => $latestEntries,
            'tax' => [
                'switchOn' => TaxConfiguration::switchIsOn(),
                'activeToday' => TaxConfiguration::isEnabled(),
                'rate' => (float) TaxConfiguration::rate(),
                'effectiveFrom' => TaxConfiguration::effectiveFrom(),
                'hasHistory' => TaxConfiguration::hasHistory(),
                'nextSchedule' => $nextSchedule ? [
                    'date' => $nextSchedule->effective_from?->toDateString(),
                    'enabled' => (bool) $nextSchedule->enabled,
                    'rate' => (float) $nextSchedule->rate,
                ] : null,
                'schedules' => $taxSchedules->map(fn (CustomerSalesTaxRate $rate) => [
                    'id' => $rate->id,
                    'date' => $rate->effective_from?->toDateString(),
                    'enabled' => (bool) $rate->enabled,
                    'rate' => (float) $rate->rate,
                    'isFuture' => $rate->effective_from?->isFuture() ?? false,
                    'destroyUrl' => route('admin.accounting.tax-configuration.destroy', $rate),
                ])->values(),
            ],
            'urls' => $this->accountingWorkspaceUrls() + [
                'taxStore' => route('admin.accounting.tax-configuration.store'),
            ],
        ]);
    }

    /**
     * Accountant-owned schedule for tax on customer invoices issued by the
     * restaurant. Supplier invoice tax is configured on the supplier document.
     */
    public function storeTaxConfiguration(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $data = $request->validate([
            'tax_enabled' => ['required', 'boolean'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'tax_effective_from' => ['nullable', 'date'],
        ]);

        $enabled = $request->boolean('tax_enabled');
        $rate = round((float) $data['tax_rate'], 4);
        if ($enabled && $rate <= 0) {
            return back()->withInput()->with('error', 'أدخل نسبة ضريبة أكبر من صفر عند التفعيل.');
        }

        $effectiveFrom = Carbon::parse($data['tax_effective_from'] ?: now())->toDateString();

        DB::transaction(function () use ($enabled, $rate, $effectiveFrom) {
            CustomerSalesTaxRate::updateOrCreate(
                ['effective_from' => $effectiveFrom],
                [
                    'enabled' => $enabled,
                    'rate' => $enabled ? $rate : 0,
                    'created_by' => auth()->id(),
                ],
            );

            // Keep legacy settings in sync only when the change is already in
            // force. Future schedules must not alter today's invoices early.
            if (Carbon::parse($effectiveFrom)->startOfDay()->lessThanOrEqualTo(now()->startOfDay())) {
                Setting::put('tax_enabled', $enabled, 'billing', 'bool');
                Setting::put('tax_rate', $enabled ? $rate : 0, 'billing', 'float');
                Setting::put('tax_effective_from', $effectiveFrom, 'billing', 'string');
            }
        });

        ActivityLog::log(
            $enabled ? 'accounting.customer_tax_scheduled' : 'accounting.customer_tax_disabled',
            $enabled
                ? "ضريبة فاتورة الزبون بنسبة {$rate}% اعتبارًا من {$effectiveFrom}"
                : "إيقاف ضريبة فاتورة الزبون اعتبارًا من {$effectiveFrom}",
            null,
            ['enabled' => $enabled, 'rate' => $rate, 'effective_from' => $effectiveFrom],
        );

        return back()->with('success', $enabled
            ? "تم حفظ نسبة فاتورة الزبون {$rate}% اعتبارًا من {$effectiveFrom}. الفواتير السابقة لن تتغير."
            : "تمت جدولة إيقاف ضريبة فاتورة الزبون اعتبارًا من {$effectiveFrom}. فواتير الموردين لا تتأثر.");
    }

    public function destroyCustomerSalesTaxRate(CustomerSalesTaxRate $taxRate)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        if ($taxRate->effective_from->startOfDay()->lessThanOrEqualTo(now()->startOfDay())) {
            return back()->with('error', 'لا يمكن حذف فترة بدأت فعليًا؛ أضف حالة جديدة بتاريخ سريان لتبقى المراجعة محفوظة.');
        }

        ActivityLog::log(
            'accounting.customer_tax_schedule_deleted',
            "حذف تغيير ضريبة فاتورة الزبون المجدول بتاريخ {$taxRate->effective_from->toDateString()}",
            $taxRate,
            ['enabled' => $taxRate->enabled, 'rate' => (float) $taxRate->rate],
        );
        $taxRate->delete();

        return back()->with('success', 'تم حذف التغيير المستقبلي دون التأثير على الفواتير أو الفترات السابقة.');
    }

    public function guide()
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.viewAny'), 403);

        $taxEnabled = TaxConfiguration::isEnabled();
        $mappings = AccountMapping::with('account')
            ->where('context', AccountMapping::CONTEXT_POSTING_ROLE)
            ->get()
            ->keyBy('key');
        $accountsByCode = Account::query()->orderBy('code')->get()->keyBy('code');
        $postingRoleDefinitions = collect(AccountingService::postingRoleDefinitions())
            ->when(! $taxEnabled, fn (Collection $roles) => $roles->except(['output_vat']));
        $resolvedRoles = $postingRoleDefinitions
            ->map(function (array $definition, string $key) use ($mappings, $accountsByCode) {
                $definition['key'] = $key;
                $definition['account'] = $mappings->get($key)?->account
                    ?: $accountsByCode->get($definition['default']);
                $definition['is_custom'] = $mappings->has($key);

                return $definition;
            });
        $postingRoles = $resolvedRoles->groupBy('group');
        $serializeAccount = static fn (?Account $account) => $account ? [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
        ] : null;
        $decorateLine = static function (array $line) use ($resolvedRoles, $serializeAccount): array {
            $role = $line['role'] ?? null;
            $definition = $role ? $resolvedRoles->get($role) : null;

            return $line + [
                'account' => $serializeAccount($definition['account'] ?? null),
            ];
        };
        $postingGroups = collect(AccountingGuide::postingGroups($taxEnabled))
            ->map(function (array $group) use ($decorateLine) {
                $group['entries'] = collect($group['entries'])
                    ->map(function (array $entry) use ($decorateLine) {
                        $entry['debits'] = collect($entry['debits'])->map($decorateLine)->values()->all();
                        $entry['credits'] = collect($entry['credits'])->map($decorateLine)->values()->all();
                        $entry['journalUrl'] = route('admin.accounting.journal', ['event_type' => $entry['eventType']]);

                        return $entry;
                    })
                    ->values()
                    ->all();

                return $group;
            })
            ->values();

        $accounting = app(AccountingService::class);
        $paymentIdentifiers = $this->paymentIdentifierValues();
        $bankDestination = collect([
            $paymentIdentifiers['bank_name'],
            $paymentIdentifiers['bank_account_number'],
            $paymentIdentifiers['bank_iban'],
        ])->filter()->implode(' · ');
        $paymentPaths = collect(PaymentMethods::catalog())
            ->map(function (array $method, string $code) use ($accounting, $accountsByCode, $serializeAccount, $bankDestination, $paymentIdentifiers) {
                $accountCode = $accounting->paymentAccountCode($code);

                return [
                    'code' => $code,
                    'label' => $method['label'],
                    'description' => $method['description'],
                    'icon' => $method['icon'],
                    'enabled' => (bool) $method['enabled'],
                    'account' => $serializeAccount($accountsByCode->get($accountCode)),
                    'destination' => match ($code) {
                        'transfer', 'card' => $bankDestination ?: null,
                        'palpay' => $paymentIdentifiers['palpay_wallet_number'] ?: null,
                        'jawwal_pay' => $paymentIdentifiers['jawwal_pay_wallet_number'] ?: null,
                        default => null,
                    },
                ];
            })
            ->values();

        $today = now()->toDateString();
        $branchId = BranchContext::current();
        $currentYear = FiscalYear::query()
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('ends_on', '>=', $today)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');
                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->orderByRaw('branch_id is null')
            ->first();
        $currentPeriod = $this->periodForDate($today);
        $journalEntries = JournalEntry::query()->count();
        $openingEntries = JournalEntry::query()
            ->whereIn('event_type', ['opening_balance', 'customer_opening_debt', 'supplier_opening_debt', 'customer_advance_opening'])
            ->count();
        $reconciliations = CashReconciliation::query()->count();
        $missingMappings = $resolvedRoles->whereNull('account')->count();
        $urls = $this->accountingWorkspaceUrls();
        $workflow = [
            [
                'key' => 'periods',
                'number' => 1,
                'title' => 'السنة والشهور المالية',
                'description' => 'ابدأ بسنة ميلادية وشهور مرتبطة بالفرع قبل إدخال حركة.',
                'status' => $currentYear && $currentPeriod ? 'ready' : 'action',
                'statusLabel' => $currentYear && $currentPeriod ? $currentPeriod->name : 'تحتاج إعداداً',
                'url' => $urls['fiscalYears'] ?? $urls['periods'] ?? null,
                'icon' => 'bi-calendar2-week',
            ],
            [
                'key' => 'chart',
                'number' => 2,
                'title' => 'الشجرة وربط العمليات',
                'description' => 'راجع الحسابات النظامية وحدد أين تذهب العمليات الجديدة.',
                'status' => $missingMappings === 0 ? 'ready' : 'warning',
                'statusLabel' => $missingMappings === 0 ? 'الربط مكتمل' : $missingMappings.' حساب غير مربوط',
                'url' => $urls['mappings'] ?? $urls['accounts'],
                'icon' => 'bi-diagram-3',
            ],
            [
                'key' => 'opening',
                'number' => 3,
                'title' => 'الرصيد الافتتاحي',
                'description' => 'أدخل أرصدة الميزانية والذمم مرة واحدة عند بداية العمل.',
                'status' => $openingEntries > 0 ? 'ready' : ($journalEntries > 0 ? 'warning' : 'action'),
                'statusLabel' => $openingEntries > 0 ? $openingEntries.' مستند افتتاح' : ($journalEntries > 0 ? 'راجع قبل الإدخال' : 'لم يُدخل بعد'),
                'url' => $urls['openingBalances'] ?? null,
                'icon' => 'bi-door-open',
            ],
            [
                'key' => 'daily',
                'number' => 4,
                'title' => 'الترحيل اليومي التلقائي',
                'description' => 'المبيعات والمخزون والموردون والمصاريف ترحّل من مستنداتها.',
                'status' => $journalEntries > 0 ? 'ready' : 'current',
                'statusLabel' => $journalEntries > 0 ? $journalEntries.' قيداً' : 'يبدأ مع التشغيل',
                'url' => $urls['journal'],
                'icon' => 'bi-lightning-charge',
            ],
            [
                'key' => 'review',
                'number' => 5,
                'title' => 'المراجعة والمطابقة',
                'description' => 'راجع الميزان وطابق الصندوق والبنك والمحافظ والذمم.',
                'status' => $reconciliations > 0 ? 'ready' : 'current',
                'statusLabel' => $reconciliations > 0 ? $reconciliations.' مطابقة' : 'دورية',
                'url' => $urls['trialBalance'],
                'icon' => 'bi-check2-square',
            ],
            [
                'key' => 'close',
                'number' => 6,
                'title' => 'الإقفال والقوائم المالية',
                'description' => 'أقفل الشهر بعد المراجعة، والسنة فقط بعد إقفال شهورها.',
                'status' => $currentPeriod?->isClosed() ? 'ready' : 'current',
                'statusLabel' => $currentPeriod?->isClosed() ? 'الفترة مقفلة' : 'بعد نهاية الفترة',
                'url' => $urls['periods'] ?? $urls['profitLoss'],
                'icon' => 'bi-lock',
            ],
        ];

        return AdminShell::render('Admin/Accounting/Guide', [
            'postingRoles' => $postingRoles->map(fn (Collection $roles) => $roles->map(fn (array $definition) => [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'description' => $definition['description'],
                'defaultCode' => $definition['default'],
                'isCustom' => (bool) $definition['is_custom'],
                'account' => $definition['account'] ? [
                    'id' => $definition['account']->id,
                    'code' => $definition['account']->code,
                    'name' => $definition['account']->name,
                ] : null,
            ])->values())->toArray(),
            'postingGroups' => $postingGroups,
            'paymentPaths' => $paymentPaths,
            'workflow' => $workflow,
            'accounting' => [
                'baseCurrency' => $this->accountingBaseCurrencyCode(),
                'taxEnabled' => $taxEnabled,
                'taxRate' => $taxEnabled ? (float) TaxConfiguration::rate() : 0,
                'currentYear' => $currentYear ? [
                    'name' => $currentYear->name,
                    'status' => $currentYear->status,
                ] : null,
                'currentPeriod' => $currentPeriod ? [
                    'name' => $currentPeriod->name,
                    'status' => $currentPeriod->status,
                ] : null,
                'missingMappings' => $missingMappings,
            ],
            'urls' => $urls,
        ]);
    }

    public function journal(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.viewAny'), 403);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'event_type' => ['nullable', 'string', 'max:80'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        [$from, $to] = $this->dateRange($filters['from'] ?? null, $filters['to'] ?? null);

        $entriesQuery = $this->scopeToCurrentBranch(
            JournalEntry::query()->with(['branch', 'creator', 'lines.account'])
        )
            ->when($from, fn (Builder $query) => $query->whereDate('posted_on', '>=', $from))
            ->whereDate('posted_on', '<=', $to)
            ->when($filters['event_type'] ?? null, fn (Builder $query, string $eventType) => $query->where('event_type', $eventType))
            ->when(trim((string) ($filters['search'] ?? '')), function (Builder $query, string $search) {
                $query->where(function (Builder $nested) use ($search) {
                    $nested->where('entry_no', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('posted_on')
            ->orderByDesc('id');

        $entries = $entriesQuery->paginate(20)->withQueryString();
        $reversedEntryIds = $this->reversedEntryIds();

        $eventTypes = $this->scopeToCurrentBranch(JournalEntry::query())
            ->whereNotNull('event_type')
            ->distinct()
            ->orderBy('event_type')
            ->pluck('event_type');

        $labels = $this->eventLabels();
        $closingTypes = ['period_closing', 'fiscal_year_closing', 'period_closing_reversal', 'fiscal_year_closing_reversal'];
        $canCorrect = auth()->user()?->hasPermission('chart_of_accounts.create');

        $entries->through(function (JournalEntry $entry) use ($labels, $reversedEntryIds, $closingTypes, $canCorrect) {
            $reversed = in_array((int) $entry->id, $reversedEntryIds, true);
            $closing = in_array($entry->event_type, $closingTypes, true);

            return [
                'id' => $entry->id,
                'number' => $entry->entry_no,
                'date' => $entry->posted_on?->toDateString(),
                'description' => $entry->description,
                'eventType' => $entry->event_type,
                'eventLabel' => $labels[$entry->event_type] ?? Str::headline((string) $entry->event_type),
                'branch' => $entry->branch?->name,
                'creator' => $entry->creator?->name,
                'amount' => round((float) $entry->lines->sum('debit'), 2),
                'reversed' => $reversed,
                'closing' => $closing,
                'correctUrl' => ($canCorrect && ! $reversed && ! $closing)
                    ? route('admin.accounting.journal.adjust.create', $entry) : null,
                'lines' => $entry->lines->sortBy('line_no')->map(fn (JournalLine $line) => [
                    'id' => $line->id,
                    'accountCode' => $line->account?->code,
                    'accountName' => $line->account?->name,
                    'description' => $line->description,
                    'debit' => (float) $line->debit,
                    'credit' => (float) $line->credit,
                ])->values(),
            ];
        });

        return AdminShell::render('Admin/Accounting/Journal', [
            'can' => ['create' => (bool) $canCorrect, 'export' => auth()->user()?->hasPermission('reports.export')],
            'currency' => $this->accountingCurrencyMeta(),
            'entries' => $entries,
            'eventTypes' => $eventTypes->map(fn (string $value) => [
                'value' => $value,
                'label' => $labels[$value] ?? Str::headline($value),
            ])->values(),
            'filters' => [
                'from' => $from,
                'to' => $to,
                'eventType' => $filters['event_type'] ?? '',
                'search' => $filters['search'] ?? '',
            ],
            'urls' => $this->accountingWorkspaceUrls() + [
                'index' => route('admin.accounting.journal'),
                'export' => route('admin.accounting.journal.export.csv'),
            ],
        ]);
    }

    public function ledger(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.viewAny'), 403);

        $filters = $request->validate([
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        [$from, $to] = $this->dateRange(
            $filters['from'] ?? now()->startOfMonth()->toDateString(),
            $filters['to'] ?? null,
        );

        $accounts = Account::query()
            ->whereNotIn('id', TaxConfiguration::hideableAccountIds())
            ->orderBy('type')
            ->orderBy('code')
            ->get();
        $account = isset($filters['account_id'])
            ? $accounts->firstWhere('id', (int) $filters['account_id'])
            : $accounts->first();

        $lines = null;
        $openingRaw = 0.0;
        $periodDebit = 0.0;
        $periodCredit = 0.0;

        if ($account) {
            $base = JournalLine::query()
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_entries.status', 'posted')
                ->where('journal_lines.account_id', $account->id)
                ->when(BranchContext::current(), fn ($query, $branchId) => $query->where('journal_entries.branch_id', $branchId));

            $opening = (clone $base)
                ->whereDate('journal_entries.posted_on', '<', $from)
                ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as debit, COALESCE(SUM(journal_lines.credit), 0) as credit')
                ->first();
            $openingRaw = round((float) ($opening?->debit ?? 0) - (float) ($opening?->credit ?? 0), 4);

            $periodBase = (clone $base)
                ->whereDate('journal_entries.posted_on', '>=', $from)
                ->whereDate('journal_entries.posted_on', '<=', $to);
            $periodTotals = (clone $periodBase)
                ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as debit, COALESCE(SUM(journal_lines.credit), 0) as credit')
                ->first();
            $periodDebit = round((float) ($periodTotals?->debit ?? 0), 4);
            $periodCredit = round((float) ($periodTotals?->credit ?? 0), 4);

            $perPage = 40;
            $page = max(1, (int) $request->integer('page', 1));
            $ordered = (clone $periodBase)
                ->select('journal_lines.*')
                ->with(['entry.branch', 'entry.creator'])
                ->orderBy('journal_entries.posted_on')
                ->orderBy('journal_entries.id')
                ->orderBy('journal_lines.line_no');
            $lines = $ordered->paginate($perPage)->withQueryString();

            $beforePageRaw = 0.0;
            $skip = ($page - 1) * $perPage;
            if ($skip > 0) {
                $previousLines = (clone $periodBase)
                    ->orderBy('journal_entries.posted_on')
                    ->orderBy('journal_entries.id')
                    ->orderBy('journal_lines.line_no')
                    ->limit($skip)
                    ->get(['journal_lines.debit', 'journal_lines.credit']);
                $beforePageRaw = (float) $previousLines->sum(fn ($line) => (float) $line->debit - (float) $line->credit);
            }

            $running = $openingRaw + $beforePageRaw;
            $lines->getCollection()->each(function (JournalLine $line) use (&$running) {
                $running = round($running + (float) $line->debit - (float) $line->credit, 4);
                $line->running_balance_raw = $running;
            });
        }

        if ($lines) {
            $lines->through(fn (JournalLine $line) => [
                'id' => $line->id,
                'date' => $line->entry?->posted_on?->toDateString(),
                'entryNumber' => $line->entry?->entry_no,
                'description' => $line->description ?: $line->entry?->description,
                'entryDescription' => $line->entry?->description,
                'creator' => $line->entry?->creator?->name,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'runningBalance' => (float) $line->running_balance_raw,
                'journalUrl' => route('admin.accounting.journal', ['search' => $line->entry?->entry_no]),
            ]);
        }

        return AdminShell::render('Admin/Accounting/Ledger', [
            'currency' => $this->accountingCurrencyMeta(),
            'accounts' => $accounts->map(fn (Account $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'type' => $item->type,
                'active' => (bool) $item->is_active,
            ])->values(),
            'account' => $account ? [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'normalBalance' => $account->normal_balance,
            ] : null,
            'lines' => $lines,
            'filters' => ['accountId' => $account?->id, 'from' => $from, 'to' => $to],
            'summary' => [
                'opening' => $openingRaw,
                'debit' => $periodDebit,
                'credit' => $periodCredit,
                'closing' => round($openingRaw + $periodDebit - $periodCredit, 4),
            ],
            'urls' => $this->accountingWorkspaceUrls() + ['index' => route('admin.accounting.ledger')],
        ]);
    }

    public function exportJournalCsv(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.viewAny'), 403);
        abort_unless(auth()->user()?->hasPermission('reports.export'), 403);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'event_type' => ['nullable', 'string', 'max:80'],
        ]);
        [$from, $to] = $this->dateRange($filters['from'] ?? null, $filters['to'] ?? null);
        $from ??= Carbon::parse($to)->startOfMonth()->toDateString();

        $query = $this->scopeToCurrentBranch(JournalEntry::query()->with('lines.account'))
            ->whereDate('posted_on', '>=', $from)
            ->whereDate('posted_on', '<=', $to)
            ->when($filters['event_type'] ?? null, fn (Builder $query, string $eventType) => $query->where('event_type', $eventType))
            ->orderBy('posted_on')
            ->orderBy('id');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['entry_no', 'posted_on', 'event_type', 'entry_description', 'base_currency', 'account_code', 'account_name', 'line_description', 'debit', 'credit', 'currency', 'exchange_rate', 'foreign_debit', 'foreign_credit']);

            $query->chunk(100, function ($entries) use ($out) {
                foreach ($entries as $entry) {
                    foreach ($entry->lines as $line) {
                        fputcsv($out, [
                            $entry->entry_no,
                            optional($entry->posted_on)->toDateString(),
                            $entry->event_type,
                            $entry->description,
                            $entry->base_currency_code,
                            $line->account?->code,
                            $line->account?->name,
                            $line->description,
                            number_format((float) $line->debit, 4, '.', ''),
                            number_format((float) $line->credit, 4, '.', ''),
                            $line->currency_code,
                            number_format((float) $line->exchange_rate, 8, '.', ''),
                            number_format((float) $line->foreign_debit, 4, '.', ''),
                            number_format((float) $line->foreign_credit, 4, '.', ''),
                        ]);
                    }
                }
            });

            fclose($out);
        }, 'general-ledger-'.$from.'-'.$to.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function trialBalance(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.viewAny'), 403);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            // `1` = hide active accounts with zero movement (default — the
            // common case: a chart of 20+ accounts where only 6 actually
            // moved this period). `0` shows the full chart for auditors
            // who want to confirm coverage.
            'show_empty' => ['nullable', 'boolean'],
        ]);

        [$from, $to] = $this->dateRange($filters['from'] ?? null, $filters['to'] ?? null);
        $showEmpty = $request->boolean('show_empty');

        $lineQuery = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->when($from, fn ($query) => $query->whereDate('journal_entries.posted_on', '>=', $from))
            ->whereDate('journal_entries.posted_on', '<=', $to);

        if ($branchId = BranchContext::current()) {
            $lineQuery->where('journal_entries.branch_id', $branchId);
        }

        $movements = $lineQuery
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, COALESCE(SUM(journal_lines.debit), 0) as debit, COALESCE(SUM(journal_lines.credit), 0) as credit')
            ->get()
            ->keyBy('account_id');

        // Two pools: active accounts (always considered for display),
        // and inactive accounts that HAVE activity (so the totals still
        // match what's in the journal — silently dropping an inactive
        // account that someone posted to would unbalance the trial).
        $activeAccounts = Account::query()
            ->where('is_active', true)
            ->whereNotIn('id', TaxConfiguration::hideableAccountIds())
            ->get();
        $inactiveWithMovement = Account::query()
            ->where('is_active', false)
            ->whereIn('id', $movements->keys())
            ->get();
        $allAccounts = $activeAccounts->concat($inactiveWithMovement)
            ->sortBy('code')
            ->values();

        $accounts = $allAccounts->map(function (Account $account) use ($movements) {
            $movement = $movements->get($account->id);
            $debit = round((float) ($movement?->debit ?? 0), 4);
            $credit = round((float) ($movement?->credit ?? 0), 4);
            $net = round($debit - $credit, 4);

            $account->movement_debit = $debit;
            $account->movement_credit = $credit;
            $account->balance_debit = max($net, 0);
            $account->balance_credit = max(-$net, 0);
            $account->is_zero = abs($debit) < 0.0001 && abs($credit) < 0.0001;

            return $account;
        });

        // Totals are computed BEFORE the empty-row filter so the bottom
        // row always matches the journal — hiding visual zeros must not
        // change the math.
        $totalMovementDebit = round((float) $accounts->sum('movement_debit'), 4);
        $totalMovementCredit = round((float) $accounts->sum('movement_credit'), 4);
        $totalBalanceDebit = round((float) $accounts->sum('balance_debit'), 4);
        $totalBalanceCredit = round((float) $accounts->sum('balance_credit'), 4);

        $visibleAccounts = $showEmpty
            ? $accounts
            : $accounts->filter(fn ($a) => ! $a->is_zero)->values();

        return AdminShell::render('Admin/Accounting/TrialBalance', [
            'currency' => $this->accountingCurrencyMeta(),
            'accounts' => $visibleAccounts->map(fn (Account $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'type' => $item->type,
                'active' => (bool) $item->is_active,
                'zero' => (bool) $item->is_zero,
                'movementDebit' => (float) $item->movement_debit,
                'movementCredit' => (float) $item->movement_credit,
                'balanceDebit' => (float) $item->balance_debit,
                'balanceCredit' => (float) $item->balance_credit,
                'ledgerUrl' => route('admin.accounting.ledger', [
                    'account_id' => $item->id, 'from' => $from, 'to' => $to,
                ]),
            ])->values(),
            'filters' => ['from' => $from, 'to' => $to, 'showEmpty' => $showEmpty],
            'metrics' => [
                'totalAccounts' => $accounts->count(),
                'hiddenZero' => $accounts->count() - $visibleAccounts->count(),
                'activeAccounts' => $accounts->filter(fn ($item) => ! $item->is_zero)->count(),
                'movementDebit' => $totalMovementDebit,
                'movementCredit' => $totalMovementCredit,
                'balanceDebit' => $totalBalanceDebit,
                'balanceCredit' => $totalBalanceCredit,
                'balanced' => abs($totalBalanceDebit - $totalBalanceCredit) < 0.01,
            ],
            'urls' => $this->accountingWorkspaceUrls() + ['index' => route('admin.accounting.trial-balance')],
        ]);
    }

    private function scopeToCurrentBranch(Builder $query): Builder
    {
        if ($branchId = BranchContext::current()) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    private function dateRange(?string $from, ?string $to): array
    {
        $fromDate = $from ? Carbon::parse($from)->toDateString() : null;
        $toDate = Carbon::parse($to ?: now())->toDateString();

        if ($fromDate && $fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        return [$fromDate, $toDate];
    }

    private function eventLabels(): array
    {
        return [
            'tax_payment' => 'سداد ضريبة',
            'tips_payout' => 'صرف إكراميات',
            'wallet_to_bank' => 'تحويل محفظة إلى البنك',
            'payment_clearing_settlement' => 'تسوية بوابة دفع (تاريخية)',
            'fixed_asset_acquired' => 'شراء أصل ثابت',
            'fixed_asset_depreciation' => 'إهلاك أصل ثابت',
            'fixed_asset_disposal' => 'استبعاد أصل ثابت',
            'invoice_issued' => 'إصدار فاتورة',
            'invoice_cancelled' => 'إلغاء فاتورة',
            'payment_received' => 'تحصيل دفعة',
            'customer_advance_deposited' => 'إيداع رصيد مقدم لزبون',
            'customer_advance_redeemed' => 'استخدام رصيد مقدم',
            'customer_advance_opening' => 'رصيد زبون مقدم افتتاحي',
            'invoice_writeoff' => 'شطب ذمة',
            'debt_writeoff_posted' => 'شطب ذمة موثّق',
            'debt_writeoff_reversed' => 'عكس شطب ذمة',
            'credit_note_issued' => 'إشعار دائن',
            'credit_note_reversed' => 'عكس إشعار دائن',
            'refund_completed' => 'استرداد مكتمل',
            'refund_reversed' => 'عكس استرداد',
            'reconciliation_adjustment' => 'تسوية فرق مطابقة',
            'payment_voided' => 'عكس دفعة',
            'expense_approved' => 'اعتماد مصروف',
            'supplier_invoice_created' => 'فاتورة مورد',
            'supplier_invoice_cancelled' => 'إلغاء فاتورة مورد',
            'supplier_payment_recorded' => 'دفعة لمورد',
            'opening_balance' => 'أرصدة افتتاحية',
            'customer_opening_debt' => 'دين عميل افتتاحي',
            'supplier_opening_debt' => 'رصيد مورد افتتاحي',
            'supplier_opening_debt_cancelled' => 'عكس رصيد مورد افتتاحي',
            'period_closing' => 'إقفال فترة',
            'period_closing_reversal' => 'عكس إقفال فترة',
            'fiscal_year_closing' => 'إقفال سنة مالية',
            'fiscal_year_closing_reversal' => 'عكس إقفال سنة مالية',
        ];
    }

    private function typeLabels(): array
    {
        return [
            'asset' => 'أصل',
            'liability' => 'التزام',
            'equity' => 'حقوق ملكية',
            'revenue' => 'إيراد',
            'contra_revenue' => 'تخفيض إيراد',
            'expense' => 'مصروف',
        ];
    }

    // ───────────────────────────────────────────────────────────────
    // Manual journal entry — the bridge that gives custom accounts
    // actual purpose. Without this, an accountant adds a new account
    // to the chart but nothing in the operational flow ever writes
    // to it. With this, they can post DR/CR pairs to any account
    // they want (subject to active-status validation).
    // ───────────────────────────────────────────────────────────────

    public function createManualEntry()
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);

        // Only ACTIVE accounts can be posted to. System inactives are
        // weeded out so the dropdown doesn't tempt accountants into
        // bringing a deactivated account back into the books by accident.
        $blockedTaxAccountIds = TaxConfiguration::isEnabled() ? collect() : TaxConfiguration::salesTaxAccountIds();
        $accounts = Account::where('is_active', true)
            ->whereNotIn('id', $blockedTaxAccountIds)
            ->orderBy('type')
            ->orderBy('code')
            ->get();

        return AdminShell::render('Admin/Accounting/ManualEntry', [
            'accounts' => $accounts->map(fn (Account $account) => [
                'id' => $account->id, 'code' => $account->code, 'name' => $account->name,
                'type' => $account->type, 'system' => (bool) $account->is_system,
            ])->values(),
            'currencies' => $this->accountingCurrencies()->map(fn (Currency $currency) => [
                'code' => $currency->code, 'name' => $currency->name,
                'rate' => $currency->is_base ? 1 : (float) ($currency->rate_to_base ?: 0),
                'base' => (bool) $currency->is_base,
            ])->values(),
            'baseCurrencyCode' => $this->accountingBaseCurrencyCode(),
            'urls' => $this->accountingWorkspaceUrls() + ['store' => route('admin.accounting.manual-entry.store')],
        ]);
    }

    public function storeManualEntry(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);
        if ($dupe = $this->duplicateSubmitGuard($request)) {
            return $dupe;
        }

        $data = $request->validate([
            'posted_on' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.currency_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'lines.*.exchange_rate' => ['nullable', 'numeric', 'min:0.000001', 'max:999999'],
            'lines.*.foreign_debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.foreign_credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ], [], [
            'lines' => 'سطور القيد',
        ]);

        // Normalise: empty numbers → 0; verify each line has EITHER
        // debit OR credit (never both, never neither).
        try {
            $lines = collect($data['lines'])->map(function ($line) use ($data) {
                $currencyCode = $this->normalizeCurrencyCode($line['currency_code'] ?? $this->accountingBaseCurrencyCode());
                $exchangeRate = (float) ($line['exchange_rate'] ?? ($currencyCode === $this->accountingBaseCurrencyCode() ? 1 : $this->configuredExchangeRate($currencyCode, $data['posted_on'])));
                $hasForeignAmounts = array_key_exists('foreign_debit', $line) || array_key_exists('foreign_credit', $line);
                $foreignDebit = round((float) ($line['foreign_debit'] ?? 0), 4);
                $foreignCredit = round((float) ($line['foreign_credit'] ?? 0), 4);
                $debit = $hasForeignAmounts ? round($foreignDebit * $exchangeRate, 4) : round((float) ($line['debit'] ?? 0), 4);
                $credit = $hasForeignAmounts ? round($foreignCredit * $exchangeRate, 4) : round((float) ($line['credit'] ?? 0), 4);

                return [
                    'account' => (int) $line['account_id'],
                    'debit' => $debit,
                    'credit' => $credit,
                    'currency_code' => $currencyCode,
                    'exchange_rate' => $exchangeRate,
                    'foreign_debit' => $hasForeignAmounts ? $foreignDebit : ($currencyCode === $this->accountingBaseCurrencyCode() ? $debit : round($debit / $exchangeRate, 4)),
                    'foreign_credit' => $hasForeignAmounts ? $foreignCredit : ($currencyCode === $this->accountingBaseCurrencyCode() ? $credit : round($credit / $exchangeRate, 4)),
                    'description' => $line['description'] ?? null,
                ];
            })->filter(fn ($l) => $l['debit'] > 0.0001 || $l['credit'] > 0.0001);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        if ($lines->count() < 2) {
            return back()->withInput()->with('error',
                'يلزم على الأقل سطرين (مدين + دائن) للقيد.');
        }

        foreach ($lines as $i => $l) {
            if ($l['debit'] > 0.0001 && $l['credit'] > 0.0001) {
                return back()->withInput()->with('error',
                    'سطر #'.($i + 1).': لا يمكن أن يكون مديناً ودائناً في نفس الوقت — اختر واحداً.');
            }
        }

        // Convert to AccountingService::post() shape — we look up the
        // account code from the id (post() uses code as the address).
        $accountIds = $lines->pluck('account')->unique()->all();
        if (! TaxConfiguration::isEnabled($data['posted_on'])
            && collect($accountIds)->intersect(TaxConfiguration::salesTaxAccountIds())->isNotEmpty()) {
            return back()->withInput()->with('error', 'حساب ضريبة مبيعات الزبائن معلق في هذا التاريخ. فعّل ضريبة فاتورة الزبون بتاريخ سريان مناسب قبل إنشاء هذا القيد.');
        }
        $accounts = Account::whereIn('id', $accountIds)->get()->keyBy('id');
        $inactiveAccounts = $accounts->filter(fn (Account $account) => ! $account->is_active);

        if ($inactiveAccounts->isNotEmpty()) {
            return back()->withInput()->with('error',
                'لا يمكن الترحيل على حساب غير نشط: '.
                $inactiveAccounts->map(fn (Account $account) => "{$account->code} — {$account->name}")->implode('، '));
        }

        $codeMap = $accounts->pluck('code', 'id');

        $postLines = $lines->map(fn ($l) => [
            'account' => $codeMap[$l['account']] ?? null,
            'debit' => $l['debit'],
            'credit' => $l['credit'],
            'currency_code' => $l['currency_code'],
            'exchange_rate' => $l['exchange_rate'],
            'foreign_debit' => $l['foreign_debit'],
            'foreign_credit' => $l['foreign_credit'],
            'description' => $l['description'],
        ])->all();

        // Wrap an arbitrary source so the journal_entries.source_* columns
        // get filled — we use the user record as a pragmatic stand-in
        // since "the user who posted this" is the most natural reference
        // for a manual entry.
        $source = auth()->user();

        try {
            $entry = app(AccountingService::class)->post(
                eventType: 'manual_journal',
                source: null,
                branchId: BranchContext::current(),
                postedOn: $data['posted_on'],
                description: $data['description'],
                lines: $postLines,
                metadata: ['posted_by_user_id' => $source->id, 'posted_by_username' => $source->username ?? null],
                createdBy: $source->id,
            );
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        ActivityLog::log(
            'journal.manual_posted',
            "قيد يدوي #{$entry->id}: {$data['description']}",
            $entry,
            ['lines' => $postLines],
        );

        return redirect()->route('admin.accounting.journal')
            ->with('success', "تم تسجيل القيد رقم #{$entry->id} بنجاح.");
    }

    public function createEntryAdjustment(JournalEntry $entry)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);
        $this->assertEntryInCurrentBranch($entry);

        $entry->load('branch', 'creator', 'lines.account');

        $blockedTaxAccountIds = TaxConfiguration::isEnabled() ? collect() : TaxConfiguration::salesTaxAccountIds();
        $accounts = Account::where('is_active', true)
            ->whereNotIn('id', $blockedTaxAccountIds)
            ->orderBy('type')
            ->orderBy('code')
            ->get();

        return AdminShell::render('Admin/Accounting/EntryAdjustment', [
            'entry' => [
                'id' => $entry->id,
                'number' => $entry->entry_no,
                'date' => $entry->posted_on?->toDateString(),
                'description' => $entry->description,
                'branch' => $entry->branch?->name ?? 'كل الفروع',
                'lines' => $entry->lines->sortBy('line_no')->values()->map(fn ($line) => [
                    'accountId' => $line->account_id,
                    'accountCode' => $line->account?->code,
                    'accountName' => $line->account?->name,
                    'description' => $line->description,
                    'debit' => (float) $line->debit,
                    'credit' => (float) $line->credit,
                    'foreignDebit' => (float) ($line->foreign_debit ?: $line->debit),
                    'foreignCredit' => (float) ($line->foreign_credit ?: $line->credit),
                    'currencyCode' => $line->currency_code ?: $this->accountingBaseCurrencyCode(),
                    'exchangeRate' => (float) ($line->exchange_rate ?: 1),
                ]),
            ],
            'accounts' => $accounts->map(fn (Account $account) => [
                'id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type,
            ])->values(),
            'isReversed' => $this->entryIsReversed($entry),
            'currencies' => $this->accountingCurrencies()->map(fn (Currency $currency) => [
                'code' => $currency->code, 'name' => $currency->name,
                'rate' => $currency->is_base ? 1 : (float) ($currency->rate_to_base ?: 0),
                'base' => (bool) $currency->is_base,
            ])->values(),
            'baseCurrencyCode' => $this->accountingBaseCurrencyCode(),
            'urls' => $this->accountingWorkspaceUrls() + ['store' => route('admin.accounting.journal.adjust.store', $entry)],
        ]);
    }

    public function storeEntryAdjustment(Request $request, JournalEntry $entry)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);
        $this->assertEntryInCurrentBranch($entry);

        // Closing entries carry period state — they zero the P&L into retained
        // earnings and belong to a closed period/year. Reversing one from the
        // journal screen dates the reversal OUTSIDE the closed period (the lock
        // pushes it forward), shifting a whole month's revenue into the next
        // month while the period still reads "closed". Undoing a close is only
        // correct via the Reopen flow, which restores the period status and
        // dates the reversal back inside the period.
        if (in_array($entry->event_type, [
            'period_closing', 'fiscal_year_closing',
            'period_closing_reversal', 'fiscal_year_closing_reversal',
        ], true)) {
            return back()->with('error', 'قيود الإقفال لا تُعكس من شاشة اليومية. استخدم «إعادة فتح الفترة أو السنة المالية» من شاشة الفترات.');
        }

        if ($this->entryIsReversed($entry)) {
            return back()->with('error', 'هذا القيد تم عكسه مسبقا. لا يمكن عكس نفس القيد مرتين.');
        }

        $data = $request->validate([
            'mode' => ['required', Rule::in(['reverse', 'correct'])],
            'posted_on' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:500'],
            'correction_description' => ['required_if:mode,correct', 'nullable', 'string', 'max:255'],
            'lines' => ['required_if:mode,correct', 'array'],
            'lines.*.account_id' => ['required_if:mode,correct', 'nullable', 'integer', 'exists:accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.currency_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'lines.*.exchange_rate' => ['nullable', 'numeric', 'min:0.000001', 'max:999999'],
            'lines.*.foreign_debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.foreign_credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = DB::transaction(function () use ($entry, $data) {
                $accounting = app(AccountingService::class);
                $entry->loadMissing('lines.account');

                $reversal = $accounting->reverseEntry(
                    original: $entry,
                    eventType: 'manual_entry_reversal_'.$entry->id,
                    postedOn: $data['posted_on'],
                    description: 'عكس قيد '.$entry->entry_no,
                    metadata: [
                        'reason' => $data['reason'],
                        'mode' => $data['mode'],
                    ],
                    createdBy: auth()->id(),
                );

                $correction = null;
                if ($data['mode'] === 'correct') {
                    $correction = $accounting->post(
                        eventType: 'manual_journal_correction',
                        source: null,
                        branchId: $entry->branch_id,
                        postedOn: $data['posted_on'],
                        description: $data['correction_description'] ?: 'تصحيح قيد '.$entry->entry_no,
                        lines: $this->postLinesFromRequest($data['lines'] ?? [], $data['posted_on']),
                        metadata: [
                            'corrects_entry_id' => $entry->id,
                            'reversal_entry_id' => $reversal?->id,
                            'reason' => $data['reason'],
                        ],
                        createdBy: auth()->id(),
                    );
                }

                ActivityLog::log(
                    $data['mode'] === 'correct' ? 'journal.corrected' : 'journal.reversed',
                    ($data['mode'] === 'correct' ? 'تصحيح قيد ' : 'عكس قيد ').$entry->entry_no,
                    $correction ?: $reversal,
                    ['original_entry_id' => $entry->id, 'reason' => $data['reason']],
                );

                return ['reversal' => $reversal, 'correction' => $correction];
            });
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $message = $result['correction']
            ? 'تم عكس القيد الأصلي وترحيل قيد التصحيح.'
            : 'تم عكس القيد بنجاح.';

        return redirect()->route('admin.accounting.journal')
            ->with('success', $message);
    }

    public function accountMappings()
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $mappings = AccountMapping::with('account')->get()
            ->keyBy(fn (AccountMapping $mapping) => $mapping->context.'|'.$mapping->key);
        $blockedTaxAccountIds = TaxConfiguration::isEnabled() ? collect() : TaxConfiguration::salesTaxAccountIds();
        $accounts = Account::where('is_active', true)
            ->whereNotIn('id', $blockedTaxAccountIds)
            ->orderBy('code')
            ->get();
        $accountsByType = $accounts->groupBy('type');
        $accountsByCode = Account::orderBy('code')->get()->keyBy('code');
        $postingRoleDefinitions = collect(AccountingService::postingRoleDefinitions())
            ->when(! TaxConfiguration::isEnabled(), fn (Collection $roles) => $roles->except(['output_vat']));
        $postingRoleGroups = $postingRoleDefinitions->groupBy('group', true);

        $serializeAccounts = fn (Collection $items) => $items->map(fn (Account $account) => [
            'id' => $account->id, 'code' => $account->code, 'name' => $account->name,
        ])->values();

        return AdminShell::render('Admin/Accounting/AccountMappings', [
            'postingGroups' => $postingRoleGroups->map(function (Collection $roles, string $group) use ($mappings, $accountsByType, $accountsByCode, $serializeAccounts) {
                return [
                    'name' => $group,
                    'roles' => $roles->map(function (array $definition, string $key) use ($mappings, $accountsByType, $accountsByCode, $serializeAccounts) {
                        $allowed = collect($definition['types'] ?? [])->flatMap(fn ($type) => $accountsByType->get($type, collect()))->sortBy('code')->values();
                        $default = $accountsByCode->get($definition['default']);

                        return [
                            'key' => $key,
                            'label' => $definition['label'],
                            'description' => $definition['description'],
                            'defaultLabel' => $definition['default'].($default ? ' — '.$default->name : ''),
                            'selected' => $mappings->get(AccountMapping::CONTEXT_POSTING_ROLE.'|'.$key)?->account_id,
                            'accounts' => $serializeAccounts($allowed),
                        ];
                    })->values(),
                ];
            })->values(),
            'expenseCategories' => Lookup::for('expense_categories')->map(fn (Lookup $category) => [
                'id' => $category->id,
                'label' => $category->label,
                'selected' => $mappings->get(AccountMapping::CONTEXT_EXPENSE_CATEGORY.'|'.AccountMapping::keyForLookup($category))?->account_id,
            ])->values(),
            'paymentMethods' => collect($this->enabledPaymentMethodOptions())->map(fn ($label, $code) => [
                'code' => $code,
                'label' => $label,
                'fallback' => match ($code) {
                    'cash' => '1000 — الصندوق',
                    'palpay' => '1020 — محفظة PalPay',
                    'jawwal_pay' => '1030 — محفظة Jawwal Pay',
                    default => '1010 — البنك',
                },
                'selected' => $mappings->get(AccountMapping::CONTEXT_PAYMENT_METHOD.'|'.$code)?->account_id,
            ])->values(),
            'expenseAccounts' => $serializeAccounts($accountsByType->get('expense', collect())),
            'paymentAccounts' => $serializeAccounts($accountsByType->get('asset', collect())),
            'paymentIdentifiers' => $this->paymentIdentifierValues(),
            'urls' => $this->accountingWorkspaceUrls() + ['store' => route('admin.accounting.mappings.store')],
        ]);
    }

    public function storeAccountMappings(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $data = $request->validate([
            'expense_category_accounts' => ['nullable', 'array'],
            'expense_category_accounts.*' => ['nullable', 'integer', 'exists:accounts,id'],
            'payment_method_accounts' => ['nullable', 'array'],
            'payment_method_accounts.*' => ['nullable', 'integer', 'exists:accounts,id'],
            'posting_role_accounts' => ['nullable', 'array'],
            'posting_role_accounts.*' => ['nullable', 'integer', 'exists:accounts,id'],
            'payment_identifiers' => ['nullable', 'array'],
            'payment_identifiers.bank_name' => ['nullable', 'string', 'max:120'],
            'payment_identifiers.bank_account_holder' => ['nullable', 'string', 'max:160'],
            'payment_identifiers.bank_account_number' => ['nullable', 'string', 'max:80'],
            'payment_identifiers.bank_iban' => ['nullable', 'string', 'max:60'],
            'payment_identifiers.palpay_wallet_number' => ['nullable', 'string', 'max:40'],
            'payment_identifiers.jawwal_pay_wallet_number' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            DB::transaction(function () use ($data) {
                $this->syncPostingRoleMappings(
                    $data['posting_role_accounts'] ?? [],
                    AccountingService::postingRoleDefinitions(),
                );

                $expenseCategories = Lookup::for('expense_categories');
                $expenseCategoryAccounts = [];
                foreach ($expenseCategories as $category) {
                    $key = AccountMapping::keyForLookup($category);
                    if ($key) {
                        $expenseCategoryAccounts[$key] = $data['expense_category_accounts'][$category->id] ?? null;
                    }
                }

                $this->syncAccountMappings(
                    AccountMapping::CONTEXT_EXPENSE_CATEGORY,
                    $expenseCategoryAccounts,
                    array_keys($expenseCategoryAccounts),
                    ['expense'],
                );

                $this->syncAccountMappings(
                    AccountMapping::CONTEXT_PAYMENT_METHOD,
                    $data['payment_method_accounts'] ?? [],
                    array_keys($this->paymentMethodOptions()),
                    ['asset'],
                );

                if (array_key_exists('payment_identifiers', $data)) {
                    $this->storePaymentIdentifiers($data['payment_identifiers']);
                }
            });
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        ActivityLog::log('account_mappings.updated', 'تحديث خرائط الحسابات التشغيلية');

        return back()->with('success', 'تم حفظ ربط الحسابات وبيانات الاستقبال المالي.');
    }

    public function openingBalances()
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);

        $accounts = $this->openingBalanceAccounts();
        $generalOpeningPosted = $this->scopeToCurrentBranch(
            JournalEntry::query()
                ->where('event_type', 'opening_balance')
                ->whereNull('source_type')
        )->exists();
        $openingEntries = $this->scopeToCurrentBranch(
            JournalEntry::with('creator')->whereIn('event_type', ['opening_balance', 'customer_opening_debt', 'supplier_opening_debt', 'customer_advance_opening'])
        )
            ->orderByDesc('posted_on')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $equityAccount = $this->postingRoleAccount('opening_balance_equity');

        return AdminShell::render('Admin/Accounting/OpeningBalances', [
            'accounts' => $accounts->map(fn (Account $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'normalBalance' => $account->normal_balance,
                'parentName' => $account->parent?->name,
            ])->values(),
            'openingEntries' => $openingEntries->map(fn (JournalEntry $entry) => [
                'id' => $entry->id,
                'number' => $entry->entry_no,
                'date' => $entry->posted_on?->toDateString(),
                'description' => $entry->description,
                'creator' => $entry->creator?->name,
                'journalUrl' => route('admin.accounting.journal', ['search' => $entry->entry_no]),
            ])->values(),
            'equityAccount' => ['code' => $equityAccount->code, 'name' => $equityAccount->name],
            'currencies' => $this->accountingCurrencies()->map(fn (Currency $currency) => [
                'code' => $currency->code,
                'name' => $currency->name,
                'symbol' => $currency->symbol,
                'rate' => $currency->is_base ? 1 : (float) ($currency->rate_to_base ?: 0),
                'base' => (bool) $currency->is_base,
            ])->values(),
            'baseCurrencyCode' => $this->accountingBaseCurrencyCode(),
            'suggestedOpeningDate' => $this->suggestedOpeningDate(),
            'generalOpeningPosted' => $generalOpeningPosted,
            'customers' => Customer::where('status', 'active')->orderBy('name')->get(['id', 'name', 'phone', 'advance_balance'])->map(fn (Customer $customer) => [
                'id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone,
                'advanceBalance' => (float) $customer->advance_balance,
            ])->values(),
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(['id', 'name'])->map(fn (Supplier $supplier) => [
                'id' => $supplier->id, 'name' => $supplier->name,
            ])->values(),
            'hasBranch' => (bool) BranchContext::current(),
            'urls' => $this->accountingWorkspaceUrls() + [
                'storeAccounts' => route('admin.accounting.opening-balances.store'),
                'storeCustomer' => route('admin.accounting.opening-balances.customer-debt.store'),
                'storeCustomerAdvance' => route('admin.accounting.opening-balances.customer-advance.store'),
                'storeSupplier' => route('admin.accounting.opening-balances.supplier-debt.store'),
            ],
        ]);
    }

    public function storeOpeningBalances(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);

        if (! BranchContext::current()) {
            return back()->withInput()->with('error', 'اختر فرعاً محدداً قبل ترحيل الأرصدة الافتتاحية.');
        }

        $data = $request->validate([
            'posted_on' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:255'],
            'auto_balance' => ['nullable', 'boolean'],
            'lines' => ['nullable', 'array', 'required_without:balances'],
            'lines.*.account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.currency_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'lines.*.exchange_rate' => ['nullable', 'numeric', 'min:0.000001', 'max:999999'],
            'lines.*.foreign_debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.foreign_credit' => ['nullable', 'numeric', 'min:0'],
            'balances' => ['nullable', 'array', 'required_without:lines'],
            'balances.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'balances.*.amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'balances.*.side' => ['required', Rule::in(['debit', 'credit'])],
            'balances.*.currency_code' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'balances.*.exchange_rate' => ['nullable', 'numeric', 'min:0.000001', 'max:999999'],
        ]);

        if ($dupe = $this->duplicateSubmitGuard($request)) {
            return $dupe;
        }

        $generalOpeningExists = $this->scopeToCurrentBranch(
            JournalEntry::query()
                ->where('event_type', 'opening_balance')
                ->whereNull('source_type')
        )->exists();
        if ($generalOpeningExists) {
            return back()->withInput()->with('error', 'تم ترحيل رصيد افتتاحي عام لهذا الفرع مسبقاً. صحّحه بقيد عكس وتسوية بدلاً من إنشاء افتتاح ثانٍ.');
        }

        try {
            $rawLines = $data['lines'] ?? collect($data['balances'] ?? [])->map(function (array $balance) {
                $amount = round((float) ($balance['amount'] ?? 0), 4);
                $side = $balance['side'] ?? 'debit';

                return [
                    'account_id' => $balance['account_id'],
                    'foreign_debit' => $side === 'debit' ? $amount : 0,
                    'foreign_credit' => $side === 'credit' ? $amount : 0,
                    'currency_code' => $balance['currency_code'],
                    'exchange_rate' => $balance['exchange_rate'] ?? null,
                ];
            })->all();

            $allowedAccountIds = $this->openingBalanceAccounts()->pluck('id')->map(fn ($id) => (int) $id);
            $hasInvalidAccount = collect($rawLines)
                ->filter(fn (array $line) => (float) ($line['debit'] ?? $line['foreign_debit'] ?? 0) > 0
                    || (float) ($line['credit'] ?? $line['foreign_credit'] ?? 0) > 0)
                ->contains(fn (array $line) => ! $allowedAccountIds->contains((int) ($line['account_id'] ?? 0)));

            if ($hasInvalidAccount) {
                throw new \RuntimeException('الأرصدة العامة تقبل حسابات الميزانية فقط. أدخل ديون العملاء والموردين من بطاقتيهما المخصصتين.');
            }

            $lines = $this->postLinesFromRequest($rawLines, $data['posted_on'], 1);
            $debit = round(array_sum(array_column($lines, 'debit')), 4);
            $credit = round(array_sum(array_column($lines, 'credit')), 4);
            $diff = round($debit - $credit, 4);

            if (abs($diff) > 0.0001 && $request->boolean('auto_balance', true)) {
                $equity = $this->postingRoleAccount('opening_balance_equity');
                $lines[] = [
                    'account' => $equity->code,
                    'debit' => $diff < 0 ? abs($diff) : 0,
                    'credit' => $diff > 0 ? abs($diff) : 0,
                    'currency_code' => $this->accountingBaseCurrencyCode(),
                    'exchange_rate' => 1,
                    'foreign_debit' => $diff < 0 ? abs($diff) : 0,
                    'foreign_credit' => $diff > 0 ? abs($diff) : 0,
                    'description' => 'موازنة تلقائية للأرصدة الافتتاحية',
                ];
            }

            app(AccountingService::class)->post(
                eventType: 'opening_balance',
                source: null,
                branchId: BranchContext::current(),
                postedOn: $data['posted_on'],
                description: $data['description'],
                lines: $lines,
                metadata: [
                    'type' => 'opening_balance',
                    'opening_scope' => 'general',
                    'opening_date' => $data['posted_on'],
                ],
                createdBy: auth()->id(),
            );
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        ActivityLog::log('accounting.opening_balance_posted', 'ترحيل أرصدة افتتاحية');

        return redirect()->route('admin.accounting.opening-balances')
            ->with('success', 'تم ترحيل الأرصدة الافتتاحية كقيد محاسبي.');
    }

    public function storeCustomerOpeningDebt(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);

        $branchId = BranchContext::current();
        if (! $branchId) {
            return back()->withInput()->with('error', 'اختر فرعاً محدداً قبل إضافة دين افتتاحي لزبون.');
        }

        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'posted_on' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:posted_on'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        if ($dupe = $this->duplicateSubmitGuard($request)) {
            return $dupe;
        }

        try {
            $invoice = DB::transaction(function () use ($data, $branchId) {
                $customer = Customer::findOrFail($data['customer_id']);
                $amount = round((float) $data['amount'], 2);
                $notes = trim(implode("\n", array_filter([
                    'رصيد افتتاحي قبل استخدام النظام',
                    ($data['due_date'] ?? null) ? 'الاستحقاق: '.$data['due_date'] : null,
                    $data['description'] ?? null,
                ])));

                $invoice = Invoice::create([
                    'branch_id' => $branchId,
                    'table_session_id' => null,
                    'order_id' => null,
                    'customer_id' => $customer->id,
                    'issued_by_user_id' => auth()->id(),
                    'subtotal' => $amount,
                    'tax_total' => 0,
                    'service_total' => 0,
                    'delivery_fee' => 0,
                    'tip' => 0,
                    'total' => $amount,
                    'balance' => $amount,
                    'status' => 'issued',
                    'is_opening_balance' => true,
                    'customer_name' => $customer->name,
                    'customer_phone' => $customer->phone,
                    'notes' => $notes,
                    'issued_at' => $data['posted_on'],
                    'settled_on_account_at' => $data['posted_on'],
                    'settled_on_account_by_user_id' => auth()->id(),
                ]);

                app(AccountingService::class)->recordCustomerOpeningDebt($invoice, auth()->id());

                return $invoice;
            });
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        ActivityLog::log('accounting.customer_opening_debt', 'إضافة دين افتتاحي لزبون', $invoice);

        return back()->with('success', 'تم إنشاء دين الزبون الافتتاحي وربطه بسجل التحصيلات.');
    }

    public function storeCustomerAdvanceOpeningBalance(Request $request, CustomerAdvanceService $advances)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);

        $branchId = (int) BranchContext::current();
        if (! $branchId) {
            return back()->withInput()->with('error', 'اختر فرعاً محدداً قبل إضافة رصيد مقدم افتتاحي.');
        }

        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'posted_on' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        if ($dupe = $this->duplicateSubmitGuard($request)) {
            return $dupe;
        }

        try {
            $advances->openingBalance(
                customer: Customer::findOrFail($data['customer_id']),
                amount: (float) $data['amount'],
                branchId: $branchId,
                userId: auth()->id(),
                postedOn: $data['posted_on'],
                notes: isset($data['description']) ? trim((string) $data['description']) ?: null : null,
            );
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'تم حفظ الرصيد المقدم الافتتاحي وربطه بدفتر الزبون وحساب الالتزامات.');
    }

    public function storeSupplierOpeningDebt(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);

        $branchId = BranchContext::current();
        if (! $branchId) {
            return back()->withInput()->with('error', 'اختر فرعاً محدداً قبل إضافة رصيد افتتاحي لمورد.');
        }

        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'reference' => ['nullable', 'string', 'max:60'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'currency_code' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0.000001', 'max:999999'],
            'posted_on' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:posted_on'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        if ($dupe = $this->duplicateSubmitGuard($request)) {
            return $dupe;
        }

        try {
            $invoice = DB::transaction(function () use ($data, $branchId) {
                $exchangeRates = app(ExchangeRateService::class);
                $baseCurrency = $exchangeRates->baseCurrencyCode();
                $currencyCode = $exchangeRates->normalizeCode($data['currency_code']);
                $exchangeRate = $currencyCode === $baseCurrency
                    ? 1.0
                    : (float) ($data['exchange_rate'] ?? $exchangeRates->rateFor($currencyCode, $baseCurrency, $data['posted_on']));
                $amount = round((float) $data['amount'], 4);
                $reference = trim((string) ($data['reference'] ?? ''));

                $invoice = SupplierInvoice::create([
                    'branch_id' => $branchId,
                    'number' => $reference !== '' ? $reference : 'OPEN-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
                    'supplier_id' => $data['supplier_id'],
                    'currency_code' => $currencyCode,
                    'exchange_rate' => $exchangeRate,
                    'subtotal' => $amount,
                    'tax_total' => 0,
                    'total' => $amount,
                    'paid_total' => 0,
                    'balance' => $amount,
                    'status' => 'unpaid',
                    'is_opening_balance' => true,
                    'invoice_date' => $data['posted_on'],
                    'due_date' => $data['due_date'] ?? null,
                    'notes' => trim(implode("\n", array_filter([
                        'رصيد مورد افتتاحي قبل استخدام النظام',
                        $data['description'] ?? null,
                    ]))),
                    'created_by' => auth()->id(),
                ]);

                app(AccountingService::class)->recordSupplierOpeningDebt($invoice->load('supplier'), auth()->id());

                return $invoice;
            });
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        ActivityLog::log('accounting.supplier_opening_debt', 'إضافة رصيد افتتاحي لمورد', $invoice);

        return back()->with('success', 'تم إنشاء رصيد المورد الافتتاحي وربطه بفاتورة قابلة للسداد.');
    }

    public function periods()
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $periods = $this->scopeToCurrentBranch(AccountingPeriod::with(['closer', 'closingEntry', 'fiscalYear']))
            ->orderByDesc('starts_on')
            ->paginate(20);
        $fiscalYears = $this->scopeToCurrentBranch(FiscalYear::query())
            ->orderByDesc('starts_on')
            ->get();
        $periodChecklists = $periods->getCollection()
            ->mapWithKeys(fn (AccountingPeriod $period) => [
                $period->id => $this->closingChecklist(
                    $period->starts_on->toDateString(),
                    $period->ends_on->toDateString(),
                    $period->branch_id,
                ),
            ]);

        return AdminShell::render('Admin/Accounting/Periods', [
            'periods' => [
                'data' => $periods->getCollection()->map(function (AccountingPeriod $period) use ($periodChecklists) {
                    $checks = collect($periodChecklists->get($period->id, []));

                    return [
                        'id' => $period->id,
                        'name' => $period->name,
                        'startsOn' => $period->starts_on?->toDateString(),
                        'endsOn' => $period->ends_on?->toDateString(),
                        'notes' => $period->notes,
                        'status' => $period->status,
                        'closed' => $period->isClosed(),
                        'closedAt' => $period->closed_at?->toDateString(),
                        'fiscalYearId' => $period->fiscal_year_id,
                        'fiscalYearName' => $period->fiscalYear?->name,
                        'checks' => $checks->values(),
                        'issueCount' => $checks->where('ok', false)->count(),
                        'updateUrl' => route('admin.accounting.periods.update', $period),
                        'destroyUrl' => route('admin.accounting.periods.destroy', $period),
                        'closeUrl' => route('admin.accounting.periods.close', $period),
                        'reopenUrl' => route('admin.accounting.periods.reopen', $period),
                    ];
                })->values(),
                'links' => $periods->linkCollection()->toArray(),
            ],
            'fiscalYears' => $fiscalYears->map(fn (FiscalYear $year) => [
                'id' => $year->id, 'name' => $year->name, 'closed' => $year->isClosed(),
            ])->values(),
            'urls' => $this->accountingWorkspaceUrls() + ['store' => route('admin.accounting.periods.store')],
        ]);
    }

    public function storePeriod(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:191'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'fiscal_year_id' => ['nullable', 'integer', 'exists:fiscal_years,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $data['name'] = trim((string) ($data['name'] ?? ''))
            ?: 'شهر '.Carbon::parse($data['starts_on'])->format('m/Y');

        $branchId = BranchContext::current();
        $year = ! empty($data['fiscal_year_id'])
            ? FiscalYear::findOrFail($data['fiscal_year_id'])
            : null;
        if ($year) {
            $this->assertFiscalYearInCurrentBranch($year);
            if ($year->isClosed()) {
                return back()->withInput()->with('error', 'لا يمكن إضافة شهر إلى سنة مالية مقفلة. أعد فتح السنة أولا.');
            }
            if ($data['starts_on'] < $year->starts_on->toDateString() || $data['ends_on'] > $year->ends_on->toDateString()) {
                return back()->withInput()->with('error', 'تواريخ الشهر يجب أن تقع بالكامل داخل السنة المالية المختارة.');
            }
        }
        $overlap = AccountingPeriod::query()
            ->where(function ($query) use ($branchId) {
                $branchId ? $query->where('branch_id', $branchId) : $query->whereNull('branch_id');
            })
            ->whereDate('starts_on', '<=', $data['ends_on'])
            ->whereDate('ends_on', '>=', $data['starts_on'])
            ->exists();

        if ($overlap) {
            return back()->withInput()->with('error', 'يوجد فترة محاسبية متداخلة مع هذه التواريخ.');
        }

        AccountingPeriod::create([
            ...$data,
            'branch_id' => $branchId,
            'status' => 'open',
        ]);

        return back()->with('success', 'تم إنشاء الفترة المحاسبية.');
    }

    public function updatePeriod(Request $request, AccountingPeriod $period)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);
        $this->assertPeriodInCurrentBranch($period);

        if ($period->isClosed()) {
            return back()->with('error', 'الفترة مقفلة. أعد فتحها قبل تعديل بياناتها.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'fiscal_year_id' => ['nullable', 'integer', 'exists:fiscal_years,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $branchId = BranchContext::current();
        $year = ! empty($data['fiscal_year_id']) ? FiscalYear::findOrFail($data['fiscal_year_id']) : null;
        if ($year) {
            $this->assertFiscalYearInCurrentBranch($year);
            if ($year->isClosed()) {
                return back()->withInput()->with('error', 'لا يمكن نقل الشهر إلى سنة مالية مقفلة.');
            }
            if ($data['starts_on'] < $year->starts_on->toDateString() || $data['ends_on'] > $year->ends_on->toDateString()) {
                return back()->withInput()->with('error', 'تواريخ الشهر يجب أن تقع بالكامل داخل السنة المالية المختارة.');
            }
        }

        $overlap = AccountingPeriod::query()
            ->where('id', '!=', $period->id)
            ->where(function ($query) use ($branchId) {
                $branchId ? $query->where('branch_id', $branchId) : $query->whereNull('branch_id');
            })
            ->whereDate('starts_on', '<=', $data['ends_on'])
            ->whereDate('ends_on', '>=', $data['starts_on'])
            ->exists();
        if ($overlap) {
            return back()->withInput()->with('error', 'يوجد شهر محاسبي متداخل مع هذه التواريخ.');
        }

        $datesChanged = $period->starts_on->toDateString() !== $data['starts_on']
            || $period->ends_on->toDateString() !== $data['ends_on'];
        if ($datesChanged && $this->journalExistsInRange(
            $period->starts_on->toDateString(),
            $period->ends_on->toDateString(),
            $period->branch_id,
        )) {
            return back()->withInput()->with('error', 'لا يمكن تغيير حدود شهر عليه قيود. يمكنك تعديل اسمه وملاحظاته أو إنشاء شهر تصحيحي.');
        }

        $period->update($data);
        ActivityLog::log('accounting.period_updated', 'تعديل فترة محاسبية '.$period->name, $period);

        return back()->with('success', 'تم تحديث الشهر المحاسبي.');
    }

    public function destroyPeriod(AccountingPeriod $period)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);
        $this->assertPeriodInCurrentBranch($period);

        if ($period->isClosed() || $this->journalExistsInRange(
            $period->starts_on->toDateString(),
            $period->ends_on->toDateString(),
            $period->branch_id,
        )) {
            return back()->with('error', 'لا يمكن حذف شهر مقفل أو يحتوي قيودا. أعد فتحه واتركه محفوظا كسجل تاريخي.');
        }

        $name = $period->name;
        $period->delete();
        ActivityLog::log('accounting.period_deleted', 'حذف فترة محاسبية فارغة '.$name);

        return back()->with('success', 'تم حذف الشهر الفارغ.');
    }

    public function closePeriod(AccountingPeriod $period)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);
        $this->assertPeriodInCurrentBranch($period);

        if ($period->isClosed()) {
            return back()->with('success', 'الفترة مقفلة مسبقا.');
        }

        try {
            DB::transaction(function () use ($period) {
                $period = AccountingPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
                $this->assertPeriodInCurrentBranch($period);
                $this->assertClosingChecklistClear(
                    $period->starts_on->toDateString(),
                    $period->ends_on->toDateString(),
                    $period->branch_id,
                );

                $movement = $this->ledgerTotals($period->starts_on->toDateString(), $period->ends_on->toDateString(), $period->branch_id);
                if (abs($movement['debit'] - $movement['credit']) > 0.01) {
                    throw new \RuntimeException('لا يمكن إقفال فترة ميزانها غير متوازن.');
                }

                $period->update([
                    'status' => 'closed',
                    'closed_at' => now(),
                    'closed_by' => auth()->id(),
                    'closing_journal_entry_id' => null,
                ]);
            });
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        ActivityLog::log('accounting.period_closed', 'إقفال فترة محاسبية '.$period->name, $period);

        return back()->with('success', 'تم إقفال الشهر ومنع أي ترحيل جديد داخله. تبقى نتيجة الشهر ظاهرة في التقارير، ويُنشأ قيد النتيجة مرة واحدة فقط عند إقفال السنة.');
    }

    public function reopenPeriod(AccountingPeriod $period)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);
        $this->assertPeriodInCurrentBranch($period);

        if (! $period->isClosed()) {
            return back()->with('success', 'الفترة مفتوحة مسبقا.');
        }

        try {
            $reversal = DB::transaction(function () use ($period) {
                $period = AccountingPeriod::with('closingEntry.lines.account')->whereKey($period->id)->lockForUpdate()->firstOrFail();
                $this->assertPeriodInCurrentBranch($period);

                $closingEntry = $period->closingEntry;

                $period->update([
                    'status' => 'open',
                    'closed_at' => null,
                    'closed_by' => null,
                    'closing_journal_entry_id' => null,
                ]);

                if ($closingEntry && ! $this->entryIsReversed($closingEntry)) {
                    return app(AccountingService::class)->reverseEntry(
                        original: $closingEntry,
                        eventType: 'period_closing_reversal',
                        postedOn: $period->ends_on,
                        description: 'عكس إقفال الفترة '.$period->name,
                        metadata: [
                            'accounting_period_id' => $period->id,
                            'reason' => 'period_reopened',
                        ],
                        createdBy: auth()->id(),
                    );
                }

                return null;
            });
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        ActivityLog::log('accounting.period_reopened', 'إعادة فتح فترة محاسبية '.$period->name, $period);

        return back()->with('success', $reversal
            ? 'تمت إعادة فتح الفترة وعكس قيد إقفال قديم كان منشأ قبل اعتماد الإقفال الشهري دون قيود.'
            : 'تمت إعادة فتح الفترة لاستقبال القيود من جديد.');
    }

    public function fiscalYears()
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $years = $this->scopeToCurrentBranch(FiscalYear::with(['closer', 'closingEntry', 'periods']))
            ->orderByDesc('starts_on')
            ->paginate(20);
        $yearChecklists = $years->getCollection()
            ->mapWithKeys(fn (FiscalYear $year) => [
                $year->id => $this->closingChecklist(
                    $year->starts_on->toDateString(),
                    $year->ends_on->toDateString(),
                    $year->branch_id,
                ),
            ]);

        return AdminShell::render('Admin/Accounting/FiscalYears', [
            'years' => [
                'data' => $years->getCollection()->map(function (FiscalYear $year) use ($yearChecklists) {
                    $periods = $year->periods->sortBy('starts_on')->values();

                    return [
                        'id' => $year->id,
                        'name' => $year->name,
                        'startsOn' => $year->starts_on?->toDateString(),
                        'endsOn' => $year->ends_on?->toDateString(),
                        'notes' => $year->notes,
                        'status' => $year->status,
                        'closed' => $year->isClosed(),
                        'closedAt' => $year->closed_at?->toDateString(),
                        'periods' => $periods->map(fn (AccountingPeriod $period) => [
                            'id' => $period->id,
                            'name' => $period->name,
                            'month' => $period->starts_on?->format('m'),
                            'closed' => $period->isClosed(),
                        ])->values(),
                        'closedPeriods' => $periods->filter(fn (AccountingPeriod $period) => $period->isClosed())->count(),
                        'checks' => collect($yearChecklists->get($year->id, []))->values(),
                        'updateUrl' => route('admin.accounting.fiscal-years.update', $year),
                        'destroyUrl' => route('admin.accounting.fiscal-years.destroy', $year),
                        'closeUrl' => route('admin.accounting.fiscal-years.close', $year),
                        'reopenUrl' => route('admin.accounting.fiscal-years.reopen', $year),
                    ];
                })->values(),
                'links' => $years->linkCollection()->toArray(),
            ],
            'urls' => $this->accountingWorkspaceUrls() + ['store' => route('admin.accounting.fiscal-years.store')],
        ]);
    }

    public function storeFiscalYear(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string'],
            'create_monthly_periods' => ['nullable', 'boolean'],
        ]);

        $startsOn = Carbon::parse($data['starts_on']);
        $endsOn = Carbon::parse($data['ends_on']);
        if (! $startsOn->isSameDay($startsOn->copy()->startOfYear())
            || ! $endsOn->isSameDay($startsOn->copy()->endOfYear())) {
            return back()->withInput()->with('error', 'السنة المالية المعتمدة لكل فرع ميلادية: من 1 يناير إلى 31 ديسمبر.');
        }

        $createMonthlyPeriods = true;
        unset($data['create_monthly_periods']);

        $branchId = BranchContext::current();
        $overlap = FiscalYear::query()
            ->where(function ($query) use ($branchId) {
                $branchId ? $query->where('branch_id', $branchId) : $query->whereNull('branch_id');
            })
            ->whereDate('starts_on', '<=', $data['ends_on'])
            ->whereDate('ends_on', '>=', $data['starts_on'])
            ->exists();

        if ($overlap) {
            return back()->withInput()->with('error', 'يوجد سنة مالية متداخلة مع هذه التواريخ.');
        }

        [$year, $periodsCreated] = DB::transaction(function () use ($data, $branchId, $createMonthlyPeriods) {
            $year = FiscalYear::create([
                ...$data,
                'branch_id' => $branchId,
                'status' => 'open',
            ]);

            return [$year, $createMonthlyPeriods ? $this->createMonthlyPeriods($year) : 0];
        });

        ActivityLog::log('accounting.fiscal_year_created', 'إنشاء سنة مالية '.$year->name, $year, [
            'monthly_periods_created' => $periodsCreated,
        ]);

        return back()->with('success', 'تم إنشاء السنة المالية'.($periodsCreated ? " وإنشاء {$periodsCreated} شهرا تلقائيا." : '.'));
    }

    public function updateFiscalYear(Request $request, FiscalYear $year)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);
        $this->assertFiscalYearInCurrentBranch($year);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string'],
        ]);

        $datesChanged = $year->starts_on->toDateString() !== $data['starts_on']
            || $year->ends_on->toDateString() !== $data['ends_on'];
        $startsOn = Carbon::parse($data['starts_on']);
        $endsOn = Carbon::parse($data['ends_on']);
        if (! $startsOn->isSameDay($startsOn->copy()->startOfYear())
            || ! $endsOn->isSameDay($startsOn->copy()->endOfYear())) {
            return back()->withInput()->with('error', 'السنة المالية المعتمدة لكل فرع ميلادية: من 1 يناير إلى 31 ديسمبر.');
        }
        if ($datesChanged && $year->isClosed()) {
            return back()->withInput()->with('error', 'أعد فتح السنة قبل تغيير تاريخ بدايتها أو نهايتها.');
        }

        $branchId = BranchContext::current();
        $overlap = FiscalYear::query()
            ->where('id', '!=', $year->id)
            ->where(function ($query) use ($branchId) {
                $branchId ? $query->where('branch_id', $branchId) : $query->whereNull('branch_id');
            })
            ->whereDate('starts_on', '<=', $data['ends_on'])
            ->whereDate('ends_on', '>=', $data['starts_on'])
            ->exists();
        if ($overlap) {
            return back()->withInput()->with('error', 'يوجد سنة مالية متداخلة مع هذه التواريخ.');
        }

        if ($datesChanged) {
            $periodOutsideRange = $year->periods()
                ->where(function ($query) use ($data) {
                    $query->whereDate('starts_on', '<', $data['starts_on'])
                        ->orWhereDate('ends_on', '>', $data['ends_on']);
                })
                ->exists();
            if ($periodOutsideRange) {
                return back()->withInput()->with('error', 'عدّل أو انقل الشهور التي ستقع خارج الحدود الجديدة أولا.');
            }

            $excludedEntry = $this->journalQueryForBranch($year->branch_id)
                ->whereBetween('posted_on', [$year->starts_on->toDateString(), $year->ends_on->toDateString()])
                ->where(function ($query) use ($data) {
                    $query->whereDate('posted_on', '<', $data['starts_on'])
                        ->orWhereDate('posted_on', '>', $data['ends_on']);
                })
                ->exists();
            if ($excludedEntry) {
                return back()->withInput()->with('error', 'الحدود الجديدة تستبعد قيودا موجودة. لا يمكن ترك قيود خارج سنتها المالية.');
            }
        }

        $year->update($data);
        ActivityLog::log('accounting.fiscal_year_updated', 'تعديل سنة مالية '.$year->name, $year);

        return back()->with('success', 'تم تحديث السنة المالية.');
    }

    public function destroyFiscalYear(FiscalYear $year)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);
        $this->assertFiscalYearInCurrentBranch($year);

        if ($year->isClosed()
            || $year->periods()->where('status', 'closed')->exists()
            || $this->journalExistsInRange($year->starts_on->toDateString(), $year->ends_on->toDateString(), $year->branch_id)) {
            return back()->with('error', 'لا يمكن حذف سنة مقفلة أو تحتوي قيودا/شهورا مقفلة. احتفظ بها كسجل مالي.');
        }

        $name = $year->name;
        DB::transaction(function () use ($year) {
            $year->periods()->delete();
            $year->delete();
        });
        ActivityLog::log('accounting.fiscal_year_deleted', 'حذف سنة مالية فارغة '.$name);

        return back()->with('success', 'تم حذف السنة المالية الفارغة وشهورها غير المستخدمة.');
    }

    public function closeFiscalYear(FiscalYear $year)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);
        $this->assertFiscalYearInCurrentBranch($year);

        if ($year->isClosed()) {
            return back()->with('success', 'السنة المالية مقفلة مسبقا.');
        }

        if ($year->periods()->where('status', 'open')->exists()) {
            return back()->with('error', 'أقفل جميع شهور السنة أولا، ثم نفّذ الإقفال السنوي.');
        }

        try {
            [$closingEntry, $netIncome] = DB::transaction(function () use ($year) {
                $year = FiscalYear::whereKey($year->id)->lockForUpdate()->firstOrFail();
                $this->assertFiscalYearInCurrentBranch($year);
                $this->assertClosingChecklistClear(
                    $year->starts_on->toDateString(),
                    $year->ends_on->toDateString(),
                    $year->branch_id,
                );

                $movement = $this->ledgerTotals($year->starts_on->toDateString(), $year->ends_on->toDateString(), $year->branch_id);
                if (abs($movement['debit'] - $movement['credit']) > 0.01) {
                    throw new \RuntimeException('لا يمكن إقفال سنة مالية ميزانها غير متوازن.');
                }

                $payload = $this->periodClosingPayload($year);
                $closingEntry = null;

                if ($payload['lines'] !== []) {
                    $closingEntry = app(AccountingService::class)->post(
                        eventType: 'fiscal_year_closing',
                        source: null,
                        branchId: $year->branch_id,
                        postedOn: $year->ends_on,
                        description: 'إقفال السنة المالية '.$year->name,
                        lines: $payload['lines'],
                        metadata: [
                            'fiscal_year_id' => $year->id,
                            'fiscal_year_name' => $year->name,
                            'starts_on' => $year->starts_on->toDateString(),
                            'ends_on' => $year->ends_on->toDateString(),
                            'net_income' => $payload['net_income'],
                        ],
                        createdBy: auth()->id(),
                    );
                }

                $year->update([
                    'status' => 'closed',
                    'closed_at' => now(),
                    'closed_by' => auth()->id(),
                    'closing_journal_entry_id' => $closingEntry?->id,
                ]);

                return [$closingEntry, $payload['net_income']];
            });
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        ActivityLog::log('accounting.fiscal_year_closed', 'إقفال سنة مالية '.$year->name, $year);

        $suffix = $closingEntry
            ? ' وتم إنشاء قيد إقفال السنة بصافي '.number_format(abs((float) $netIncome), 2).' '.(((float) $netIncome) >= 0 ? 'ربح' : 'خسارة').'.'
            : ' ولا يوجد رصيد إيرادات أو مصاريف يحتاج قيد سنة إضافي.';

        return back()->with('success', 'تم إقفال السنة المالية. أي قيد داخلها سيرفضه النظام.'.$suffix);
    }

    public function reopenFiscalYear(FiscalYear $year)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);
        $this->assertFiscalYearInCurrentBranch($year);

        if (! $year->isClosed()) {
            return back()->with('success', 'السنة المالية مفتوحة مسبقا.');
        }

        try {
            $reversal = DB::transaction(function () use ($year) {
                $year = FiscalYear::with('closingEntry.lines.account')->whereKey($year->id)->lockForUpdate()->firstOrFail();
                $this->assertFiscalYearInCurrentBranch($year);

                $closingEntry = $year->closingEntry;

                $year->update([
                    'status' => 'open',
                    'closed_at' => null,
                    'closed_by' => null,
                    'closing_journal_entry_id' => null,
                ]);

                if ($closingEntry && ! $this->entryIsReversed($closingEntry)) {
                    return app(AccountingService::class)->reverseEntry(
                        original: $closingEntry,
                        eventType: 'fiscal_year_closing_reversal',
                        postedOn: $year->ends_on,
                        description: 'عكس إقفال السنة المالية '.$year->name,
                        metadata: [
                            'fiscal_year_id' => $year->id,
                            'reason' => 'fiscal_year_reopened',
                        ],
                        createdBy: auth()->id(),
                    );
                }

                return null;
            });
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        ActivityLog::log('accounting.fiscal_year_reopened', 'إعادة فتح سنة مالية '.$year->name, $year);

        return back()->with('success', $reversal
            ? 'تمت إعادة فتح السنة المالية وعكس قيد الإقفال تلقائيا.'
            : 'تمت إعادة فتح السنة المالية.');
    }

    public function balanceSheet(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.viewAny'), 403);

        $data = $request->validate(['as_of' => ['nullable', 'date']]);
        $asOf = Carbon::parse($data['as_of'] ?? now())->toDateString();
        $rows = $this->ledgerAccountRows(null, $asOf);

        $assets = $this->balanceSheetRows($rows, ['asset'], 'debit');
        $liabilities = $this->balanceSheetRows($rows, ['liability'], 'credit');
        $equity = $this->balanceSheetRows($rows, ['equity'], 'credit');
        $currentEarnings = $this->currentEarnings($rows);

        $totalAssets = round($assets->sum('balance'), 2);
        $totalLiabilities = round($liabilities->sum('balance'), 2);
        $totalEquity = round($equity->sum('balance') + $currentEarnings, 2);

        $serializeRows = fn (Collection $items) => $items->map(fn ($row) => [
            'id' => (int) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'balance' => (float) $row->balance,
            'ledgerUrl' => route('admin.accounting.ledger', [
                'account_id' => $row->id,
                'to' => $asOf,
            ]),
        ])->values();

        return AdminShell::render('Admin/Accounting/BalanceSheet', [
            'asOf' => $asOf,
            'sections' => [
                'assets' => $serializeRows($assets),
                'liabilities' => $serializeRows($liabilities),
                'equity' => $serializeRows($equity),
            ],
            'metrics' => [
                'currentEarnings' => round($currentEarnings, 2),
                'totalAssets' => $totalAssets,
                'totalLiabilities' => $totalLiabilities,
                'totalEquity' => $totalEquity,
                'difference' => round($totalAssets - ($totalLiabilities + $totalEquity), 2),
                'balanced' => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.01,
            ],
            'currency' => $this->accountingCurrencyMeta(),
            'urls' => $this->accountingWorkspaceUrls() + ['index' => route('admin.accounting.balance-sheet')],
        ]);
    }

    public function taxReport(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.viewAny'), 403);

        if (! TaxConfiguration::switchIsOn() && ! TaxConfiguration::hasHistory()) {
            return redirect()->route('admin.accounting.index')
                ->with('info', 'لا يوجد تقرير ضريبة لأن الضريبة معطلة ولا توجد حركات تاريخية عليها.');
        }

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        [$from, $to] = $this->dateRange($filters['from'] ?? now()->startOfMonth()->toDateString(), $filters['to'] ?? null);
        $rows = $this->ledgerAccountRows($from, $to);

        $outputTax = $this->netForCodes($rows, $this->postingRoleCodes('output_vat'), 'credit');
        $inputTax = $this->netForCodes($rows, $this->postingRoleCodes('input_vat'), 'debit');
        $payable = $outputTax - $inputTax;

        return AdminShell::render('Admin/Accounting/TaxReport', [
            'from' => $from,
            'to' => $to,
            'taxLabel' => MarketProfile::taxLabel(),
            'metrics' => [
                'outputTax' => round($outputTax, 2),
                'inputTax' => round($inputTax, 2),
                'payable' => round($payable, 2),
            ],
            'outputCodes' => $this->postingRoleCodes('output_vat'),
            'inputCodes' => $this->postingRoleCodes('input_vat'),
            'currency' => $this->accountingCurrencyMeta(),
            'urls' => $this->accountingWorkspaceUrls() + ['index' => route('admin.accounting.tax-report')],
        ]);
    }

    public function aging(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.viewAny'), 403);

        $data = $request->validate(['as_of' => ['nullable', 'date']]);
        $asOf = Carbon::parse($data['as_of'] ?? now())->toDateString();
        $branchId = BranchContext::current();
        $receivableBalances = $this->receivableLedgerBalances($asOf, $branchId);
        $payableBalances = $this->payableLedgerBalances($asOf, $branchId);

        $arRows = Invoice::with('customer')
            ->whereIn('id', array_keys($receivableBalances))
            ->whereDate('issued_at', '<=', $asOf)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('issued_at')
            ->get()
            ->map(fn (Invoice $invoice) => $this->agingRow(
                $invoice->number,
                $invoice->customer?->name ?? $invoice->customer_name ?? 'عميل غير محدد',
                $invoice->due_date?->toDateString() ?? $invoice->issued_at?->toDateString() ?? $invoice->created_at?->toDateString(),
                (float) ($receivableBalances[$invoice->id] ?? 0),
                $asOf,
            ))
            ->filter(fn (array $row) => $row['amount'] > 0.01)
            ->values();

        $apRows = SupplierInvoice::with('supplier')
            ->whereIn('id', array_keys($payableBalances))
            ->whereDate('invoice_date', '<=', $asOf)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('invoice_date')
            ->get()
            ->map(fn (SupplierInvoice $invoice) => $this->agingRow(
                $invoice->number,
                $invoice->supplier?->name ?? 'مورد غير محدد',
                $invoice->invoice_date?->toDateString(),
                (float) ($payableBalances[$invoice->id] ?? 0),
                $asOf,
            ))
            ->filter(fn (array $row) => $row['amount'] > 0.01)
            ->values();

        $arTotals = $this->agingTotals($arRows);
        $apTotals = $this->agingTotals($apRows);

        // Tie-out to the general ledger. The invoice-level aging total can differ
        // from the 1100 / 2000 account balance because AR/AP can also carry
        // opening balances and manual journal entries (which have no invoice
        // document) plus per-invoice negatives dropped above. Surfacing that
        // residual lets the report reconcile to the trial balance instead of
        // silently disagreeing with it.
        $arLedger = $this->bookBalanceForRole('accounts_receivable', 'debit', $asOf);
        $apLedger = $this->bookBalanceForRole('accounts_payable', 'credit', $asOf);

        return AdminShell::render('Admin/Accounting/Aging', [
            'asOf' => $asOf,
            'arRows' => $arRows,
            'apRows' => $apRows,
            'totals' => [
                'receivables' => $arTotals,
                'payables' => $apTotals,
                'receivablesLedger' => round($arLedger, 2),
                'payablesLedger' => round($apLedger, 2),
                'receivablesUnassigned' => round($arLedger - (float) $arTotals['total'], 2),
                'payablesUnassigned' => round($apLedger - (float) $apTotals['total'], 2),
            ],
            'currency' => $this->accountingCurrencyMeta(),
            'urls' => $this->accountingWorkspaceUrls() + ['index' => route('admin.accounting.aging')],
        ]);
    }

    public function reconciliations(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $accounts = Account::where('is_active', true)
            ->where('type', 'asset')
            ->orderBy('code')
            ->get();
        $selectedAccount = $accounts->firstWhere('id', (int) $request->get('account_id')) ?: $accounts->first();
        $statementDate = Carbon::parse($request->get('statement_date', now()))->toDateString();
        $bookBalance = $selectedAccount ? $this->bookBalanceForAccount($selectedAccount->id, $statementDate) : 0;
        $period = $this->periodForDate($statementDate);

        $reconciliations = $this->scopeToCurrentBranch(CashReconciliation::with('account', 'period', 'reconciler', 'resolver', 'resolutionEntry'))
            ->orderByDesc('statement_date')
            ->orderByDesc('id')
            ->paginate(20);

        return AdminShell::render('Admin/Accounting/Reconciliations', [
            'accounts' => $accounts->map(fn (Account $account) => [
                'id' => $account->id, 'code' => $account->code, 'name' => $account->name,
            ])->values(),
            'adjustmentAccounts' => Account::query()
                ->where('is_active', true)
                ->whereIn('type', ['expense', 'revenue', 'equity', 'contra_revenue'])
                ->orderBy('type')->orderBy('code')->get()
                ->map(fn (Account $account) => [
                    'id' => $account->id, 'code' => $account->code,
                    'name' => $account->name, 'type' => $account->type,
                ])->values(),
            'selectedAccount' => $selectedAccount ? [
                'id' => $selectedAccount->id, 'code' => $selectedAccount->code, 'name' => $selectedAccount->name,
            ] : null,
            'statementDate' => $statementDate,
            'bookBalance' => round($bookBalance, 2),
            'period' => $period ? ['id' => $period->id, 'name' => $period->name, 'closed' => $period->isClosed()] : null,
            'reconciliations' => [
                'data' => $reconciliations->getCollection()->map(fn (CashReconciliation $row) => [
                    'id' => $row->id,
                    'date' => $row->statement_date?->toDateString(),
                    'accountCode' => $row->account?->code,
                    'accountName' => $row->account?->name,
                    'bookBalance' => (float) $row->book_balance,
                    'statementBalance' => (float) $row->statement_balance,
                    'difference' => (float) $row->difference,
                    'reconciler' => $row->reconciler?->name,
                    'notes' => $row->notes,
                    'status' => $row->status,
                    'statusLabel' => match ($row->status) {
                        'matched' => 'مطابق', 'resolved' => 'فرق مسوّى', default => 'فرق يحتاج معالجة',
                    },
                    'resolver' => $row->resolver?->name,
                    'resolvedAt' => $row->resolved_at?->format('Y-m-d H:i'),
                    'resolutionNotes' => $row->resolution_notes,
                    'resolutionEntry' => $row->resolutionEntry?->entry_no,
                    'resolveUrl' => $row->status === 'variance'
                        ? route('admin.accounting.reconciliations.resolve', $row) : null,
                ])->values(),
                'links' => $reconciliations->linkCollection()->toArray(),
                'total' => $reconciliations->total(),
            ],
            'currency' => $this->accountingCurrencyMeta(),
            'urls' => $this->accountingWorkspaceUrls() + [
                'index' => route('admin.accounting.reconciliations'),
                'store' => route('admin.accounting.reconciliations.store'),
            ],
        ]);
    }

    public function storeReconciliation(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'statement_date' => ['required', 'date'],
            'statement_balance' => ['required', 'numeric'],
            'notes' => ['nullable', 'string'],
        ]);

        $account = Account::findOrFail($data['account_id']);
        if (! $account->is_active || $account->type !== 'asset') {
            return back()->withInput()->with('error', 'المطابقة مسموحة فقط لحسابات الأصول النشطة.');
        }

        $statementDate = Carbon::parse($data['statement_date'])->toDateString();
        $bookBalance = $this->bookBalanceForAccount($account->id, $statementDate);
        $statementBalance = round((float) $data['statement_balance'], 4);

        $reconciliation = CashReconciliation::create([
            'branch_id' => BranchContext::current(),
            'accounting_period_id' => $this->periodForDate($statementDate)?->id,
            'account_id' => $account->id,
            'statement_date' => $statementDate,
            'book_balance' => $bookBalance,
            'statement_balance' => $statementBalance,
            'difference' => round($statementBalance - $bookBalance, 4),
            'status' => abs($statementBalance - $bookBalance) <= 0.01 ? 'matched' : 'variance',
            'reconciled_at' => now(),
            'reconciled_by' => auth()->id(),
            'notes' => $data['notes'] ?? null,
        ]);

        ActivityLog::log('accounting.reconciled', 'مطابقة حساب '.$account->code, $reconciliation);

        return redirect()->route('admin.accounting.reconciliations', [
            'account_id' => $account->id,
            'statement_date' => $statementDate,
        ])->with('success', 'تم حفظ المطابقة.');
    }

    public function resolveReconciliation(Request $request, CashReconciliation $reconciliation)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);
        if (($branchId = BranchContext::current()) && (int) $reconciliation->branch_id !== (int) $branchId) {
            abort(404);
        }

        $data = $request->validate([
            'adjustment_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'posted_on' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['required', 'string', 'max:1000'],
        ]);

        try {
            DB::transaction(function () use ($reconciliation, $data) {
                $reconciliation = CashReconciliation::query()->whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();
                if ($reconciliation->status !== 'variance') {
                    throw new \RuntimeException('هذه المطابقة متوازنة أو تمت تسوية فرقها مسبقاً.');
                }

                $asset = Account::query()->whereKey($reconciliation->account_id)->where('is_active', true)->firstOrFail();
                $adjustment = Account::query()->whereKey($data['adjustment_account_id'])->where('is_active', true)->firstOrFail();
                if ((int) $asset->id === (int) $adjustment->id) {
                    throw new \RuntimeException('اختر حساب تسوية مختلفاً عن حساب الصندوق أو البنك.');
                }
                $difference = round((float) $reconciliation->difference, 4);
                $amount = abs($difference);
                if ($amount <= 0.01) {
                    throw new \RuntimeException('لا يوجد فرق يحتاج إلى قيد تسوية.');
                }

                $lines = $difference > 0
                    ? [
                        ['account' => $asset->code, 'debit' => $amount, 'credit' => 0],
                        ['account' => $adjustment->code, 'debit' => 0, 'credit' => $amount],
                    ]
                    : [
                        ['account' => $adjustment->code, 'debit' => $amount, 'credit' => 0],
                        ['account' => $asset->code, 'debit' => 0, 'credit' => $amount],
                    ];

                $entry = app(AccountingService::class)->post(
                    eventType: 'reconciliation_adjustment',
                    source: $reconciliation,
                    branchId: $reconciliation->branch_id,
                    postedOn: $data['posted_on'],
                    description: "تسوية فرق مطابقة {$asset->code} بتاريخ {$reconciliation->statement_date?->toDateString()}",
                    lines: $lines,
                    metadata: ['difference' => $difference, 'statement_date' => $reconciliation->statement_date?->toDateString()],
                    createdBy: auth()->id(),
                );

                $reconciliation->update([
                    'status' => 'resolved',
                    'resolution_journal_entry_id' => $entry?->id,
                    'resolved_by' => auth()->id(),
                    'resolved_at' => now(),
                    'resolution_notes' => trim($data['notes']),
                ]);
                ActivityLog::log('accounting.reconciliation_resolved', 'تسوية فرق المطابقة '.$reconciliation->id, $reconciliation, [
                    'journal_entry_id' => $entry?->id, 'difference' => $difference,
                ], causerId: auth()->id());
            });
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم ترحيل قيد تسوية الفرق وربطه بالمطابقة.');
    }

    public function settlements(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'as_of' => ['nullable', 'date'],
        ]);

        [$from, $to] = $this->dateRange($filters['from'] ?? now()->startOfMonth()->toDateString(), $filters['to'] ?? null);
        $asOf = Carbon::parse($filters['as_of'] ?? now())->toDateString();
        $taxAmounts = $this->taxSettlementAmounts($from, $to);
        $tipsPayable = max(0, $this->bookBalanceForRole('tips_payable', 'credit', $asOf));
        $accounting = app(AccountingService::class);
        $methodLabels = $this->paymentMethodOptions();
        $wallets = collect(['palpay', 'jawwal_pay'])->map(function (string $method) use ($accounting, $methodLabels, $asOf) {
            $accountCode = $accounting->paymentAccountCode($method);
            $configured = Account::query()->where('code', $accountCode)->exists();

            return [
                'method' => $method,
                'label' => $methodLabels[$method] ?? $method,
                'accountCode' => $accountCode,
                'balance' => $configured
                    ? round($accounting->availableWalletBalance($method, BranchContext::current(), $asOf), 2)
                    : 0,
                'configured' => $configured,
                'transferable' => $configured && ! in_array($accountCode, $this->postingRoleCodes('bank_account'), true),
            ];
        })->values();

        $recentSettlements = $this->scopeToCurrentBranch(
            JournalEntry::with(['lines.account', 'creator'])
                ->whereIn('event_type', ['tax_payment', 'tips_payout', 'wallet_to_bank', 'payment_clearing_settlement'])
        )
            ->orderByDesc('posted_on')
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString();

        return AdminShell::render('Admin/Accounting/Settlements', [
            'from' => $from,
            'to' => $to,
            'asOf' => $asOf,
            'taxAmounts' => [
                'label' => $taxAmounts['tax_label'],
                'output' => round((float) $taxAmounts['output_tax'], 2),
                'input' => round((float) $taxAmounts['input_tax'], 2),
                'payable' => round((float) $taxAmounts['payable'], 2),
            ],
            'tipsPayable' => round($tipsPayable, 2),
            'wallets' => $wallets,
            'paymentMethods' => collect($this->enabledPaymentMethodOptions())->map(fn ($label, $code) => [
                'code' => $code, 'label' => $label,
            ])->values(),
            'recentSettlements' => [
                'data' => $recentSettlements->getCollection()->map(fn (JournalEntry $entry) => [
                    'id' => $entry->id,
                    'date' => $entry->posted_on?->toDateString(),
                    'number' => $entry->entry_no,
                    'description' => $entry->description,
                    'type' => $entry->event_type,
                    'typeLabel' => $this->eventLabels()[$entry->event_type] ?? $entry->event_type,
                    'debit' => (float) $entry->lines->sum('debit'),
                    'credit' => (float) $entry->lines->sum('credit'),
                    'creator' => $entry->creator?->name,
                    'journalUrl' => route('admin.accounting.journal', ['search' => $entry->entry_no]),
                ])->values(),
                'links' => $recentSettlements->linkCollection()->toArray(),
                'total' => $recentSettlements->total(),
            ],
            'showTaxSettlement' => TaxConfiguration::isEnabled($to)
                || TaxConfiguration::hasHistory()
                || $taxAmounts['payable'] > 0.01,
            'currency' => $this->accountingCurrencyMeta(),
            'urls' => $this->accountingWorkspaceUrls() + [
                'index' => route('admin.accounting.settlements'),
                'taxPayment' => route('admin.accounting.settlements.tax-payment'),
                'tipsPayout' => route('admin.accounting.settlements.tips-payout'),
                'walletTransfer' => route('admin.accounting.settlements.wallet-transfer'),
            ],
        ]);
    }

    public function storeTaxPayment(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        if (! TaxConfiguration::switchIsOn() && ! TaxConfiguration::hasHistory()) {
            return back()->with('error', 'الضريبة معطلة ولا يوجد رصيد ضريبي تاريخي لتسويته.');
        }

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'posted_on' => ['required', 'date'],
            'payment_method' => ['required', 'string', Rule::in(array_keys($this->enabledPaymentMethodOptions()))],
        ]);

        [$from, $to] = $this->dateRange($data['from'], $data['to']);
        $postedOn = Carbon::parse($data['posted_on'])->toDateString();
        $taxAmounts = $this->taxSettlementAmounts($from, $to);

        if ($taxAmounts['payable'] <= 0.01) {
            return back()->withInput()->with('error', 'لا يوجد رصيد ضريبة مستحق لهذا المدى.');
        }

        try {
            $entry = app(AccountingService::class)->recordTaxPayment(
                outputTax: $taxAmounts['output_tax'],
                inputTax: $taxAmounts['input_tax'],
                paymentMethod: $data['payment_method'],
                branchId: BranchContext::current(),
                postedOn: $postedOn,
                createdBy: auth()->id(),
                metadata: [
                    'from' => $from,
                    'to' => $to,
                    'tax_label' => $taxAmounts['tax_label'],
                ],
            );
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        ActivityLog::log('accounting.tax_payment_posted', 'سداد ضريبة', $entry);

        return redirect()->route('admin.accounting.journal', ['event_type' => 'tax_payment'])
            ->with('success', 'تم ترحيل قيد سداد الضريبة.');
    }

    public function storeTipPayout(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $data = $request->validate([
            'posted_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'string', Rule::in(array_keys($this->enabledPaymentMethodOptions()))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $postedOn = Carbon::parse($data['posted_on'])->toDateString();
        $amount = round((float) $data['amount'], 4);
        $tipsPayable = max(0, $this->bookBalanceForRole('tips_payable', 'credit', $postedOn));

        if ($amount > $tipsPayable + 0.01) {
            return back()->withInput()->with('error', 'مبلغ صرف الإكراميات أكبر من الرصيد المستحق.');
        }

        try {
            $entry = app(AccountingService::class)->recordTipPayout(
                amount: $amount,
                paymentMethod: $data['payment_method'],
                branchId: BranchContext::current(),
                postedOn: $postedOn,
                createdBy: auth()->id(),
                notes: $data['notes'] ?? null,
            );
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        ActivityLog::log('accounting.tips_payout_posted', 'صرف إكراميات', $entry);

        return redirect()->route('admin.accounting.journal', ['event_type' => 'tips_payout'])
            ->with('success', 'تم ترحيل قيد صرف الإكراميات.');
    }

    public function storeWalletTransfer(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $data = $request->validate([
            '_idem' => ['nullable', 'uuid'],
            'wallet_method' => ['required', Rule::in(['palpay', 'jawwal_pay'])],
            'posted_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($dupe = $this->duplicateSubmitGuard($request)) {
            return $dupe;
        }

        try {
            $entry = app(AccountingService::class)->recordWalletTransfer(
                walletMethod: $data['wallet_method'],
                amount: round((float) $data['amount'], 4),
                branchId: BranchContext::current(),
                postedOn: Carbon::parse($data['posted_on'])->toDateString(),
                createdBy: auth()->id(),
                notes: $data['notes'] ?? null,
            );
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        ActivityLog::log('accounting.wallet_transferred', 'تحويل محفظة إلى البنك', $entry);

        return redirect()->route('admin.accounting.settlements', ['as_of' => $data['posted_on']])
            ->with('success', 'تم ترحيل المحفظة إلى البنك وتحديث الرصيدين.');
    }

    private function reversedEntryIds(): array
    {
        return $this->scopeToCurrentBranch(JournalEntry::query())
            ->whereNotNull('metadata')
            ->get(['metadata'])
            ->map(fn (JournalEntry $entry) => (int) ($entry->metadata['reverses_entry_id'] ?? 0))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function entryIsReversed(JournalEntry $entry): bool
    {
        return in_array((int) $entry->id, $this->reversedEntryIds(), true);
    }

    private function assertEntryInCurrentBranch(JournalEntry $entry): void
    {
        if (($branchId = BranchContext::current()) && (int) $entry->branch_id !== (int) $branchId) {
            abort(404);
        }
    }

    private function postLinesFromRequest(array $rawLines, mixed $postedOn, int $minimumLines = 2): array
    {
        $lines = collect($rawLines)->map(function (array $line) use ($postedOn) {
            $currencyCode = $this->normalizeCurrencyCode($line['currency_code'] ?? $this->accountingBaseCurrencyCode());
            $exchangeRate = (float) ($line['exchange_rate'] ?? ($currencyCode === $this->accountingBaseCurrencyCode() ? 1 : $this->configuredExchangeRate($currencyCode, $postedOn)));
            $hasForeignAmounts = array_key_exists('foreign_debit', $line) || array_key_exists('foreign_credit', $line);
            $foreignDebit = round((float) ($line['foreign_debit'] ?? 0), 4);
            $foreignCredit = round((float) ($line['foreign_credit'] ?? 0), 4);
            $debit = $hasForeignAmounts ? round($foreignDebit * $exchangeRate, 4) : round((float) ($line['debit'] ?? 0), 4);
            $credit = $hasForeignAmounts ? round($foreignCredit * $exchangeRate, 4) : round((float) ($line['credit'] ?? 0), 4);

            return [
                'account' => (int) ($line['account_id'] ?? 0),
                'debit' => $debit,
                'credit' => $credit,
                'currency_code' => $currencyCode,
                'exchange_rate' => $exchangeRate,
                'foreign_debit' => $hasForeignAmounts ? $foreignDebit : ($currencyCode === $this->accountingBaseCurrencyCode() ? $debit : round($debit / $exchangeRate, 4)),
                'foreign_credit' => $hasForeignAmounts ? $foreignCredit : ($currencyCode === $this->accountingBaseCurrencyCode() ? $credit : round($credit / $exchangeRate, 4)),
                'description' => $line['description'] ?? null,
            ];
        })->filter(fn ($line) => $line['debit'] > 0.0001 || $line['credit'] > 0.0001)->values();

        if ($lines->count() < $minimumLines) {
            throw new \RuntimeException($minimumLines > 1
                ? 'يلزم على الأقل سطرين (مدين + دائن) لقيد التصحيح.'
                : 'يلزم إدخال سطر واحد على الأقل.');
        }

        foreach ($lines as $index => $line) {
            if ($line['debit'] > 0.0001 && $line['credit'] > 0.0001) {
                throw new \RuntimeException('سطر #'.($index + 1).': لا يمكن أن يكون مدينا ودائنا في نفس الوقت.');
            }
        }

        $accounts = Account::whereIn('id', $lines->pluck('account')->unique()->all())->get()->keyBy('id');
        if (! TaxConfiguration::isEnabled($postedOn)
            && $lines->pluck('account')->intersect(TaxConfiguration::salesTaxAccountIds())->isNotEmpty()) {
            throw new \RuntimeException('حساب ضريبة مبيعات الزبائن معلق في هذا التاريخ. فعّل ضريبة فاتورة الزبون بتاريخ سريان مناسب قبل إنشاء هذا القيد.');
        }
        foreach ($lines as $line) {
            $account = $accounts->get($line['account']);
            if (! $account || ! $account->is_active) {
                throw new \RuntimeException('كل سطور التصحيح يجب أن تستخدم حسابات نشطة.');
            }
        }

        return $lines->map(fn ($line) => [
            'account' => $accounts->get($line['account'])->code,
            'debit' => $line['debit'],
            'credit' => $line['credit'],
            'currency_code' => $line['currency_code'],
            'exchange_rate' => $line['exchange_rate'],
            'foreign_debit' => $line['foreign_debit'],
            'foreign_credit' => $line['foreign_credit'],
            'description' => $line['description'],
        ])->all();
    }

    /**
     * Opening balances are a balance-sheet setup operation. Revenue and
     * expense accounts start from the fiscal period's activity, while customer
     * and supplier balances must go through their dedicated document forms so
     * collections, aging and supplier payments continue to work.
     */
    private function openingBalanceAccounts(): Collection
    {
        $blockedTaxAccountIds = TaxConfiguration::isEnabled() ? collect() : TaxConfiguration::salesTaxAccountIds();

        return Account::query()
            ->with('parent')
            ->where('is_active', true)
            ->whereNotIn('id', $blockedTaxAccountIds)
            ->whereIn('type', ['asset', 'liability', 'equity'])
            ->whereNotIn('code', [
                AccountingService::ACCOUNTS_RECEIVABLE,
                AccountingService::ACCOUNTS_PAYABLE,
                AccountingService::OPENING_BALANCE_EQUITY,
            ])
            ->orderByRaw("CASE type WHEN 'asset' THEN 1 WHEN 'liability' THEN 2 ELSE 3 END")
            ->orderBy('display_order')
            ->orderBy('code')
            ->get();
    }

    private function accountingBaseCurrencyCode(): string
    {
        return $this->normalizeCurrencyCode(Setting::get('accounting_base_currency', Currency::base()?->code ?? MarketProfile::currency()));
    }

    private function accountingCurrencies()
    {
        $currencies = Currency::orderByDesc('is_base')->orderBy('display_order')->orderBy('code')->get();
        if ($currencies->isNotEmpty()) {
            return $currencies;
        }

        return collect([
            new Currency([
                'code' => $this->accountingBaseCurrencyCode(),
                'name' => $this->accountingBaseCurrencyCode(),
                'symbol' => Setting::get('accounting_currency_symbol', Setting::get('currency_symbol', config('restaurant.currency_symbol', '$'))),
                'rate_to_base' => 1,
                'is_base' => true,
                'is_active' => true,
            ]),
        ]);
    }

    private function configuredExchangeRate(string $currencyCode, mixed $postedOn = null): float
    {
        $currencyCode = $this->normalizeCurrencyCode($currencyCode);
        if ($currencyCode === $this->accountingBaseCurrencyCode()) {
            return 1.0;
        }

        return app(ExchangeRateService::class)->rateFor($currencyCode, $this->accountingBaseCurrencyCode(), $postedOn);
    }

    private function normalizeCurrencyCode(mixed $code): string
    {
        $code = strtoupper(trim((string) $code));

        return $code !== '' ? $code : $this->accountingBaseCurrencyCode();
    }

    private function paymentMethodOptions(): array
    {
        return collect(PaymentMethods::catalog())
            ->mapWithKeys(fn (array $method, string $code) => [$code => $method['label']])
            ->all();
    }

    private function enabledPaymentMethodOptions(): array
    {
        $catalog = PaymentMethods::catalog();

        return collect(PaymentMethods::enabled())
            ->mapWithKeys(fn (string $code) => [$code => $catalog[$code]['label']])
            ->all();
    }

    private function syncAccountMappings(string $context, array $submitted, array $allowedKeys, array $allowedTypes): void
    {
        $allowedKeys = array_map('strval', $allowedKeys);

        AccountMapping::where('context', $context)->whereNotIn('key', $allowedKeys)->delete();

        foreach ($allowedKeys as $key) {
            $accountId = $submitted[$key] ?? null;

            if (! $accountId) {
                AccountMapping::where('context', $context)->where('key', $key)->delete();

                continue;
            }

            $account = Account::find((int) $accountId);
            if (! $account || ! $account->is_active || ! in_array($account->type, $allowedTypes, true)) {
                throw new \RuntimeException('تم اختيار حساب غير صالح لأحد الربوط.');
            }

            AccountMapping::updateOrCreate(
                ['context' => $context, 'key' => $key],
                ['account_id' => $account->id],
            );
        }
    }

    private function ledgerTotals(?string $from, string $to, ?int $branchId = null): array
    {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->when($from, fn ($query) => $query->whereDate('journal_entries.posted_on', '>=', $from))
            ->whereDate('journal_entries.posted_on', '<=', $to);

        $branchId ??= BranchContext::current();
        if ($branchId) {
            $query->where('journal_entries.branch_id', $branchId);
        }

        $row = $query->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as debit, COALESCE(SUM(journal_lines.credit), 0) as credit')->first();

        return [
            'debit' => round((float) ($row->debit ?? 0), 4),
            'credit' => round((float) ($row->credit ?? 0), 4),
        ];
    }

    private function ledgerAccountRows(?string $from, string $to, ?int $branchId = null)
    {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.status', 'posted')
            ->when($from, fn ($query) => $query->whereDate('journal_entries.posted_on', '>=', $from))
            ->whereDate('journal_entries.posted_on', '<=', $to);

        $branchId ??= BranchContext::current();
        if ($branchId) {
            $query->where('journal_entries.branch_id', $branchId);
        }

        return $query
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type', 'accounts.normal_balance')
            ->orderBy('accounts.code')
            ->selectRaw('
                accounts.id,
                accounts.code,
                accounts.name,
                accounts.type,
                accounts.normal_balance,
                COALESCE(SUM(journal_lines.debit), 0) as debit,
                COALESCE(SUM(journal_lines.credit), 0) as credit
            ')
            ->get()
            ->map(function ($row) {
                $row->debit = (float) $row->debit;
                $row->credit = (float) $row->credit;

                return $row;
            });
    }

    private function assertClosingChecklistClear(string $from, string $to, ?int $branchId): void
    {
        $blockers = collect($this->closingChecklist($from, $to, $branchId))
            ->filter(fn (array $item) => ($item['severity'] ?? '') === 'block' && ! ($item['ok'] ?? false))
            ->pluck('message')
            ->values();

        if ($blockers->isNotEmpty()) {
            throw new \RuntimeException('قبل الإقفال يجب معالجة: '.$blockers->implode('، '));
        }
    }

    /**
     * One-shot idempotency guard for source-less financial posts (manual journal,
     * opening balances). The form carries a fresh `_idem` UUID; the first submit
     * claims it atomically, a duplicate (F5 / back-then-resubmit) finds it taken
     * and is bounced with a clear message instead of double-posting.
     */
    private function duplicateSubmitGuard(Request $request)
    {
        $token = (string) $request->input('_idem', '');
        if ($token === '') {
            return null;   // stale cached form without the field — let it through
        }
        if (! Cache::add('idem:acct:'.$token, true, now()->addMinutes(10))) {
            return back()->withInput()->with('error', 'تم ترحيل هذا القيد مسبقاً — مُنع إرسال مكرر.');
        }

        return null;
    }

    private function closingChecklist(string $from, string $to, ?int $branchId): array
    {
        $ledger = $this->ledgerTotals($from, $to, $branchId);
        $activeSessions = TableSession::query()
            ->where('status', 'active')
            ->whereDate('opened_at', '<=', $to)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->count();

        $activeOrders = Order::query()
            ->whereIn('status', OrderStatus::active())
            ->where(function ($query) use ($to) {
                $query->whereDate('submitted_at', '<=', $to)
                    ->orWhere(function ($query) use ($to) {
                        $query->whereNull('submitted_at')->whereDate('created_at', '<=', $to);
                    });
            })
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->count();

        $openSupplierInvoices = SupplierInvoice::query()
            ->whereIn('status', ['unpaid', 'partially_paid'])
            ->whereDate('invoice_date', '<=', $to)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->count();

        $reconciliationsQuery = CashReconciliation::query()
            ->whereDate('statement_date', '>=', $from)
            ->whereDate('statement_date', '<=', $to)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId));
        $reconciliations = (clone $reconciliationsQuery)->count();
        // A saved reconciliation with a non-zero difference is NOT resolved — it
        // must not turn the checklist item green just by existing.
        $unbalancedReconciliations = (clone $reconciliationsQuery)
            ->whereRaw('ABS(difference) > 0.01')
            ->where('status', '!=', 'resolved')
            ->count();

        return [
            [
                'label' => 'توازن دفتر القيود',
                'ok' => abs($ledger['debit'] - $ledger['credit']) <= 0.01,
                'severity' => 'block',
                'message' => 'ميزان الفترة غير متوازن',
                'detail' => 'مدين '.number_format($ledger['debit'], 2).' / دائن '.number_format($ledger['credit'], 2),
            ],
            [
                'label' => 'إغلاق جلسات الطاولات',
                'ok' => $activeSessions === 0,
                'severity' => 'block',
                'message' => 'يوجد جلسات طاولات نشطة',
                'detail' => $activeSessions.' جلسة نشطة',
            ],
            [
                'label' => 'إنهاء الطلبات المفتوحة',
                'ok' => $activeOrders === 0,
                'severity' => 'block',
                'message' => 'يوجد طلبات لم تكتمل',
                'detail' => $activeOrders.' طلب مفتوح',
            ],
            [
                'label' => 'فواتير الموردين المفتوحة',
                'ok' => $openSupplierInvoices === 0,
                'severity' => $unbalancedReconciliations > 0 ? 'block' : 'warn',
                'message' => 'يوجد فواتير موردين غير مدفوعة بالكامل',
                'detail' => $openSupplierInvoices.' فاتورة مفتوحة',
            ],
            [
                'label' => 'مطابقة الصندوق/البنك',
                'ok' => $reconciliations > 0 && $unbalancedReconciliations === 0,
                'severity' => 'warn',
                'message' => $reconciliations === 0
                    ? 'لا توجد مطابقة صندوق أو بنك داخل المدى'
                    : 'توجد مطابقة بفرق غير مسوّى بين الدفتر والكشف',
                'detail' => $unbalancedReconciliations > 0
                    ? $reconciliations.' مطابقة — منها '.$unbalancedReconciliations.' بفرق غير مسوّى'
                    : $reconciliations.' مطابقة محفوظة',
            ],
        ];
    }

    private function periodClosingPayload(AccountingPeriod|FiscalYear $period): array
    {
        $rows = $this->ledgerAccountRows($period->starts_on->toDateString(), $period->ends_on->toDateString(), $period->branch_id)
            ->filter(fn ($row) => in_array($row->type, ['revenue', 'contra_revenue', 'expense'], true))
            ->sortBy('code')
            ->values();

        $lines = [];
        $debitTotal = 0.0;
        $creditTotal = 0.0;

        foreach ($rows as $row) {
            $net = round((float) $row->debit - (float) $row->credit, 4);
            if (abs($net) <= 0.0001) {
                continue;
            }

            $amount = abs($net);
            $line = [
                'account' => $row->code,
                'debit' => $net < 0 ? $amount : 0.0,
                'credit' => $net > 0 ? $amount : 0.0,
                'description' => 'إقفال رصيد '.$row->code.' - '.$row->name,
            ];

            $debitTotal += (float) $line['debit'];
            $creditTotal += (float) $line['credit'];
            $lines[] = $line;
        }

        $netIncome = round($debitTotal - $creditTotal, 4);
        if (abs($netIncome) > 0.0001) {
            $retainedEarnings = $this->postingRoleAccount('retained_earnings');
            $lines[] = [
                'account' => $retainedEarnings->code,
                'debit' => $netIncome < 0 ? abs($netIncome) : 0.0,
                'credit' => $netIncome > 0 ? $netIncome : 0.0,
                'description' => $netIncome > 0
                    ? 'ترحيل صافي ربح الفترة إلى الأرباح المحتجزة'
                    : 'ترحيل صافي خسارة الفترة إلى الأرباح المحتجزة',
            ];
        }

        return [
            'lines' => $lines,
            'net_income' => $netIncome,
        ];
    }

    private function balanceSheetRows($rows, array $types, string $normalBalance)
    {
        return collect($rows)
            ->whereIn('type', $types)
            ->map(function ($row) use ($normalBalance) {
                $row->balance = round($normalBalance === 'credit'
                    ? (float) $row->credit - (float) $row->debit
                    : (float) $row->debit - (float) $row->credit, 2);

                return $row;
            })
            ->filter(fn ($row) => abs((float) $row->balance) > 0.0001)
            ->values();
    }

    private function currentEarnings($rows): float
    {
        return round((float) collect($rows)
            ->filter(fn ($row) => in_array($row->type, ['revenue', 'contra_revenue', 'expense'], true))
            ->sum(function ($row) {
                return match ($row->type) {
                    'revenue' => (float) $row->credit - (float) $row->debit,
                    'contra_revenue', 'expense' => -((float) $row->debit - (float) $row->credit),
                    default => 0,
                };
            }), 2);
    }

    private function netForCodes($rows, array $codes, string $normalBalance): float
    {
        return collect($rows)
            ->filter(fn ($row) => in_array($row->code, $codes, true))
            ->sum(fn ($row) => $normalBalance === 'credit'
                ? (float) $row->credit - (float) $row->debit
                : (float) $row->debit - (float) $row->credit);
    }

    private function bookBalanceForAccount(int $accountId, string $asOf): float
    {
        $account = Account::findOrFail($accountId);
        $rows = $this->ledgerAccountRows(null, $asOf);
        $row = collect($rows)->firstWhere('id', $account->id);
        if (! $row) {
            return 0.0;
        }

        return round($account->normal_balance === 'credit'
            ? (float) $row->credit - (float) $row->debit
            : (float) $row->debit - (float) $row->credit, 4);
    }

    private function bookBalanceForRole(string $role, string $normalBalance, string $asOf): float
    {
        return round($this->netForCodes(
            $this->ledgerAccountRows(null, $asOf),
            $this->postingRoleCodes($role),
            $normalBalance,
        ), 4);
    }

    private function taxSettlementAmounts(string $from, string $to): array
    {
        // Balance-based settlement: the payable is the OUTSTANDING balance of the
        // VAT accounts as of TODAY (cumulative from the start of the books), which
        // every prior tax_payment already reduced (it debits 2100 / credits 1300).
        // We measure as of today — NOT the range end $to — because a settlement is
        // dated when it's actually paid (often after the period it covers). Using
        // $to would exclude that later payment and let the same range be paid
        // twice. Measuring to today captures every prior payment, so re-opening
        // shows only what's still owed and a payment never bleeds into the next
        // period. $from/$to are kept only for the on-screen label / metadata.
        $rows = $this->ledgerAccountRows(null, now()->toDateString());
        $outputTax = round(max(0, $this->netForCodes($rows, $this->postingRoleCodes('output_vat'), 'credit')), 2);
        $inputTax = round(max(0, $this->netForCodes($rows, $this->postingRoleCodes('input_vat'), 'debit')), 2);
        $payable = round(max(0, $outputTax - $inputTax), 2);

        return [
            'output_tax' => $outputTax,
            'input_tax' => $inputTax,
            'payable' => $payable,
            'tax_label' => MarketProfile::taxLabel(),
        ];
    }

    private function postingRoleCodes(string $role): array
    {
        $definition = AccountingService::postingRoleDefinitions()[$role] ?? null;
        if (! $definition) {
            return [];
        }

        $codes = [$definition['default']];
        $mapping = AccountMapping::with('account')
            ->where('context', AccountMapping::CONTEXT_POSTING_ROLE)
            ->where('key', $role)
            ->first();
        $account = $mapping?->account;

        if ($account && $account->is_active && in_array($account->type, $definition['types'], true)) {
            $codes[] = $account->code;
        }

        return collect($codes)->filter()->unique()->values()->all();
    }

    private function postingRoleAccount(string $role): Account
    {
        $definition = AccountingService::postingRoleDefinitions()[$role] ?? null;
        if (! $definition) {
            throw new \RuntimeException("دور ترحيل غير معروف: {$role}");
        }

        $mapping = AccountMapping::with('account')
            ->where('context', AccountMapping::CONTEXT_POSTING_ROLE)
            ->where('key', $role)
            ->first();
        $account = $mapping?->account;
        if ($account && $account->is_active && in_array($account->type, $definition['types'], true)) {
            return $account;
        }

        return Account::where('code', $definition['default'])->firstOrFail();
    }

    private function receivableLedgerBalances(string $asOf, ?int $branchId): array
    {
        return $this->ledgerDocumentBalances(
            asOf: $asOf,
            branchId: $branchId,
            accountCodes: $this->postingRoleCodes('accounts_receivable'),
            documentSourceType: Invoice::class,
            paymentSourceType: Payment::class,
            paymentTable: 'payments',
            paymentDocumentColumn: 'invoice_id',
            normalBalance: 'debit',
        );
    }

    private function payableLedgerBalances(string $asOf, ?int $branchId): array
    {
        return $this->ledgerDocumentBalances(
            asOf: $asOf,
            branchId: $branchId,
            accountCodes: $this->postingRoleCodes('accounts_payable'),
            documentSourceType: SupplierInvoice::class,
            paymentSourceType: SupplierPayment::class,
            paymentTable: 'supplier_payments',
            paymentDocumentColumn: 'supplier_invoice_id',
            normalBalance: 'credit',
        );
    }

    private function ledgerDocumentBalances(
        string $asOf,
        ?int $branchId,
        array $accountCodes,
        string $documentSourceType,
        string $paymentSourceType,
        string $paymentTable,
        string $paymentDocumentColumn,
        string $normalBalance,
    ): array {
        if ($accountCodes === []) {
            return [];
        }

        $balances = [];

        $directRows = $this->ledgerDocumentBalanceQuery($asOf, $branchId, $accountCodes)
            ->where('journal_entries.source_type', $documentSourceType)
            ->whereNotNull('journal_entries.source_id')
            ->groupBy('journal_entries.source_id')
            ->selectRaw('
                journal_entries.source_id as document_id,
                COALESCE(SUM(journal_lines.debit), 0) as debit,
                COALESCE(SUM(journal_lines.credit), 0) as credit
            ')
            ->get();

        foreach ($directRows as $row) {
            $this->addLedgerDocumentBalance($balances, $row, $normalBalance);
        }

        $paymentRows = $this->ledgerDocumentBalanceQuery($asOf, $branchId, $accountCodes)
            ->join($paymentTable, "{$paymentTable}.id", '=', 'journal_entries.source_id')
            ->where('journal_entries.source_type', $paymentSourceType)
            ->whereNotNull("{$paymentTable}.{$paymentDocumentColumn}")
            ->groupBy("{$paymentTable}.{$paymentDocumentColumn}")
            ->selectRaw("
                {$paymentTable}.{$paymentDocumentColumn} as document_id,
                COALESCE(SUM(journal_lines.debit), 0) as debit,
                COALESCE(SUM(journal_lines.credit), 0) as credit
            ")
            ->get();

        foreach ($paymentRows as $row) {
            $this->addLedgerDocumentBalance($balances, $row, $normalBalance);
        }

        return collect($balances)
            ->filter(fn (float $balance) => $balance > 0.01)
            ->map(fn (float $balance) => round($balance, 2))
            ->all();
    }

    private function ledgerDocumentBalanceQuery(string $asOf, ?int $branchId, array $accountCodes)
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.posted_on', '<=', $asOf)
            ->whereIn('accounts.code', $accountCodes)
            ->when($branchId, fn ($query) => $query->where('journal_entries.branch_id', $branchId));
    }

    private function addLedgerDocumentBalance(array &$balances, object $row, string $normalBalance): void
    {
        $documentId = (int) ($row->document_id ?? 0);
        if ($documentId <= 0) {
            return;
        }

        $amount = $normalBalance === 'credit'
            ? (float) $row->credit - (float) $row->debit
            : (float) $row->debit - (float) $row->credit;

        $balances[$documentId] = round(($balances[$documentId] ?? 0) + $amount, 4);
    }

    private function agingRow(string $number, string $party, ?string $date, float $amount, string $asOf): array
    {
        $days = $date ? max(0, Carbon::parse($date)->diffInDays(Carbon::parse($asOf), false)) : 0;
        $bucket = match (true) {
            $days <= 30 => 'current',
            $days <= 60 => '31_60',
            $days <= 90 => '61_90',
            default => 'over_90',
        };

        return [
            'number' => $number,
            'party' => $party,
            'date' => $date,
            'days' => $days,
            'amount' => round($amount, 2),
            'bucket' => $bucket,
        ];
    }

    private function agingTotals($rows): array
    {
        $totals = [
            'current' => 0.0,
            '31_60' => 0.0,
            '61_90' => 0.0,
            'over_90' => 0.0,
            'total' => 0.0,
        ];

        foreach ($rows as $row) {
            $totals[$row['bucket']] += $row['amount'];
            $totals['total'] += $row['amount'];
        }

        return array_map(fn ($value) => round((float) $value, 2), $totals);
    }

    private function periodForDate(string $date): ?AccountingPeriod
    {
        $branchId = BranchContext::current();

        return AccountingPeriod::query()
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
    }

    /**
     * A short exception queue for the accounting home. Accountants should not
     * have to open five reports just to discover what prevents a clean close.
     */
    private function accountingAttention(string $today, ?AccountingPeriod $currentPeriod): array
    {
        $branchId = BranchContext::current();
        $items = collect();
        $urls = $this->accountingWorkspaceUrls();

        if (! $currentPeriod) {
            $items->push([
                'key' => 'period_missing',
                'severity' => 'critical',
                'title' => 'لا توجد فترة مالية تغطي اليوم',
                'detail' => 'أنشئ السنة والفترة قبل اعتماد أي ترحيل جديد.',
                'icon' => 'bi-calendar-x',
                'countLabel' => null,
                'amount' => null,
                'actionLabel' => 'إعداد الفترات',
                'url' => $urls['fiscalYears'] ?? $urls['periods'] ?? null,
            ]);
        } elseif ($currentPeriod->isClosed()) {
            $items->push([
                'key' => 'period_closed',
                'severity' => 'critical',
                'title' => 'فترة اليوم مقفلة',
                'detail' => "{$currentPeriod->name} تمنع الترحيل بتاريخ اليوم؛ راجع الإقفال قبل التشغيل.",
                'icon' => 'bi-lock-fill',
                'countLabel' => null,
                'amount' => null,
                'actionLabel' => 'مراجعة الفترة',
                'url' => $urls['periods'] ?? null,
            ]);
        }

        $missingMappings = $this->missingPostingRoleCount();
        if ($missingMappings > 0) {
            $items->push([
                'key' => 'posting_mappings',
                'severity' => 'critical',
                'title' => 'ربط الترحيل غير مكتمل',
                'detail' => 'بعض العمليات لا تملك حساباً نظامياً صالحاً لاستقبال القيد.',
                'icon' => 'bi-diagram-3-fill',
                'countLabel' => $missingMappings.' ربط',
                'amount' => null,
                'actionLabel' => 'إكمال الربط',
                'url' => $urls['mappings'] ?? $urls['accounts'] ?? null,
            ]);
        }

        $varianceQuery = $this->scopeToCurrentBranch(CashReconciliation::query())
            ->where('status', 'variance');
        $varianceCount = (clone $varianceQuery)->count();
        if ($varianceCount > 0) {
            $items->push([
                'key' => 'reconciliation_variances',
                'severity' => 'critical',
                'title' => 'فروقات صندوق أو بنك غير مسوّاة',
                'detail' => 'افتح المطابقة وحدد سبب كل فرق أو أنشئ قيد التسوية الموثق.',
                'icon' => 'bi-exclamation-diamond-fill',
                'countLabel' => $varianceCount.' مطابقة',
                'amount' => round((float) (clone $varianceQuery)->selectRaw('SUM(ABS(difference)) as total')->value('total'), 2),
                'actionLabel' => 'معالجة الفروقات',
                'url' => $urls['reconciliations'] ?? null,
            ]);
        }

        $overdueReceivables = Invoice::query()
            ->where('status', '!=', 'cancelled')
            ->where('balance', '>', 0.01)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId));
        $overdueReceivablesCount = (clone $overdueReceivables)->count();
        if ($overdueReceivablesCount > 0) {
            $items->push([
                'key' => 'overdue_receivables',
                'severity' => 'warning',
                'title' => 'ذمم زبائن تجاوزت موعدها',
                'detail' => 'راجع أعمار الديون وحدد ما سيُحصّل أو يُسوّى أو يُشطب بصلاحية.',
                'icon' => 'bi-person-exclamation',
                'countLabel' => $overdueReceivablesCount.' فاتورة',
                'amount' => round((float) (clone $overdueReceivables)->sum('balance'), 2),
                'actionLabel' => 'فتح أعمار الذمم',
                'url' => $urls['aging'],
            ]);
        }

        $overduePayables = SupplierInvoice::query()
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->where('balance', '>', 0.01)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId));
        $overduePayablesCount = (clone $overduePayables)->count();
        if ($overduePayablesCount > 0) {
            $items->push([
                'key' => 'overdue_payables',
                'severity' => 'warning',
                'title' => 'مستحقات موردين متأخرة',
                'detail' => 'راجع الفواتير المتأخرة ورتّب السداد من شاشة الذمم.',
                'icon' => 'bi-truck-front-fill',
                'countLabel' => $overduePayablesCount.' فاتورة',
                'amount' => round((float) (clone $overduePayables)->sum('balance'), 2),
                'actionLabel' => 'فتح أعمار الذمم',
                'url' => $urls['aging'],
            ]);
        }

        return $items->values()->all();
    }

    private function missingPostingRoleCount(): int
    {
        $definitions = collect(AccountingService::postingRoleDefinitions())
            ->when(! TaxConfiguration::isEnabled(), fn (Collection $roles) => $roles->except(['output_vat']));
        $mappings = AccountMapping::with('account')
            ->where('context', AccountMapping::CONTEXT_POSTING_ROLE)
            ->get()
            ->keyBy('key');
        $fallbacks = Account::query()
            ->whereIn('code', $definitions->pluck('default')->filter()->all())
            ->get()
            ->keyBy('code');

        return $definitions->filter(function (array $definition, string $key) use ($mappings, $fallbacks) {
            $mapped = $mappings->get($key)?->account;
            if ($mapped && $mapped->is_active && in_array($mapped->type, $definition['types'], true)) {
                return false;
            }

            $fallback = $fallbacks->get($definition['default']);

            return ! $fallback
                || ! $fallback->is_active
                || ! in_array($fallback->type, $definition['types'], true);
        })->count();
    }

    private function assertPeriodInCurrentBranch(AccountingPeriod $period): void
    {
        if (($branchId = BranchContext::current()) && (int) $period->branch_id !== (int) $branchId) {
            abort(404);
        }
    }

    private function assertFiscalYearInCurrentBranch(FiscalYear $year): void
    {
        if (($branchId = BranchContext::current()) && (int) $year->branch_id !== (int) $branchId) {
            abort(404);
        }
    }

    private function suggestedOpeningDate(): string
    {
        $branchId = BranchContext::current();
        if (! $branchId) {
            return now()->toDateString();
        }

        $firstOperationalDate = JournalEntry::query()
            ->where('branch_id', $branchId)
            ->where('event_type', '!=', 'opening_balance')
            ->min('posted_on');

        return $firstOperationalDate
            ? Carbon::parse($firstOperationalDate)->toDateString()
            : now()->toDateString();
    }

    private function journalQueryForBranch(?int $branchId): Builder
    {
        return JournalEntry::query()
            ->where('status', 'posted')
            ->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId));
    }

    private function journalExistsInRange(string $from, string $to, ?int $branchId): bool
    {
        return $this->journalQueryForBranch($branchId)
            ->whereBetween('posted_on', [$from, $to])
            ->exists();
    }

    private function createMonthlyPeriods(FiscalYear $year): int
    {
        $cursor = $year->starts_on->copy();
        $lastDate = $year->ends_on->copy();
        $created = 0;

        while ($cursor->lte($lastDate)) {
            $startsOn = $cursor->copy();
            $endsOn = $cursor->copy()->endOfMonth()->min($lastDate);

            $existing = AccountingPeriod::query()
                ->where(function ($query) use ($year) {
                    $year->branch_id
                        ? $query->where('branch_id', $year->branch_id)
                        : $query->whereNull('branch_id');
                })
                ->whereDate('starts_on', $startsOn->toDateString())
                ->whereDate('ends_on', $endsOn->toDateString())
                ->first();

            if ($existing) {
                if (! $existing->fiscal_year_id) {
                    $existing->update(['fiscal_year_id' => $year->id]);
                }
            } else {
                $overlap = AccountingPeriod::query()
                    ->where(function ($query) use ($year) {
                        $year->branch_id
                            ? $query->where('branch_id', $year->branch_id)
                            : $query->whereNull('branch_id');
                    })
                    ->whereDate('starts_on', '<=', $endsOn->toDateString())
                    ->whereDate('ends_on', '>=', $startsOn->toDateString())
                    ->exists();

                if (! $overlap) {
                    AccountingPeriod::create([
                        'branch_id' => $year->branch_id,
                        'fiscal_year_id' => $year->id,
                        'name' => 'شهر '.$startsOn->format('m/Y'),
                        'starts_on' => $startsOn,
                        'ends_on' => $endsOn,
                        'status' => 'open',
                    ]);
                    $created++;
                }
            }

            $cursor = $endsOn->copy()->addDay();
        }

        return $created;
    }

    private function syncPostingRoleMappings(array $submitted, array $definitions): void
    {
        $allowedKeys = array_keys($definitions);

        AccountMapping::where('context', AccountMapping::CONTEXT_POSTING_ROLE)
            ->whereNotIn('key', $allowedKeys)
            ->delete();

        foreach ($definitions as $key => $definition) {
            $accountId = $submitted[$key] ?? null;

            if (! $accountId) {
                AccountMapping::where('context', AccountMapping::CONTEXT_POSTING_ROLE)
                    ->where('key', $key)
                    ->delete();

                continue;
            }

            $allowedTypes = $definition['types'] ?? [];
            $account = Account::find((int) $accountId);
            if (! $account || ! $account->is_active || ! in_array($account->type, $allowedTypes, true)) {
                throw new \RuntimeException('تم اختيار حساب غير صالح لإحدى قواعد الترحيل.');
            }

            AccountMapping::updateOrCreate(
                ['context' => AccountMapping::CONTEXT_POSTING_ROLE, 'key' => $key],
                ['account_id' => $account->id],
            );
        }
    }

    /**
     * These are real receiving identifiers, not general-ledger account codes.
     * Keeping them beside the mappings lets the accountant configure only the
     * financial destination fields without gaining access to all system settings.
     */
    private function paymentIdentifierValues(): array
    {
        return collect($this->paymentIdentifierKeys())
            ->mapWithKeys(fn (string $key) => [$key => trim((string) Setting::get($key, ''))])
            ->all();
    }

    private function storePaymentIdentifiers(array $values): void
    {
        foreach ($this->paymentIdentifierKeys() as $key) {
            $value = trim((string) ($values[$key] ?? ''));

            if ($value === '') {
                Setting::query()->where('key', $key)->delete();
                Cache::forget('setting.'.$key);

                continue;
            }

            Setting::put($key, $value, 'payment_destinations', 'string');
        }
    }

    private function paymentIdentifierKeys(): array
    {
        return [
            'bank_name',
            'bank_account_holder',
            'bank_account_number',
            'bank_iban',
            'palpay_wallet_number',
            'jawwal_pay_wallet_number',
        ];
    }

    /**
     * Stable internal navigation for every accounting Vue workspace. Keeping
     * it server-owned prevents a user from seeing an action their permission
     * set does not allow and keeps the global sidebar deliberately small.
     */
    private function accountingWorkspaceUrls(): array
    {
        return AccountingWorkspace::urls();
    }

    private function accountingCurrencyMeta(): array
    {
        $code = $this->accountingBaseCurrencyCode();
        $currency = Currency::query()->where('code', $code)->first();

        return [
            'code' => $code,
            'symbol' => $currency?->symbol ?: $code,
            'decimals' => 2,
        ];
    }
}
