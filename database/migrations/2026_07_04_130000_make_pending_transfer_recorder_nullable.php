<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A pending transfer can now be declared by the CUSTOMER themselves from the
 * QR bill screen — in which case there is no staff user who recorded it. Make
 * `recorded_by_user_id` nullable so a customer-initiated claim (recorded_by =
 * null) can be stored and still reach the cashier's verification queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_transfers', function (Blueprint $table) {
            $table->foreignId('recorded_by_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Leave it nullable — reverting could break customer-declared rows.
    }
};
