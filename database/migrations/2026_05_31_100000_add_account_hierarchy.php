<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give accountants the flexibility to organise the chart as a tree:
 *
 *   - `parent_account_id` (self-referential, nullable) lets them add
 *     a sub-account under any existing one. E.g., they can create
 *     "5101 — مصاريف صيانة" under "5100 — مصاريف تشغيلية" so the
 *     trial balance can roll up by parent.
 *
 *   - `display_order` (int default 0) controls sibling order on the
 *     accountant-facing chart view — without it, accounts within a
 *     type all sort by code, which is rigid.
 *
 *   - FK is `nullOnDelete`: deleting a parent doesn't cascade to
 *     children. The children become roots. This is the safest default
 *     given that operational AccountingService uses string codes and
 *     doesn't care about hierarchy.
 *
 * Existing seeded accounts stay as roots (`parent_account_id = null`).
 * Users add sub-accounts via the new UI.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts', 'parent_account_id')) {
                $table->foreignId('parent_account_id')->nullable()->after('type')
                      ->constrained('accounts')->nullOnDelete()
                      ->comment('Optional parent for sub-account organisation. Null = root account.');
            }
            if (! Schema::hasColumn('accounts', 'display_order')) {
                $table->unsignedSmallInteger('display_order')->default(0)->after('is_active')
                      ->comment('Sibling order on the chart view. Lower shows first.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            if (Schema::hasColumn('accounts', 'parent_account_id')) {
                $table->dropConstrainedForeignId('parent_account_id');
            }
            if (Schema::hasColumn('accounts', 'display_order')) {
                $table->dropColumn('display_order');
            }
        });
    }
};
