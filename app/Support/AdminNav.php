<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Lookup;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Review;
use App\Models\Role;
use App\Models\Station;
use App\Models\Table;
use App\Models\User;

/**
 * Server-built admin navigation for the Inertia shell (Wave 0).
 *
 * This is admin/partials/sidebar.blade.php reborn as data: every @can /
 * hasAnyRole / config() / badge decision that lived in those 731 lines of
 * Blade lives here, so authorization stays a PHP concern. The Vue layout
 * renders whatever this emits and adds ZERO gating of its own — the client
 * can never reveal a link the server didn't send.
 *
 * Item shape:
 *   label    string
 *   icon     string|null  full class ("bi bi-…" or "ri-…")
 *   href     string|null  null on pure parents
 *   active   bool         request()->routeIs(…); parents roll up children
 *   badge    array|null   ['value' => int, 'tone' => 'danger'|'warning'|'success']
 *   tag      string|null  e.g. 'LIVE'
 *   newTab   bool
 *   children array        same shape; ['section' => label] rows are headers
 */
class AdminNav
{
    public static function build(): array
    {
        $u = auth()->user();
        if (! $u) {
            return [];
        }

        $badges = SidebarBadges::counts();
        $is = fn (string ...$patterns) => request()->routeIs(...$patterns);
        $items = [];

        // ── Dashboard ────────────────────────────────────────────────
        $items[] = self::leaf(__('admin.nav.dashboard'), 'bi-house-door', route('admin.dashboard'), $is('admin.dashboard'));

        // ── Monitoring (management roles) ────────────────────────────
        if ($u->isManagementLevel()) {
            $items[] = self::group(__('admin.nav.monitoring'), 'bi-display', [
                self::leaf(__('admin.nav.branch_overview'), 'bi-building', route('admin.partner.overview'), $is('admin.partner.overview')),
                self::leaf(__('admin.nav.live_monitor'), 'bi-display', route('admin.partner.live-monitor'), $is('admin.partner.live-monitor'), null, ['newTab' => true, 'tag' => 'LIVE']),
            ], ['tag' => 'LIVE']);
        }

        // ── Daily operations ─────────────────────────────────────────
        $ops = [];
        $canCashier = $u->can('viewAny', Payment::class);
        $canExpenses = $u->can('viewAny', Expense::class);
        $pendingExpenses = $canExpenses ? ($badges['pending_expenses'] ?? 0) : 0;

        if ($u->can('viewAny', Table::class)) {
            $ops[] = self::leaf(
                __('admin.nav.tables'),
                'bi-grid-3x3-gap',
                route('admin.tables.index'),
                $is('admin.tables.*', 'admin.waiter-orders.*')
            );
        }

        if ($u->hasPermission('tables.assign_sections')) {
            $ops[] = self::leaf('توزيع الأقسام', 'bi-people', route('admin.section-assignments.index'), $is('admin.section-assignments.*'));
        }

        if ($u->can('viewAny', Attendance::class)) {
            $ops[] = self::leaf(__('admin.nav.attendance'), 'bi-clock', route('admin.attendance.index'), $is('admin.attendance.*'), self::badge($badges['open_attendance'], 'success'));
        }

        $canCust = $u->can('viewAny', Customer::class);
        $canDebt = $u->can('viewAny', Payment::class);
        $canRes = $u->can('viewAny', Reservation::class);
        $canRev = $u->can('viewAny', Review::class);
        $pendingRes = $canRes ? $badges['pending_reservations'] : 0;

        if ($canCust || $canDebt || $canRes || $canRev) {
            $ops[] = self::group(__('admin.nav.customers'), 'bi-people', array_filter([
                $canCust ? self::leaf(__('admin.nav.customers'), 'bi-people-fill', route('admin.customers.index'), $is('admin.customers.*')) : null,
                $canDebt ? self::leaf(__('admin.nav.debt_ledger'), 'bi-wallet2', route('admin.customers.debts.index'), $is('admin.customers.debts.*')) : null,
                $canRes ? self::leaf(__('admin.nav.reservations'), 'bi-calendar-event-fill', route('admin.reservations.index'), $is('admin.reservations.*'), self::badge($pendingRes, 'warning')) : null,
                $canRev ? self::leaf(__('admin.nav.reviews'), 'bi-star-fill', route('admin.reviews.index'), $is('admin.reviews.*')) : null,
            ]), ['badge' => self::badge($pendingRes, 'warning')]);
        }

        if ($u->can('viewAny', Order::class)) {
            $ops[] = self::leaf(__('admin.nav.dine_in_orders'), 'bi-journal-text', route('admin.orders.index'), $is('admin.orders.*'), self::badge($badges['pending_orders'], 'danger'));
        }

        if ($canCashier) {
            $ops[] = self::leaf(__('admin.nav.cashier'), 'bi-cash-stack', route('admin.cashier.index'), $is('admin.cashier.*'));
        }

        if ($canExpenses) {
            $ops[] = self::leaf(__('admin.nav.operating_expenses'), 'bi-receipt', route('admin.expenses.index'), $is('admin.expenses.*'), self::badge($pendingExpenses, 'warning'));
        }

        if ($u->hasPermission('staff_meals.viewAny')) {
            $ops[] = self::leaf(__('admin.nav.staff_meals'), 'bi-cup-hot-fill', route('admin.staff-meals.index'), $is('admin.staff-meals.*'));
        }

        if ($u->can('archive', Order::class)) {
            $ops[] = self::leaf(__('admin.nav.orders_archive'), 'bi-archive', route('admin.orders.archive'), $is('admin.orders.archive'));
        }

        // Production stations: station.{code}.view permission OR matching
        // user.station_id — hides entirely when the user has none.
        $stations = collect();
        try {
            $stations = Station::where('active', true)
                ->orderBy('display_order')
                ->get()
                ->filter(fn ($s) => $u->canAccessStation($s->code))
                ->values();
        } catch (\Throwable $e) {
        }

        if ($stations->isNotEmpty()) {
            $ops[] = self::group(__('admin.nav.production'), 'bi-fire', $stations->map(fn ($s) => self::leaf(
                $s->name,
                $s->icon ?: 'ri-fire-fill',
                route('admin.station.show', $s->code),
                $is('admin.station.show') && request()->route('code') === $s->code,
            ))->all());
        }

        if ($grp = self::group(__('admin.nav.operations'), 'bi-grid-1x2', $ops)) {
            $items[] = $grp;
        }

        // ── Inventory + purchasing (managers up) ─────────────────────
        // The workspace owns its task navigation. Keeping one global entry
        // avoids making the admin bar compete with the operator's workflow.
        if ($u->hasPermission('inventory.viewAny')) {
            $items[] = self::leaf(
                __('admin.nav.inventory_purchasing'),
                'bi-box-seam',
                route('admin.inventory.dashboard'),
                $is(
                    'admin.inventory.*', 'admin.ingredients.*', 'admin.suppliers.*',
                    'admin.vendor-prices.*', 'admin.purchase-orders.*',
                    'admin.supplier-invoices.*', 'admin.stock-counts.*',
                    'admin.batches.*', 'admin.branch-transfers.*',
                    'admin.storage-locations.*', 'admin.waste.*', 'admin.units.*',
                    'admin.reports.reorder-suggestions', 'admin.reports.stock-valuation',
                ),
            );
        }

        // ── Reports: the hub owns its internal navigation ────────────
        if ($u->hasPermission('reports.viewAny')) {
            $items[] = self::leaf(__('admin.nav.reports'), 'bi-graph-up', route('admin.reports.index'), $is('admin.reports.*'));
        }

        // ── Accounting: the center owns all ledgers and setup links ──
        $canViewAcc = $u->hasPermission('chart_of_accounts.viewAny');
        if ($canViewAcc) {
            $items[] = self::leaf(__('admin.nav.accounting_center'), 'bi-calculator-fill', route('admin.accounting.index'), $is('admin.accounting.*', 'admin.accounts.*'));
        }

        // ── System administration ────────────────────────────────────
        // Operational staff may read menu items to take or prepare orders.
        // The system-administration group is only for people who can change
        // the catalogue; read access alone must not expose a dead-end menu.
        $canMenu = $u->can('create', MenuItem::class)
            || $u->can('update', MenuItem::class)
            || $u->can('delete', MenuItem::class);
        $canStations = $u->hasAnyRole(['super_admin', 'admin']);
        $canUsers = $u->can('viewAny', User::class);
        $canRoles = $u->can('viewAny', Role::class);
        $canBranches = $u->can('viewAny', Branch::class);
        $canSettings = $u->hasPermission('settings.view');
        $canActivity = $u->can('viewAny', ActivityLog::class);
        $canLookups = $u->can('viewAny', Lookup::class);
        $canSystemHealth = $u->isSuperAdmin();
        if ($canMenu || $canStations || $canUsers || $canRoles || $canBranches || $canSettings || $canActivity || $canLookups || $canSystemHealth) {
            $sys = [];

            if ($canMenu || $canStations) {
                $sys[] = self::group(__('admin.nav.menu'), 'bi-book', array_filter([
                    $canMenu ? self::leaf(__('admin.nav.categories'), 'bi-grid-fill', route('admin.categories.index'), $is('admin.categories.*')) : null,
                    $canMenu ? self::leaf(__('admin.nav.menu_items'), 'bi-egg-fried', route('admin.menu-items.index'), $is('admin.menu-items.*')) : null,
                    $canMenu ? self::leaf(__('admin.nav.modifiers'), 'bi-plus-circle-fill', route('admin.modifiers.index'), $is('admin.modifiers.*')) : null,
                    $canMenu ? self::leaf(__('admin.nav.allergens'), 'bi-exclamation-triangle-fill', route('admin.allergens.index'), $is('admin.allergens.*')) : null,
                    ($canMenu && $u->hasPermission('promotions.viewAny')) ? self::leaf(__('admin.nav.promotions'), 'bi-tag-fill', route('admin.promotions.index'), $is('admin.promotions.*')) : null,
                    $canStations ? self::leaf(__('admin.nav.stations'), 'bi-fire', route('admin.stations.index'), $is('admin.stations.*')) : null,
                ]));
            }

            if ($canBranches) {
                $sys[] = self::leaf(__('admin.nav.branches'), 'bi-building', route('admin.branches.index'), $is('admin.branches.*'));
            }

            if ($canUsers || $canRoles) {
                $sys[] = self::group(__('admin.nav.users_permissions'), 'bi-people', array_filter([
                    $canUsers ? self::leaf(__('admin.nav.employees'), 'bi-person-fill', route('admin.users.index'), $is('admin.users.*')) : null,
                    $canRoles ? self::leaf('مركز الصلاحيات', 'bi-shield-lock-fill', route('admin.permissions.index'), $is('admin.roles.*', 'admin.permissions.*')) : null,
                ]));
            }

            if ($canLookups) {
                $sys[] = self::leaf(__('admin.nav.lookups'), 'bi-list-ul', route('admin.lookups.index'), $is('admin.lookups.*'));
            }

            if ($canSettings) {
                $sys[] = self::leaf(__('admin.nav.settings'), 'bi-gear', route('admin.settings.index'), $is('admin.settings.*', 'admin.currencies.*'));
            }

            if ($canActivity) {
                $sys[] = self::leaf(__('admin.nav.activity_log'), 'bi-clock-history', route('admin.activity-logs.index'), $is('admin.activity-logs.*'));
            }

            if ($canSystemHealth) {
                $sys[] = self::leaf('حالة النظام', 'bi-heart-pulse-fill', route('admin.system-health'), $is('admin.system-health'));
            }

            if ($grp = self::group(__('admin.nav.system_admin'), 'bi-layers', $sys)) {
                $items[] = $grp;
            }
        }

        return array_values(array_filter($items));
    }

