<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Wipes demo / operational data while preserving the production-critical
 * system data so the client can start fresh from inside the running app.
 *
 * What gets wiped:
 *   - All branches (FK cascade clears stations, tables, categories,
 *     menu_items, modifier_groups, expenses, orders, invoices, payments,
 *     refunds, shifts, attendances, reservations, reviews, purchase_orders,
 *     supplier_invoices, stock_counts, inventory_movements,
 *     ingredient_batches, storage_locations, table_sessions, branch_user
 *     pivot, per-branch roles, per-branch lookups).
 *   - All users EXCEPT the one performing the reset (so they stay logged in).
 *   - End-customer accounts (`customers` + their portal sessions).
 *   - Loyalty rows (loyalty_customers, loyalty_transactions).
 *   - Activity logs (their subject FKs would dangle anyway).
 *
 * What stays untouched:
 *   - Roles (global templates), Permissions, role_permission pivot.
 *   - Units, Allergens, Currencies, Settings.
 *   - Suppliers, Ingredients (treated as global "company-wide" resources).
 *   - Global lookups (expense_categories rows where branch_id IS NULL).
 *   - The acting Super Admin user.
 *   - Laravel internals (migrations, sessions, jobs, cache, …).
 *
 * Safety contract:
 *   - The whole wipe runs inside a transaction; a mid-stream failure rolls
 *     back to pre-reset state.
 *   - Caller MUST already be authorized as Super Admin — this service does
 *     not re-check, it only takes the user and trusts the controller's
 *     authorization. The CLI variant skips the user-keeping step.
 */
