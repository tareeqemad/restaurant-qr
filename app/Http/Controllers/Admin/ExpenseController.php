<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\Lookup;
use App\Models\Shift;
use App\Models\Supplier;
use App\Services\Accounting\AccountingService;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Branch-scoped expense management.
 *
 *   index   — list with filter rail + KPIs (this-month + today + pending)
 *   create  — manual entry (defaults today's date + open shift if any)
 *   store   — validate + persist; auto-numbered EXP-YYYYMMDD-NNNN
 *   edit    — only while pending
 *   approve — flips to approved + (if cash) creates a CashMovement
 *             on the active shift, so the till close-out matches
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
                  ->orWhere('vendor_name',   'like', "%{$search}%")
                  ->orWhere('payment_reference', 'like', "%{$search}%");
            });
        }

        $filteredQuery = clone $query;
        $hasFilters = $request->hasAny(['search', 'from', 'to', 'category_id', 'status', 'payment_method']);
        $filteredStats = null;

        if ($hasFilters || $request->get('export') === 'xlsx') {
            $filteredStats = [
                'count'    => (clone $filteredQuery)->count(),
                'total'    => (float) (clone $filteredQuery)->sum('amount'),
                'pending'  => (clone $filteredQuery)->where('status', 'pending_approval')->count(),
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
            'pending'      => Expense::pending()->count(),
            'today_total'  => (float) Expense::approved()->whereDate('expense_date', $today)->sum('amount'),
            'month_total'  => (float) Expense::approved()->whereDate('expense_date', '>=', $monthStart)->sum('amount'),
        ];

        return view('admin.expenses.index', [
            'expenses'   => $expenses,
            'stats'      => $stats,
            'filteredStats' => $filteredStats,
            'categories' => Lookup::for('expense_categories'),
            'paymentMethods' => Expense::PAYMENT_METHODS,
        ]);
    }

    public function create()
    {
        $this->authorize('create', Expense::class);

        return view('admin.expenses.create', [
            'expense'    => new Expense(['expense_date' => now()->toDateString(), 'payment_method' => 'cash']),
            'suppliers'  => Supplier::orderBy('name')->get(['id', 'name']),
            'categories' => Lookup::for('expense_categories'),
            'paymentMethods' => Expense::PAYMENT_METHODS,
            'openShift'  => $this->openShiftForCurrentBranch(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Expense::class);

        $data = $this->validateData($request);

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
            'status'             => 'pending_approval',
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

        return view('admin.expenses.edit', [
            'expense'    => $expense,
            'suppliers'  => Supplier::orderBy('name')->get(['id', 'name']),
            'categories' => Lookup::for('expense_categories'),
            'paymentMethods' => Expense::PAYMENT_METHODS,
            'openShift'  => $this->openShiftForCurrentBranch(),
        ]);
    }

    public function update(Request $request, Expense $expense)
    {
        $this->authorize('update', $expense);

        $data = $this->validateData($request);

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
                'status'              => 'approved',
                'approved_by_user_id' => auth()->id(),
                'approved_at'         => now(),
            ]);

            // Bridge to the cashier's till — only when paid in cash AND
            // there's an open shift in the same branch. Otherwise the
            // expense is recorded but doesn't touch the drawer (e.g. a
            // bank transfer paid by the manager from the company account).
            if ($expense->isCash()) {
                $shift = $this->openShiftForBranch($expense->branch_id, $expense->shift_id);

                if ($shift) {
                    $movement = CashMovement::create([
                        // Stamp the branch from the shift (which itself is
                        // branch-scoped). Don't rely on BranchContext —
                        // expense approval can be done by an owner-level
                        // user in "all branches" mode.
                        'branch_id' => $shift->branch_id,
                        'shift_id' => $shift->id,
                        'type'     => 'pay_out',
                        'amount'   => $expense->amount,
                        'reason'   => "مصروف {$expense->expense_number} — {$expense->description}",
                        'user_id'  => auth()->id(),
                    ]);

                    $expense->update([
                        'cash_movement_id' => $movement->id,
                        'shift_id'         => $shift->id,
                    ]);
                }
            }

            app(AccountingService::class)->recordExpenseApproved($expense->fresh());
        });

        $tail = $expense->cash_movement_id
            ? ' وتمّ خصم المبلغ من درج الكاشير الحالي.'
            : ($expense->isCash() ? ' (لا توجد وردية مفتوحة لربط الحركة بها).' : '');

        ActivityLog::log('expense.approved',
            "اعتماد مصروف {$expense->expense_number} ({$expense->amount})",
            $expense
        );

        return back()->with('success', "تم اعتماد المصروف.{$tail}");
    }

    public function reject(Request $request, Expense $expense)
    {
        $this->authorize('reject', $expense);

        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:255'],
        ]);

        $expense->update([
            'status'              => 'rejected',
            'rejection_reason'    => $data['rejection_reason'],
            'approved_by_user_id' => auth()->id(),
            'approved_at'         => now(),
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
            'expense_category_id' => ['required', Rule::exists('lookups', 'id')->where(fn ($q) =>
                $q->where('group', 'expense_categories')->where('is_active', true)
            )],
            'description'       => ['required', 'string', 'max:255'],
            'amount'            => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'payment_method'    => ['required', Rule::in(array_keys(Expense::PAYMENT_METHODS))],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'vendor_name'       => ['nullable', 'string', 'max:150'],
            'supplier_id'       => ['nullable', Rule::exists('suppliers', 'id')],
            'expense_date'      => ['required', 'date', 'before_or_equal:today'],
            'shift_id'          => ['nullable', Rule::exists('shifts', 'id')],
            'notes'             => ['nullable', 'string', 'max:1000'],
            'attachment'        => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ]);
    }

    /** Look up the most recently opened shift in the active branch, if any. */
    protected function openShiftForCurrentBranch(): ?Shift
    {
        $branchId = BranchContext::current();
        if (! $branchId) {
            return null;
        }
        return $this->openShiftForBranch($branchId);
    }

    protected function openShiftForBranch(int $branchId, ?int $preferredShiftId = null): ?Shift
    {
        return BranchContext::forBranch($branchId, function () use ($preferredShiftId) {
            // If the staffer picked a specific shift on the form and it's
            // still open, honour that; otherwise grab the latest open one.
            if ($preferredShiftId) {
                $picked = Shift::where('id', $preferredShiftId)->where('status', 'open')->first();
                if ($picked) {
                    return $picked;
                }
            }
            return Shift::where('status', 'open')->latest('opened_at')->first();
        });
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
            ? \App\Models\Branch::find($branchId)?->name
            : 'كل الفروع';
        $currency = \App\Models\Setting::get('currency_symbol', config('restaurant.currency_symbol', '₪'));
        $stamp    = now()->format('Y-m-d_H-i');
        $filename = "expenses_{$stamp}.xlsx";

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('المصروفات التشغيلية');
        $sheet->setRightToLeft(true);

        $S = \PhpOffice\PhpSpreadsheet\Style\Alignment::class;
        $F = \PhpOffice\PhpSpreadsheet\Style\Fill::class;
        $B = \PhpOffice\PhpSpreadsheet\Style\Border::class;

        // ─── Header card ───────────────────────────────────────────
        $sheet->setCellValue('A1', 'تقرير المصروفات التشغيلية');
        $sheet->mergeCells('A1:I1');
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
                    ? trim(($appliedFilters['from'] ?? '…') . ' → ' . ($appliedFilters['to'] ?? '…'))
                    : 'كل التواريخ',
            ],
            ['عدد السجلات', number_format($filteredStats['count'])],
            ['إجمالي المبلغ', number_format($filteredStats['total'], 2) . ' ' . $currency],
            ['بانتظار الاعتماد · معتمدة',
                number_format($filteredStats['pending']) . ' · ' . number_format($filteredStats['approved'])],
        ];
        foreach ($meta as $i => [$label, $value]) {
            $r = 2 + $i;
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $value);
            $sheet->mergeCells("B{$r}:I{$r}");
        }
        $sheet->getStyle('A2:A7')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => $F::FILL_SOLID, 'startColor' => ['rgb' => 'EEF6F1']],
            'alignment' => ['horizontal' => $S::HORIZONTAL_RIGHT, 'vertical' => $S::VERTICAL_CENTER, 'indent' => 1],
        ]);
        $sheet->getStyle('B2:I7')->applyFromArray([
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
            "المبلغ ({$currency})",    // G
            'طريقة الدفع',              // H
            'الحالة',                   // I
        ];
        $sheet->fromArray($headers, null, 'A9');
        $sheet->getStyle('A9:I9')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => $F::FILL_SOLID, 'startColor' => ['rgb' => '0F4731']],
            'alignment' => ['horizontal' => $S::HORIZONTAL_CENTER, 'vertical' => $S::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(9)->setRowHeight(26);

        // ─── Data rows ─────────────────────────────────────────────
        $statusLabels = [
            'pending_approval' => 'بانتظار الاعتماد',
            'approved'         => 'معتمد',
            'rejected'         => 'مرفوض',
        ];
        $statusTint = [
            'pending_approval' => 'FFF6E0', // amber
            'approved'         => 'EEF6F1', // green
            'rejected'         => 'FBE9E9', // red
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
                $exp->paymentMethodLabel(),
                $statusLabels[$statusKey] ?? $statusKey,
            ], null, "A{$row}");

            if ($tint = $statusTint[$statusKey] ?? null) {
                $sheet->getStyle("A{$row}:I{$row}")->applyFromArray([
                    'fill' => ['fillType' => $F::FILL_SOLID, 'startColor' => ['rgb' => $tint]],
                ]);
            }

            $row++;
            $index++;
        }

        // ─── Totals + formatting ───────────────────────────────────
        $lastRow = $row - 1;
        if ($lastRow >= 10) {
            $sheet->getStyle("G10:G{$lastRow}")->getNumberFormat()
                ->setFormatCode("#,##0.00 \"{$currency}\"");

            $totalRow = $lastRow + 1;
            $sheet->setCellValue("A{$totalRow}", 'الإجمالي');
            $sheet->mergeCells("A{$totalRow}:F{$totalRow}");
            $sheet->setCellValue("G{$totalRow}", "=SUM(G10:G{$lastRow})");
            $sheet->setCellValue("H{$totalRow}", '');
            $sheet->setCellValue("I{$totalRow}", count($expenses) . ' سجل');
            $sheet->getStyle("A{$totalRow}:I{$totalRow}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '0F4731']],
                'fill' => ['fillType' => $F::FILL_SOLID, 'startColor' => ['rgb' => 'D4E4D9']],
                'borders' => ['top' => ['borderStyle' => $B::BORDER_MEDIUM, 'color' => ['rgb' => '0F4731']]],
                'alignment' => ['vertical' => $S::VERTICAL_CENTER],
            ]);
            $sheet->getStyle("G{$totalRow}")->getNumberFormat()
                ->setFormatCode("#,##0.00 \"{$currency}\"");
            $sheet->getRowDimension($totalRow)->setRowHeight(26);
        } else {
            // Empty result — still drop a clear marker so the user knows the
            // filter window had zero matches (vs. a broken export).
            $sheet->setCellValue('A10', 'لا توجد مصروفات مطابقة لهذه الفلاتر.');
            $sheet->mergeCells('A10:I10');
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
        $sheet->getColumnDimension('H')->setWidth(14);
        $sheet->getColumnDimension('I')->setWidth(18);
        $sheet->freezePane('A10');

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