    protected static function leaf(string $label, ?string $icon, string $href, bool $active, ?array $badge = null, array $extra = []): array
    {
        return array_merge([
            'label' => $label,
            'icon' => self::icon($icon),
            'href' => $href,
            'active' => $active,
            'badge' => $badge,
            'tag' => null,
            'newTab' => false,
            'children' => [],
        ], $extra);
    }

    /**
     * Parents render only when at least one real link survived its gate —
     * an empty dropdown helps nobody. Active rolls up from any child.
     */
    protected static function group(string $label, ?string $icon, array $children, array $extra = []): ?array
    {
        $children = array_values(array_filter($children));
        $links = array_filter($children, fn ($c) => empty($c['section']));
        if ($links === []) {
            return null;
        }

        return array_merge([
            'label' => $label,
            'icon' => self::icon($icon),
            'href' => null,
            'active' => (bool) array_filter($links, fn ($c) => ! empty($c['active'])),
            'badge' => null,
            'tag' => null,
            'newTab' => false,
            'children' => $children,
        ], $extra);
    }

    protected static function badge(int $value, string $tone): ?array
    {
        return $value > 0 ? ['value' => $value, 'tone' => $tone] : null;
    }

    /** "bi-x" → "bi bi-x"; anything else (ri-…, fe-…) passes through. */
    protected static function icon(?string $icon): ?string
    {
        if (! $icon) {
            return null;
        }

        return str_starts_with($icon, 'bi-') ? 'bi '.$icon : $icon;
    }
}
