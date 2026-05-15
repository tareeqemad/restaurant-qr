<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class OptimizedAssetController extends Controller
{
    private const ALLOWED_PREFIXES = [
        'assets/dashtic/css/',
        'assets/dashtic/libs/bootstrap/css/',
    ];

    private const MIME_TYPES = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
    ];

    public function __invoke(Request $request, string $path): SymfonyResponse
    {
        $path = str_replace('\\', '/', ltrim($path, '/'));

        abort_if($path === '' || str_contains($path, '..'), 404);
        abort_unless($this->isAllowedPath($path), 404);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        abort_unless(isset(self::MIME_TYPES[$extension]), 404);

        $publicRoot = realpath(public_path());
        $file = realpath(public_path($path));

        abort_unless($publicRoot && $file && is_file($file), 404);
        abort_unless(str_starts_with($file, $publicRoot.DIRECTORY_SEPARATOR), 404);

        $etag = '"'.md5($path.'|'.filesize($file).'|'.filemtime($file)).'"';
        $headers = [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Content-Type' => self::MIME_TYPES[$extension],
            'ETag' => $etag,
            'Vary' => 'Accept-Encoding',
        ];

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        $gzipFile = $file.'.gz';
        $acceptsGzip = str_contains($request->headers->get('Accept-Encoding', ''), 'gzip');

        if ($acceptsGzip && is_file($gzipFile) && filemtime($gzipFile) >= filemtime($file)) {
            $headers['Content-Encoding'] = 'gzip';

            return response()->file($gzipFile, $headers);
        }

        return response()->file($file, $headers);
    }

    private function isAllowedPath(string $path): bool
    {
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
