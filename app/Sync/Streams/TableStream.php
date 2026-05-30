<?php

namespace App\Sync\Streams;

use App\Sync\SyncRegistry;
use App\Sync\SyncStream;
use App\Sync\SyncUuid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TableStream extends SyncStream
{
    /** @var array<string, array<int, string>> */
    private static array $columns = [];

    public function __construct(
        private readonly string $table,
        private readonly string $direction,
        private readonly array $config = [],
    ) {}

    public static function down(string $table, array $config): self
    {
        return new self($table, 'down', $config);
    }

    public static function up(string $table, array $config): self
    {
        return new self($table, 'up', $config);
    }

    public function name(): string
    {
        return $this->table;
    }

    public function direction(): string
    {
        return $this->direction;
    }

    public function serve(?string $cursor, int $limit): array
    {
        return $this->export($cursor, $limit);
    }

    public function collect(?string $cursor, int $limit): array
    {
        return $this->export($cursor, $limit);
    }

    public function apply(array $changes): int
    {
        return $this->import($changes);
    }

    public function receive(array $changes, array $context = []): int
    {
        return $this->import($changes, $context);
    }

    /**
     * @return array{changes: array<int, array<string, mixed>>, cursor: ?string}
     */
    private function export(?string $cursor, int $limit): array
    {
        if (! Schema::hasTable($this->table) || ! SyncUuid::hasUuidColumn($this->table)) {
            return ['changes' => [], 'cursor' => $cursor];
        }

        SyncUuid::backfill($this->table);

        $columns = $this->payloadColumns();
        if ($columns === []) {
            return ['changes' => [], 'cursor' => $cursor];
        }

        $hasUpdatedAt = $this->hasColumn('updated_at');
        $query = DB::table($this->table);

        if ($hasUpdatedAt) {
            [$cursorTime, $cursorUuid] = $this->parseTimeCursor($cursor);
            if ($cursorTime !== null) {
                $query->where(function ($q) use ($cursorTime, $cursorUuid) {
                    $q->where('updated_at', '>', $cursorTime)
                        ->orWhere(function ($q) use ($cursorTime, $cursorUuid) {
                            $q->where('updated_at', '=', $cursorTime)
                                ->where(SyncRegistry::UUID_COLUMN, '>', $cursorUuid);
                        });
                });
            }

            $query->orderBy('updated_at')->orderBy(SyncRegistry::UUID_COLUMN);
        } else {
            $cursorId = $this->parseIdCursor($cursor);
            if ($cursorId !== null && $this->hasColumn('id')) {
                $query->where('id', '>', $cursorId);
            }

            $query->orderBy('id');
        }

        $select = $this->hasColumn('id') && ! in_array('id', $columns, true)
            ? array_merge(['id'], $columns)
            : $columns;

        $rows = $query->limit($limit)->get($select);
        $changes = $rows->map(fn ($row) => $this->exportRow((array) $row, $columns))->all();
        $last = $rows->last();

        $nextCursor = $cursor;
        if ($last) {
            $nextCursor = $hasUpdatedAt
                ? $this->formatTimeCursor((string) $last->updated_at, (string) $last->{SyncRegistry::UUID_COLUMN})
                : $this->formatIdCursor((int) $last->id);
        }

        return ['changes' => $changes, 'cursor' => $nextCursor];
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function import(array $changes, array $context = []): int
    {
        if (! Schema::hasTable($this->table) || ! SyncUuid::hasUuidColumn($this->table)) {
            return 0;
        }

        $applied = 0;
        foreach ($changes as $change) {
            if (empty($change[SyncRegistry::UUID_COLUMN])) {
                continue;
            }

            $row = $this->importRow($change, $context);
            if ($row === []) {
                continue;
            }

            $existingId = DB::table($this->table)
                ->where(SyncRegistry::UUID_COLUMN, $row[SyncRegistry::UUID_COLUMN])
                ->value('id');

            if (! $existingId) {
                $existingId = $this->findByNaturalKey($row);
            }

            if ($existingId) {
                DB::table($this->table)->where('id', $existingId)->update($row);
            } else {
                if ($this->hasColumn('created_at') && ! array_key_exists('created_at', $row)) {
                    $row['created_at'] = now();
                }
                if ($this->hasColumn('updated_at') && ! array_key_exists('updated_at', $row)) {
                    $row['updated_at'] = now();
                }
                DB::table($this->table)->insert($row);
            }

            $applied++;
        }

        return $applied;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $payloadColumns
     * @return array<string, mixed>
     */
    private function exportRow(array $row, array $payloadColumns): array
    {
        $payload = array_intersect_key($row, array_flip($payloadColumns));
        $refs = [];

        foreach ($this->foreigns() as $column => $targetTable) {
            if (! array_key_exists($column, $row) || empty($row[$column])) {
                continue;
            }

            $refUuid = $this->uuidForId($targetTable, (int) $row[$column]);
            if ($refUuid) {
                $refs[$column] = $refUuid;
            }
        }

        if ($refs !== []) {
            $payload['_sync_refs'] = $refs;
        }

        $morphRefs = [];
        foreach ($this->morphs() as $name => $morph) {
            $typeColumn = $morph['type'] ?? null;
            $idColumn = $morph['id'] ?? null;

            if (! $typeColumn || ! $idColumn || empty($row[$typeColumn]) || empty($row[$idColumn])) {
                continue;
            }

            $targetTable = SyncRegistry::modelTableMap()[$row[$typeColumn]] ?? null;
            if (! $targetTable) {
                continue;
            }

            $refUuid = $this->uuidForId($targetTable, (int) $row[$idColumn]);
            if ($refUuid) {
                $morphRefs[$name] = [
                    'type' => $row[$typeColumn],
                    'uuid' => $refUuid,
                ];
            }
        }

        if ($morphRefs !== []) {
            $payload['_sync_morph_refs'] = $morphRefs;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private function importRow(array $change, array $context): array
    {
        $refs = $change['_sync_refs'] ?? [];
        $morphRefs = $change['_sync_morph_refs'] ?? [];
        unset($change['_sync_refs'], $change['_sync_morph_refs'], $change['id']);

        $row = [];
        foreach ($change as $column => $value) {
            if ($this->hasColumn($column)) {
                $row[$column] = $value;
            }
        }

        foreach ($this->foreigns() as $column => $targetTable) {
            if (! $this->hasColumn($column)) {
                continue;
            }

            $refUuid = $refs[$column] ?? null;
            if ($refUuid) {
                $row[$column] = $this->idForUuid($targetTable, $refUuid);
            } elseif ($column === 'branch_id' && ! empty($context['branch_uuid'])) {
                $row[$column] = $this->idForUuid('branches', (string) $context['branch_uuid']);
            } elseif (array_key_exists($column, $row) && $row[$column] !== null) {
                $row[$column] = null;
            }

            if ($column === 'branch_id' && empty($row[$column]) && ! empty($context['branch_id'])) {
                $row[$column] = (int) $context['branch_id'];
            }
        }

        foreach ($this->morphs() as $name => $morph) {
            $typeColumn = $morph['type'] ?? null;
            $idColumn = $morph['id'] ?? null;
            $ref = $morphRefs[$name] ?? null;

            if (! $typeColumn || ! $idColumn || ! $ref || ! $this->hasColumn($typeColumn) || ! $this->hasColumn($idColumn)) {
                continue;
            }

            $row[$typeColumn] = $ref['type'] ?? null;
            $targetTable = $row[$typeColumn] ? (SyncRegistry::modelTableMap()[$row[$typeColumn]] ?? null) : null;
            $row[$idColumn] = $targetTable && ! empty($ref['uuid'])
                ? $this->idForUuid($targetTable, $ref['uuid'])
                : null;
        }

        return $row;
    }

    /**
     * @return array<int, string>
     */
    private function payloadColumns(): array
    {
        $excluded = array_flip(array_merge(['id'], $this->config['exclude'] ?? []));

        return array_values(array_filter(
            $this->columns($this->table),
            fn (string $column) => ! isset($excluded[$column])
        ));
    }

    /**
     * @return array<string, string>
     */
    private function foreigns(): array
    {
        $foreigns = [];
        foreach (($this->config['foreigns'] ?? []) as $column => $target) {
            $foreigns[$column] = is_array($target) ? $target['table'] : $target;
        }

        return $foreigns;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function morphs(): array
    {
        return $this->config['morphs'] ?? [];
    }

    private function uuidForId(string $table, int $id): ?string
    {
        if (! SyncUuid::hasUuidColumn($table)) {
            return null;
        }

        return DB::table($table)->where('id', $id)->value(SyncRegistry::UUID_COLUMN);
    }

    private function idForUuid(string $table, string $uuid): ?int
    {
        if (! SyncUuid::hasUuidColumn($table)) {
            return null;
        }

        $id = DB::table($table)->where(SyncRegistry::UUID_COLUMN, $uuid)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function findByNaturalKey(array $row): ?int
    {
        $columns = $this->config['natural'] ?? [];
        if ($columns === []) {
            return null;
        }

        $query = DB::table($this->table);
        foreach ($columns as $column) {
            if (! $this->hasColumn($column) || ! array_key_exists($column, $row) || $row[$column] === null) {
                return null;
            }
            $query->where($column, $row[$column]);
        }

        $id = $query->value('id');

        return $id === null ? null : (int) $id;
    }

    private function hasColumn(string $column): bool
    {
        return in_array($column, $this->columns($this->table), true);
    }

    /**
     * @return array<int, string>
     */
    private function columns(string $table): array
    {
        return self::$columns[$table] ??= Schema::hasTable($table)
            ? Schema::getColumnListing($table)
            : [];
    }

    /**
     * @return array{?string, string}
     */
    private function parseTimeCursor(?string $cursor): array
    {
        if (! $cursor || str_starts_with($cursor, 'id:')) {
            return [null, ''];
        }

        if (! str_contains($cursor, '|')) {
            return [$cursor, ''];
        }

        [$time, $uuid] = explode('|', $cursor, 2);

        return [$time ?: null, $uuid ?: ''];
    }

    private function formatTimeCursor(string $time, string $uuid): string
    {
        return $time.'|'.$uuid;
    }

    private function parseIdCursor(?string $cursor): ?int
    {
        if (! $cursor || ! str_starts_with($cursor, 'id:')) {
            return null;
        }

        return (int) substr($cursor, 3);
    }

    private function formatIdCursor(int $id): string
    {
        return 'id:'.$id;
    }
}
