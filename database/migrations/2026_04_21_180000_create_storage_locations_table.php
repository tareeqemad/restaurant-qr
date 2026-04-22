<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Storage locations (multi-warehouse support).
 *
 * Design:
 *  - storage_locations: master list (main_kitchen, bar, freezer, dry_storage)
 *  - ingredient_stock: per-location qty + per-location cost_per_unit
 *  - inventory_movements.location_id: which location did this movement affect
 *  - ingredients.current_stock: kept in sync as SUM(ingredient_stock.qty)
 *                               for backward compatibility with existing reports
 *
 * The system seeds a single "default" location so existing stock stays
 * addressable without migrating every row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_locations', function (Blueprint $table) {
            $table->id();
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
        });

        Schema::create('ingredient_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('storage_location_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('reorder_threshold', 15, 4)->default(0)->comment('Per-location threshold (overrides ingredient default if set)');
            $table->timestamps();

            $table->unique(['ingredient_id', 'storage_location_id']);
            $table->index(['storage_location_id', 'quantity']);
        });

        // Add location to movements — nullable because legacy movements aren't located.
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('storage_location_id')->nullable()->after('batch_id')
                  ->constrained()->nullOnDelete();
        });

        // Seed a default "main_kitchen" location and copy existing stock into it
        // so existing data continues to work.
        DB::table('storage_locations')->insert([
            [
                'code' => 'main_kitchen',
                'name' => 'المطبخ الرئيسي',
                'icon' => 'bi-fire',
                'color' => '#1f4733',
                'is_default' => true,
                'active' => true,
                'display_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'bar',
                'name' => 'البار',
                'icon' => 'bi-cup-straw',
                'color' => '#b8872a',
                'is_default' => false,
                'active' => true,
                'display_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'freezer',
                'name' => 'الثلاجة',
                'icon' => 'bi-snow',
                'color' => '#5ac8fa',
                'is_default' => false,
                'active' => true,
                'display_order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $defaultId = DB::table('storage_locations')->where('code', 'main_kitchen')->value('id');
        $ingredients = DB::table('ingredients')->select('id', 'current_stock', 'reorder_threshold')->get();
        foreach ($ingredients as $ing) {
            DB::table('ingredient_stock')->insert([
                'ingredient_id'       => $ing->id,
                'storage_location_id' => $defaultId,
                'quantity'            => $ing->current_stock,
                'reorder_threshold'   => $ing->reorder_threshold,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['storage_location_id']);
            $table->dropColumn('storage_location_id');
        });
        Schema::dropIfExists('ingredient_stock');
        Schema::dropIfExists('storage_locations');
    }
};
