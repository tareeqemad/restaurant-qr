<?php

namespace Database\Seeders;

use App\Models\Lookup;
use Illuminate\Database\Seeder;

/**
 * Default waste reasons — referenced by
 * `inventory_movements.waste_reason_lookup_id`.
 *
 * Codes mirror the legacy `App\Enums\WasteReason` so both the FK
 * (waste_reason_lookup_id) and the legacy string column (waste_reason)
 * can coexist during the rollout. Reports already using the string
 * column keep working until they're migrated to use the FK.
 *
 * Global (no branch_id). is_system=true protects them from accidental
 * hard-delete in the lookups admin — the admin can still deactivate
 * or rename, plus add new branch-specific reasons.
 */
class WasteReasonSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'expired',   'label' => 'انتهت الصلاحية',     'color' => '#d97706', 'icon' => 'bi-calendar-x-fill',     'order' => 1],
            ['code' => 'damaged',   'label' => 'تلف',                 'color' => '#b91c1c', 'icon' => 'bi-box2-heart',          'order' => 2],
            ['code' => 'spilled',   'label' => 'انسكاب / كسر',         'color' => '#0ea5e9', 'icon' => 'bi-droplet-half',        'order' => 3],
            ['code' => 'prep_loss', 'label' => 'فقد في التحضير',       'color' => '#475569', 'icon' => 'bi-scissors',            'order' => 4],
            ['code' => 'theft',     'label' => 'سرقة / فقد',           'color' => '#7f1d1d', 'icon' => 'bi-shield-exclamation',  'order' => 5],
            ['code' => 'other',     'label' => 'أخرى',                 'color' => '#64748b', 'icon' => 'bi-three-dots',          'order' => 9],
        ];

        foreach ($rows as $r) {
            Lookup::updateOrCreate(
                ['group' => 'waste_reasons', 'branch_id' => null, 'code' => $r['code']],
                [
                    'label'         => $r['label'],
                    'color'         => $r['color'],
                    'icon'          => $r['icon'],
                    'display_order' => $r['order'],
                    'is_active'     => true,
                    'is_system'     => true,
                ]
            );
        }
    }
}
