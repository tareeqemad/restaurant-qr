<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic group-based "soft enum" table. Hybrid scoping:
 *
 *   - `branch_id IS NULL` → global (e.g. `expense_categories` shared
 *     across the whole company).
 *   - `branch_id IS NOT NULL` → per-branch (e.g. `zones` — each branch
 *     has its own indoor/outdoor/balcony list).
 *
 * Operational tables FK by `id`, never by `code`, so admin renames are
 * safe. `is_system` rows are seeded by code and the UI blocks hard-delete
 * (would break a backfilled FK).
 *
 * Unique key is `(group, branch_id, code)` — MySQL's NULL-distinct rule
 * means two rows with `(expense_categories, NULL, 'food')` are technically
 * possible at the DB layer; the seeders use updateOrCreate to keep
 * uniqueness honest at write time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lookups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()
                ->constrained('branches')->cascadeOnDelete();
            $table->string('group', 60)->comment('e.g. expense_categories');
            $table->string('code', 60)->nullable()
                ->comment('stable text key for system rows; informational for user rows');
            $table->string('label', 120);
            $table->string('color', 30)->nullable()->comment('hex or bootstrap color name');
            $table->string('icon', 60)->nullable()->comment('Bootstrap Icons class, e.g. bi-lightning');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false)
                ->comment('seeded by code; cannot be hard-deleted');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['group', 'branch_id', 'code'], 'lookups_group_branch_code_uniq');
            $table->index(['group', 'is_active', 'display_order'], 'lookups_group_active_order_idx');
            $table->index(['group', 'branch_id', 'is_active', 'display_order'], 'lookups_group_branch_active_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lookups');
    }
};
