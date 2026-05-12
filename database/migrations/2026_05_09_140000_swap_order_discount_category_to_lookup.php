<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promote `order_discounts.category` (free string) to a foreign key on
 * `lookups`. Reasoning:
 *   - Categories now live in /admin/lookups under group `discount_categories`,
 *     so admins can add/rename/recolour them without a redeploy.
 *   - The P&L breakdown joins on `lookups.label` and `lookups.color` for the
 *     per-category total + dot colour. With a string column we'd have to
 *     duplicate label/color on every discount row, drift over time, and
 *     break renames.
 *
 * Migration plan (data-preserving):
 *   1. Seed the catalogue rows in `lookups` (one per legacy category code).
 *   2. Add `category_lookup_id` FK column.
 *   3. Backfill from the old string `category` → matching lookup row.
 *   4. Drop the old `category` column.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Seed lookup catalogue (idempotent — re-running is safe).
        $now = now();
        $catalog = [
            ['code' => 'regular_customer', 'label' => 'عميل دائم / مكافأة', 'color' => '#0d9488', 'order' => 1],
            ['code' => 'complaint',        'label' => 'تعويض شكوى',         'color' => '#b91c1c', 'order' => 2],
            ['code' => 'manager_approval', 'label' => 'بموافقة المدير',     'color' => '#7c3aed', 'order' => 3],
            ['code' => 'promo',            'label' => 'حملة ترويجية',       'color' => '#b97818', 'order' => 4],
            ['code' => 'staff_meal',       'label' => 'وجبة موظف',          'color' => '#0ea5e9', 'order' => 5],
            ['code' => 'other',            'label' => 'أخرى',               'color' => '#94a3b8', 'order' => 99],
        ];
        foreach ($catalog as $row) {
            DB::table('lookups')->updateOrInsert(
                ['group' => 'discount_categories', 'code' => $row['code'], 'branch_id' => null],
                [
                    'label'         => $row['label'],
                    'color'         => $row['color'],
                    'display_order' => $row['order'],
                    'is_active'     => true,
                    'is_system'     => true,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]
            );
        }

        // 2. Add the FK column.
        Schema::table('order_discounts', function (Blueprint $table) {
            if (! Schema::hasColumn('order_discounts', 'category_lookup_id')) {
                $table->foreignId('category_lookup_id')
                    ->nullable()
                    ->after('amount')
                    ->constrained('lookups')
                    ->nullOnDelete();
            }
        });

        // 3. Backfill from the old string column when present.
        if (Schema::hasColumn('order_discounts', 'category')) {
            $codeToId = DB::table('lookups')
                ->where('group', 'discount_categories')
                ->whereNull('branch_id')
                ->pluck('id', 'code');

            foreach ($codeToId as $code => $id) {
                DB::table('order_discounts')
                    ->where('category', $code)
                    ->whereNull('category_lookup_id')
                    ->update(['category_lookup_id' => $id]);
            }

            // 4. Drop the legacy column once data is moved.
            Schema::table('order_discounts', function (Blueprint $table) {
                $table->dropColumn('category');
            });
        }
    }

    public function down(): void
    {
        Schema::table('order_discounts', function (Blueprint $table) {
            if (! Schema::hasColumn('order_discounts', 'category')) {
                $table->string('category', 32)->nullable()->after('amount');
            }
        });

        // Best-effort restore: write the lookup `code` back into the string col.
        $idToCode = DB::table('lookups')
            ->where('group', 'discount_categories')
            ->whereNull('branch_id')
            ->pluck('code', 'id');
        foreach ($idToCode as $id => $code) {
            DB::table('order_discounts')
                ->where('category_lookup_id', $id)
                ->update(['category' => $code]);
        }

        Schema::table('order_discounts', function (Blueprint $table) {
            if (Schema::hasColumn('order_discounts', 'category_lookup_id')) {
                $table->dropConstrainedForeignId('category_lookup_id');
            }
        });
    }
};
