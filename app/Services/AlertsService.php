<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\Order;
use App\Models\StockCount;
use App\Models\SupplierInvoice;

/**
 * Surfaces operational alerts for the dashboard and badge counters.
 *
 * Rather than scattering alert queries across controllers, this service
 * centralizes them so the dashboard, sidebar badges, and any notification
 * subsystem can pull from the same source of truth.
 *
 * Every method returns simple arrays/collections so Blade can iterate
 * without extra typecasting.
 */
class AlertsService
{
    /**
     * Aggregate snapshot for the dashboard alerts panel.
     * Returns a list of alert rows with severity, icon, label, count, route.
     */
    public function dashboardSnapshot(): array
    {
        $alerts = [];

        // 1) Expired batches — critical (must be wasted)
        $expiredCount = IngredientBatch::where('remaining_qty', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now()->toDateString())
            ->count();
        if ($expiredCount > 0) {
            $alerts[] = [
                'severity' => 'critical',
                'icon'     => 'bi-x-octagon-fill',
                'title'    => 'دفعات منتهية الصلاحية',
                'message'  => "{$expiredCount} دفعة لها مخزون متبقٍ ويجب معالجتها كهدر.",
                'count'    => $expiredCount,
                'route'    => route('admin.batches.index', ['expired' => 1]),
                'cta'      => 'عرضها',
            ];
        }

        // 2) Near-expiry (within 7 days) — warning
        $nearExpiry = IngredientBatch::where('remaining_qty', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->count();
        if ($nearExpiry > 0) {
            $alerts[] = [
                'severity' => 'warning',
                'icon'     => 'bi-alarm-fill',
                'title'    => 'دفعات تنتهي قريباً',
                'message'  => "{$nearExpiry} دفعة تنتهي صلاحيتها خلال 7 أيام — خطط لاستخدامها أولاً.",
                'count'    => $nearExpiry,
                'route'    => route('admin.batches.index', ['expiring' => 1]),
                'cta'      => 'عرضها',
            ];
        }

        // 3) Overdue supplier invoices — critical (cashflow)
        $overdueAP = SupplierInvoice::whereNotIn('status', ['paid', 'cancelled'])
            ->whereDate('due_date', '<', now()->toDateString())
            ->count();
        if ($overdueAP > 0) {
            $amountAP = (float) SupplierInvoice::whereNotIn('status', ['paid', 'cancelled'])
                ->whereDate('due_date', '<', now()->toDateString())
                ->sum('balance');
            $alerts[] = [
                'severity' => 'critical',
                'icon'     => 'bi-cash-coin',
                'title'    => 'فواتير موردين متأخرة',
                'message'  => "{$overdueAP} فاتورة تجاوزت تاريخ الاستحقاق (الإجمالي: ".number_format($amountAP, 2).").",
                'count'    => $overdueAP,
                'route'    => route('admin.supplier-invoices.index', ['overdue' => 1]),
                'cta'      => 'سدّد',
            ];
        }

        // 4) AP due in next 7 days — info
        $dueSoon = SupplierInvoice::whereNotIn('status', ['paid', 'cancelled'])
            ->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->count();
        if ($dueSoon > 0) {
            $alerts[] = [
                'severity' => 'info',
                'icon'     => 'bi-calendar-event',
                'title'    => 'فواتير موردين مستحقة قريباً',
                'message'  => "{$dueSoon} فاتورة تستحق خلال 7 أيام.",
                'count'    => $dueSoon,
                'route'    => route('admin.supplier-invoices.index'),
                'cta'      => 'عرضها',
            ];
        }

        // 5) Low-stock items — warning
        $lowStock = Ingredient::where('track_stock', true)
            ->whereColumn('current_stock', '<=', 'reorder_threshold')
            ->where('current_stock', '>', 0)
            ->count();
        if ($lowStock > 0) {
            $alerts[] = [
                'severity' => 'warning',
                'icon'     => 'bi-exclamation-triangle-fill',
                'title'    => 'مكونات بمخزون منخفض',
                'message'  => "{$lowStock} مكون وصل حد الطلب أو أقل. أنشئ أمر شراء.",
                'count'    => $lowStock,
                'route'    => route('admin.ingredients.index', ['low_stock' => 1]),
                'cta'      => 'عرضها',
            ];
        }

        // 6) Out of stock items — critical
        $outOfStock = Ingredient::where('track_stock', true)
            ->where('current_stock', '<=', 0)
            ->count();
        if ($outOfStock > 0) {
            $alerts[] = [
                'severity' => 'critical',
                'icon'     => 'bi-x-circle-fill',
                'title'    => 'مكونات نفذت',
                'message'  => "{$outOfStock} مكون خرج من المخزون تماماً — قد يمنع اعتماد طلبات.",
                'count'    => $outOfStock,
                'route'    => route('admin.ingredients.index'),
                'cta'      => 'عرضها',
            ];
        }

        // 7) Stale stock count — info (last count > 30 days ago or never)
        $lastCount = StockCount::where('status', 'finalized')->max('finalized_at');
        if (!$lastCount || \Carbon\Carbon::parse($lastCount)->lt(now()->subDays(30))) {
            $days = $lastCount ? (int) \Carbon\Carbon::parse($lastCount)->diffInDays(now()) : null;
            $alerts[] = [
                'severity' => 'info',
                'icon'     => 'bi-clipboard-check',
                'title'    => 'حان وقت الجرد',
                'message'  => $lastCount
                    ? "آخر جرد قبل {$days} يوم. يُوصى بالجرد شهرياً."
                    : "لم يُجرَ جرد فعلي للمخزون حتى الآن.",
                'count'    => 1,
                'route'    => route('admin.stock-counts.create'),
                'cta'      => 'ابدأ جرداً',
            ];
        }

        // 8) Pending orders awaiting approval — info
        $pendingOrders = Order::where('status', 'pending')->count();
        if ($pendingOrders > 0) {
            $alerts[] = [
                'severity' => 'info',
                'icon'     => 'bi-hourglass-split',
                'title'    => 'طلبات بانتظار الاعتماد',
                'message'  => "{$pendingOrders} طلب من الزبائن بانتظار موافقتك.",
                'count'    => $pendingOrders,
                'route'    => route('admin.orders.index', ['status' => 'pending']),
                'cta'      => 'افتح',
            ];
        }

        return $alerts;
    }
}
