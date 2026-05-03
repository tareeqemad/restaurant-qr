<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Measurement units (g/kg/ml/l/pcs/…). Each unit has a base and a
 * conversion factor; recipes + inventory movements normalize to base
 * for stock math.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique()->comment('kg, g, l, ml, pcs, tbsp, tsp, cup');
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->enum('unit_type', ['weight', 'volume', 'count', 'length'])->default('weight');
            $table->decimal('factor_to_base', 20, 8)->default(1.00000000)
                ->comment('Conversion to base unit (e.g. g=1, kg=1000)');
            $table->boolean('is_base')->default(false);
            $table->timestamps();

            $table->index('unit_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
