<?php

namespace App\Helpers;

use App\Models\Permission;

/**
 * Translates internal permission names (e.g. `orders.approve`) into
 * human-readable Arabic labels + icons + group metadata, so the roles
 * screen can render a clean tree instead of raw permission codes.
 *
 * Kept as a single source of truth — when a new permission is added to
 * the seeder, also add its translation here. The roles form, the user
 * profile page, and the audit log all read from this one helper.
 */
class Permissions
{
    /** Arabic module (group) metadata: label + icon + display order. */
    public static function groupMeta(string $group): array
    {
        return self::GROUPS[$group] ?? [
            'label' => $group,
            'icon' => 'bi-square',
            'order' => 999,
        ];
    }

    /** Arabic label for a specific permission name (e.g. `orders.approve`). */
    public static function actionLabel(string $permissionName): string
    {
        if (isset(self::OVERRIDES[$permissionName])) {
            return self::OVERRIDES[$permissionName];
        }

        // Dynamic: `station.{code}.view` → "شاشة: <station name>"
        if (preg_match('/^station\.([a-z0-9_-]+)\.view$/i', $permissionName, $m)) {
            $stationCode = $m[1];
            $perm = Permission::where('name', $permissionName)->first();
            if ($perm && $perm->label && $perm->label !== $permissionName) {
                return $perm->label;       // "شاشة: المطبخ" (set by StationObserver)
            }

            return 'الوصول لشاشة: '.$stationCode;
        }

        $parts = explode('.', $permissionName, 2);
        $action = $parts[1] ?? $parts[0];

        return self::ACTIONS[$action] ?? $action;
    }

