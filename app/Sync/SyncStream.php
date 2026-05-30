<?php

namespace App\Sync;

/**
 * A single sync stream — one logical dataset moving in one direction between a
 * branch and the cloud. The same class runs on both nodes; which methods fire
 * depends on the node's role:
 *
 *   direction "down" (cloud → branch):
 *     - cloud serves   → serve()
 *     - branch applies → apply()
 *
 *   direction "up" (branch → cloud):
 *     - branch collects → collect()
 *     - cloud receives  → receive()
 *
 * See docs/offline-sync-architecture.md §3 for the ownership split that keeps
 * each stream single-direction and conflict-free.
 */
abstract class SyncStream
{
    abstract public function name(): string;

    /** @return 'down'|'up' */
    abstract public function direction(): string;

    /**
     * CLOUD side of a "down" stream: return changes after $cursor.
     *
     * @return array{changes: array<int, array<string, mixed>>, cursor: ?string}
     */
    public function serve(?string $cursor, int $limit): array
    {
        throw new \LogicException(static::class.' does not serve (not a down stream).');
    }

    /**
     * BRANCH side of a "down" stream: apply pulled changes locally.
     *
     * @param  array<int, array<string, mixed>>  $changes
     * @return int rows applied
     */
    public function apply(array $changes): int
    {
        throw new \LogicException(static::class.' does not apply (not a down stream).');
    }

    /**
     * BRANCH side of an "up" stream: collect local changes after $cursor.
     *
     * @return array{changes: array<int, array<string, mixed>>, cursor: ?string}
     */
    public function collect(?string $cursor, int $limit): array
    {
        throw new \LogicException(static::class.' does not collect (not an up stream).');
    }

    /**
     * CLOUD side of an "up" stream: persist pushed changes.
     *
     * @param  array<int, array<string, mixed>>  $changes
     * @return int rows received
     */
    public function receive(array $changes, array $context = []): int
    {
        throw new \LogicException(static::class.' does not receive (not an up stream).');
    }
}
