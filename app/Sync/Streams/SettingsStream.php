<?php

namespace App\Sync\Streams;

use App\Sync\SyncStream;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Settings — cloud-authoritative configuration that flows DOWN to branches.
 *
 * The owner edits a flag/price/toggle on the cloud and every branch picks it up
 * on the next sync. Keyed by the natural `key` column (unique), so no ULID is
 * needed and the cloud always wins. Raw `value` strings are moved verbatim via
 * the query builder to bypass the model's type-casting, then the per-key cache
 * is busted so Setting::get() reflects the new value immediately.
 *
 * Cursor: the max `updated_at` consumed so far. Settings volume is tiny (well
 * under one batch), so same-second ties never straddle a batch boundary.
 */
class SettingsStream extends SyncStream
{
    /** Columns moved across the wire (raw, uncast). */
    private const COLUMNS = ['key', 'value', 'group', 'type', 'label', 'description', 'created_at', 'updated_at'];

    public function name(): string
    {
        return 'settings';
    }

    public function direction(): string
    {
        return 'down';
    }

    public function serve(?string $cursor, int $limit): array
    {
        $rows = DB::table('settings')
            ->when($cursor, fn ($q) => $q->where('updated_at', '>', $cursor))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(self::COLUMNS);

        $changes = $rows->map(fn ($r) => (array) $r)->all();
        $nextCursor = $rows->last()->updated_at ?? $cursor;

        return ['changes' => $changes, 'cursor' => $nextCursor];
    }

    public function apply(array $changes): int
    {
        $applied = 0;

        foreach ($changes as $change) {
            if (empty($change['key'])) {
                continue;
            }

            DB::table('settings')->updateOrInsert(
                ['key' => $change['key']],
                [
                    'value' => $change['value'] ?? null,
                    'group' => $change['group'] ?? 'general',
                    'type' => $change['type'] ?? 'string',
                    'label' => $change['label'] ?? null,
                    'description' => $change['description'] ?? null,
                    'updated_at' => $change['updated_at'] ?? now(),
                    'created_at' => $change['created_at'] ?? now(),
                ],
            );

            Cache::forget('setting.'.$change['key']);
            $applied++;
        }

        return $applied;
    }
}
