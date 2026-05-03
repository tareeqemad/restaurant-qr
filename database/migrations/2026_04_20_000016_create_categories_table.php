<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-branch menu categories. Slug is unique per-branch so two branches
 * can both have a "drinks" category without colliding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->text('description')->nullable();
            $table->text('description_en')->nullable();
            $table->string('image')->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('color', 7)->default('#b91c1c');
            $table->foreignId('default_station_id')->nullable()
                ->constrained('stations')->nullOnDelete();
            $table->integer('display_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'slug'], 'categories_branch_slug_uniq');
            $table->index('active');
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
