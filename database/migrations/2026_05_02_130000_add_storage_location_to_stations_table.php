<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->foreignId('storage_location_id')
                ->nullable()
                ->after('icon')
                ->constrained('storage_locations')
                ->nullOnDelete();

            $table->index('storage_location_id');
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropForeign(['storage_location_id']);
            $table->dropIndex(['storage_location_id']);
            $table->dropColumn('storage_location_id');
        });
    }
};
