<?php

namespace App\Sync;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP wrapper the branch uses to talk to the cloud. Centralises the base
 * URL, auth token and timeout so individual streams stay focused on data.
 */
class SyncClient
{
    public function __construct(
        private readonly ?string $baseUrl,
        private readonly ?string $token,
        private readonly int $timeout = 15,
        private readonly ?string $branchId = null,
        private readonly ?string $branchUuid = null,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            rtrim((string) config('sync.cloud_url'), '/') ?: null,
            config('sync.token'),
            (int) config('sync.timeout', 15),
            config('sync.branch_id'),
            config('sync.branch_uuid'),
        );
    }

    public function isConfigured(): bool
    {
        return ! empty($this->baseUrl) && ! empty($this->token);
    }

    /**
     * Is the cloud reachable right now? Hits the unauthenticated health route
     * so a flaky connection is detected before we attempt real work.
     */
    public function reachable(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            return Http::timeout(min($this->timeout, 8))
                ->get($this->baseUrl.'/up')
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Pull a batch of downstream changes for a stream since the given cursor.
     *
     * @return array{changes: array<int, array<string, mixed>>, cursor: ?string}
     */
    public function pull(string $stream, ?string $cursor, int $limit): array
    {
        $response = $this->request()->get($this->baseUrl.'/api/sync/pull', [
            'stream' => $stream,
            'cursor' => $cursor,
            'limit' => $limit,
        ])->throw();

        return [
            'changes' => $response->json('changes', []),
            'cursor' => $response->json('cursor'),
        ];
    }

    /**
     * Push a batch of upstream changes for a stream.
     *
     * @param  array<int, array<string, mixed>>  $changes
     */
    public function push(string $stream, array $changes): void
    {
        $this->request()->post($this->baseUrl.'/api/sync/push', [
            'stream' => $stream,
            'branch_id' => $this->branchId,
            'branch_uuid' => $this->branchUuid,
            'changes' => $changes,
        ])->throw();
    }

    private function request(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->withToken($this->token)
            ->acceptJson()
            ->withHeaders(array_filter([
                'X-Branch-Id' => $this->branchId,
                'X-Branch-Uuid' => $this->branchUuid,
            ]));
    }
}
