<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modifier groups + modifiers + their pivots:
 *   - modifier_groups          : per-branch, slug unique per-branch
 *   - modifiers                : children of a group (price deltas)
 *   - menu_item_modifier_group : pivot — which item exposes which group
 *   - modifier_recipe_items    : ingredient cost added when modifier picked
 *
 * The pivot to menu_items lives here (not in the menu_items file) because
 * Laravel processes migrations in filename order and we need menu_items
 * to exist first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->integer('min_select')->default(0);
            $table->integer('max_select')->default(1);
            $table->boolean('required')->default(false);
            $table->integer('display_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'slug'], 'modifier_groups_branch_slug_uniq');
            $table->index('branch_id');
        });

        Schema::create('modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->decimal('price_delta', 10, 2)->default(0)->comment('Can be negative (discount)');
            $table->boolean('active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->index(['modifier_group_id', 'active']);
        });

        Schema::create('menu_item_modifier_group', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->integer('display_order')->default(0);
            $table->unique(['menu_item_id', 'modifier_group_id'], 'mig_unique');
        });

        Schema::create('modifier_recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modifier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained();
            $table->decimal('quantity', 15, 4);
            $table->foreignId('unit_id')->constrained('units');
            $table->timestamps();

            $table->unique(['modifier_id', 'ingredient_id'], 'mri_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_recipe_items');
        Schema::dropIfExists('menu_item_modifier_group');
        Schema::dropIfExists('modifiers');
        Schema::dropIfExists('modifier_groups');
    }
};