    /** Returns all groups sorted in display order, each with its permissions. */
    public static function tree($permissionsByGroup): array
    {
        $tree = [];
        foreach ($permissionsByGroup as $group => $perms) {
            // Retired modules may still have historical permission rows in an
            // upgraded database. Keep them out of assignment screens until a
            // dedicated, explicitly approved data-cleanup migration removes
            // those records and their pivots.
            if (! isset(self::GROUPS[$group])) {
                continue;
            }

            $meta = self::groupMeta($group);
            $tree[] = [
                'key' => $group,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'order' => $meta['order'],
                'permissions' => collect($perms)->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'label' => self::actionLabel($p->name),
                    ...self::impactMeta($p->name),
                ])->values()->all(),
            ];
        }
        usort($tree, fn ($a, $b) => $a['order'] <=> $b['order']);

        return $tree;
    }

    /**
     * The permission centre must explain the operational consequence, not
     * expose an internal key and leave the owner guessing. These categories
     * are intentionally small: they communicate risk without pretending to
     * replace the server policy that remains the source of truth.
     */
    public static function impactMeta(string $permissionName): array
    {
        $action = explode('.', $permissionName, 2)[1] ?? $permissionName;

        if (in_array($permissionName, self::SENSITIVE_PERMISSIONS, true)
            || in_array($action, ['delete', 'refund', 'void', 'writeoff', 'finalize'], true)) {
            return [
                'impactLabel' => 'إجراء حساس وموثّق',
                'impactTone' => 'sensitive',
                'impactIcon' => 'bi-shield-exclamation',
            ];
        }

        if (in_array($action, ['viewAny', 'view'], true) || str_ends_with($permissionName, '.view')) {
            return [
                'impactLabel' => 'يفتح الشاشة أو بياناتها',
                'impactTone' => 'access',
                'impactIcon' => 'bi-eye',
            ];
        }

        return [
            'impactLabel' => 'يسمح بتنفيذ الإجراء',
            'impactTone' => 'action',
            'impactIcon' => 'bi-lightning-charge',
        ];
    }

    /** Modules (groups) — ordered for display. */
    private const GROUPS = [
        'users' => ['label' => 'المستخدمون',         'icon' => 'bi-people-fill',          'order' => 1],
        'settings' => ['label' => 'الإعدادات',          'icon' => 'bi-gear-fill',            'order' => 2],

        'categories' => ['label' => 'الأقسام',            'icon' => 'bi-tags-fill',            'order' => 10],
        'menu_items' => ['label' => 'أصناف المنيو',       'icon' => 'bi-card-list',            'order' => 11],
        'modifiers' => ['label' => 'الإضافات',           'icon' => 'bi-sliders2',             'order' => 12],

        'tables' => ['label' => 'الطاولات',           'icon' => 'bi-grid-3x3-gap-fill',    'order' => 20],
        'orders' => ['label' => 'الطلبات',            'icon' => 'bi-receipt-cutoff',       'order' => 21],
        'payments' => ['label' => 'الفواتير والدفع',    'icon' => 'bi-cash-stack',           'order' => 22],
        'discounts' => ['label' => 'الخصومات',           'icon' => 'bi-percent',              'order' => 23],
        'expenses' => ['label' => 'المصروفات',          'icon' => 'bi-cash-coin',            'order' => 24],
        'customers' => ['label' => 'العملاء',            'icon' => 'bi-person-hearts',        'order' => 25],
        'reservations' => ['label' => 'الحجوزات',           'icon' => 'bi-calendar-check',       'order' => 26],
        'reviews' => ['label' => 'التقييمات',          'icon' => 'bi-star-fill',            'order' => 27],
        'attendance' => ['label' => 'الحضور والانصراف',   'icon' => 'bi-clock-history',        'order' => 28],
        'lookups' => ['label' => 'إدارة الثوابت',      'icon' => 'bi-list-ul',              'order' => 4],

        'ingredients' => ['label' => 'المكوّنات',          'icon' => 'bi-basket-fill',          'order' => 30],
        'inventory' => ['label' => 'المخزون',            'icon' => 'bi-box-seam',        'order' => 31],
        'storage_locations' => ['label' => 'مواقع التخزين',       'icon' => 'bi-boxes',              'order' => 32],
        'stock_counts' => ['label' => 'الجرد',              'icon' => 'bi-clipboard2-check',     'order' => 33],
        'waste' => ['label' => 'الهدر والتالف',       'icon' => 'bi-trash3-fill',          'order' => 34],
        'suppliers' => ['label' => 'الموردون',           'icon' => 'bi-truck-front-fill',     'order' => 35],
        'purchase_orders' => ['label' => 'أوامر الشراء',       'icon' => 'bi-bag-check-fill',       'order' => 36],
        'supplier_invoices' => ['label' => 'فواتير الموردين',  'icon' => 'bi-file-earmark-text',    'order' => 37],

        'reports' => ['label' => 'التقارير',           'icon' => 'bi-graph-up-arrow',       'order' => 40],
        'activity_logs' => ['label' => 'سجل النشاطات',       'icon' => 'bi-journal-text',         'order' => 41],
        'staff_meals' => ['label' => 'بدل وجبات الموظفين',  'icon' => 'bi-cup-hot-fill',         'order' => 42],
        'promotions' => ['label' => 'عروض وخصومات الأصناف', 'icon' => 'bi-tag-fill',             'order' => 27],
        'chart_of_accounts' => ['label' => 'شجرة الحسابات',     'icon' => 'bi-diagram-3-fill',       'order' => 43],

        // Dynamic group — one permission per Station, auto-managed by
        // StationObserver. The tree shows a card per station with a single
        // "view" toggle.
        'stations_access' => ['label' => 'شاشات المحطات',     'icon' => 'bi-tv-fill',              'order' => 50],
    ];

    /** Generic action names — used when no explicit override exists. */
    private const ACTIONS = [
        'viewAny' => 'عرض القائمة',
        'view' => 'عرض التفاصيل',
        'create' => 'إنشاء',
        'update' => 'تعديل',
        'edit' => 'تعديل',
        'delete' => 'حذف',
        'manage' => 'إدارة شاملة',
        'approve' => 'اعتماد',
        'cancel' => 'إلغاء',
        'refund' => 'استرداد',
        'toggle_availability' => 'تفعيل / إيقاف التوفّر',
        'open' => 'فتح',
        'close' => 'إغلاق',
        'view_all' => 'عرض كل السجلات',
        'export' => 'تصدير',
        'send' => 'إرسال',
        'receive' => 'استلام',
        'finalize' => 'اعتماد نهائي',
        'pay' => 'دفع',
        'transfer' => 'تحويل',
        'serve' => 'تسليم',
        'confirm' => 'تأكيد',
        'seat' => 'إجلاس الزبون',
        'complete' => 'إكمال',
        'no_show' => 'لم يحضر',
        'hide' => 'إخفاء',
        'unhide' => 'إظهار',
    ];

    /**
     * Explicit overrides — use when the generic action label is ambiguous
     * in the context of a specific module. Key is the full permission name.
     */
    private const OVERRIDES = [
        'payments.create' => 'إصدار فاتورة وتحصيل دفعة',
        'payments.settle_on_account' => 'تسجيل الفاتورة كاملة أو المتبقي منها ديناً وإلغاء التأجيل',
        'payments.refund' => 'استرداد مبلغ',
        'payments.void_own' => 'تصحيح دفعة سجّلتها اليوم',
        'payments.void' => 'إلغاء دفعة خاطئة وعكس قيدها',
        'payments.writeoff' => 'شطب رصيد فاتورة غير قابل للتحصيل',
        'payments.cancel_invoice' => 'إلغاء فاتورة قبل التحصيل',
        'discounts.apply' => 'إضافة خصم على الفاتورة',
        'discounts.remove' => 'إزالة خصم',
        'reports.export' => 'تصدير التقارير (PDF / Excel)',
        'orders.approve' => 'اعتماد الطلبات الواردة',
        'orders.cancel' => 'إلغاء الطلبات',
        'orders.archive' => 'أرشيف الطلبات والبحث المتقدّم',
        'tables.transfer' => 'نقل جلسة إلى طاولة أخرى',
        'tables.assign_sections' => 'توزيع الجرسون على أقسام الصالة',
        'menu_items.toggle_availability' => 'تفعيل / إيقاف الأصناف',
        'inventory.manage' => 'إدارة المخزون (جرد، تعديل، تحويل)',
        'expenses.approve' => 'اعتماد المصروفات',
        'expenses.reject' => 'رفض المصروفات',
        'customers.block' => 'حظر / إلغاء حظر العميل',
        'customers.manage_credit' => 'تحديد سقف دين الزبون وتعديله',
        // Staff meals — overrides because the generic action labels are
        // too vague ("settle" alone doesn't convey it's debt settlement).
        'staff_meals.viewAny' => 'عرض لوحة بدل وجبات الموظفين',
        'staff_meals.quick_consume' => 'تسجيل استهلاك سريع للموظفين',
        'staff_meals.settle' => 'تسوية مستحقات الموظفين',
        'staff_meals.waive' => 'إعفاء حركة أو منح هدية',
        'staff_meals.close_month' => 'إقفال شهر بدل الوجبات + كشف الرواتب',
    ];

    private const SENSITIVE_PERMISSIONS = [
        'payments.refund',
        'payments.void',
        'payments.writeoff',
        'payments.cancel_invoice',
        'expenses.approve',
        'expenses.reject',
        'purchase_orders.approve',
        'supplier_invoices.pay',
        'stock_counts.finalize',
        'staff_meals.close_month',
        'chart_of_accounts.create',
        'chart_of_accounts.update',
        'chart_of_accounts.delete',
        'users.delete',
        'settings.update',
    ];
}
