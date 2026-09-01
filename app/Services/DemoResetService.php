<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Clears trial activity before handover without throwing away the catalogue
 * the restaurant approved while testing the product.
 *
 * A setup handover may preserve one branch as a catalogue template. Its menu,
 * recipes, ingredient definitions, images, modifier groups, stations and
 * storage-location skeleton survive; stock, suppliers, tables, people and all
 * financial/operational history do not. A plain administrative reset can omit
 * the branch id and retain the older full-wipe behaviour.
 *
 * The caller owns authorization. Foreign keys are temporarily disabled because
 * this one-shot operation intentionally cuts through the entire dependency
 * graph while keeping the current authenticated session alive.
 */
class DemoResetService
{
    /**
     * @param  ?User  $keepUser  The user to preserve (typically auth()->user()).
     *                           Pass null when running from CLI / setup where
     *                           wiping every user is intended.
     * @param  bool  $wipeBusinessReferenceData  Also rebuild accounting, suppliers,
     *                                           permissions and other business references.
     * @param  ?int  $preserveBranchId  Branch whose catalogue becomes the real menu.
     * @return array{branches:int, users_deleted:int}
     */
    public function reset(
        ?User $keepUser = null,
        bool $wipeBusinessReferenceData = false,
        ?int $preserveBranchId = null,
    ): array {
        $keepUserId = $keepUser?->id;

        if ($preserveBranchId !== null && ! DB::table('branches')->where('id', $preserveBranchId)->exists()) {
            throw new \InvalidArgumentException('The catalogue branch selected for setup does not exist.');
        }

        $catalogue = $this->catalogueIds($preserveBranchId);

        // We deliberately DON'T wrap this in DB::transaction. MySQL's TRUNCATE
        // is DDL and implicitly commits any open transaction, which would
        // leave the wrapper in an invalid state. We use DELETE FROM throughout
        // (slower for huge tables but irrelevant for a one-shot cleanup) and
        // temporarily disable FK constraints to skip dependency-order gymnastics.
        $branchesDeleted = 0;
        $usersDeleted = 0;

        Schema::disableForeignKeyConstraints();
        try {
            // 1) Tables that are independent or whose FKs are nullable —
            //    wipe them first so the cascade from branches doesn't have
            //    to chase deeper trees.
            // Every table carrying trial activity is explicit here. This list
            // is intentionally broader than cascade requirements: a new table
            // must make a conscious handover decision instead of surviving by
            // accident because its foreign key happened to be nullable.
            $operational = [
                'activity_logs',
                'notifications', 'announcements', 'section_assignments',
                'delivery_assignments',
                'order_item_ingredient_exclusions', 'order_change_requests',
                'order_item_modifiers', 'order_discounts', 'order_items', 'orders',
                'invoice_splits', 'credit_note_lines', 'refund_allocations',
                'credit_notes', 'debt_writeoffs', 'invoices',
                'cash_reconciliations', 'pending_transfers', 'customer_advance_transactions', 'payments', 'refunds',
                'loyalty_transactions', 'loyalty_customers',
                'customer_addresses', 'customers',
                'attendances',
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
                'table_sessions',
                'staff_meal_charges', 'staff_meal_month_closures',
                'tables',
                'discounts',
                'expenses',
                'fixed_asset_depreciations', 'fixed_assets',
                'journal_lines', 'journal_entries',
                'accounting_periods', 'fiscal_years',
                'customer_sales_tax_rates',
                'branch_ownerships', 'branch_legal_profiles',
            ];

            foreach ($operational as $table) {
                if (\Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            $this->pruneCatalogue($preserveBranchId, $catalogue, $keepUserId);

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
                    'ingredient_supplier_prices',
                    'branch_supplier',
                    'suppliers',
                    'account_mappings',
                    'currency_exchange_rates',
                    'accounts',
                    'sync_states',
                    'business_owners',
                ];

                foreach ($businessReference as $table) {
                    if (\Schema::hasTable($table)) {
                        DB::table($table)->delete();
                    }
                }

                if ($preserveBranchId === null) {
                    $this->deleteIfPresent('recipe_items');
                    $this->deleteIfPresent('modifier_recipe_items');
                    $this->deleteIfPresent('ingredient_units');
                    $this->deleteIfPresent('ingredients');
                } elseif (Schema::hasTable('ingredients')) {
                    // Definitions and recipe costs are catalogue data; physical
                    // quantity and preferred demo supplier are not.
                    DB::table('ingredients')->update([
                        'current_stock' => 0,
                        'supplier_id' => null,
                    ]);
                }
            }

            // 5) branch_user pivot — explicit (FK cascade SHOULD handle it
            //    when branches are deleted, but explicit is safer).
            DB::table('branch_user')->delete();

            // 6) Branches themselves. During setup the selected branch is
            //    renamed in place after the wipe, preserving exact menu ids,
            //    images and station routing without a lossy export/import.
            if ($preserveBranchId === null) {
                $branchesDeleted = DB::table('branches')->delete();
            } else {
                $branchesDeleted = DB::table('branches')
                    ->where('id', '!=', $preserveBranchId)
                    ->delete();
            }

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
                'kept_user_id' => $keepUserId,
                'branches' => $branchesDeleted,
                'users_deleted' => $usersDeleted,
                'full_business_wipe' => $wipeBusinessReferenceData,
                'preserved_branch_id' => $preserveBranchId,
            ]);
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        // The setup controller selects the preserved/new branch after writing
        // its real identity. Keeping stale branch state here would let the
        // first post-reset query inherit the demo context.
        BranchContext::clear();
        if (function_exists('session') && app()->bound('session.store')) {
            session()->forget(['active_branch_id', 'view_all_branches']);
        }

