<?php

namespace App\Support;

use App\Models\Branch;

/**
 * A task-first map for the inventory and purchasing workspace.
 *
 * The main admin navigation intentionally exposes one entry only. Once the
 * operator enters the workspace this map becomes the secondary navigation,
 * keeping purchasing, stock control and setup close without turning the
 * global navigation into an inventory manual.
 */
class InventoryWorkspace
{
    public static function payload(): ?array
    {
        if (! request()->routeIs(self::routePatterns())) {
            return null;
        }

        $multiBranch = false;
        try {
            $multiBranch = Branch::where('is_active', true)->count() > 1;
        } catch (\Throwable) {
        }

        return [
            'type' => 'inventory',
            'active' => self::activeKey(),
            'groups' => [
                [
                    'id' => 'home',
                    'label' => 'المركز',
                    'icon' => 'bi-grid-1x2-fill',
                    'items' => [
                        self::item('dashboard', 'ملخص العمل', 'admin.inventory.dashboard'),
                        self::item('movements', 'حركة المخزون', 'admin.inventory.index'),
                    ],
                ],
                [
                    'id' => 'purchasing',
                    'label' => 'الشراء',
                    'icon' => 'bi-bag-check-fill',
                    'items' => [
                        self::item('purchaseOrders', 'أوامر الشراء', 'admin.purchase-orders.index'),
                        self::item('supplierInvoices', 'فواتير الموردين', 'admin.supplier-invoices.index'),
                        self::item('suppliers', 'الموردون', 'admin.suppliers.index'),
                        config('restaurant.nav.vendor_price_compare')
                            ? self::item('prices', 'مقارنة الأسعار', 'admin.vendor-prices.compare') : null,
                    ],
                ],
                [
                    'id' => 'stock',
                    'label' => 'المخزون',
                    'icon' => 'bi-box-seam-fill',
                    'items' => [
                        self::item('ingredients', 'المكونات والأرصدة', 'admin.ingredients.index'),
                        config('restaurant.nav.batch_expiry')
                            ? self::item('batches', 'الدفعات والصلاحية', 'admin.batches.index') : null,
                        self::item('locations', 'مواقع التخزين', 'admin.storage-locations.index'),
                    ],
                ],
                [
                    'id' => 'control',
                    'label' => 'الرقابة',
                    'icon' => 'bi-clipboard2-check-fill',
                    'items' => [
                        self::item('counts', 'الجرد الفعلي', 'admin.stock-counts.index'),
                        self::item('waste', 'الهدر والتالف', 'admin.waste.index'),
                        $multiBranch ? self::item('transfers', 'تحويلات الفروع', 'admin.branch-transfers.index') : null,
                    ],
                ],
                [
                    'id' => 'setup',
                    'label' => 'الإعداد',
                    'icon' => 'bi-sliders2',
                    'items' => [
                        config('restaurant.nav.units_management')
                            ? self::item('units', 'وحدات القياس', 'admin.units.index') : null,
                        self::item('reorder', 'اقتراحات التوريد', 'admin.reports.reorder-suggestions'),
                        self::item('valuation', 'تقييم المخزون', 'admin.reports.stock-valuation'),
                    ],
                ],
            ],
        ];
    }

    protected static function item(string $key, string $label, string $route): array
    {
        return ['key' => $key, 'label' => $label, 'href' => route($route)];
    }

    protected static function activeKey(): string
    {
        return match (true) {
            request()->routeIs('admin.inventory.dashboard') => 'dashboard',
            request()->routeIs('admin.inventory.*') => 'movements',
            request()->routeIs('admin.purchase-orders.*') => 'purchaseOrders',
            request()->routeIs('admin.supplier-invoices.*') => 'supplierInvoices',
            request()->routeIs('admin.suppliers.*') => 'suppliers',
            request()->routeIs('admin.vendor-prices.*') => 'prices',
            request()->routeIs('admin.ingredients.*') => 'ingredients',
            request()->routeIs('admin.batches.*') => 'batches',
            request()->routeIs('admin.storage-locations.*') => 'locations',
            request()->routeIs('admin.stock-counts.*') => 'counts',
            request()->routeIs('admin.waste.*') => 'waste',
            request()->routeIs('admin.branch-transfers.*') => 'transfers',
            request()->routeIs('admin.units.*') => 'units',
            request()->routeIs('admin.reports.reorder-suggestions') => 'reorder',
            request()->routeIs('admin.reports.stock-valuation') => 'valuation',
            default => 'dashboard',
        };
    }

    protected static function routePatterns(): array
    {
        return [
            'admin.inventory.*', 'admin.ingredients.*', 'admin.suppliers.*',
            'admin.vendor-prices.*', 'admin.purchase-orders.*',
            'admin.supplier-invoices.*', 'admin.stock-counts.*',
            'admin.batches.*', 'admin.branch-transfers.*',
            'admin.storage-locations.*', 'admin.waste.*', 'admin.units.*',
            'admin.reports.reorder-suggestions', 'admin.reports.stock-valuation',
        ];
    }
}
