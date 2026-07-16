<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who covers which section, today.
 *
 * Until now "my tables" was an accident: TableSession.assigned_waiter_id is
 * only stamped when a waiter happens to APPROVE an order first (OrderService),
 * so ownership went to whoever tapped fastest. There was no way to say "tonight
 * Ahmad has the terrace" — which is how every real floor actually runs, and the
 * foundation the section-first board needs.
 *
 * Shape notes:
 *  - Many-to-many: a big section can carry two waiters, and a waiter can cover
 *    two sections. Hence a row per (section, waiter) rather than a column.
 *  - `service_date` is deliberate. A dateless "current assignment" would leave
 *    yesterday's waiter owning the terrace this morning until someone remembered
 *    to clear it; keying on the date lets stale assignments expire themselves.
 *  - branch_id is required even though zones (lookups group='zones') are GLOBAL:
 *    the dictionary of section names is shared, but "the terrace at Khan Younis"
 *    is a different floor from "the terrace at Gaza".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('section_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_lookup_id')->constrained('lookups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('service_date');

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['branch_id', 'zone_lookup_id', 'user_id', 'service_date'],
                'section_assignments_unique'
            );
            // The board's hot read: "my sections in this branch today".
            $table->index(['branch_id', 'service_date'], 'section_assignments_branch_date_idx');
            $table->index(['user_id', 'service_date'], 'section_assignments_user_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_assignments');
    }
};
