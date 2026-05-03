<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredient_batches', function (Blueprint $table) {
            $table->foreignId('storage_location_id')
                ->nullable()
                ->after('ingredient_id')
                ->constrained('storage_locations')
                ->nullOnDelete();

            $table->index('storage_location_id');
            $table->index(['ingredient_id', 'storage_location_id', 'expiry_date'], 'ingredient_batches_fifo_location_idx');
        });

        $defaultLocations = DB::table('storage_locations')
            ->select('id', 'branch_id', 'is_default')
            ->orderByDesc('is_default')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->groupBy('branch_id')
            ->map(fn ($locations) => $locations->first()->id);

        DB::table('ingredient_batches')
            ->select('id', 'branch_id')
            ->orderBy('id')
            ->lazy()
            ->each(function ($batch) use ($defaultLocations) {
                $movementLocation = DB::table('inventory_movements')
                    ->where('batch_id', $batch->id)
                    ->whereNotNull('storage_location_id')
                    ->orderBy('id')
                    ->value('storage_location_id');

                $locationId = $movementLocation ?: ($defaultLocations[$batch->branch_id] ?? null);
                if ($locationId) {
                    DB::table('ingredient_batches')
                        ->where('id', $batch->id)
                        ->update(['storage_location_id' => $locationId]);
                }
            });

        $defaultLocationId = DB::table('storage_locations')
            ->where('active', true)
            ->orderByDesc('is_default')
            ->orderBy('display_order')
            ->orderBy('id')
            ->value('id');

        if ($defaultLocationId) {
            DB::table('ingredients')
                ->select('id', 'current_stock', 'reorder_threshold')
                ->where('current_stock', '>', 0)
                ->orderBy('id')
                ->lazy()
                ->each(function ($ingredient) use ($defaultLocationId) {
                    $hasLocationStock = DB::table('ingredient_stock')
                        ->where('ingredient_id', $ingredient->id)
                        ->exists();

                    if (! $hasLocationStock) {
                        DB::table('ingredient_stock')->insert([
                            'ingredient_id' => $ingredient->id,
                            'storage_location_id' => $defaultLocationId,
                            'quantity' => $ingredient->current_stock,
                            'reorder_threshold' => $ingredient->reorder_threshold,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('ingredient_batches', function (Blueprint $table) {
            $table->dropForeign(['storage_location_id']);
            $table->dropIndex('ingredient_batches_fifo_location_idx');
            $table->dropIndex(['storage_location_id']);
            $table->dropColumn('storage_location_id');
        });
    }
};
