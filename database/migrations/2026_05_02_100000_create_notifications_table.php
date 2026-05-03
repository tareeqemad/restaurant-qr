<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standard Laravel notifications table (extends the framework default with
 * a few app-specific columns we filter by — branch_id, type_key, severity).
 *
 * Notifiable polymorph (notifiable_type/_id) is the recipient — for us
 * always App\Models\User, but we keep the polymorph for flexibility (could
 * later notify a Customer or a Role group).
 *
 * `data` is a JSON blob that each Notification class fills via toArray():
 * label, body, action_url, icon, etc. We don't normalize those into
 * columns because they vary per notification type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Notification class FQN (e.g. App\Notifications\NewOrderNotification).
            // Kept as string for queryability and to mirror the Laravel default.
            $table->string('type');

            // Polymorphic recipient — User in practice. Indexed so unread-counter
            // queries (notifiable + read_at IS NULL) hit the index.
            $table->morphs('notifiable');

            // App-specific filters: by branch (so branch-scoped admins only
            // see their own branch) and by a stable type_key (so the inbox
            // can group/filter "new orders" without parsing the FQN).
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type_key', 64)->nullable()->index();
            $table->string('severity', 16)->nullable()->index(); // info|success|warning|danger

            // Payload (title, body, action_url, icon, related entity reference).
            $table->json('data');

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Hot path: "give me my unread, newest first"
            $table->index(['notifiable_type', 'notifiable_id', 'read_at', 'created_at'], 'notif_recipient_unread_idx');
            $table->index(['branch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
