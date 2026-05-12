<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promote `inventory_movements.waste_reason` (free string ↔ enum value)
 * to a proper FK on `lookups`. The string column stays in place for
 * backward compatibility — old reports query it directly. Both columns
 * are written together going forward; the FK is the source of truth.
 *
 * Steps:
 *   1. Seed the catalogue rows in `lookups` group=`waste_reasons`
 *      (idempotent — re-running is safe).
 *   2. Add `waste_reason_lookup_id` FK column.
 *   3. Backfill from the existing string `waste_reason` → matching lookup row.
 *
 * The legacy `waste_reason` string column is intentionally NOT dropped.
 * Legacy queries (waste-analysis report, activity log payloads) still use
 * it. Both columns are kept in sync by the controller.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── 1. Seed catalogue ─────────────────────────────────────
        $now = now();
        $catalog = [
            ['code' => 'expired',   'label' => 'انتهت الصلاحية',     'color' => '#d97706', 'icon' => 'bi-calendar-x-fill',     'order' => 1],
            ['code' => 'damaged',   'label' => 'تلف',                 'color' => '#b91c1c', 'icon' => 'bi-box2-heart',          'order' => 2],
            ['code' => 'spilled',   'label' => 'انسكاب / كسر',         'color' => '#0ea5e9', 'icon' => 'bi-droplet-half',        'order' => 3],
            ['code' => 'prep_loss', 'label' => 'فقد في التحضير',       'color' => '#475569', 'icon' => 'bi-scissors',            'order' => 4],
            ['code' => 'theft',     'label' => 'سرقة / فقد',           'color' => '#7f1d1d', 'icon' => 'bi-shield-exclamation',  'order' => 5],
            ['code' => 'other',     'label' => 'أخرى',                 'color' => '#64748b', 'icon' => 'bi-three-dots',          'order' => 9],
        ];
        foreach ($catalog as $row) {
            DB::table('lookups')->updateOrInsert(
                ['group' => 'waste_reasons', 'code' => $row['code'], 'branch_id' => null],
                [
                    'label'         => $row['label'],
                    'color'         => $row['color'],
                    'icon'          => $row['icon'],
                    'display_order' => $row['order'],
                    'is_active'     => true,
                    'is_system'     => true,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]
            );
        }

        // ─── 2. Add FK column ──────────────────────────────────────
        Schema::table('inventory_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_movements', 'waste_reason_lookup_id')) {
                $table->foreignId('waste_reason_lookup_id')
                    ->nullable()
                    ->after('waste_reason')
                    ->constrained('lookups')
                    ->nullOnDelete();
            }
        });

        // ─── 3. Backfill from the existing string column ───────────
        if (Schema::hasColumn('inventory_movements', 'waste_reason')) {
            $codeToId = DB::table('lookups')
                ->where('group', 'waste_reasons')
                ->whereNull('branch_id')
                ->pluck('id', 'code');

            foreach ($codeToId as $code => $id) {
                DB::table('inventory_movements')
                    ->where('waste_reason', $code)
                    ->whereNull('waste_reason_lookup_id')
                    ->update(['waste_reason_lookup_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_movements', 'waste_reason_lookup_id')) {
                $table->dropConstrainedForeignId('waste_reason_lookup_id');
            }
        });
    }
};
