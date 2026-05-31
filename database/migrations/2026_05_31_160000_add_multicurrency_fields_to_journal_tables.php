<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('journal_entries', 'base_currency_code')) {
                $table->string('base_currency_code', 5)->nullable();
            }

            if (! Schema::hasColumn('journal_entries', 'currency_code')) {
                $table->string('currency_code', 5)->nullable();
            }

            if (! Schema::hasColumn('journal_entries', 'exchange_rate')) {
                $table->decimal('exchange_rate', 18, 8)->default(1);
            }
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('journal_lines', 'currency_code')) {
                $table->string('currency_code', 5)->nullable()->index();
            }

            if (! Schema::hasColumn('journal_lines', 'exchange_rate')) {
                $table->decimal('exchange_rate', 18, 8)->default(1);
            }

            if (! Schema::hasColumn('journal_lines', 'foreign_debit')) {
                $table->decimal('foreign_debit', 18, 4)->default(0);
            }

            if (! Schema::hasColumn('journal_lines', 'foreign_credit')) {
                $table->decimal('foreign_credit', 18, 4)->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            if (Schema::hasColumn('journal_lines', 'foreign_credit')) {
                $table->dropColumn('foreign_credit');
            }

            if (Schema::hasColumn('journal_lines', 'foreign_debit')) {
                $table->dropColumn('foreign_debit');
            }

            if (Schema::hasColumn('journal_lines', 'exchange_rate')) {
                $table->dropColumn('exchange_rate');
            }

            if (Schema::hasColumn('journal_lines', 'currency_code')) {
                $table->dropColumn('currency_code');
            }
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            foreach (['exchange_rate', 'currency_code', 'base_currency_code'] as $column) {
                if (Schema::hasColumn('journal_entries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
