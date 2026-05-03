<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categorise waste so the waste-analysis report can group by cause:
 *   expired | damaged | spilled | prep_loss | theft | other
 *
 * Optional, only populated when type='waste'. Other movement types ignore
 * this column. Lives here (rather than a join table) because it's pure
 * categorical metadata that never needs its own FK target.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->string('waste_reason', 32)->nullable()->after('reason');
            $table->index(['type', 'waste_reason'], 'movements_waste_reason_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex('movements_waste_reason_idx');
            $table->dropColumn('waste_reason');
        });
    }
};
