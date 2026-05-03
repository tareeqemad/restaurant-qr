<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BrandAssetController extends Controller
{
    public function __invoke(Request $request, string $key)
    {
        abort_unless(in_array($key, ['brand_logo', 'brand_favicon'], true), 404);

        $path = trim((string) Setting::get($key, ''));
        if ($path === '' || str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'data:')) {
            abort(404);
        }

        $normalized = ltrim($path, '/');
        $normalized = str_starts_with($normalized, 'storage/')
            ? substr($normalized, strlen('storage/'))
            : $normalized;

        abort_unless(Storage::disk('public')->exists($normalized), 404);

        $absolutePath = Storage::disk('public')->path($normalized);
        abort_unless(is_file($absolutePath) && is_readable($absolutePath), 404);

        return response()->file($absolutePath, [
            'Cache-Control' => 'public, max-age=604800',
            'Content-Type' => Storage::disk('public')->mimeType($normalized) ?: 'application/octet-stream',
        ]);
    }
}
