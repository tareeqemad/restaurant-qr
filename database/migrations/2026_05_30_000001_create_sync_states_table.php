<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-stream sync bookkeeping. One row per sync stream (e.g. "settings"),
 * holding the cursor we've consumed up to and the outcome of the last run so
 * the admin "sync status" view (and operators) can see health at a glance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_states', function (Blueprint $table) {
            $table->id();
            $table->string('stream')->unique();      // e.g. settings, menu_items, orders
            $table->string('direction', 8);          // down | up
            $table->string('cursor')->nullable();    // opaque resume token (e.g. last updated_at)
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_status', 16)->default('pending'); // ok | error | offline | pending
            $table->text('last_error')->nullable();
            $table->unsignedInteger('last_count')->default(0);     // rows moved in last run
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_states');
    }
};
