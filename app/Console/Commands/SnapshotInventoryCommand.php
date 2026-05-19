<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\InventorySnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes a close-of-day snapshot for every (ingredient × branch) pair.
 *
 * Schedule (in routes/console.php):
 *     Schedule::command('app:snapshot-inventory')->dailyAt('23:59');
 *
 * Manual run:
 *     php artisan app:snapshot-inventory                # snapshot for today
 *     php artisan app:snapshot-inventory --date=YYYY-MM-DD   # backfill
 *
 * Idempotent: re-running for the same date overwrites that day's row
 * instead of duplicating, so re-running after a fix is safe.
 */
class SnapshotInventoryCommand extends Command
{
    protected $signature = 'app:snapshot-inventory
                            {--date= : Capture date (YYYY-MM-DD). Defaults to today.}';

    protected $description = 'Capture today\'s per-branch per-ingredient stock + value to inventory_snapshots.';

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->toDateString()
            : now()->toDateString();

        $branches = Branch::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($branches->isEmpty()) {
            $this->warn('No active branches — nothing to snapshot.');
            return self::SUCCESS;
        }

        $ingredients = Ingredient::query()
            ->where('track_stock', true)
            ->where('active', true)
            ->get();

        if ($ingredients->isEmpty()) {
            $this->warn('No tracked ingredients — nothing to snapshot.');
            return self::SUCCESS;
        }

        $this->info("Snapshotting {$ingredients->count()} ingredients across {$branches->count()} branches for {$date}…");

        $written = 0;

        // No outer transaction — updateOrCreate is atomic per row, and
        // wrapping all branches+ingredients in a single transaction makes
        // SQLite (the test driver) skip-visible the rows from a prior
        // invocation, which breaks the idempotency contract on re-runs.
        // A partial failure of one row also leaves all the prior rows
        // committed, which is the more useful behavior for a nightly cron.
        foreach ($branches as $branch) {
            foreach ($ingredients as $ingredient) {
                $qty = (float) IngredientStock::query()
                    ->join('storage_locations', 'ingredient_stock.storage_location_id', '=', 'storage_locations.id')
                    ->where('ingredient_stock.ingredient_id', $ingredient->id)
                    ->where('storage_locations.branch_id', $branch->id)
                    ->sum('ingredient_stock.quantity');

                $cost = $ingredient->costAtBranch($branch->id);

                // Find existing snapshot explicitly with whereDate to
                // avoid driver-specific date-vs-datetime equality issues
                // (SQLite stores 'date' columns as text and Eloquent's
                // updateOrCreate matcher uses string equality, which can
                // miss the previous run on re-execution and trip the
                // unique constraint).
                $existing = InventorySnapshot::where('ingredient_id', $ingredient->id)
                    ->where('branch_id', $branch->id)
                    ->whereDate('taken_on', $date)
                    ->first();

                $attrs = [
                    'quantity_in_base' => round($qty, 4),
                    'cost_value'       => round($qty * $cost, 2),
                ];

                if ($existing) {
                    $existing->update($attrs);
                } else {
                    InventorySnapshot::create(array_merge($attrs, [
                        'ingredient_id' => $ingredient->id,
                        'branch_id'     => $branch->id,
                        'taken_on'      => $date,
                    ]));
                }
                $written++;
            }
        }

        $this->info("  ✓ Wrote/updated {$written} snapshot rows.");
        return self::SUCCESS;
    }
}
