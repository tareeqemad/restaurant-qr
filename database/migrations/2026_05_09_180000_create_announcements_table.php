<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing announcements / promo broadcasts for portal customers.
 *
 * One row = one campaign. When the admin clicks "نشر" (publish), the
 * AnnouncementService walks the audience filter, fans the announcement
 * out as a row in `notifications` for each matching customer, and flips
 * the row's status to `published`.
 *
 * Audience filtering is JSON to keep the schema flexible — initial
 * implementation supports:
 *   audience_type=all                 → every customer with marketing opt-in
 *   audience_type=tier                → audience_filter={"min_tier":"silver"}
 *   audience_type=inactive_days       → audience_filter={"days":30}
 *   audience_type=specific            → audience_filter={"customer_ids":[1,2]}
 *
 * Branch scope: nullable. NULL = global (all branches), set = restrict to
 * customers whose primary branch matches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('image_path')->nullable();
            $table->string('icon', 64)->nullable();        // bi-stars, bi-percent, etc.
            $table->string('color', 16)->nullable();       // hex; defaults to gold in UI
            $table->string('cta_text', 80)->nullable();    // "اطلب الآن", "احجز طاولة", …
            $table->string('cta_url')->nullable();
            $table->foreignId('discount_id')->nullable()
                ->constrained('discounts')->nullOnDelete();

            $table->string('audience_type', 32)->default('all'); // all|tier|inactive_days|specific
            $table->json('audience_filter')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->string('status', 16)->default('draft'); // draft|scheduled|published|expired|archived
            $table->integer('recipients_count')->default(0); // filled at publish time
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'starts_at', 'ends_at'], 'ann_active_idx');
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
