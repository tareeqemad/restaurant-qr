<?php

namespace App\Http\Middleware;

use App\Support\MarketHtmlTranslator;
use App\Support\MarketProfile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TranslateMarketHtml
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! MarketProfile::isUs() || $request->expectsJson()) {
            return $response;
        }

        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return $response;
        }

        if (! method_exists($response, 'getContent') || ! method_exists($response, 'setContent')) {
            return $response;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($contentType !== '' && ! str_contains($contentType, 'text/html')) {
            return $response;
        }

        $content = $response->getContent();
        if (! is_string($content) || $content === '' || preg_match('/\p{Arabic}/u', $content) !== 1) {
            return $response;
        }

        $response->setContent(MarketHtmlTranslator::translateForCurrentMarket($content));
        $response->headers->remove('Content-Length');

        return $response;
    }
}
