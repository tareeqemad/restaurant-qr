<?php

namespace App\Helpers;

/**
 * Translates internal permission names (e.g. `orders.approve`) into
 * human-readable Arabic labels + icons + group metadata, so the roles
 * screen can render a clean tree instead of raw English keys.
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
            'icon'  => 'bi-square',
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
            $perm = \App\Models\Permission::where('name', $permissionName)->first();
            if ($perm && $perm->label && $perm->label !== $permissionName) {
                return $perm->label;       // "شاشة: المطبخ" (set by StationObserver)
            }
            return 'الوصول لشاشة: ' . $stationCode;
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
            $meta = self::groupMeta($group);
            $tree[] = [
                'key'         => $group,
                'label'       => $meta['label'],
                'icon'        => $meta['icon'],
                'order'       => $meta['order'],
                'permissions' => collect($perms)->map(fn($p) => [
                    'id'    => $p->id,
                    'name'  => $p->name,
                    'label' => self::actionLabel($p->name),
                ])->values()->all(),
            ];
        }
        usort($tree, fn($a, $b) => $a['order'] <=> $b['order']);
        return $tree;
    }

    /** Modules (groups) — ordered for display. */
    private const GROUPS = [
        'roles'          => ['label' => 'الأدوار والصلاحيات', 'icon' => 'bi-shield-lock-fill',    'order' => 1],
        'users'          => ['label' => 'المستخدمون',         'icon' => 'bi-people-fill',          'order' => 2],
        'settings'       => ['label' => 'الإعدادات',          'icon' => 'bi-gear-fill',            'order' => 3],

        'categories'     => ['label' => 'الأقسام',            'icon' => 'bi-tags-fill',            'order' => 10],
        'menu_items'     => ['label' => 'أصناف المنيو',       'icon' => 'bi-card-list',            'order' => 11],
        'modifiers'      => ['label' => 'الإضافات',           'icon' => 'bi-sliders2',             'order' => 12],

        'tables'         => ['label' => 'الطاولات',           'icon' => 'bi-grid-3x3-gap-fill',    'order' => 20],
        'orders'         => ['label' => 'الطلبات',            'icon' => 'bi-receipt-cutoff',       'order' => 21],
        'payments'       => ['label' => 'الفواتير والدفع',    'icon' => 'bi-cash-stack',           'order' => 22],
        'shifts'         => ['label' => 'الورديات',           'icon' => 'bi-clock-history',        'order' => 23],
        'expenses'       => ['label' => 'المصروفات',          'icon' => 'bi-cash-coin',            'order' => 24],
        'customers'      => ['label' => 'العملاء',            'icon' => 'bi-person-hearts',        'order' => 25],
        'lookups'        => ['label' => 'إدارة الثوابت',      'icon' => 'bi-list-ul',              'order' => 4],

        'ingredients'    => ['label' => 'المكوّنات',          'icon' => 'bi-basket-fill',          'order' => 30],
        'inventory'      => ['label' => 'المخزون',            'icon' => 'bi-box-seam-fill',        'order' => 31],
        'storage_locations' => ['label' => 'مواقع التخزين',       'icon' => 'bi-boxes',              'order' => 32],
        'stock_counts'   => ['label' => 'الجرد',              'icon' => 'bi-clipboard2-check',     'order' => 33],
        'waste'          => ['label' => 'الهدر والتالف',       'icon' => 'bi-trash3-fill',          'order' => 34],
        'suppliers'      => ['label' => 'الموردون',           'icon' => 'bi-truck-front-fill',     'order' => 35],
        'purchase_orders' => ['label' => 'أوامر الشراء',       'icon' => 'bi-bag-check-fill',       'order' => 36],
        'supplier_invoices' => ['label' => 'فواتير الموردين',  'icon' => 'bi-file-earmark-text',    'order' => 37],

        'reports'        => ['label' => 'التقارير',           'icon' => 'bi-graph-up-arrow',       'order' => 40],
        'activity_logs'  => ['label' => 'سجل النشاطات',       'icon' => 'bi-journal-text',         'order' => 41],

        // Dynamic group — one permission per Station, auto-managed by
        // StationObserver. The tree shows a card per station with a single
        // "view" toggle.
        'stations_access' => ['label' => 'شاشات المحطات',     'icon' => 'bi-tv-fill',              'order' => 50],
    ];

    /** Generic action names — used when no explicit override exists. */
    private const ACTIONS = [
        'viewAny'              => 'عرض القائمة',
        'view'                 => 'عرض التفاصيل',
        'create'               => 'إنشاء',
        'update'               => 'تعديل',
        'edit'                 => 'تعديل',
        'delete'               => 'حذف',
        'manage'               => 'إدارة شاملة',
        'approve'              => 'اعتماد',
        'cancel'               => 'إلغاء',
        'refund'               => 'استرداد',
        'toggle_availability'  => 'تفعيل / إيقاف التوفّر',
        'open'                 => 'فتح',
        'close'                => 'إغلاق',
        'view_all'             => 'عرض كل السجلات',
        'export'               => 'تصدير',
        'send'                 => 'إرسال',
        'receive'              => 'استلام',
        'finalize'             => 'اعتماد نهائي',
        'pay'                  => 'دفع',
        'transfer'             => 'تحويل',
    ];

    /**
     * Explicit overrides — use when the generic action label is ambiguous
     * in the context of a specific module. Key is the full permission name.
     */
    private const OVERRIDES = [
        'payments.refund'              => 'استرداد مبلغ',
        'shifts.view_all'              => 'عرض ورديات كل الكاشيرات',
        'reports.export'               => 'تصدير التقارير (PDF / Excel)',
        'orders.approve'               => 'اعتماد الطلبات الواردة',
        'orders.cancel'                => 'إلغاء الطلبات',
        'orders.archive'               => 'أرشيف الطلبات والبحث المتقدّم',
        'menu_items.toggle_availability' => 'تفعيل / إيقاف الأصناف',
        'inventory.manage'             => 'إدارة المخزون (جرد، تعديل، تحويل)',
        'expenses.approve'             => 'اعتماد المصروفات',
        'expenses.reject'              => 'رفض المصروفات',
        'customers.block'              => 'حظر / إلغاء حظر العميل',
    ];
}
