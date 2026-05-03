<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer reviews — one per completed reservation (uniqueness enforced
 * by `reservations_reservation_unique`). Branch staff can hide rather
 * than delete; the model exposes `isHidden()` / `isPublished()` helpers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating')->comment('1..5');
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->enum('status', ['published', 'hidden'])->default('published');
            $table->foreignId('hidden_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('hidden_at')->nullable();
            $table->string('hidden_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('reservation_id', 'reviews_reservation_unique');
            $table->index(['branch_id', 'status', 'rating'], 'reviews_branch_status_rating_idx');
            $table->index(['customer_id', 'created_at'], 'reviews_customer_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
