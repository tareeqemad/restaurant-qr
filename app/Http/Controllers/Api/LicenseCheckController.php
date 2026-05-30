<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Services\Licensing\LicenseSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LicenseCheckController extends Controller
{
    public function __invoke(Request $request, LicenseSigner $signer): JsonResponse
    {
        abort_unless(config('license.role') === 'cloud', 404);

        $data = $request->validate([
            'license_key' => ['required', 'string', 'max:120'],
            'branch_uuid' => ['nullable', 'string', 'max:80'],
        ]);

        $license = License::where('license_key', $data['license_key'])->first();

        if (! $license) {
            return response()->json(['message' => 'License key not found.'], 404);
        }

        $payload = $license->signedPayload($data['branch_uuid'] ?? null);

        return response()->json([
            'payload' => $payload,
            'signature' => $signer->sign($payload),
        ]);
    }
}