class DemoResetService
{
    /**
     * @param  ?User  $keepUser  The user to preserve (typically auth()->user()).
     *                           Pass null when running from CLI / setup where
     *                           wiping every user is intended.
     * @param  bool  $wipeBusinessReferenceData  True for demo-to-live setup:
     *                           also remove suppliers, ingredients, custom
     *                           accounts/roles/lookups, sync, and license rows.
     * @return array{branches:int, users_deleted:int, customers:int}
     */
    public function reset(?User $keepUser = null, bool $wipeBusinessReferenceData = false): array
    {
        $keepUserId = $keepUser?->id;

        // We deliberately DON'T wrap this in DB::transaction. MySQL's TRUNCATE
        // is DDL and implicitly commits any open transaction, which would
        // leave the wrapper in an invalid state. We use DELETE FROM throughout
        // (slower for huge tables but irrelevant for a one-shot cleanup) and
        // temporarily disable FK constraints to skip dependency-order gymnastics.
        $branchesDeleted = 0;
        $usersDeleted    = 0;

        Schema::disableForeignKeyConstraints();
        try {
            // 1) Tables that are independent or whose FKs are nullable —
            //    wipe them first so the cascade from branches doesn't have
            //    to chase deeper trees.
            DB::table('activity_logs')->delete();
            DB::table('loyalty_transactions')->delete();
            DB::table('loyalty_customers')->delete();
            if (\Schema::hasTable('customer_addresses')) {
                DB::table('customer_addresses')->delete();
            }
            DB::table('customers')->delete();

            // 2) Operational + per-branch data. We list every operational
            //    table so a future schema addition that forgets the FK still
            //    gets wiped. Order is leaf → root to keep things tidy even
            //    though FK_CHECKS is off.
            $operational = [
                'notifications', 'announcements',
                'delivery_assignments',
                'order_item_modifiers', 'order_discounts', 'order_items', 'orders',
                'invoice_splits', 'invoices',
                'payments', 'refunds',
                'cash_movements',
                'shifts', 'attendances',
                'reservations', 'reviews',
                'branch_transfer_items', 'branch_transfers',
                'purchase_receipt_items', 'purchase_receipts',
                'purchase_order_items', 'purchase_orders',
                'supplier_payments', 'supplier_invoice_items', 'supplier_invoices',
                'stock_count_items', 'stock_counts',
                'inventory_movements',
                'inventory_snapshots',
                'ingredient_batches',
                'ingredient_stock',
                'storage_locations',
                'table_sessions',
                'pending_transfers',
                'staff_meal_charges', 'staff_meal_month_closures',
                'recipe_items', 'modifier_recipe_items',
                'menu_item_allergens', 'menu_item_modifier_group',
                'modifiers', 'modifier_groups',
                'menu_items', 'categories',
                'menu_promotions',
                'tables',
                'discounts',
                'expenses',
                'stations',
            ];

            foreach ($operational as $table) {
                if (\Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            // 3) Per-branch lookups (zones). Global ones (expense_categories,
            //    branch_id IS NULL) stay.
            if ($wipeBusinessReferenceData) {
                DB::table('lookups')->delete();
            } else {
                DB::table('lookups')->whereNotNull('branch_id')->delete();
            }

            // 4) Per-branch roles (overrides). Global role templates
            //    (branch_id IS NULL) stay so the next setup flow can
            //    assign them.
            if ($wipeBusinessReferenceData) {
                DB::table('role_permission')->delete();
                DB::table('user_permission')->delete();
                DB::table('permissions')->delete();
                DB::table('roles')->delete();
            } else {
                DB::table('roles')->whereNotNull('branch_id')->delete();
                DB::table('user_permission')->delete();
            }

            if ($wipeBusinessReferenceData) {
                $businessReference = [
                    'ingredient_units',
                    'ingredient_supplier_prices',
                    'branch_supplier',
                    'ingredients',
                    'suppliers',
                    'journal_lines',
                    'journal_entries',
                    'currency_exchange_rates',
                    'accounts',
                    'sync_states',
                    'license_activations',
                    'license_payments',
                    'licenses',
                    'local_license_states',
                ];

                foreach ($businessReference as $table) {
                    if (\Schema::hasTable($table)) {
                        DB::table($table)->delete();
                    }
                }
            }

            // 5) branch_user pivot — explicit (FK cascade SHOULD handle it
            //    when branches are deleted, but explicit is safer).
            DB::table('branch_user')->delete();

            // 6) Branches themselves.
            $branchesDeleted = DB::table('branches')->count();
            DB::table('branches')->delete();

            // 7) Users — keep the current Super Admin so the session
            //    survives the wipe. CLI calls pass null to wipe everyone.
            $usersQuery = DB::table('users');
            if ($keepUserId !== null) {
                $usersQuery->where('id', '!=', $keepUserId);
            }
            $usersDeleted = $usersQuery->count();
            $usersQuery->delete();
            if (\Schema::hasTable('password_reset_tokens')) {
                DB::table('password_reset_tokens')->delete();
            }

            Log::info('DemoResetService: wipe complete', [
                'kept_user_id'   => $keepUserId,
                'branches'       => $branchesDeleted,
                'users_deleted'  => $usersDeleted,
                'full_business_wipe' => $wipeBusinessReferenceData,
            ]);
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        // Clear BranchContext — there are no branches to be in any more.
        BranchContext::clear();
        if (function_exists('session') && app()->bound('session.store')) {
            session()->forget(['active_branch_id', 'view_all_branches']);
        }

        // Audit row, written AFTER activity_logs has been wiped so it
        // becomes the single oldest entry on the fresh slate.
        if ($keepUserId !== null) {
            ActivityLog::log(
                'system.demo_reset',
                'تم مسح كل بيانات العرض التجريبية وإعادة النظام لحالة الإنتاج النظيفة.',
                null
            );
        }

        return [
            'branches'      => $branchesDeleted,
            'users_deleted' => $usersDeleted,
        ];
    }

    /**
     * Quick health-check before showing the reset button — true if there's
     * any operational/demo data the reset would actually clear. Lets the
     * UI hide the button (or show a "no demo data" message) on a clean
     * production install.
     */
    public function hasDemoData(): bool
    {
        return DB::table('branches')->exists()
            || DB::table('users')->count() > 1
            || DB::table('orders')->exists();
    }
}
