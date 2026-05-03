<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-branch storage locations (main_kitchen, walk_in_freezer, bar_back, …).
 * `code` is unique GLOBALLY (legacy from before per-branch was introduced);
 * names are descriptive. Stock + batches FK here for FIFO + per-location
 * thresholds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('code', 40)->unique()->comment('e.g. main_kitchen, bar, freezer');
            $table->string('name');
            $table->string('icon', 40)->nullable()->comment('Bootstrap-icons class');
            $table->string('color', 20)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false)->comment('Pre-selected in forms');
            $table->boolean('active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_locations');
    }
};
