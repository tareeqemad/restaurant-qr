<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounts')) {
            DB::table('accounts')->updateOrInsert(
                ['code' => '3020'],
                [
                    'name' => 'أرباح محتجزة',
                    'type' => 'equity',
                    'normal_balance' => 'credit',
                    'description' => 'صافي أرباح أو خسائر الفترات المقفلة بعد ترحيل حسابات الإيراد والمصاريف.',
                    'is_system' => true,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        if (Schema::hasTable('accounting_periods') && ! Schema::hasColumn('accounting_periods', 'closing_journal_entry_id')) {
            Schema::table('accounting_periods', function (Blueprint $table) {
                $table->foreignId('closing_journal_entry_id')
                    ->nullable()
                    ->after('closed_by')
                    ->constrained('journal_entries')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('accounting_periods') && Schema::hasColumn('accounting_periods', 'closing_journal_entry_id')) {
            Schema::table('accounting_periods', function (Blueprint $table) {
                $table->dropConstrainedForeignId('closing_journal_entry_id');
            });
        }

        if (Schema::hasTable('accounts')) {
            DB::table('accounts')
                ->where('code', '3020')
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('journal_lines')
                        ->join('accounts as closing_account', 'closing_account.id', '=', 'journal_lines.account_id')
                        ->where('closing_account.code', '3020');
                })
                ->delete();
        }
    }
};
