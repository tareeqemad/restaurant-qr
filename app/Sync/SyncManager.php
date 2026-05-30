<?php

namespace App\Sync;

use App\Models\SyncState;
use App\Sync\Streams\SettingsStream;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates a sync pass. Called every minute by the scheduler and on demand
 * from the admin "Sync now" button. The branch is the active party: it pulls
 * "down" streams and pushes "up" streams whenever the cloud is reachable. If
 * the internet is down the pass is a cheap no-op that just records "offline",
 * so the next minute retries automatically.
 */
class SyncManager
{
    /**
     * The registered streams, in run order. New streams (menu_items down,
     * orders up, …) are added here as later phases land.
     *
     * @return array<int, SyncStream>
     */
    public function streams(): array
    {
        return [
            new SettingsStream,
        ];
    }

    public function streamByName(string $name, string $direction): ?SyncStream
    {
        foreach ($this->streams() as $stream) {
            if ($stream->name() === $name && $stream->direction() === $direction) {
                return $stream;
            }
        }

        return null;
    }

    /**
     * Run a full pass. Returns a small report for the command / button to show.
     *
     * @return array<string, mixed>
     */
    public function run(): array
    {
        if (! config('sync.enabled') || config('sync.role') !== 'branch') {
            return ['skipped' => true, 'reason' => 'sync disabled or node is not a branch'];
        }

        $client = SyncClient::fromConfig();

        if (! $client->isConfigured()) {
            return ['skipped' => true, 'reason' => 'SYNC_CLOUD_URL / SYNC_TOKEN not set'];
        }

        if (! $client->reachable()) {
            foreach ($this->streams() as $stream) {
                SyncState::for($stream->name(), $stream->direction())->markOffline();
            }

            return ['offline' => true];
        }

        $report = [];

        foreach ($this->streams() as $stream) {
            $state = SyncState::for($stream->name(), $stream->direction());

            try {
                $count = $stream->direction() === 'down'
                    ? $this->pullDown($stream, $client, $state)
                    : $this->pushUp($stream, $client, $state);

                $report[$stream->name()] = ['ok' => true, 'count' => $count];
            } catch (\Throwable $e) {
                $state->markError($e->getMessage());
                Log::error('Sync stream failed', ['stream' => $stream->name(), 'error' => $e->getMessage()]);
                $report[$stream->name()] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        return ['ran' => true, 'streams' => $report];
    }

    private function pullDown(SyncStream $stream, SyncClient $client, SyncState $state): int
    {
        $cursor = $state->cursor;
        $batch = (int) config('sync.batch', 200);
        $total = 0;

        while (true) {
            $result = $client->pull($stream->name(), $cursor, $batch);
            $changes = $result['changes'];

            if (empty($changes)) {
                break;
            }

            $total += $stream->apply($changes);
            $cursor = $result['cursor'] ?? $cursor;

            if (count($changes) < $batch) {
                break;
            }
        }

        $state->markOk($cursor, $total);

        return $total;
    }

    private function pushUp(SyncStream $stream, SyncClient $client, SyncState $state): int
    {
        $cursor = $state->cursor;
        $batch = (int) config('sync.batch', 200);
        $total = 0;

        while (true) {
            $result = $stream->collect($cursor, $batch);
            $changes = $result['changes'];

            if (empty($changes)) {
                break;
            }

            $client->push($stream->name(), $changes);
            $cursor = $result['cursor'] ?? $cursor;
            $total += count($changes);

            if (count($changes) < $batch) {
                break;
            }
        }

        $state->markOk($cursor, $total);

        return $total;
    }
}
