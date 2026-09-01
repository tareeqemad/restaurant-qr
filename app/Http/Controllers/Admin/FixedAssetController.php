<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\Setting;
use App\Models\Supplier;
use App\Services\Accounting\AccountingService;
use App\Services\ExchangeRateService;
use App\Support\BranchContext;
use App\Support\AdminShell;
use App\Support\MarketProfile;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FixedAssetController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.viewAny'), 403);

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:30'],
            'category' => ['nullable', 'string', 'max:120'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $query = FixedAsset::with(['branch', 'purchaseEntry', 'disposalEntry'])
            ->orderByDesc('acquisition_date')
            ->orderByDesc('id');

        $query
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->when($filters['category'] ?? null, fn ($q, string $category) => $q->where('category', $category))
            ->when(trim((string) ($filters['search'] ?? '')), function ($q, string $search) {
                $q->where(function ($nested) use ($search) {
                    $nested->where('asset_number', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('vendor_name', 'like', "%{$search}%");
                });
            });

        $assets = $query->paginate(20)->withQueryString();
        $statsRows = FixedAsset::query()->get();

        $canCreate = (bool) auth()->user()?->hasPermission('chart_of_accounts.create');
        $canUpdate = (bool) auth()->user()?->hasPermission('chart_of_accounts.update');

        return AdminShell::render('Admin/Accounting/FixedAssets', [
            'assets' => [
                'data' => $assets->getCollection()->map(fn (FixedAsset $asset) => [
                    'id' => $asset->id,
                    'number' => $asset->asset_number,
                    'name' => $asset->name,
                    'category' => $asset->category,
                    'vendor' => $asset->vendor_name,
                    'date' => $asset->acquisition_date?->toDateString(),
                    'cost' => (float) $asset->cost,
                    'accumulated' => (float) $asset->accumulated_depreciation,
                    'bookValue' => $asset->bookValue(),
                    'status' => $asset->status,
                    'showUrl' => route('admin.accounting.fixed-assets.show', $asset),
                ])->values(),
                'links' => $assets->linkCollection()->toArray(),
                'total' => $assets->total(),
            ],
            'stats' => [
                'count' => $statsRows->count(),
                'active' => $statsRows->where('status', 'active')->count(),
                'cost' => round((float) $statsRows->sum('cost'), 2),
                'accumulated' => round((float) $statsRows->sum('accumulated_depreciation'), 2),
                'book' => round((float) $statsRows->sum(fn (FixedAsset $asset) => $asset->bookValue()), 2),
            ],
            'statusLabels' => $this->statusLabels(),
            'categories' => FixedAsset::query()
                ->whereNotNull('category')
                ->distinct()
                ->orderBy('category')
                ->pluck('category'),
            'filters' => $filters,
            'defaultPeriodMonth' => now()->format('Y-m'),
            'defaultPostedOn' => now()->endOfMonth()->toDateString(),
            'can' => ['create' => $canCreate, 'update' => $canUpdate],
            'currency' => ['code' => $this->accountingBaseCurrencyCode(), 'symbol' => MarketProfile::currencySymbol()],
            'urls' => $this->workspaceUrls() + [
                'index' => route('admin.accounting.fixed-assets.index'),
                'create' => $canCreate ? route('admin.accounting.fixed-assets.create') : null,
                'runDepreciation' => $canUpdate ? route('admin.accounting.fixed-assets.depreciation-run') : null,
            ],
        ]);
    }

    public function create()
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);

        $asset = new FixedAsset([
                'asset_number' => FixedAsset::generateNumber(),
                'acquisition_date' => now()->toDateString(),
                'in_service_date' => now()->toDateString(),
                'currency_code' => $this->accountingBaseCurrencyCode(),
                'exchange_rate' => 1,
                'useful_life_months' => 60,
                'payment_method' => 'bank_transfer',
            ]);

        return AdminShell::render('Admin/Accounting/FixedAssetCreate', [
            'defaults' => [
                'assetNumber' => $asset->asset_number,
                'acquisitionDate' => $asset->acquisition_date?->toDateString(),
                'inServiceDate' => $asset->in_service_date?->toDateString(),
                'currencyCode' => $asset->currency_code,
                'exchangeRate' => (float) $asset->exchange_rate,
                'usefulLifeMonths' => $asset->useful_life_months,
                'paymentMethod' => $asset->payment_method,
            ],
            'currencies' => $this->accountingCurrencies()->map(fn (Currency $currency) => [
                'code' => $currency->code, 'name' => $currency->name,
                'rate' => $currency->is_base ? 1 : (float) ($currency->rate_to_base ?: 0),
                'base' => (bool) $currency->is_base,
            ])->values(),
            'paymentMethods' => collect($this->paymentMethods())->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values(),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name'])->map(fn (Supplier $supplier) => ['id' => $supplier->id, 'name' => $supplier->name])->values(),
            'hasBranch' => (bool) BranchContext::current(),
            'urls' => $this->workspaceUrls() + [
                'index' => route('admin.accounting.fixed-assets.index'),
                'store' => route('admin.accounting.fixed-assets.store'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);

        if (! BranchContext::current()) {
            return back()->withInput()->with('error', 'اختر فرعا قبل إضافة أصل ثابت.');
        }

        $data = $this->validatedAssetData($request);

        try {
            $amounts = $this->assetAmounts($data);
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        if ($amounts['salvage_value'] >= $amounts['cost']) {
            return back()->withInput()->with('error', 'القيمة التخريدية يجب أن تكون أقل من تكلفة الأصل.');
        }

        try {
            $asset = DB::transaction(function () use ($data, $amounts) {
                $asset = FixedAsset::create([
                    ...$data,
                    ...$amounts,
                    'branch_id' => BranchContext::current(),
                    'depreciation_method' => 'straight_line',
                    'status' => 'active',
                    'created_by' => auth()->id(),
                ]);

                $entry = app(AccountingService::class)->recordFixedAssetAcquisition($asset);
                $asset->update(['purchase_journal_entry_id' => $entry?->id]);

                return $asset->fresh(['purchaseEntry']);
            });
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        ActivityLog::log('fixed_asset.created', "إضافة أصل ثابت {$asset->asset_number} - {$asset->name}", $asset);

        return redirect()->route('admin.accounting.fixed-assets.show', $asset)
            ->with('success', 'تمت إضافة الأصل وترحيل قيد الشراء.');
    }

    public function show(FixedAsset $fixedAsset)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.viewAny'), 403);

        $fixedAsset->load(['purchaseEntry', 'disposalEntry', 'depreciations.journalEntry', 'supplier', 'creator']);

        return AdminShell::render('Admin/Accounting/FixedAssetShow', [
            'asset' => [
                'id' => $fixedAsset->id,
                'number' => $fixedAsset->asset_number,
                'name' => $fixedAsset->name,
                'status' => $fixedAsset->status,
                'statusLabel' => $this->statusLabels()[$fixedAsset->status] ?? $fixedAsset->status,
                'category' => $fixedAsset->category,
                'description' => $fixedAsset->description,
                'vendor' => $fixedAsset->supplier?->name ?? $fixedAsset->vendor_name,
                'acquisitionDate' => $fixedAsset->acquisition_date?->toDateString(),
                'inServiceDate' => $fixedAsset->in_service_date?->toDateString(),
                'usefulLifeMonths' => $fixedAsset->useful_life_months,
                'currencyCode' => $fixedAsset->currency_code,
                'exchangeRate' => (float) $fixedAsset->exchange_rate,
                'foreignCost' => (float) $fixedAsset->foreign_cost,
                'cost' => (float) $fixedAsset->cost,
                'salvageValue' => (float) $fixedAsset->salvage_value,
                'accumulated' => (float) $fixedAsset->accumulated_depreciation,
                'bookValue' => $fixedAsset->bookValue(),
                'monthlyDepreciation' => $fixedAsset->monthlyDepreciationAmount(),
                'remainingDepreciable' => $fixedAsset->remainingDepreciableAmount(),
                'depreciatedThrough' => $fixedAsset->depreciated_through?->toDateString(),
                'notes' => $fixedAsset->notes,
                'disposedOn' => $fixedAsset->disposed_on?->toDateString(),
                'disposalProceeds' => (float) $fixedAsset->disposal_proceeds,
                'purchaseEntry' => $fixedAsset->purchaseEntry ? [
                    'number' => $fixedAsset->purchaseEntry->entry_no,
                    'url' => route('admin.accounting.journal', ['search' => $fixedAsset->purchaseEntry->entry_no]),
                ] : null,
                'disposalEntry' => $fixedAsset->disposalEntry ? [
                    'number' => $fixedAsset->disposalEntry->entry_no,
                    'url' => route('admin.accounting.journal', ['search' => $fixedAsset->disposalEntry->entry_no]),
                ] : null,
                'depreciations' => $fixedAsset->depreciations->sortByDesc('period_end')->values()->map(fn (FixedAssetDepreciation $row) => [
                    'id' => $row->id,
                    'period' => $row->period_start?->format('Y-m'),
                    'postedOn' => $row->posted_on?->toDateString(),
                    'amount' => (float) $row->amount,
                    'accumulatedAfter' => (float) $row->accumulated_after,
                    'notes' => $row->notes,
                    'entry' => $row->journalEntry ? [
                        'number' => $row->journalEntry->entry_no,
                        'url' => route('admin.accounting.journal', ['search' => $row->journalEntry->entry_no]),
                    ] : null,
                ])->values(),
            ],
            'disposalPaymentMethods' => collect($this->disposalPaymentMethods())->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values(),
            'nextDepreciationAmount' => round($fixedAsset->depreciationAmountForPeriod(now()->endOfMonth()), 2),
            'canUpdate' => (bool) auth()->user()?->hasPermission('chart_of_accounts.update'),
            'currency' => ['code' => $this->accountingBaseCurrencyCode(), 'symbol' => MarketProfile::currencySymbol()],
            'urls' => $this->workspaceUrls() + [
                'index' => route('admin.accounting.fixed-assets.index'),
                'depreciation' => route('admin.accounting.fixed-assets.depreciation', $fixedAsset),
                'dispose' => route('admin.accounting.fixed-assets.dispose', $fixedAsset),
            ],
        ]);
    }

    public function storeDepreciation(Request $request, FixedAsset $fixedAsset)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $data = $request->validate([
            'period_month' => ['required', 'date_format:Y-m'],
            'posted_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($fixedAsset->status === 'disposed') {
            return back()->withInput()->with('error', 'لا يمكن ترحيل إهلاك لأصل مستبعد.');
        }

        $periodStart = Carbon::parse($data['period_month'].'-01')->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();
        if ($fixedAsset->in_service_date && $fixedAsset->in_service_date->gt($periodEnd)) {
            return back()->withInput()->with('error', 'لا يمكن إهلاك الأصل قبل تاريخ بدء الاستخدام.');
        }
        if ($fixedAsset->depreciations()
            ->whereDate('period_start', $periodStart->toDateString())
            ->whereDate('period_end', $periodEnd->toDateString())
            ->exists()) {
            return back()->withInput()->with('error', 'تم ترحيل إهلاك هذه الفترة مسبقا.');
        }

        $amount = $fixedAsset->depreciationAmountForPeriod($periodEnd);
        if ($amount <= 0.01) {
            return back()->withInput()->with('error', 'الأصل لا يملك قيمة قابلة للإهلاك لهذه الفترة.');
        }

        try {
            $depreciation = DB::transaction(function () use ($fixedAsset, $data, $periodStart, $periodEnd, $amount) {
                return $this->createDepreciationForAsset(
                    $fixedAsset,
                    $periodStart,
                    $periodEnd,
                    Carbon::parse($data['posted_on']),
                    $data['notes'] ?? null,
                    $amount,
                );
            });
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        ActivityLog::log('fixed_asset.depreciation_posted', "ترحيل إهلاك {$fixedAsset->asset_number}", $depreciation);

        return redirect()->route('admin.accounting.fixed-assets.show', $fixedAsset)
            ->with('success', 'تم ترحيل قيد الإهلاك.');
    }

    public function runDepreciation(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $data = $request->validate([
            'period_month' => ['required', 'date_format:Y-m'],
            'posted_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $periodStart = Carbon::parse($data['period_month'].'-01')->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();
        $postedOn = Carbon::parse($data['posted_on']);

        try {
            $result = DB::transaction(function () use ($periodStart, $periodEnd, $postedOn, $data) {
                $posted = 0;
                $skipped = 0;
                $total = 0.0;

                $assets = FixedAsset::query()
                    ->where('status', 'active')
                    ->whereDate('in_service_date', '<=', $periodEnd->toDateString())
                    ->orderBy('asset_number')
                    ->lockForUpdate()
                    ->get();

                foreach ($assets as $asset) {
                    if ($asset->depreciations()
                        ->whereDate('period_start', $periodStart->toDateString())
                        ->whereDate('period_end', $periodEnd->toDateString())
                        ->exists()) {
                        $skipped++;
                        continue;
                    }

                    $amount = $asset->depreciationAmountForPeriod($periodEnd);
                    if ($amount <= 0.01) {
                        $skipped++;
                        continue;
                    }

                    $this->createDepreciationForAsset(
                        $asset,
                        $periodStart,
                        $periodEnd,
                        $postedOn,
                        $data['notes'] ?? null,
                        $amount,
                    );

                    $posted++;
                    $total += $amount;
                }

                return [
                    'posted' => $posted,
                    'skipped' => $skipped,
                    'total' => round($total, 2),
                ];
            });
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        if ($result['posted'] === 0) {
            return redirect()->route('admin.accounting.fixed-assets.index')
                ->with('info', 'لا توجد أصول مستحقة لترحيل إهلاك هذا الشهر.');
        }

        ActivityLog::log(
            'fixed_asset.depreciation_batch_posted',
            "ترحيل إهلاك شهري لعدد {$result['posted']} أصول بإجمالي {$result['total']}",
            null,
            ['period' => $data['period_month'], 'posted' => $result['posted'], 'skipped' => $result['skipped']]
        );

        return redirect()->route('admin.accounting.fixed-assets.index')
            ->with('success', "تم ترحيل إهلاك {$result['posted']} أصول بإجمالي ".\App\Helpers\Money::formatAccounting($result['total']).'.');
    }

    public function dispose(Request $request, FixedAsset $fixedAsset)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $data = $request->validate([
            'disposed_on' => ['required', 'date'],
            'disposal_proceeds' => ['required', 'numeric', 'min:0'],
            'disposal_payment_method' => ['nullable', 'string', Rule::in(array_keys($this->disposalPaymentMethods()))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($fixedAsset->status === 'disposed') {
            return back()->withInput()->with('error', 'هذا الأصل مستبعد مسبقا.');
        }

        try {
            $entry = DB::transaction(function () use ($fixedAsset, $data) {
                $entry = app(AccountingService::class)->recordFixedAssetDisposal(
                    asset: $fixedAsset,
                    proceeds: (float) $data['disposal_proceeds'],
                    paymentMethod: $data['disposal_payment_method'] ?? 'bank_transfer',
                    postedOn: Carbon::parse($data['disposed_on'])->toDateString(),
                    createdBy: auth()->id(),
                    notes: $data['notes'] ?? null,
                );

                $fixedAsset->update([
                    'status' => 'disposed',
                    'disposed_on' => Carbon::parse($data['disposed_on'])->toDateString(),
                    'disposal_proceeds' => (float) $data['disposal_proceeds'],
                    'disposal_payment_method' => $data['disposal_payment_method'] ?? null,
                    'disposal_journal_entry_id' => $entry?->id,
                    'notes' => trim((string) $fixedAsset->notes."\n".($data['notes'] ?? '')) ?: $fixedAsset->notes,
                ]);

                return $entry;
            });
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        ActivityLog::log('fixed_asset.disposed', "استبعاد أصل ثابت {$fixedAsset->asset_number}", $fixedAsset->fresh());

        return redirect()->route('admin.accounting.fixed-assets.show', $fixedAsset)
            ->with('success', 'تم استبعاد الأصل وترحيل القيد.');
    }

    private function validatedAssetData(Request $request): array
    {
        return $request->validate([
            'asset_number' => ['nullable', 'string', 'max:40', 'unique:fixed_assets,asset_number'],
            'name' => ['required', 'string', 'max:191'],
            'category' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'vendor_name' => ['nullable', 'string', 'max:150'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'acquisition_date' => ['required', 'date'],
            'in_service_date' => ['required', 'date', 'after_or_equal:acquisition_date'],
            'foreign_cost' => ['required', 'numeric', 'gt:0'],
            'foreign_salvage_value' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['required', 'string', 'max:5'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'useful_life_months' => ['required', 'integer', 'min:1', 'max:600'],
            'payment_method' => ['required', 'string', Rule::in(array_keys($this->paymentMethods()))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function assetAmounts(array $data): array
    {
        $currencyCode = strtoupper(trim($data['currency_code']));
        $baseCurrencyCode = $this->accountingBaseCurrencyCode();
        $exchangeRate = $currencyCode === $baseCurrencyCode
            ? 1.0
            : (float) ($data['exchange_rate'] ?? app(ExchangeRateService::class)->rateFor($currencyCode, $baseCurrencyCode, $data['acquisition_date']));
        $foreignCost = round((float) $data['foreign_cost'], 4);
        $foreignSalvage = round((float) ($data['foreign_salvage_value'] ?? 0), 4);

        return [
            'currency_code' => $currencyCode,
            'exchange_rate' => $exchangeRate,
            'foreign_cost' => $foreignCost,
            'foreign_salvage_value' => $foreignSalvage,
            'cost' => round($foreignCost * $exchangeRate, 4),
            'salvage_value' => round($foreignSalvage * $exchangeRate, 4),
        ];
    }

    private function accountingBaseCurrencyCode(): string
    {
        return strtoupper(trim((string) Setting::get('accounting_base_currency', Currency::base()?->code ?? MarketProfile::currency())));
    }

    private function accountingCurrencies()
    {
        return Currency::where('is_active', true)
            ->orderByDesc('is_base')
            ->orderBy('code')
            ->get()
            ->whenEmpty(fn () => collect([
                new Currency([
                    'code' => $this->accountingBaseCurrencyCode(),
                    'name' => $this->accountingBaseCurrencyCode(),
                    'symbol' => Setting::get('accounting_currency_symbol', Setting::get('currency_symbol', '$')),
                    'rate_to_base' => 1,
                    'is_base' => true,
                    'is_active' => true,
                ]),
            ]));
    }

    private function paymentMethods(): array
    {
        return [
            'bank_transfer' => 'تحويل/بنك',
            'cash' => 'نقدي',
            'card' => 'بطاقة',
            'cheque' => 'شيك',
            'accounts_payable' => 'ذمم موردين',
            'owner_capital' => 'مساهمة مالك',
            'other' => 'أخرى',
        ];
    }

    private function disposalPaymentMethods(): array
    {
        return [
            'bank_transfer' => 'تحويل/بنك',
            'cash' => 'نقدي',
            'card' => 'بطاقة',
            'cheque' => 'شيك',
            'other' => 'أخرى',
        ];
    }

    private function statusLabels(): array
    {
        return [
            'active' => 'نشط',
            'fully_depreciated' => 'مهلك بالكامل',
            'disposed' => 'مستبعد',
        ];
    }

    private function workspaceUrls(): array
    {
        $user = auth()->user();
        $canCreate = (bool) $user?->hasPermission('chart_of_accounts.create');
        $canUpdate = (bool) $user?->hasPermission('chart_of_accounts.update');

        return [
            'home' => route('admin.accounting.index'), 'guide' => route('admin.accounting.guide'),
            'journal' => route('admin.accounting.journal'), 'ledger' => route('admin.accounting.ledger'),
            'trialBalance' => route('admin.accounting.trial-balance'), 'profitLoss' => route('admin.reports.profit-loss'),
            'balanceSheet' => route('admin.accounting.balance-sheet'), 'aging' => route('admin.accounting.aging'),
            'taxReport' => route('admin.accounting.tax-report'), 'accounts' => route('admin.accounts.index'),
            'openingBalances' => $canCreate ? route('admin.accounting.opening-balances') : null,
            'manualEntry' => $canCreate ? route('admin.accounting.manual-entry.create') : null,
            'fiscalYears' => $canUpdate ? route('admin.accounting.fiscal-years') : null,
            'periods' => $canUpdate ? route('admin.accounting.periods') : null,
            'mappings' => $canUpdate ? route('admin.accounting.mappings') : null,
            'reconciliations' => $canUpdate ? route('admin.accounting.reconciliations') : null,
            'settlements' => $canUpdate ? route('admin.accounting.settlements') : null,
            'fixedAssets' => route('admin.accounting.fixed-assets.index'),
        ];
    }

    private function createDepreciationForAsset(
        FixedAsset $asset,
        Carbon $periodStart,
        Carbon $periodEnd,
        Carbon $postedOn,
        ?string $notes,
        ?float $amount = null,
    ): FixedAssetDepreciation {
        $amount ??= $asset->depreciationAmountForPeriod($periodEnd);
        $accumulatedAfter = round((float) $asset->accumulated_depreciation + $amount, 4);

        $depreciation = FixedAssetDepreciation::create([
            'branch_id' => $asset->branch_id,
            'fixed_asset_id' => $asset->id,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'posted_on' => $postedOn->toDateString(),
            'amount' => $amount,
            'accumulated_after' => $accumulatedAfter,
            'created_by' => auth()->id(),
            'notes' => $notes,
        ]);

        $entry = app(AccountingService::class)->recordFixedAssetDepreciation($depreciation);
        $depreciation->update(['journal_entry_id' => $entry?->id]);

        $asset->update([
            'accumulated_depreciation' => $accumulatedAfter,
            'depreciated_through' => $periodEnd->toDateString(),
            'status' => $accumulatedAfter >= $asset->depreciableAmount() - 0.01 ? 'fully_depreciated' : 'active',
        ]);

        return $depreciation->fresh('journalEntry');
    }
}
