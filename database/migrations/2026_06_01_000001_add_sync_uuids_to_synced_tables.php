<?php

use App\Sync\SyncRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (SyncRegistry::tableNames() as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, SyncRegistry::UUID_COLUMN)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string(SyncRegistry::UUID_COLUMN, 26)->nullable()->after('id');
            });
        }

        foreach (SyncRegistry::tableNames() as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, SyncRegistry::UUID_COLUMN) || ! Schema::hasColumn($table, 'id')) {
                continue;
            }

            do {
                $ids = DB::table($table)
                    ->whereNull(SyncRegistry::UUID_COLUMN)
                    ->orderBy('id')
                    ->limit(500)
                    ->pluck('id');

                foreach ($ids as $id) {
                    DB::table($table)->where('id', $id)->update([
                        SyncRegistry::UUID_COLUMN => (string) Str::ulid(),
                    ]);
                }
            } while ($ids->isNotEmpty());
        }

        foreach (SyncRegistry::tableNames() as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, SyncRegistry::UUID_COLUMN)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->unique(SyncRegistry::UUID_COLUMN, $this->indexName($table));
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(SyncRegistry::tableNames()) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, SyncRegistry::UUID_COLUMN)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                try {
                    $blueprint->dropUnique($this->indexName($table));
                } catch (Throwable) {
                    //
                }
                $blueprint->dropColumn(SyncRegistry::UUID_COLUMN);
            });
        }
    }

    private function indexName(string $table): string
    {
        return $table.'_sync_uuid_unique';
    }
};
