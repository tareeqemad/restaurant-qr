<?php

namespace App\Notifications;

use App\Models\Shift;

/**
 * Sent when a cashier closes their shift. The most actionable signal here
 * is `cash_variance` — a non-zero value means the till is over/short, and
 * a manager should reconcile before the next shift opens.
 */
class ShiftClosedNotification extends BaseNotification
{
    public function __construct(public Shift $shift)
    {
        parent::__construct();
        // Shifts are not strictly branch-scoped (branch_user pivot tracks
        // membership), so fall back to the cashier's primary branch when
        // the shift row itself doesn't carry one.
        $this->branchId = $shift->branch_id
            ?? optional($shift->user)->primaryBranch()?->id
            ?? $this->branchId;
    }

    public function typeKey(): string { return 'shift.closed'; }

    /**
     * Variance > 1 unit (any direction) = warning; spot-on = info.
     * Big shortages get danger to make sure they catch attention.
     */
    public function severity(): string
    {
        $variance = abs((float) $this->shift->cash_variance);
        if ($variance < 1)   return 'info';
        if ($variance < 50)  return 'warning';
        return 'danger';
    }

    public function icon(): string
    {
        return abs((float) $this->shift->cash_variance) >= 1
            ? 'bi-cash-stack'
            : 'bi-check2-circle';
    }

    public function title(): string
    {
        $cashier = $this->shift->user?->name ?? 'كاشير';
        return "إغلاق وردية: {$cashier}";
    }

    public function body(): string
    {
        $variance = (float) $this->shift->cash_variance;
        $sales    = number_format((float) $this->shift->total_sales, 2);
        $tag      = $variance == 0
            ? 'مطابق تماماً'
            : ($variance > 0
                ? 'فائض ' . number_format($variance, 2)
                : 'عجز '   . number_format(abs($variance), 2));
        return "إجمالي المبيعات: {$sales} ₪ · {$tag}";
    }

    public function actionUrl(): string
    {
        return route('admin.shifts.index');
    }

    public function actionLabel(): string
    {
        return 'افتح الورديات';
    }

    public function extra(): array
    {
        return [
            'shift_id'       => $this->shift->id,
            'cashier_id'     => $this->shift->user_id,
            'cash_variance'  => (float) $this->shift->cash_variance,
            'total_sales'    => (float) $this->shift->total_sales,
        ];
    }
}
