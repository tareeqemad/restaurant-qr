<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Lookup;
use App\Models\Table;
use Illuminate\Database\Seeder;

/**
 * Seeds the Khan Yunis main branch floor: 40 tables, 15 indoor + 25 outdoor.
 *
 * The size is deliberate — a real floor is where the tables board has to prove
 * itself. A 12-table demo hides everything that matters about triage: the feed
 * stays short by accident rather than by design, and the section tabs look like
 * decoration instead of the thing that keeps a waiter from drowning.
 *
 * Numbering is the section layout: 1-15 indoor, 16-40 outdoor. Idempotent —
 * keyed on (branch, number), so re-running reshapes the floor without
 * duplicating it.
 *
 * The indoor/outdoor zones are GLOBAL (shared across branches) — their
 * scope comes from the system row in `lookup_groups`.
 */
class TableSeeder extends Seeder
{
    /** 15 + 25 = a 40-table floor. */
    private const INDOOR = 15;

    private const OUTDOOR = 25;

    public function run(): void
    {
        $branch = Branch::where('code', 'main-khan-yunis')->firstOrFail();

        // Zone lookups are shared across branches.
        $indoor = Lookup::updateOrCreate(
            ['group' => 'zones', 'branch_id' => null, 'code' => 'indoor'],
            ['label' => 'داخلي', 'color' => '#1f4733', 'display_order' => 1, 'is_active' => true, 'is_system' => true]
        );

        $outdoor = Lookup::updateOrCreate(
            ['group' => 'zones', 'branch_id' => null, 'code' => 'outdoor'],
            ['label' => 'خارجي', 'color' => '#b8872a', 'display_order' => 2, 'is_active' => true, 'is_system' => true]
        );

        $total = self::INDOOR + self::OUTDOOR;

        for ($i = 1; $i <= $total; $i++) {
            $isIndoor = $i <= self::INDOOR;

            $table = Table::firstOrNew([
                'branch_id' => $branch->id,
                'number' => (string) $i,
            ]);

            $table->fill([
                'name' => 'طاولة '.$i,
                // A believable mix of two-tops, four-tops and the odd big round.
                'capacity' => match ($i % 5) {
                    0 => 6,
                    1, 2 => 2,
                    default => 4,
                },
                'zone_lookup_id' => $isIndoor ? $indoor->id : $outdoor->id,
                'active' => true,
            ]);

            // Status is set on CREATE only. Re-running the seeder on a working
            // floor must never yank a table with a live party back to
            // "available" — the seeder describes the layout, not who's sitting.
            if (! $table->exists) {
                $table->status = 'available';
            }

            $table->save();
        }
    }
}
