<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Services\Licensing\LicenseSigner;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LicenseCheckController extends Controller
{
    public function __invoke(Request $request, LicenseSigner $signer): JsonResponse
    {
        abort_unless(config('license.role') === 'cloud', 404);

        $data = $request->validate([
            'license_key' => ['required', 'string', 'max:120'],
            'branch_uuid' => ['required', 'string', 'max:80'],
            'branch_id' => ['nullable', 'string', 'max:80'],
            'app_url' => ['nullable', 'string', 'max:191'],
        ]);

        $license = License::where('license_key', $data['license_key'])->first();

        if (! $license) {
            return response()->json(['message' => 'License key not found.'], 404);
        }

        try {
            $activation = $license->recordActivation(
                branchUuid: $data['branch_uuid'],
                branchId: $data['branch_id'] ?? null,
                appUrl: $data['app_url'] ?? null,
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'status' => str_contains($e->getMessage(), 'limit') ? 'activation_limit' : 'activation_invalid',
            ], 423);
        }

        $payload = $license->signedPayload($data['branch_uuid'], $activation);

        return response()->json([
            'payload' => $payload,
            'signature' => $signer->sign($payload),
        ]);
    }
}