        // Audit row, written AFTER activity_logs has been wiped so it
        // becomes the single oldest entry on the fresh slate.
        if ($keepUserId !== null) {
            ActivityLog::log(
                'system.demo_reset',
                'تم تسليم النسخة التجريبية: مسحت الحركات والحسابات الوهمية، واحتُفظ بمنيو الفرع المعتمدة.',
                null
            );
        }

        return [
            'branches' => $branchesDeleted,
            'users_deleted' => $usersDeleted,
        ];
    }

    /**
     * @return array{items:array<int,int>,groups:array<int,int>,modifiers:array<int,int>}
     */
    private function catalogueIds(?int $branchId): array
    {
        if ($branchId === null) {
            return ['items' => [], 'groups' => [], 'modifiers' => []];
        }

        $items = DB::table('menu_items')->where('branch_id', $branchId)->pluck('id')->all();
        $groups = DB::table('modifier_groups')->where('branch_id', $branchId)->pluck('id')->all();
        $modifiers = $groups === []
            ? []
            : DB::table('modifiers')->whereIn('modifier_group_id', $groups)->pluck('id')->all();

        return compact('items', 'groups', 'modifiers');
    }

    /** @param array{items:array<int,int>,groups:array<int,int>,modifiers:array<int,int>} $catalogue */
    private function pruneCatalogue(?int $branchId, array $catalogue, ?int $keepUserId): void
    {
        if ($branchId === null) {
            foreach ([
                'recipe_items', 'modifier_recipe_items', 'menu_item_allergens',
                'menu_item_modifier_group', 'modifiers', 'modifier_groups',
                'menu_items', 'categories', 'menu_promotions',
                'storage_locations', 'stations',
            ] as $table) {
                $this->deleteIfPresent($table);
            }

            return;
        }

        $this->deleteNotIn('menu_item_allergens', 'menu_item_id', $catalogue['items']);
        $this->deleteNotIn('menu_item_modifier_group', 'menu_item_id', $catalogue['items']);
        $this->deleteNotIn('modifier_recipe_items', 'modifier_id', $catalogue['modifiers']);

        if (Schema::hasTable('recipe_items')) {
            $query = DB::table('recipe_items')->whereNotNull('menu_item_id');
            $catalogue['items'] === []
                ? $query->delete()
                : $query->whereNotIn('menu_item_id', $catalogue['items'])->delete();
        }

        $this->deleteNotIn('modifiers', 'modifier_group_id', $catalogue['groups']);
        $this->deleteOtherBranches('modifier_groups', $branchId);
        $this->deleteOtherBranches('menu_items', $branchId);
        $this->deleteOtherBranches('categories', $branchId);
        $this->deleteOtherBranches('storage_locations', $branchId);
        $this->deleteOtherBranches('stations', $branchId);

        if (Schema::hasTable('menu_promotions')) {
            DB::table('menu_promotions')
                ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', '!=', $branchId))
                ->delete();
            DB::table('menu_promotions')->where('branch_id', $branchId)->update([
                'usage_count' => 0,
                'created_by_user_id' => $keepUserId,
            ]);
        }
    }

    /** @param array<int,int> $ids */
    private function deleteNotIn(string $table, string $column, array $ids): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $query = DB::table($table);
        $ids === [] ? $query->delete() : $query->whereNotIn($column, $ids)->delete();
    }

    private function deleteOtherBranches(string $table, int $branchId): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', '!=', $branchId))
            ->delete();
    }

    private function deleteIfPresent(string $table): void
    {
        if (Schema::hasTable($table)) {
            DB::table($table)->delete();
        }
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
