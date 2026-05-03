<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per QR-scan dining session at a table. Token in cookie keeps
 * the customer pinned to their session across page loads.
 *
 * `customer_id` is set when:
 *   - QR scanned while diner was logged into the portal, OR
 *   - cashier linked an account mid-meal via the customer-link panel.
 * Stays NULL for anonymous walk-ins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('table_id')->constrained();
            $table->foreignId('customer_id')->nullable()
                ->constrained('customers')->nullOnDelete();
            $table->string('token', 64)->unique()
                ->comment('Cookie-held session identifier for customer');
            $table->integer('cover_count')->default(1);
            $table->enum('status', ['active', 'closed', 'abandoned'])->default('active');
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->foreignId('opened_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_waiter_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->useCurrent()->useCurrentOnUpdate();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('bill_requested_at')->nullable();
            $table->text('bill_request_note')->nullable();
            $table->timestamps();

            $table->index(['table_id', 'status']);
            $table->index('status');
            $table->index('branch_id');
            $table->index('customer_id');
            $table->index('bill_requested_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_sessions');
    }
};
