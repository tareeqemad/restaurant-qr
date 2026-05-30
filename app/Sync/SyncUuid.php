<?php

namespace App\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SyncUuid
{
    /** @var array<string, bool> */
    private static array $hasUuidColumn = [];

    public static function assignIfMissing(Model $model): void
    {
        $table = $model->getTable();

        if (! self::hasUuidColumn($table) || ! empty($model->{SyncRegistry::UUID_COLUMN})) {
            return;
        }

        $model->{SyncRegistry::UUID_COLUMN} = self::new();
    }

    public static function new(): string
    {
        return (string) Str::ulid();
    }

    public static function hasUuidColumn(string $table): bool
    {
        return self::$hasUuidColumn[$table] ??= Schema::hasTable($table)
            && Schema::hasColumn($table, SyncRegistry::UUID_COLUMN);
    }

    public static function backfill(string $table, int $chunkSize = 500): int
    {
        if (! self::hasUuidColumn($table)) {
            return 0;
        }

        $count = 0;

        do {
            $ids = DB::table($table)
                ->whereNull(SyncRegistry::UUID_COLUMN)
                ->limit($chunkSize)
                ->pluck('id');

            foreach ($ids as $id) {
                DB::table($table)->where('id', $id)->update([
                    SyncRegistry::UUID_COLUMN => self::new(),
                ]);
                $count++;
            }
        } while ($ids->isNotEmpty());

        return $count;
    }
}
