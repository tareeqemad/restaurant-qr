<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique()->comment('kitchen|bar|dessert|coffee|grill|cold');
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('color', 7)->default('#b91c1c');
            $table->string('icon', 64)->nullable();
            $table->integer('display_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

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
