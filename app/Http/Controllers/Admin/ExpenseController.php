<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\Money;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\Lookup;
use App\Models\Setting;
use App\Models\Supplier;
use App\Services\Accounting\AccountingService;
use App\Services\ExchangeRateService;
use App\Support\AdminShell;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Branch-scoped expense management.
 *
 *   index   — list with filter rail + KPIs (this-month + today + pending)
 *   create  — manual entry (defaults today's date)
 *   store   — validate + persist; auto-numbered EXP-YYYYMMDD-NNNN
 *   edit    — only while pending
 *   approve — flips to approved and posts the accounting effect
 *   reject  — with reason; record stays for audit, no cash effect
 *   destroy — admin-only soft delete; refuses if cash already posted
 */
class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Expense::class);

        $query = Expense::with(['supplier', 'branch', 'category'])
            ->orderBy('expense_date', 'desc')
            ->orderBy('id', 'desc');

        // Excel export: same filters as the list, streams an .xlsx with KPIs
        // header + per-row data + totals row. Applied AFTER filters below.

        // ─── Filters ───────────────────────────────────────────────
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($categoryId = $request->get('category_id')) {
            $query->forCategory((int) $categoryId);
        }
        if ($method = $request->get('payment_method')) {
            $query->where('payment_method', $method);
        }
        if ($from = $request->get('from')) {
            $query->whereDate('expense_date', '>=', $from);
        }
        if ($to = $request->get('to')) {
            $query->whereDate('expense_date', '<=', $to);
        }
        if ($search = trim((string) $request->get('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('expense_number', 'like', "%{$search}%")
                    ->orWhere('vendor_name', 'like', "%{$search}%")
                    ->orWhere('payment_reference', 'like', "%{$search}%");
            });
        }

        $filteredQuery = clone $query;
        $hasFilters = $request->hasAny(['search', 'from', 'to', 'category_id', 'status', 'payment_method']);
        $filteredStats = null;

        if ($hasFilters || $request->get('export') === 'xlsx') {
            $filteredStats = [
                'count' => (clone $filteredQuery)->count(),
                'total' => $this->baseAmountSum(clone $filteredQuery),
                'pending' => (clone $filteredQuery)->where('status', 'pending_approval')->count(),
                'approved' => (clone $filteredQuery)->where('status', 'approved')->count(),
            ];
        }

        if ($request->get('export') === 'xlsx') {
            return $this->exportXlsx(
                (clone $filteredQuery)->get(),
                $filteredStats,
                $request->only(['from', 'to', 'category_id', 'status', 'payment_method', 'search'])
            );
        }

        $expenses = $query->paginate(25)->withQueryString();

        // ─── KPIs (computed on the same branch-scoped baseline) ────
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $stats = [
            'pending' => Expense::pending()->count(),
            'today_total' => $this->baseAmountSum(Expense::approved()->whereDate('expense_date', $today)),
            'month_total' => $this->baseAmountSum(Expense::approved()->whereDate('expense_date', '>=', $monthStart)),
        ];

        $user = auth()->user();
        $baseCurrencyCode = app(ExchangeRateService::class)->baseCurrencyCode();
        $showBranch = (bool) session('view_all_branches');

        $expenses->through(fn (Expense $expense) => [
            'id' => $expense->id,
            'number' => $expense->expense_number,
            'description' => $expense->description,
            'vendor' => $expense->supplier?->name ?: $expense->vendor_name,
            'notes' => $expense->notes,
            'category' => [
                'label' => $expense->category?->label ?? 'غير مصنّف',
                'color' => $expense->category?->color,
            ],
            'amount' => $expense->formatMoney(),
            'baseAmount' => ($expense->currency_code ?: $baseCurrencyCode) !== $baseCurrencyCode
                ? number_format($expense->baseAmount(), 2).' '.$baseCurrencyCode
                : null,
            'paymentMethod' => $expense->paymentMethodLabel(),
            'paymentReference' => $expense->payment_reference,
            'date' => $expense->expense_date->format('Y/m/d'),
            'status' => $expense->status,
            'statusLabel' => $expense->statusLabel(),
            'statusColor' => $expense->statusColor(),
            'rejectionReason' => $expense->rejection_reason,
            'attachmentUrl' => $expense->attachment_path ? Storage::url($expense->attachment_path) : null,
            'branch' => $showBranch && $expense->branch ? [
                'name' => $expense->branch->localizedName(),
                'hue' => ($expense->branch->id * 47) % 360,
            ] : null,
            'can' => [
                'approve' => (bool) $user?->can('approve', $expense),
                'reject' => (bool) $user?->can('reject', $expense),
                'update' => (bool) $user?->can('update', $expense),
                'delete' => (bool) $user?->can('delete', $expense),
            ],
            'urls' => [
                'edit' => route('admin.expenses.edit', $expense),
                'approve' => route('admin.expenses.approve', $expense),
                'reject' => route('admin.expenses.reject', $expense),
                'destroy' => route('admin.expenses.destroy', $expense),
            ],
        ]);

        return AdminShell::render('Admin/Expenses/Index', [
            'expenses' => $expenses,
            'stats' => [
                'pending' => $stats['pending'],
                'todayTotal' => Money::format($stats['today_total']),
                'monthTotal' => Money::format($stats['month_total']),
            ],
            'filteredStats' => $filteredStats ? [
                ...$filteredStats,
                'totalFormatted' => Money::format($filteredStats['total']),
            ] : null,
            'filters' => [
                'search' => (string) $request->get('search', ''),
                'from' => (string) $request->get('from', ''),
                'to' => (string) $request->get('to', ''),
                'categoryId' => (string) $request->get('category_id', ''),
                'status' => (string) $request->get('status', ''),
                'paymentMethod' => (string) $request->get('payment_method', ''),
            ],
            'categories' => Lookup::for('expense_categories')->map(fn (Lookup $category) => [
                'id' => $category->id,
                'label' => $category->label,
            ])->values(),
            'paymentMethods' => collect(Expense::PAYMENT_METHODS)->map(fn ($label, $value) => [
                'value' => $value,
                'label' => $label,
            ])->values(),
            'can' => [
                'create' => (bool) $user?->can('create', Expense::class),
                'manageCategories' => (bool) $user?->can('viewAny', Lookup::class),
            ],
            'urls' => [
                'index' => route('admin.expenses.index'),
                'create' => route('admin.expenses.create'),
                'categories' => route('admin.lookups.index', ['group' => 'expense_categories']),
            ],
        ]);
    }

    public function create()
    {
        $this->authorize('create', Expense::class);

        return AdminShell::render('Admin/Expenses/Form', $this->expenseFormData(
            new Expense([
                'expense_date' => now()->toDateString(),
                'payment_method' => 'cash',
                'currency_code' => app(ExchangeRateService::class)->baseCurrencyCode(),
                'exchange_rate' => 1,
            ])
        ));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Expense::class);

        $data = $this->resolveCurrency($this->validateData($request));

        if (! BranchContext::current()) {
            return back()->withInput()->with('error',
                'اختر فرعاً قبل إضافة المصروف — لا يمكن التسجيل في وضع «كل الفروع».');
        }

        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('expenses', 'public');
        }

        $expense = Expense::create([
            ...$data,
            'created_by_user_id' => auth()->id(),
            'status' => 'pending_approval',
        ]);

        ActivityLog::log('expense.created',
            "تسجيل مصروف {$expense->expense_number} ({$expense->description}) — {$expense->amount}",
            $expense
        );

        return redirect()->route('admin.expenses.index')
            ->with('success', "تم تسجيل المصروف {$expense->expense_number} وهو بانتظار الاعتماد.");
    }

    public function edit(Expense $expense)
    {
        $this->authorize('update', $expense);

        return AdminShell::render('Admin/Expenses/Form', $this->expenseFormData($expense));
    }

    public function update(Request $request, Expense $expense)
    {
        $this->authorize('update', $expense);

        $data = $this->resolveCurrency($this->validateData($request));

        if ($request->hasFile('attachment')) {
            // Drop the previous attachment if we're replacing it.
            if ($expense->attachment_path) {
                Storage::disk('public')->delete($expense->attachment_path);
            }
            $data['attachment_path'] = $request->file('attachment')->store('expenses', 'public');
        }

        $expense->update($data);

        ActivityLog::log('expense.updated',
            "تعديل مصروف {$expense->expense_number}",
            $expense
        );

        return redirect()->route('admin.expenses.index')
            ->with('success', 'تم حفظ التعديلات.');
    }

    public function approve(Expense $expense)
    {
        $this->authorize('approve', $expense);

        DB::transaction(function () use ($expense) {
            $expense->update([
                'status' => 'approved',
                'approved_by_user_id' => auth()->id(),
                'approved_at' => now(),
            ]);

            app(AccountingService::class)->recordExpenseApproved($expense->fresh());
        });

        ActivityLog::log('expense.approved',
            "اعتماد مصروف {$expense->expense_number} ({$expense->amount})",
            $expense
        );

        return back()->with('success', 'تم اعتماد المصروف وتسجيل أثره المحاسبي.');
    }

    public function reject(Request $request, Expense $expense)
    {
        $this->authorize('reject', $expense);

        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:255'],
        ]);

        $expense->update([
            'status' => 'rejected',
            'rejection_reason' => $data['rejection_reason'],
            'approved_by_user_id' => auth()->id(),
            'approved_at' => now(),
        ]);

        ActivityLog::log('expense.rejected',
            "رفض مصروف {$expense->expense_number}: {$data['rejection_reason']}",
            $expense
        );

        return back()->with('success', 'تم رفض المصروف.');
    }

    public function destroy(Expense $expense)
    {
        $this->authorize('delete', $expense);

        $number = $expense->expense_number;

        if ($expense->attachment_path) {
            Storage::disk('public')->delete($expense->attachment_path);
        }
        $expense->delete();

        ActivityLog::log('expense.deleted', "حذف مصروف {$number}");

        return redirect()->route('admin.expenses.index')
            ->with('success', 'تم حذف المصروف.');
    }

    // ─── Helpers ───────────────────────────────────────────────────

    protected function validateData(Request $request): array
    {
        return $request->validate([
            'expense_category_id' => ['required', Rule::exists('lookups', 'id')->where(fn ($q) => $q->where('group', 'expense_categories')->where('is_active', true)
            )],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'currency_code' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')->where('is_active', true)],
            'exchange_rate' => ['nullable', 'numeric', 'min:0.000001', 'max:999999'],
            'payment_method' => ['required', Rule::in(array_keys(Expense::PAYMENT_METHODS))],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'vendor_name' => ['nullable', 'string', 'max:150'],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ]);
    }

    protected function resolveCurrency(array $data): array
    {
        $exchangeRates = app(ExchangeRateService::class);
        $baseCurrency = $exchangeRates->baseCurrencyCode();
        $currencyCode = $exchangeRates->normalizeCode($data['currency_code'] ?? $baseCurrency);

        $data['currency_code'] = $currencyCode;
        $data['exchange_rate'] = $currencyCode === $baseCurrency
            ? 1.0
            : (float) ($data['exchange_rate'] ?? $exchangeRates->rateFor($currencyCode, $baseCurrency, $data['expense_date']));

        return $data;
    }

    protected function currencyFormData(): array
    {
        return [
            'currencies' => Currency::where('is_active', true)->orderByDesc('is_base')->orderBy('display_order')->get(),
            'baseCurrencyCode' => app(ExchangeRateService::class)->baseCurrencyCode(),
        ];
    }

    protected function expenseFormData(Expense $expense): array
    {
        $user = auth()->user();
        $baseCurrencyCode = app(ExchangeRateService::class)->baseCurrencyCode();

        return [
            'expense' => [
                'id' => $expense->id,
                'number' => $expense->expense_number,
                'description' => $expense->description ?? '',
                'amount' => $expense->amount ?? '',
                'currencyCode' => $expense->currency_code ?: $baseCurrencyCode,
                'exchangeRate' => $expense->exchange_rate ?: 1,
                'categoryId' => $expense->expense_category_id,
                'paymentMethod' => $expense->payment_method ?: 'cash',
                'paymentReference' => $expense->payment_reference ?? '',
                'vendorName' => $expense->vendor_name ?? '',
                'supplierId' => $expense->supplier_id,
                'date' => optional($expense->expense_date)->format('Y-m-d') ?: now()->toDateString(),
                'notes' => $expense->notes ?? '',
                'attachmentUrl' => $expense->attachment_path ? Storage::url($expense->attachment_path) : null,
            ],
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name'])
                ->map(fn (Supplier $supplier) => ['id' => $supplier->id, 'name' => $supplier->name])
                ->values(),
            'categories' => Lookup::for('expense_categories')
                ->map(fn (Lookup $category) => ['id' => $category->id, 'label' => $category->label])
                ->values(),
            'paymentMethods' => collect(Expense::PAYMENT_METHODS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'currencies' => Currency::where('is_active', true)
                ->orderByDesc('is_base')->orderBy('display_order')->get()
                ->map(fn (Currency $currency) => [
                    'code' => $currency->code,
                    'name' => $currency->name,
                    'rate' => (float) $currency->rate_to_base,
                    'base' => (bool) $currency->is_base,
                ])->values(),
            'baseCurrencyCode' => $baseCurrencyCode,
            'canManageCategories' => (bool) $user?->can('viewAny', Lookup::class),
            'urls' => [
                'index' => route('admin.expenses.index'),
                'submit' => $expense->exists
                    ? route('admin.expenses.update', $expense)
                    : route('admin.expenses.store'),
                'categories' => route('admin.lookups.index', ['group' => 'expense_categories']),
            ],
        ];
    }

    protected function baseAmountSum($query): float
    {
        return (float) ((clone $query)
            ->selectRaw('COALESCE(SUM(amount * COALESCE(exchange_rate, 1)), 0) as base_total')
            ->value('base_total') ?? 0);
    }

    /**
     * Stream the filtered expenses as a real .xlsx workbook (Office Open XML
     * → UTF-8 by spec, so Arabic survives the round-trip through Excel on
     * Arabic Windows where CSV would mojibake).
     *
     * Layout:
     *   - Block A1:B7  — header card (title + filter window + totals)
     *   - Row 9        — column headers (green band)
     *   - Row 10..N    — one expense per row, status colour-coded
     *   - Row N+1      — totals (SUM of amount, COUNT row)
     */
    protected function exportXlsx($expenses, array $filteredStats, array $appliedFilters)
    {
        $branchName = ($branchId = BranchContext::current())
            ? Branch::find($branchId)?->name
            : 'كل الفروع';
        $currency = Setting::get('currency_symbol', config('restaurant.currency_symbol', '₪'));
        $baseCurrencyCode = app(ExchangeRateService::class)->baseCurrencyCode();
        $stamp = now()->format('Y-m-d_H-i');
        $filename = "expenses_{$stamp}.xlsx";

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('المصروفات التشغيلية');
        $sheet->setRightToLeft(true);

        $S = Alignment::class;
        $F = Fill::class;
        $B = Border::class;

        // ─── Header card ───────────────────────────────────────────
        $sheet->setCellValue('A1', 'تقرير المصروفات التشغيلية');
        $sheet->mergeCells('A1:L1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => $F::FILL_SOLID, 'startColor' => ['rgb' => '0F4731']],
            'alignment' => ['horizontal' => $S::HORIZONTAL_CENTER, 'vertical' => $S::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(34);

        $meta = [
            ['الفرع', $branchName ?? '—'],
            ['تاريخ التصدير', now()->format('Y-m-d H:i')],
            ['الفترة',
                ($appliedFilters['from'] ?? null) || ($appliedFilters['to'] ?? null)
                    ? trim(($appliedFilters['from'] ?? '…').' → '.($appliedFilters['to'] ?? '…'))
                    : 'كل التواريخ',
            ],
            ['عدد السجلات', number_format($filteredStats['count'])],
            ['إجمالي المبلغ', number_format($filteredStats['total'], 2).' '.$currency],
            ['بانتظار الاعتماد · معتمدة',
                number_format($filteredStats['pending']).' · '.number_format($filteredStats['approved'])],
        ];
        foreach ($meta as $i => [$label, $value]) {
            $r = 2 + $i;
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $value);
            $sheet->mergeCells("B{$r}:L{$r}");
        }
        $sheet->getStyle('A2:A7')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => $F::FILL_SOLID, 'startColor' => ['rgb' => 'EEF6F1']],
            'alignment' => ['horizontal' => $S::HORIZONTAL_RIGHT, 'vertical' => $S::VERTICAL_CENTER, 'indent' => 1],
        ]);
        $sheet->getStyle('B2:L7')->applyFromArray([
            'alignment' => ['horizontal' => $S::HORIZONTAL_RIGHT, 'vertical' => $S::VERTICAL_CENTER, 'indent' => 1],
            'borders' => ['bottom' => ['borderStyle' => $B::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
        ]);

        // ─── Column headers ────────────────────────────────────────
        $headers = [
            '#',                        // A
            'رقم المصروف',              // B
            'التاريخ',                  // C
            'الوصف',                    // D
            'التصنيف',                  // E
            'المورد',                   // F
            'المبلغ الأصلي',            // G
            'العملة',                    // H
            'سعر الصرف',                 // I
            "القيمة بالدفاتر ({$baseCurrencyCode})", // J
            'طريقة الدفع',              // K
            'الحالة',                   // L
        ];
        $sheet->fromArray($headers, null, 'A9');
        $sheet->getStyle('A9:L9')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => $F::FILL_SOLID, 'startColor' => ['rgb' => '0F4731']],
            'alignment' => ['horizontal' => $S::HORIZONTAL_CENTER, 'vertical' => $S::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(9)->setRowHeight(26);

        // ─── Data rows ─────────────────────────────────────────────
        $statusLabels = [
            'pending_approval' => 'بانتظار الاعتماد',
            'approved' => 'معتمد',
            'rejected' => 'مرفوض',
        ];
        $statusTint = [
            'pending_approval' => 'FFF6E0', // amber
            'approved' => 'EEF6F1', // green
            'rejected' => 'FBE9E9', // red
        ];

        $row = 10;
        $index = 1;
        foreach ($expenses as $exp) {
            $statusKey = $exp->status;
            $sheet->fromArray([
                $index,
                $exp->expense_number,
                optional($exp->expense_date)->format('Y-m-d'),
                $exp->description,
                $exp->category?->label ?? '— محذوف —',
                $exp->supplier?->name ?? $exp->vendor_name ?? '—',
                (float) $exp->amount,
                $exp->currency_code ?: $baseCurrencyCode,
                (float) ($exp->exchange_rate ?: 1),
                $exp->baseAmount(),
                $exp->paymentMethodLabel(),
                $statusLabels[$statusKey] ?? $statusKey,
            ], null, "A{$row}");

            if ($tint = $statusTint[$statusKey] ?? null) {
                $sheet->getStyle("A{$row}:L{$row}")->applyFromArray([
                    'fill' => ['fillType' => $F::FILL_SOLID, 'startColor' => ['rgb' => $tint]],
                ]);
            }

            $row++;
            $index++;
        }

        // ─── Totals + formatting ───────────────────────────────────
        $lastRow = $row - 1;
        if ($lastRow >= 10) {
            $sheet->getStyle("G10:G{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("I10:I{$lastRow}")->getNumberFormat()->setFormatCode('0.000000');
            $sheet->getStyle("J10:J{$lastRow}")->getNumberFormat()
                ->setFormatCode("#,##0.00 \"{$baseCurrencyCode}\"");

            $totalRow = $lastRow + 1;
            $sheet->setCellValue("A{$totalRow}", 'الإجمالي');
            $sheet->mergeCells("A{$totalRow}:I{$totalRow}");
            $sheet->setCellValue("J{$totalRow}", "=SUM(J10:J{$lastRow})");
            $sheet->setCellValue("K{$totalRow}", '');
            $sheet->setCellValue("L{$totalRow}", count($expenses).' سجل');
            $sheet->getStyle("A{$totalRow}:L{$totalRow}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '0F4731']],
                'fill' => ['fillType' => $F::FILL_SOLID, 'startColor' => ['rgb' => 'D4E4D9']],
                'borders' => ['top' => ['borderStyle' => $B::BORDER_MEDIUM, 'color' => ['rgb' => '0F4731']]],
                'alignment' => ['vertical' => $S::VERTICAL_CENTER],
            ]);
            $sheet->getStyle("J{$totalRow}")->getNumberFormat()
                ->setFormatCode("#,##0.00 \"{$baseCurrencyCode}\"");
            $sheet->getRowDimension($totalRow)->setRowHeight(26);
        } else {
            // Empty result — still drop a clear marker so the user knows the
            // filter window had zero matches (vs. a broken export).
            $sheet->setCellValue('A10', 'لا توجد مصروفات مطابقة لهذه الفلاتر.');
            $sheet->mergeCells('A10:L10');
            $sheet->getStyle('A10')->applyFromArray([
                'font' => ['italic' => true, 'color' => ['rgb' => '6B7280']],
                'alignment' => ['horizontal' => $S::HORIZONTAL_CENTER, 'vertical' => $S::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension(10)->setRowHeight(40);
        }

        // Column widths — auto for narrow ones, fixed for the description so
        // long lines don't blow up the grid.
        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(40);
        $sheet->getColumnDimension('E')->setWidth(20);
        $sheet->getColumnDimension('F')->setWidth(22);
        $sheet->getColumnDimension('G')->setWidth(18);
        $sheet->getColumnDimension('H')->setWidth(10);
        $sheet->getColumnDimension('I')->setWidth(13);
        $sheet->getColumnDimension('J')->setWidth(20);
        $sheet->getColumnDimension('K')->setWidth(16);
        $sheet->getColumnDimension('L')->setWidth(18);
        $sheet->freezePane('A10');

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
