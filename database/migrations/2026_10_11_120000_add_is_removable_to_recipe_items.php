<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_items', function (Blueprint $table) {
            // Existing menus retain their current customization behavior.
            $table->boolean('is_removable')->default(true)->after('is_optional');
        });
    }

    public function down(): void
    {
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->dropColumn('is_removable');
        });
    }
};
