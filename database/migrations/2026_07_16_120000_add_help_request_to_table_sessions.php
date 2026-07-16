<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Call the waiter" — the diner needs something and nobody is looking.
 *
 * Deliberately the exact twin of the existing bill_requested_at /
 * bill_request_note pair on this table: same shape, same lifecycle, same
 * clock-since-asked semantics. The only reason the floor could already hear
 * "I want to pay" but not "I need you" was that nobody had built this half.
 *
 * Cleared by a waiter acknowledging it (they went), not by a timer — an
 * unanswered request must keep shouting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('table_sessions', function (Blueprint $table) {
            $table->timestamp('help_requested_at')->nullable()->after('bill_request_note');
            $table->string('help_request_note', 500)->nullable()->after('help_requested_at');
            $table->foreignId('help_ack_by_user_id')->nullable()->after('help_request_note')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('table_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('help_ack_by_user_id');
            $table->dropColumn(['help_requested_at', 'help_request_note']);
        });
    }
};
