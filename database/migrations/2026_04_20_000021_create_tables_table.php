<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical dining tables. `number` is unique per-branch (each branch has
 * its own numbering); `qr_token` is GLOBALLY unique (it's the URL the
 * QR code resolves to, must never collide).
 *
 * `zone_lookup_id` points at a per-branch `lookups` row in the `zones`
 * group — admin-managed indoor/outdoor/balcony/etc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('number', 16)->comment('Display number on QR');
            $table->string('name')->nullable()->comment('e.g. بالقرب من النافذة');
            $table->string('qr_token', 64)->unique()->comment('Immutable per-table token in QR URL');
            $table->integer('capacity')->default(4);
            $table->foreignId('zone_lookup_id')->nullable()
                ->constrained('lookups')->nullOnDelete();
            $table->enum('status', ['available', 'occupied', 'reserved', 'out_of_service'])
                ->default('available');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'number'], 'tables_branch_number_uniq');
            $table->index('status');
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};
