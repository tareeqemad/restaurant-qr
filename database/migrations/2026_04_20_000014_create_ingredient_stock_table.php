<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-location stock cache. ingredients.current_stock is the cross-branch
 * total (denormalized for quick reorder reports); this table breaks it
 * down per storage_location so an admin can see "we have 5kg in main
 * kitchen but only 200g at the bar".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->foreignId('storage_location_id')->constrained('storage_locations');
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('reorder_threshold', 15, 4)->default(0)
                ->comment('Per-location threshold (overrides ingredient default if set)');
            $table->timestamps();

            $table->unique(['ingredient_id', 'storage_location_id']);
            $table->index(['storage_location_id', 'quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_stock');
    }
};
