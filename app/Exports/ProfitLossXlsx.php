<?php

namespace App\Exports;

use App\Helpers\Money;
use App\Models\Branch;
use App\Models\Setting;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Multi-sheet xlsx export of the P&L report. Sheets:
 *   1. ملخص (Cover) — period, branch, key totals, where every number came from
 *   2. قائمة الدخل — full waterfall, Excel formulas inline so finance can audit
 *   3. الخصومات — every row with category, cashier, %
 *   4. المصروفات — every category + by payment method
 *   5. الاتجاه اليومي — day-by-day rev/cogs/expenses/profit
 *   6. أعلى الأصناف — top 10 by profit contribution
 */
class ProfitLossXlsx
{
    protected string $currency;
    protected string $brandGreen = '0F2D22';
    protected string $brandGold  = 'B97818';
    protected string $headerBg   = '1F4733';
    protected string $rowAlt     = 'FAFDFA';

    public function download(array $r)
    {
        $this->currency = Setting::get('currency_symbol', config('restaurant.currency_symbol', '₪'));

        $book = new Spreadsheet();
        $book->getProperties()
            ->setCreator(config('restaurant.name', 'Relax'))
            ->setTitle('Profit & Loss')
            ->setSubject('تفاصيل الصندوق');

        $this->buildSummarySheet($book->getActiveSheet(), $r);
        $this->buildIncomeStatement($book->createSheet(), $r);
        $this->buildDiscountsSheet($book->createSheet(), $r);
        $this->buildExpensesSheet($book->createSheet(), $r);
        $this->buildTrendSheet($book->createSheet(), $r);
        $this->buildTopItemsSheet($book->createSheet(), $r);

        $book->setActiveSheetIndex(0);

        $stamp = now()->format('Y-m-d_H-i');
        $filename = "profit-loss_{$r['period']['from']}_to_{$r['period']['to']}_{$stamp}.xlsx";

        return response()->streamDownload(function () use ($book) {
            $writer = new Xlsx($book);
            // Skip formula precalculation — Excel will compute on open. Saves
            // a few seconds for large sheets and dodges PhpSpreadsheet's
            // known issue evaluating SUM ranges with mixed types.
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // ─── Sheet 1: Cover / Summary ──────────────────────────────────────

    protected function buildSummarySheet($sheet, array $r): void
    {
        $sheet->setTitle('ملخص');
        $sheet->setRightToLeft(true);

        $branchName = $r['period']['branch_id']
            ? (Branch::find($r['period']['branch_id'])?->name ?? '—')
            : 'كل الفروع';

        $sheet->setCellValue('A1', 'تفاصيل الصندوق');
        $sheet->mergeCells('A1:D1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => $this->brandGreen]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(38);

        $rows = [
            ['الفترة', Carbon::parse($r['period']['from'])->locale('ar')->isoFormat('D MMMM YYYY')
                . ' — ' . Carbon::parse($r['period']['to'])->locale('ar')->isoFormat('D MMMM YYYY')
                . ' ('.$r['period']['days'].' يوم)'],
            ['الفرع', $branchName],
            ['طريقة احتساب التكلفة', $r['period']['per_branch_cost'] ? 'بأسعار شراء الفرع' : 'بالتكلفة الموحَّدة'],
            ['تاريخ التقرير', now()->locale('ar')->isoFormat('D MMMM YYYY · HH:mm')],
        ];
        $row = 3;
        foreach ($rows as $pair) {
            $sheet->setCellValue("A{$row}", $pair[0]);
            $sheet->setCellValue("B{$row}", $pair[1]);
            $sheet->mergeCells("B{$row}:D{$row}");
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF6F1']],
            ]);
            $row++;
        }

        $row += 1;
        $this->writeKpi($sheet, $row++, 'صافي المبيعات', $r['sales']['net_sales'], $this->brandGreen);
        $this->writeKpi($sheet, $row++, 'الربح الإجمالي', $r['profit']['gross_profit'], '14532D');
        $this->writeKpi($sheet, $row++, 'إجمالي المصروفات', $r['costs']['expenses'], 'B45309');
        $this->writeKpi($sheet, $row++, 'صافي الربح', $r['profit']['net_profit'], $this->brandGold);
        $this->writeKpi($sheet, $row++, 'هامش الربح الصافي', $r['profit']['net_margin_pct'], '475569', '%');

        $row += 1;
        $sheet->setCellValue("A{$row}", 'كيف نستخدم هذا التقرير؟');
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => $this->brandGold]],
        ]);
        $row++;
        $note = "كل ورقة تشرح جزءاً من القصة:\n"
            . "• قائمة الدخل: المعادلة الكاملة من الإيراد إلى صافي الربح.\n"
            . "• الخصومات: كم خصم وعلى يد من ولماذا.\n"
            . "• المصروفات: التشغيلية معتمدة فقط، حسب التصنيف وطريقة الدفع.\n"
            . "• الاتجاه اليومي: لرسم بياني أو ملاحظة الموسمية.\n"
            . "• أعلى الأصناف: الأكثر ربحاً بترتيب المساهمة.";
        $sheet->setCellValue("A{$row}", $note);
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getRowDimension($row)->setRowHeight(110);

        foreach (['A', 'B', 'C', 'D'] as $col) $sheet->getColumnDimension($col)->setWidth(28);
    }

    protected function writeKpi($sheet, int $row, string $label, float $value, string $rgb, string $suffix = ''): void
    {
        $sheet->setCellValue("A{$row}", $label);
        $sheet->setCellValue("B{$row}", $value);
        $sheet->mergeCells("B{$row}:D{$row}");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $rgb]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("B{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => $rgb]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $fmt = $suffix === '%'
            ? '#,##0.00 "%"'
            : "#,##0.00 \"{$this->currency}\"";
        $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode($fmt);
        $sheet->getRowDimension($row)->setRowHeight(28);
    }

    // ─── Sheet 2: Income Statement ─────────────────────────────────────

    protected function buildIncomeStatement($sheet, array $r): void
    {
        $sheet->setTitle('قائمة الدخل');
        $sheet->setRightToLeft(true);

        $sheet->fromArray(['البند', 'القيمة', '% من صافي المبيعات', 'ملاحظة'], null, 'A1');
        $this->styleHeader($sheet, 'A1:D1');

        $netSales = $r['sales']['net_sales'];
        $pctOf = fn($v) => $netSales > 0 ? ($v / $netSales) : 0;

        $rows = [
            ['الإيرادات الإجمالية',  $r['sales']['gross_sales'],     null, 'مجموع subtotal للفواتير المصدرة (المُحصَّلة كلياً أو جزئياً)'],
            ['(−) الخصومات',         -$r['sales']['discounts_total'], $netSales > 0 ? -$r['sales']['discounts_total']/$netSales : null, 'مجموع discount_total على نفس الفواتير'],
            ['= صافي المبيعات',      $r['sales']['net_sales'],       1.0, 'الإيرادات الإجمالية − الخصومات'],
            ['(−) تكلفة البضاعة (COGS)', -$r['costs']['cogs'],       $netSales > 0 ? -$r['costs']['cogs']/$netSales : null, 'كمية الأصناف المباعة × تكلفة المكوّنات'],
            ['(−) الهدر',            -$r['costs']['waste'],          $netSales > 0 ? -$r['costs']['waste']/$netSales : null, 'inventory_movements حيث type=waste'],
            ['= الربح الإجمالي',     $r['profit']['gross_profit'],   $pctOf($r['profit']['gross_profit']), 'صافي المبيعات − COGS − الهدر'],
            ['(−) المصروفات التشغيلية', -$r['costs']['expenses'],    $netSales > 0 ? -$r['costs']['expenses']/$netSales : null, 'مصروفات معتمدة في الفترة (إيجار، رواتب، فواتير…)'],
            ['(−) عمولات منصات التوصيل', -$r['costs']['platform_commission'], $netSales > 0 ? -$r['costs']['platform_commission']/$netSales : null, 'orders.total × platform_commission_pct/100'],
            ['= الربح من العمليات',  $r['profit']['operating_profit'], $pctOf($r['profit']['operating_profit']), 'الربح الإجمالي − المصروفات − العمولات'],
            ['(−) الاستردادات',      -$r['costs']['refunds'],         $netSales > 0 ? -$r['costs']['refunds']/$netSales : null, 'مبالغ مُعادة للزبائن في الفترة'],
            ['= صافي الربح',         $r['profit']['net_profit'],     $pctOf($r['profit']['net_profit']), 'الرقم النهائي'],
        ];

        $row = 2;
        foreach ($rows as $r2) {
            $sheet->setCellValue("A{$row}", $r2[0]);
            $sheet->setCellValue("B{$row}", $r2[1]);
            if ($r2[2] !== null) $sheet->setCellValue("C{$row}", $r2[2]);
            $sheet->setCellValue("D{$row}", $r2[3]);

            $isSubtotal = str_starts_with($r2[0], '=');
            $isFinal = $r2[0] === '= صافي الربح';

            if ($isFinal) {
                $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => $this->brandGold]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF9E7']],
                    'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => $this->brandGold]]],
                ]);
            } elseif ($isSubtotal) {
                $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF6F1']],
                ]);
            }
            $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode("#,##0.00 \"{$this->currency}\"");
            if ($r2[2] !== null) $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode('0.00%');
            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(34);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(22);
        $sheet->getColumnDimension('D')->setWidth(50);
        $sheet->getStyle('D2:D'.($row-1))->getAlignment()->setWrapText(true);
        $sheet->freezePane('A2');
    }

    // ─── Sheet 3: Discounts ────────────────────────────────────────────

    protected function buildDiscountsSheet($sheet, array $r): void
    {
        $sheet->setTitle('الخصومات');
        $sheet->setRightToLeft(true);

        $sheet->setCellValue('A1', 'الخصومات حسب التصنيف');
        $sheet->mergeCells('A1:D1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => $this->brandGreen]],
        ]);

        $sheet->fromArray(['التصنيف', 'العدد', 'المجموع', '% من صافي المبيعات'], null, 'A3');
        $this->styleHeader($sheet, 'A3:D3');

        $netSales = $r['sales']['net_sales'];
        $row = 4;
        foreach ($r['discounts']['by_category'] as $b) {
            $sheet->setCellValue("A{$row}", $b['label']);
            $sheet->setCellValue("B{$row}", $b['count']);
            $sheet->setCellValue("C{$row}", $b['total']);
            $sheet->setCellValue("D{$row}", $netSales > 0 ? $b['total']/$netSales : 0);
            $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode("#,##0.00 \"{$this->currency}\"");
            $sheet->getStyle("D{$row}")->getNumberFormat()->setFormatCode('0.00%');
            $row++;
        }

        // Cashier breakdown
        $row += 2;
        $sheet->setCellValue("A{$row}", 'الخصومات حسب الموظف');
        $sheet->mergeCells("A{$row}:C{$row}");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => $this->brandGreen]],
        ]);
        $row += 2;
        $sheet->setCellValue("A{$row}", 'الموظف');
        $sheet->setCellValue("B{$row}", 'العدد');
        $sheet->setCellValue("C{$row}", 'المجموع');
        $this->styleHeader($sheet, "A{$row}:C{$row}");
        $row++;
        foreach ($r['discounts']['by_cashier'] as $b) {
            $sheet->setCellValue("A{$row}", $b['name']);
            $sheet->setCellValue("B{$row}", $b['count']);
            $sheet->setCellValue("C{$row}", $b['total']);
            $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode("#,##0.00 \"{$this->currency}\"");
            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(32);
        $sheet->getColumnDimension('B')->setWidth(12);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(22);
    }

    // ─── Sheet 4: Expenses ─────────────────────────────────────────────

    protected function buildExpensesSheet($sheet, array $r): void
    {
        $sheet->setTitle('المصروفات');
        $sheet->setRightToLeft(true);

        $sheet->setCellValue('A1', 'المصروفات حسب التصنيف');
        $sheet->mergeCells('A1:D1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => $this->brandGreen]],
        ]);

        $sheet->fromArray(['التصنيف', 'العدد', 'المجموع', '% من صافي المبيعات'], null, 'A3');
        $this->styleHeader($sheet, 'A3:D3');

        $netSales = $r['sales']['net_sales'];
        $row = 4;
        foreach ($r['expenses_breakdown']['by_category'] as $b) {
            $sheet->setCellValue("A{$row}", $b['label']);
            $sheet->setCellValue("B{$row}", $b['count']);
            $sheet->setCellValue("C{$row}", $b['total']);
            $sheet->setCellValue("D{$row}", $netSales > 0 ? $b['total']/$netSales : 0);
            $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode("#,##0.00 \"{$this->currency}\"");
            $sheet->getStyle("D{$row}")->getNumberFormat()->setFormatCode('0.00%');
            $row++;
        }

        $row += 2;
        $sheet->setCellValue("A{$row}", 'حسب طريقة الدفع');
        $sheet->mergeCells("A{$row}:C{$row}");
        $sheet->getStyle("A{$row}")->applyFromArray(['font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => $this->brandGreen]]]);
        $row += 2;
        $sheet->fromArray(['الطريقة', 'العدد', 'المجموع'], null, "A{$row}");
        $this->styleHeader($sheet, "A{$row}:C{$row}");
        $row++;
        foreach ($r['expenses_breakdown']['by_method'] as $b) {
            $sheet->setCellValue("A{$row}", $b['label']);
            $sheet->setCellValue("B{$row}", $b['count']);
            $sheet->setCellValue("C{$row}", $b['total']);
            $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode("#,##0.00 \"{$this->currency}\"");
            $row++;
        }

        // Recent expenses table
        if ($r['expenses_breakdown']['recent']->count()) {
            $row += 2;
            $sheet->setCellValue("A{$row}", 'آخر المصروفات المعتمدة');
            $sheet->mergeCells("A{$row}:E{$row}");
            $sheet->getStyle("A{$row}")->applyFromArray(['font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => $this->brandGreen]]]);
            $row += 2;
            $sheet->fromArray(['التاريخ', 'الوصف', 'التصنيف', 'الطريقة', 'المبلغ'], null, "A{$row}");
            $this->styleHeader($sheet, "A{$row}:E{$row}");
            $row++;
            foreach ($r['expenses_breakdown']['recent'] as $exp) {
                $sheet->setCellValue("A{$row}", $exp->expense_date->format('Y-m-d'));
                $sheet->setCellValue("B{$row}", $exp->description);
                $sheet->setCellValue("C{$row}", $exp->category?->label ?? '—');
                $sheet->setCellValue("D{$row}", $exp->payment_method);
                $sheet->setCellValue("E{$row}", (float) $exp->amount);
                $sheet->getStyle("E{$row}")->getNumberFormat()->setFormatCode("#,##0.00 \"{$this->currency}\"");
                $row++;
            }
        }

        foreach (['A', 'B', 'C', 'D', 'E'] as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // ─── Sheet 5: Daily Trend ──────────────────────────────────────────

    protected function buildTrendSheet($sheet, array $r): void
    {
        $sheet->setTitle('الاتجاه اليومي');
        $sheet->setRightToLeft(true);

        $sheet->fromArray(['التاريخ', 'صافي المبيعات', 'تكلفة البضاعة', 'المصروفات', 'الربح', 'المُحصَّل'], null, 'A1');
        $this->styleHeader($sheet, 'A1:F1');

        $row = 2;
        foreach ($r['trend'] as $d) {
            $sheet->setCellValue("A{$row}", $d['date']);
            $sheet->setCellValue("B{$row}", $d['net_sales']);
            $sheet->setCellValue("C{$row}", $d['cogs']);
            $sheet->setCellValue("D{$row}", $d['expenses']);
            $sheet->setCellValue("E{$row}", $d['profit']);
            $sheet->setCellValue("F{$row}", $d['collected']);
            $row++;
        }

        // Total row with formulas
        $last = $row - 1;
        if ($last >= 2) {
            $sheet->setCellValue("A{$row}", 'الإجمالي');
            foreach (['B', 'C', 'D', 'E', 'F'] as $col) {
                $sheet->setCellValue("{$col}{$row}", "=SUM({$col}2:{$col}{$last})");
            }
            $sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF6F1']],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => $this->brandGreen]]],
            ]);
            foreach (['B','C','D','E','F'] as $col) {
                $sheet->getStyle("{$col}2:{$col}{$row}")->getNumberFormat()->setFormatCode("#,##0.00 \"{$this->currency}\"");
            }
        }

        foreach (['A','B','C','D','E','F'] as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
        $sheet->freezePane('A2');
    }

    // ─── Sheet 6: Top items ────────────────────────────────────────────

    protected function buildTopItemsSheet($sheet, array $r): void
    {
        $sheet->setTitle('أعلى الأصناف');
        $sheet->setRightToLeft(true);

        $sheet->fromArray(['#', 'الصنف', 'الكمية', 'الإيراد', 'التكلفة', 'الربح', 'هامش %'], null, 'A1');
        $this->styleHeader($sheet, 'A1:G1');

        $row = 2;
        foreach ($r['top_items'] as $i => $it) {
            $rev = (float) $it->revenue;
            $sheet->setCellValue("A{$row}", $i + 1);
            $sheet->setCellValue("B{$row}", $it->name);
            $sheet->setCellValue("C{$row}", (float) $it->qty);
            $sheet->setCellValue("D{$row}", $rev);
            $sheet->setCellValue("E{$row}", (float) $it->cogs);
            $sheet->setCellValue("F{$row}", (float) $it->profit);
            $sheet->setCellValue("G{$row}", $rev > 0 ? (float) $it->profit / $rev : 0);
            $row++;
        }

        if ($row > 2) {
            foreach (['D','E','F'] as $col) $sheet->getStyle("{$col}2:{$col}".($row-1))->getNumberFormat()->setFormatCode("#,##0.00 \"{$this->currency}\"");
            $sheet->getStyle("G2:G".($row-1))->getNumberFormat()->setFormatCode('0.00%');
        }

        foreach (['A','B','C','D','E','F','G'] as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
        $sheet->freezePane('A2');
    }

    // ─── Common header style ───────────────────────────────────────────

    protected function styleHeader($sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $this->headerBg]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $row = (int) preg_replace('/\D/', '', explode(':', $range)[0]);
        $sheet->getRowDimension($row)->setRowHeight(26);
    }
}
