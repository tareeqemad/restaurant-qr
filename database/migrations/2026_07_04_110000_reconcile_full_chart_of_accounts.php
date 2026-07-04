<?php

use Database\Seeders\AccountingSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Self-healing reconcile of the chart of accounts.
 *
 * Some databases ended up missing the feature accounts that later
 * migrations were supposed to add (staff-meal 1110/2110/4030/5050/5060,
 * fixed-asset 1500/1590/4230/5500/5530, FX 4220/5520) — e.g. a DB that was
 * re-seeded from AccountingSeeder's old (base-only) list after those
 * migrations had already been marked "Ran", so they never re-executed.
 *
 * When those accounts are missing, StaffMealService / FixedAssetController
 * post to hard-coded codes that don't resolve — StaffMealService swallows
 * the error in a try/catch, so meals silently never hit the ledger.
 *
 * AccountingSeeder is now the single, COMPLETE source of truth for the
 * system chart (43 accounts, with the disabled set defaulting to inactive
 * on first insert only). Running it here guarantees every deploy converges
 * to the full correct chart. It is idempotent: existing accounts keep their
 * current is_active state, only genuinely-missing accounts are created.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new AccountingSeeder())->run();
    }

    public function down(): void
    {
        // No-op: we never delete accounts (journal lines reference them, and
        // dropping a system account would corrupt historical postings).
    }
};
