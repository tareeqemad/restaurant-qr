<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the per-branch role override.
 *
 * Background:
 *   The original design had `branch_user.role_id` as an OPTIONAL FK to
 *   `roles` so a user could carry a different role per branch (e.g.,
 *   "manager" globally but "manager-limited" in branch X). After 6 months
 *   of production use, ZERO rows ever set this column to a non-null value
 *   — the team always relied on the global `users.role`.
 *
 *   The dual concept caused real user confusion ("ليش في الدور وفي الدور
 *   في النظام؟") and added validation/UI complexity. Removing it makes
 *   the model match how the team actually thinks: one role per user.
 *
 * Verified before drop: see checks above the controller change.
 *
 * NOT touched:
 *   - The `roles` table itself stays — it carries permissions per global
 *     role and is referenced by `User::permissions()` via name lookup.
 *   - `Branch::roles()` relation stays harmless (returns 0 rows now).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_user', function (Blueprint $table) {
            if (Schema::hasColumn('branch_user', 'role_id')) {
                $table->dropForeign(['role_id']);

                if (Schema::hasIndex('branch_user', 'branch_user_role_id_index')) {
                    $table->dropIndex('branch_user_role_id_index');
                }

                $table->dropColumn('role_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('branch_user', function (Blueprint $table) {
            if (! Schema::hasColumn('branch_user', 'role_id')) {
                $table->foreignId('role_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('roles')
                    ->nullOnDelete();
            }
        });
    }
};
