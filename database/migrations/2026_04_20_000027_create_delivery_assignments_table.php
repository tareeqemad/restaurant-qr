<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('delivery_assignments')) {
            return;
        }

        Schema::create('delivery_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('driver_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('provider', 32)->default('internal');
            $table->enum('status', [
                'pending',
                'assigned',
                'picked_up',
                'en_route',
                'delivered',
                'failed',
                'cancelled',
            ])->default('pending');
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone', 32)->nullable();
            $table->text('address_snapshot')->nullable();
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->text('delivery_notes')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('en_route_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('order_id');
            $table->index(['branch_id', 'status']);
            $table->index(['driver_user_id', 'status']);
            $table->index(['status', 'assigned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_assignments');
    }
};
