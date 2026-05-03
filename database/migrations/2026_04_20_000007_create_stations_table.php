<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stations (kitchen, bar, grill, …) — per-branch. Each branch defines its
 * own physical sections; the same `code` ("kitchen") can exist in every
 * branch, distinguished by `(branch_id, code)`.
 *
 * Also wires the FK from users.station_id → stations.id (the column was
 * created upfront in the users migration without a FK because the stations
 * table didn't exist yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('code', 32)->comment('kitchen|bar|dessert|coffee|grill|cold');
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('color', 7)->default('#b91c1c');
            $table->string('icon', 64)->nullable();
            $table->integer('display_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'code'], 'stations_branch_code_uniq');
            $table->index('branch_id');
        });

        // Late-bind the FK that the users migration deferred.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('station_id')->references('id')->on('stations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['station_id']);
        });
        Schema::dropIfExists('stations');
    }
};
