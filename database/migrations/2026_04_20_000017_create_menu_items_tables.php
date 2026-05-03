<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu items + their pivots:
 *   - menu_items         : per-branch dishes; slug + sku unique per-branch
 *   - menu_item_allergens: pivot to global allergens
 *   - recipe_items       : ingredient breakdown for cost + stock deduction
 *
 * `menu_item_modifier_group` lives in the next migration (modifiers file)
 * because it needs both menu_items + modifier_groups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->nullable()
                ->constrained('stations')->nullOnDelete();
            $table->string('sku', 64)->nullable();
            $table->string('slug');
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->text('description')->nullable();
            $table->text('description_en')->nullable();
            $table->decimal('price', 10, 2);
            $table->decimal('cost', 10, 2)->default(0)
                ->comment('Computed from recipe; used for profit reports');
            $table->string('image')->nullable();
            $table->integer('prep_time_minutes')->default(10);
            $table->integer('calories')->nullable();
            $table->boolean('is_available')->default(true)->comment('false = 86 out-of-stock');
            $table->boolean('is_featured')->default(false);
            $table->string('unavailable_reason')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'slug'], 'menu_items_branch_slug_uniq');
            $table->unique(['branch_id', 'sku'],  'menu_items_branch_sku_uniq');
            $table->index(['category_id', 'is_available']);
            $table->index('branch_id');
        });

        Schema::create('menu_item_allergens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('allergen_id')->constrained()->cascadeOnDelete();
            $table->unique(['menu_item_id', 'allergen_id']);
        });

        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained();
            $table->decimal('quantity', 15, 4);
            $table->foreignId('unit_id')->constrained('units');
            $table->boolean('is_optional')->default(false);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['menu_item_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_items');
        Schema::dropIfExists('menu_item_allergens');
        Schema::dropIfExists('menu_items');
    }
};
