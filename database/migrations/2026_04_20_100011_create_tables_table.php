<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->string('number', 16)->unique()->comment('Display number on QR');
            $table->string('name')->nullable()->comment('e.g. بالقرب من النافذة');
            $table->string('qr_token', 64)->unique()->comment('Immutable per-table token in QR URL');
            $table->integer('capacity')->default(4);
            $table->string('zone', 64)->nullable()->comment('Indoor/Outdoor/VIP');
            $table->enum('status', ['available', 'occupied', 'reserved', 'out_of_service'])->default('available');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};
