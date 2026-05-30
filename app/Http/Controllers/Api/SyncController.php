<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Sync\SyncManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cloud-side endpoints the branches dial into. The cloud is passive: it serves
 * downstream config on pull and persists upstream transactions on push. The
 * branch drives the schedule (see App\Sync\SyncManager).
 */
class SyncController extends Controller
{
    public function __construct(private readonly SyncManager $manager) {}

    public function ping(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'role' => config('sync.role'),
            'time' => now()->toIso8601String(),
        ]);
    }

    public function pull(Request $request): JsonResponse
    {
        $stream = $this->resolve((string) $request->query('stream'), 'down');

        $cursor = $request->query('cursor');
        $limit = min(max((int) $request->query('limit', 200), 1), 500);

        return response()->json($stream->serve($cursor ?: null, $limit));
    }

    public function push(Request $request): JsonResponse
    {
        $stream = $this->resolve((string) $request->input('stream'), 'up');

        $received = $stream->receive($request->input('changes', []), [
            'branch_id' => $request->input('branch_id') ?: $request->header('X-Branch-Id'),
            'branch_uuid' => $request->input('branch_uuid') ?: $request->header('X-Branch-Uuid'),
        ]);

        return response()->json(['ok' => true, 'received' => $received]);
    }

    private function resolve(string $name, string $direction)
    {
        $stream = $this->manager->streamByName($name, $direction);

        abort_if($stream === null, 404, "Unknown {$direction} stream: {$name}");

        return $stream;
    }
}
