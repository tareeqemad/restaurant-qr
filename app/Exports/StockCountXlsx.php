<?php

namespace App\Exports;

use App\Models\StockCount;
use App\Support\MarketProfile;
use App\Support\MarketSpreadsheetLocalizer;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Multi-sheet xlsx export of one stock count. Three sheets:
 *
 *   1. ملخص          — number, date, location, status, who, totals
 *   2. كل البنود     — every line: name, unit, system, counted, variance, cost
 *   3. الفروقات فقط  — items where counted_qty differs from system_qty
 *                       (the "decision sheet" the manager actually files)
 *
 * Designed to be useful at every stage:
 *   - draft   → snapshot of work-in-progress, useful for night-shift handoff
 *   - finalized → permanent record of what was adjusted and by how much
 *   - cancelled → audit of what was abandoned and why
 */
class StockCountXlsx
{
    protected string $brandGreen = '0F2D22';
    protected string $brandGold  = 'B97818';
    protected string $headerBg   = '1F4733';
    protected string $rowAlt     = 'FAFDFA';

    protected string $currency;

    public function download(StockCount $count)
    {
        $this->currency = config('restaurant.currency_symbol', '₪');
        $count->loadMissing(['items.ingredient.baseUnit', 'creator', 'finalizer', 'storageLocation']);

        $book = new Spreadsheet();
        $book->getProperties()
            ->setCreator(config('restaurant.name', 'Relax'))
            ->setTitle('Stock Count ' . $count->number)
            ->setSubject('تقرير الجرد ' . $count->number);

        $this->buildSummarySheet($book->getActiveSheet(), $count);
        $this->buildAllItemsSheet($book->createSheet(), $count);
        $this->buildVariancesSheet($book->createSheet(), $count);

        MarketSpreadsheetLocalizer::apply($book);
        $book->setActiveSheetIndex(0);

        $stamp    = now()->format('Y-m-d_H-i');
        $safeNum  = preg_replace('/[^A-Za-z0-9_.-]/', '_', $count->number);
        $filename = "stock-count_{$safeNum}_{$stamp}.xlsx";

        return response()->streamDownload(function () use ($book) {
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // ─── Sheet 1: Summary ──────────────────────────────────────────────

    protected function buildSummarySheet($sheet, StockCount $count): void
    {
        $sheet->setTitle('ملخص');
        $sheet->setRightToLeft(true);

        $sheet->setCellValue('A1', "تقرير الجرد — {$count->number}");
        $sheet->mergeCells('A1:D1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => $this->brandGreen]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(38);

        $statusLabel = match ($count->status) {
            'draft'     => 'مسودة (قيد العدّ)',
            'finalized' => 'مُعتمد (تم تطبيق التسويات)',
            'cancelled' => 'ملغي',
            default     => $count->status ?? '—',
        };

        $meta = [
            ['الرقم',           $count->number],
            ['التاريخ',         $count->count_date?->format('Y-m-d') ?? '—'],
            ['موقع التخزين',    $count->storageLocation?->name ?? 'إجمالي الفرع'],
            ['الحالة',          $statusLabel],
            ['أنشأه',           $count->creator?->name ?? '—'],
            ['اعتمده',          $count->finalizer?->name ?? '—'],
            ['تاريخ الاعتماد',  $count->finalized_at?->format('Y-m-d H:i') ?? '—'],
            ['ملاحظات',         (string) ($count->notes ?: '—')],
            ['تاريخ التقرير',   now()->locale(MarketProfile::lang())->isoFormat('D MMMM YYYY · HH:mm')],
        ];

        $row = 3;
        foreach ($meta as $pair) {
            $sheet->setCellValue("A{$row}", $pair[0]);
            $sheet->setCellValue("B{$row}", $pair[1]);
            $sheet->mergeCells("B{$row}:D{$row}");
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF6F1']],
            ]);
            $sheet->getStyle("A{$row}:D{$row}")
                ->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
            $row++;
        }

        // ─── KPIs ─────────────────────────────────────────────────────
        $items     = $count->items;
        $total     = $items->count();
        $counted   = $items->whereNotNull('counted_qty')->count();
        $pending   = $total - $counted;
        $shortages = $items->filter(fn ($i) => $i->counted_qty !== null && (float) $i->variance < -0.0001)->count();
        $overages  = $items->filter(fn ($i) => $i->counted_qty !== null && (float) $i->variance > 0.0001)->count();
        $matches   = $items->filter(fn ($i) => $i->counted_qty !== null && abs((float) $i->variance) <= 0.0001)->count();
        $costDelta = (float) $items->sum('variance_cost');

        $row += 1;
        $this->writeKpi($sheet, $row++, 'إجمالي المكوّنات',       $total,     $this->brandGreen, 'count');
        $this->writeKpi($sheet, $row++, 'تم عدّها',                $counted,   '14532D',          'count');
        $this->writeKpi($sheet, $row++, 'لم تُعد بعد',             $pending,   '475569',          'count');
        $this->writeKpi($sheet, $row++, 'مطابقة (لا فرق)',          $matches,   '14532D',          'count');
        $this->writeKpi($sheet, $row++, 'فيها نقص',                 $shortages, 'B91C1C',          'count');
        $this->writeKpi($sheet, $row++, 'فيها زيادة',               $overages,  'C2410C',          'count');
        $this->writeKpi($sheet, $row++, 'صافي قيمة الفروقات',       $costDelta, $this->brandGold,  'money');

        // ─── Footnote ─────────────────────────────────────────────────
        $row += 1;
        $sheet->setCellValue("A{$row}", 'كيف نقرأ هذا التقرير؟');
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => $this->brandGold]],
        ]);
        $row++;
        $note = "الجرد = مقارنة كمية النظام (المتوقعة) بالكمية الفعلية الموجودة في المخزن.\n\n"
              . "• «نقص» = الكمية الفعلية أقل من النظام (احتمال هدر، سرقة، خطأ تسجيل، أو استهلاك غير موثَّق).\n"
              . "• «زيادة» = الكمية الفعلية أكبر من النظام (احتمال استلام غير مُسجّل أو خطأ في حركة سابقة).\n"
              . "• «صافي قيمة الفروقات» = القيمة المالية للتسوية. سالب = خسارة، موجب = ربح غير مُسجّل.\n"
              . "• عند اعتماد الجرد، النظام يُنشئ حركة «تعديل» لكل فرق ليطابق المخزون الفعلي.\n"
              . "• المكونات «لم تُعد» تبقى كما هي ولا تُؤثَّر بالاعتماد.";
        $sheet->setCellValue("A{$row}", $note);
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getRowDimension($row)->setRowHeight(140);

        foreach (['A', 'B', 'C', 'D'] as $col) $sheet->getColumnDimension($col)->setWidth(28);
    }

    /** @param string $kind 'count' | 'money' */
    protected function writeKpi($sheet, int $row, string $label, $value, string $rgb, string $kind = 'count'): void
    {
        $sheet->setCellValue("A{$row}", $label);
        $sheet->setCellValue("B{$row}", (float) $value);
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
        $fmt = $kind === 'money' ? "#,##0.00 \"{$this->currency}\"" : '#,##0';
        $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode($fmt);
        $sheet->getRowDimension($row)->setRowHeight(28);
    }

    // ─── Sheet 2: All items ────────────────────────────────────────────

    protected function buildAllItemsSheet($sheet, StockCount $count): void
    {
        $sheet->setTitle('كل البنود');
        $sheet->setRightToLeft(true);

        $headers = [
            '#', 'المكوّن', 'الوحدة',
            'كمية النظام', 'الكمية الفعلية', 'الفرق',
            'تكلفة الوحدة', 'قيمة الفرق', 'الحالة', 'ملاحظات',
        ];
        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeader($sheet, 'A1:J1');

        $row = 2;
        $i = 1;
        foreach ($count->items as $it) {
            $cost = (float) ($it->ingredient?->cost_per_unit ?? 0);
            $countedQty = $it->counted_qty === null ? null : (float) $it->counted_qty;
            $variance   = (float) $it->variance;
            $varianceCost = (float) $it->variance_cost;

            $statusLabel = $countedQty === null
                ? 'لم تُعد'
                : (abs($variance) <= 0.0001 ? 'مطابقة'
                    : ($variance < 0 ? 'نقص' : 'زيادة'));

            $sheet->setCellValue("A{$row}", $i);
            $sheet->setCellValue("B{$row}", $it->ingredient?->name ?? '—');
            $sheet->setCellValue("C{$row}", $it->ingredient?->baseUnit?->code ?? '—');
            $sheet->setCellValue("D{$row}", (float) $it->system_qty);
            if ($countedQty !== null) {
                $sheet->setCellValue("E{$row}", $countedQty);
                $sheet->setCellValue("F{$row}", $variance);
            } else {
                $sheet->setCellValue("E{$row}", '—');
                $sheet->setCellValue("F{$row}", '—');
            }
            $sheet->setCellValue("G{$row}", $cost);
            if ($countedQty !== null) {
                $sheet->setCellValue("H{$row}", $varianceCost);
            } else {
                $sheet->setCellValue("H{$row}", '—');
            }
            $sheet->setCellValue("I{$row}", $statusLabel);
            $sheet->setCellValue("J{$row}", (string) $it->notes);

            // Number formats
            $sheet->getStyle("D{$row}:F{$row}")->getNumberFormat()->setFormatCode('#,##0.0000');
            $sheet->getStyle("G{$row}:H{$row}")->getNumberFormat()->setFormatCode("#,##0.00 \"{$this->currency}\"");

            // Color cues
            if ($countedQty === null) {
                $sheet->getStyle("A{$row}:J{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
            } elseif ($variance < -0.0001) {
                $sheet->getStyle("A{$row}:J{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEE2E2');
            } elseif ($variance > 0.0001) {
                $sheet->getStyle("A{$row}:J{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF3C7');
            } elseif ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:J{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($this->rowAlt);
            }

            $row++;
            $i++;
        }

        // Total row
        if ($row > 2) {
            $last = $row - 1;
            $sheet->setCellValue("A{$row}", 'الإجمالي');
            $sheet->mergeCells("A{$row}:G{$row}");
            $sheet->setCellValue("H{$row}", "=SUM(H2:H{$last})");
            $sheet->getStyle("H{$row}")->getNumberFormat()->setFormatCode("#,##0.00 \"{$this->currency}\"");
            $sheet->getStyle("A{$row}:J{$row}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF6F1']],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => $this->brandGreen]]],
            ]);
        }

        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(28);
        foreach (['C','D','E','F','G','H','I'] as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
        $sheet->getColumnDimension('J')->setWidth(35);
        $sheet->freezePane('A2');
        if ($count->items->count() > 0) {
            $sheet->setAutoFilter('A1:J'.($row-1));
        }
    }

    // ─── Sheet 3: Variances only ───────────────────────────────────────

    protected function buildVariancesSheet($sheet, StockCount $count): void
    {
        $sheet->setTitle('الفروقات فقط');
        $sheet->setRightToLeft(true);

        $headers = ['#', 'المكوّن', 'الوحدة', 'كمية النظام', 'الكمية الفعلية', 'الفرق', 'قيمة الفرق', 'الحالة', 'ملاحظات'];
        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeader($sheet, 'A1:I1');

        $variances = $count->items->filter(
            fn ($i) => $i->counted_qty !== null && abs((float) $i->variance) > 0.0001
        )->values();

        if ($variances->isEmpty()) {
            $sheet->setCellValue("A2", '🎉 لا توجد فروقات — كل المكونات المعدودة تطابق سجل النظام.');
            $sheet->mergeCells("A2:I2");
            $sheet->getStyle("A2")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => '14532D']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D1FAE5']],
            ]);
            $sheet->getRowDimension(2)->setRowHeight(40);
            foreach (['A','B','C','D','E','F','G','H','I'] as $col) {
                $sheet->getColumnDimension($col)->setWidth(15);
            }
            return;
        }

        $row = 2;
        $i = 1;
        foreach ($variances as $it) {
            $variance = (float) $it->variance;
            $statusLabel = $variance < 0 ? 'نقص' : 'زيادة';

            $sheet->setCellValue("A{$row}", $i);
            $sheet->setCellValue("B{$row}", $it->ingredient?->name ?? '—');
            $sheet->setCellValue("C{$row}", $it->ingredient?->baseUnit?->code ?? '—');
            $sheet->setCellValue("D{$row}", (float) $it->system_qty);
            $sheet->setCellValue("E{$row}", (float) $it->counted_qty);
            $sheet->setCellValue("F{$row}", $variance);
            $sheet->setCellValue("G{$row}", (float) $it->variance_cost);
            $sheet->setCellValue("H{$row}", $statusLabel);
            $sheet->setCellValue("I{$row}", (string) $it->notes);

            $sheet->getStyle("D{$row}:F{$row}")->getNumberFormat()->setFormatCode('#,##0.0000');
            $sheet->getStyle("G{$row}")->getNumberFormat()->setFormatCode("#,##0.00 \"{$this->currency}\"");

            if ($variance < 0) {
                $sheet->getStyle("A{$row}:I{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEE2E2');
            } else {
                $sheet->getStyle("A{$row}:I{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF3C7');
            }

            $row++;
            $i++;
        }

        // Totals
        $last = $row - 1;
        $sheet->setCellValue("A{$row}", 'الإجمالي');
        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->setCellValue("G{$row}", "=SUM(G2:G{$last})");
        $sheet->getStyle("G{$row}")->getNumberFormat()->setFormatCode("#,##0.00 \"{$this->currency}\"");
        $sheet->getStyle("A{$row}:I{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF6F1']],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => $this->brandGreen]]],
        ]);

        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(28);
        foreach (['C','D','E','F','G','H'] as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
        $sheet->getColumnDimension('I')->setWidth(35);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:I'.($row-1));
    }

    // ─── Common header ─────────────────────────────────────────────────

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
