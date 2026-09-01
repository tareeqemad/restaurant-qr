<?php

namespace App\Exports;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\User;
use App\Support\BranchContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Multi-sheet xlsx export of staff attendance, honouring whatever
 * filters are active on the index page (date / from-to / status / user).
 *
 * Sheets:
 *   1. ملخص     — period, branch, applied filters, headline KPIs
 *   2. السجلات  — every matching attendance row, fully detailed
 *   3. حسب الموظف — aggregation per staff member (records, hours, review, source mix)
 *
 * Why a dedicated class rather than a quick CSV: managers receive these
 * files for payroll review, so number formats (hours as `[h]:mm`),
 * RTL layout, branded headers, and frozen panes all matter. CSV breaks
 * on Arabic in Excel and loses every cell-level format.
 */
class AttendanceXlsx
{
    protected string $brandGreen = '0F2D22';

    protected string $brandGold = 'B97818';

    protected string $headerBg = '1F4733';

    protected string $rowAlt = 'FAFDFA';

    /** @var array{from:string,to:string,status:?string,user_id:?int,branch_id:?int,branch_label:string} */
    protected array $period;

    public function download(array $filters)
    {
        $this->period = $this->resolvePeriod($filters);

        // Fetch everything in scope ONCE — sheets reuse the same dataset
        // so we don't pay for the query 3 times.
        $rows = $this->buildQuery($filters)->get();

        $book = new Spreadsheet;
        $book->getProperties()
            ->setCreator(config('restaurant.name', 'Relax'))
            ->setTitle('Attendance Report')
            ->setSubject('تقرير الحضور والانصراف');

        $this->buildSummarySheet($book->getActiveSheet(), $rows);
        $this->buildRecordsSheet($book->createSheet(), $rows);
        $this->buildPerEmployeeSheet($book->createSheet(), $rows);

        $book->setActiveSheetIndex(0);

        $stamp = now()->format('Y-m-d_H-i');
        $filename = "attendance_{$this->period['from']}_to_{$this->period['to']}_{$stamp}.xlsx";

        return response()->streamDownload(function () use ($book) {
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // ─── Period & query ────────────────────────────────────────────────

    /**
     * Honour the index-page filters and resolve a clear from/to window.
     * If neither `date` nor `from/to` is given, default to the current
     * month — matches the manager's "give me last month's hours" intent.
     */
    protected function resolvePeriod(array $f): array
    {
        $from = $f['from'] ?? null;
        $to = $f['to'] ?? null;
        $date = $f['date'] ?? null;

        if ($date) {
            $from = $to = $date;
        }
        if (! $from) {
            $from = now()->startOfMonth()->toDateString();
        }
        if (! $to) {
            $to = now()->endOfMonth()->toDateString();
        }

        $branchId = BranchContext::current();

        return [
            'from' => $from,
            'to' => $to,
            'status' => $f['status'] ?? null,
            'user_id' => isset($f['user_id']) ? (int) $f['user_id'] : null,
            'branch_id' => $branchId,
            'branch_label' => $branchId
                ? (Branch::find($branchId)?->name ?? '—')
                : 'كل الفروع',
        ];
    }

    protected function buildQuery(array $f): Builder
    {
        $q = Attendance::with(['user', 'branch', 'editedBy'])
            ->whereDate('clock_in_at', '>=', $this->period['from'])
            ->whereDate('clock_in_at', '<=', $this->period['to'])
            ->orderBy('clock_in_at', 'desc');

        if ($this->period['status'] === 'open') {
            $q->whereNull('clock_out_at');
        }
        if ($this->period['status'] === 'closed') {
            $q->whereNotNull('clock_out_at')->where('source', '!=', Attendance::SOURCE_REVIEW);
        }
        if ($this->period['status'] === 'review') {
            $q->where('source', Attendance::SOURCE_REVIEW);
        }
        if ($this->period['user_id']) {
            $q->where('user_id', $this->period['user_id']);
        }
        if ($search = trim((string) ($f['search'] ?? ''))) {
            $q->whereHas('user', fn (Builder $user) => $user
                ->where('name', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%"));
        }

        return $q;
    }

    // ─── Sheet 1: Summary ──────────────────────────────────────────────

    protected function buildSummarySheet($sheet, $rows): void
    {
        $sheet->setTitle('ملخص');
        $sheet->setRightToLeft(true);

        // Title
        $sheet->setCellValue('A1', 'تقرير الحضور والانصراف');
        $sheet->mergeCells('A1:D1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => $this->brandGreen]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(38);

        // Filter / period block
        $statusLabel = match ($this->period['status']) {
            'open' => 'مفتوحة فقط (حاضرون الآن)',
            'closed' => 'مغلقة فقط (أكملوا انصرافهم)',
            'review' => 'تحتاج مراجعة فقط (ساعاتها غير محتسبة)',
            default => 'كل الحالات',
        };
        $userLabel = $this->period['user_id']
            ? (User::find($this->period['user_id'])?->name ?? "#{$this->period['user_id']}")
            : 'كل الموظفين';
        $days = Carbon::parse($this->period['from'])
            ->diffInDays(Carbon::parse($this->period['to'])) + 1;

        $meta = [
            ['الفترة', Carbon::parse($this->period['from'])->locale('ar')->isoFormat('D MMMM YYYY')
                .' — '.Carbon::parse($this->period['to'])->locale('ar')->isoFormat('D MMMM YYYY')
                ." ({$days} يوم)"],
            ['الفرع',   $this->period['branch_label']],
            ['الموظف',  $userLabel],
            ['الحالة',  $statusLabel],
            ['تاريخ التقرير', now()->locale('ar')->isoFormat('D MMMM YYYY · HH:mm')],
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

        // KPIs
        $totalShifts = $rows->count();
        $openShifts = $rows->whereNull('clock_out_at')->count();
        $reviewShifts = $rows->where('source', Attendance::SOURCE_REVIEW)->count();
        $closedRows = $rows->whereNotNull('clock_out_at')
            ->where('source', '!=', Attendance::SOURCE_REVIEW);
        $closedShifts = $closedRows->count();
        $totalMinutes = (int) $rows->sum(fn ($r) => $r->effectiveMinutes());
        $avgMinutes = $closedShifts > 0
            ? (int) round($closedRows->avg('worked_minutes'))
            : 0;
        $uniqueStaff = $rows->pluck('user_id')->unique()->count();

        $row += 1;
        $this->writeKpi($sheet, $row++, 'إجمالي السجلات', $totalShifts, $this->brandGreen, 'count');
        $this->writeKpi($sheet, $row++, 'سجلات مفتوحة', $openShifts, '0E7490', 'count');
        $this->writeKpi($sheet, $row++, 'سجلات مغلقة', $closedShifts, '14532D', 'count');
        $this->writeKpi($sheet, $row++, 'موظفون فريدون', $uniqueStaff, '475569', 'count');
        $this->writeKpi($sheet, $row++, 'مجموع ساعات العمل', $totalMinutes, $this->brandGold, 'hours');
        $this->writeKpi($sheet, $row++, 'متوسط ساعات الورديّة (للمغلقة)', $avgMinutes, '92400E', 'hours');
        if ($reviewShifts > 0) {
            $this->writeKpi($sheet, $row++, 'سجلات تحتاج مراجعة', $reviewShifts, 'B45309', 'count');
        }

        // Footnote
        $row += 1;
        $sheet->setCellValue("A{$row}", 'كيف نقرأ هذا التقرير؟');
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => $this->brandGold]],
        ]);
        $row++;
        $note = "• ورقة «السجلات»: كل سطر هو ورديّة كاملة بتفاصيلها (دخول، خروج، استراحة، صافي عمل).\n"
              ."• ورقة «حسب الموظف»: تجميع لكل موظف خلال الفترة مع متوسط الورديّة.\n"
              ."• «الساعات» تُعرض بصيغة [س]:د — جاهزة للجمع في Excel كمدة زمنية.\n"
              ."• «تحتاج مراجعة»: سجل تُرك مفتوحاً أكثر من 24 ساعة؛ ساعاتُه صفر حتى يصححه المدير.\n"
              .'• الورديّات المفتوحة تستخدم الساعة الحالية لاحتساب «المدة حتى الآن».';
        $sheet->setCellValue("A{$row}", $note);
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getRowDimension($row)->setRowHeight(110);

        foreach (['A', 'B', 'C', 'D'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(28);
        }
    }

    /**
     * @param  string  $kind  'count' | 'hours'
     */
    protected function writeKpi($sheet, int $row, string $label, int $value, string $rgb, string $kind = 'count'): void
    {
        $sheet->setCellValue("A{$row}", $label);

        if ($kind === 'hours') {
            // Excel "duration" — store as fraction of a day, format as [h]:mm.
            $sheet->setCellValue("B{$row}", $value / 1440); // minutes → days
            $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode('[h]:mm "س ودقيقة"');
        } else {
            $sheet->setCellValue("B{$row}", $value);
            $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode('#,##0');
        }

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
        $sheet->getRowDimension($row)->setRowHeight(28);
    }

    // ─── Sheet 2: Records ──────────────────────────────────────────────

    protected function buildRecordsSheet($sheet, $rows): void
    {
        $sheet->setTitle('السجلات');
        $sheet->setRightToLeft(true);

        $headers = [
            'الموظف', 'اسم المستخدم', 'الفرع',
            'التاريخ', 'الحضور', 'الانصراف',
            'دقائق الاستراحة', 'صافي العمل (س:د)',
            'الحالة', 'المصدر', 'عُدّل بواسطة', 'ملاحظات',
        ];
        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeader($sheet, 'A1:L1');

        $row = 2;
        foreach ($rows as $a) {
            $worked = $a->effectiveMinutes();

            $sheet->setCellValue("A{$row}", $a->user->name ?? '—');
            $sheet->setCellValue("B{$row}", $a->user->username ?? '');
            $sheet->setCellValue("C{$row}", $a->branch->name ?? '—');
            $sheet->setCellValue("D{$row}", $a->clock_in_at->format('Y-m-d'));
            $sheet->setCellValue("E{$row}", $a->clock_in_at->format('H:i'));
            $sheet->setCellValue("F{$row}", $a->clock_out_at?->format('H:i') ?? '—');
            $sheet->setCellValue("G{$row}", (int) $a->break_minutes);
            // Worked minutes as Excel duration (fraction of a day) — formattable [h]:mm
            $sheet->setCellValue("H{$row}", $worked / 1440);
            $sheet->getStyle("H{$row}")->getNumberFormat()->setFormatCode('[h]:mm');

            $sheet->setCellValue("I{$row}", $a->needsReview()
                ? 'تحتاج مراجعة — غير محتسبة'
                : ($a->isOpen() ? 'مفتوحة (حاضر الآن)' : 'مغلقة'));
            $sheet->setCellValue("J{$row}", $this->sourceLabel($a->source));
            $sheet->setCellValue("K{$row}", $a->editedBy->name ?? '—');
            $sheet->setCellValue("L{$row}", (string) $a->notes);

            // Visual cues for non-closed rows
            if ($a->isOpen()) {
                $sheet->getStyle("A{$row}:L{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('ECFDF5');
            } elseif ($a->source === Attendance::SOURCE_REVIEW) {
                $sheet->getStyle("A{$row}:L{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('FEF3C7');
            } elseif ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:L{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB($this->rowAlt);
            }

            $row++;
        }

        // Total row with SUM formula on the duration column
        if ($row > 2) {
            $last = $row - 1;
            $sheet->setCellValue("A{$row}", 'الإجمالي');
            $sheet->mergeCells("A{$row}:G{$row}");
            $sheet->setCellValue("H{$row}", "=SUM(H2:H{$last})");
            $sheet->getStyle("H{$row}")->getNumberFormat()->setFormatCode('[h]:mm');
            $sheet->getStyle("A{$row}:L{$row}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF6F1']],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => $this->brandGreen]]],
            ]);
            $sheet->getStyle("A{$row}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT);
        } else {
            $sheet->setCellValue('A2', 'لا توجد سجلات ضمن الفلاتر المختارة.');
            $sheet->mergeCells('A2:L2');
            $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        // Column widths — auto for short numerics, explicit for the wide ones
        foreach (['A', 'C', 'J', 'K'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(22);
        }
        foreach (['B', 'D', 'E', 'F', 'G', 'H', 'I'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('L')->setWidth(40);
        $sheet->getStyle('L2:L'.max(2, $row - 1))->getAlignment()->setWrapText(true);

        $sheet->freezePane('A2');
        // AutoFilter is friendly for managers — they can slice without re-running.
        if ($rows->count() > 0) {
            $sheet->setAutoFilter('A1:L'.($row - 1));
        }
    }

    // ─── Sheet 3: Per-Employee aggregation ─────────────────────────────

    protected function buildPerEmployeeSheet($sheet, $rows): void
    {
        $sheet->setTitle('حسب الموظف');
        $sheet->setRightToLeft(true);

        $headers = [
            'الموظف', 'اسم المستخدم',
            'عدد السجلات', 'سجلات مغلقة', 'سجلات مفتوحة',
            'إجمالي ساعات العمل', 'متوسط الورديّة',
            'إجمالي الاستراحة (د)',
            'ذاتي', 'يدوي', 'تحتاج مراجعة',
        ];
        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeader($sheet, 'A1:K1');

        $grouped = $rows->groupBy('user_id');
        $row = 2;

        foreach ($grouped as $userId => $userRows) {
            $first = $userRows->first();
            $closed = $userRows->whereNotNull('clock_out_at')
                ->where('source', '!=', Attendance::SOURCE_REVIEW);
            $open = $userRows->whereNull('clock_out_at');
            $totalM = (int) $userRows->sum(fn ($r) => $r->effectiveMinutes());
            $avgM = $closed->count() > 0
                ? (int) round($closed->avg('worked_minutes'))
                : 0;
            $breakM = (int) $userRows->sum('break_minutes');

            $sheet->setCellValue("A{$row}", $first->user->name ?? '—');
            $sheet->setCellValue("B{$row}", $first->user->username ?? '');
            $sheet->setCellValue("C{$row}", $userRows->count());
            $sheet->setCellValue("D{$row}", $closed->count());
            $sheet->setCellValue("E{$row}", $open->count());
            $sheet->setCellValue("F{$row}", $totalM / 1440);
            $sheet->setCellValue("G{$row}", $avgM / 1440);
            $sheet->setCellValue("H{$row}", $breakM);
            $sheet->setCellValue("I{$row}", $userRows->where('source', 'self')->count());
            $sheet->setCellValue("J{$row}", $userRows->where('source', 'manager_added')->count());
            $sheet->setCellValue("K{$row}", $userRows->where('source', Attendance::SOURCE_REVIEW)->count());

            $sheet->getStyle("F{$row}:G{$row}")->getNumberFormat()->setFormatCode('[h]:mm');
            $sheet->getStyle("C{$row}:E{$row}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("H{$row}:K{$row}")->getNumberFormat()->setFormatCode('#,##0');

            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:K{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB($this->rowAlt);
            }

            $row++;
        }

        // Total row
        if ($row > 2) {
            $last = $row - 1;
            $sheet->setCellValue("A{$row}", 'الإجمالي');
            $sheet->mergeCells("A{$row}:B{$row}");
            foreach (['C', 'D', 'E', 'F', 'H', 'I', 'J', 'K'] as $col) {
                $sheet->setCellValue("{$col}{$row}", "=SUM({$col}2:{$col}{$last})");
            }
            // Average of averages is misleading — use overall average instead
            $sheet->setCellValue("G{$row}", "=IFERROR(F{$row}/D{$row},0)");

            $sheet->getStyle("F{$row}:G{$row}")->getNumberFormat()->setFormatCode('[h]:mm');
            $sheet->getStyle("C{$row}:E{$row}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("H{$row}:K{$row}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("A{$row}:K{$row}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF6F1']],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => $this->brandGreen]]],
            ]);
            $sheet->getStyle("A{$row}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT);
        } else {
            $sheet->setCellValue('A2', 'لا توجد بيانات للتجميع.');
            $sheet->mergeCells('A2:K2');
            $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        foreach (['A'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(22);
        }
        foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A2');
        if ($grouped->count() > 0) {
            $sheet->setAutoFilter('A1:K'.($row - 1));
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    protected function sourceLabel(?string $src): string
    {
        return match ($src) {
            'self' => 'ذاتي',
            'manager_added' => 'يدوي (مدير)',
            Attendance::SOURCE_REVIEW => 'يحتاج مراجعة',
            default => $src ?? '—',
        };
    }

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
