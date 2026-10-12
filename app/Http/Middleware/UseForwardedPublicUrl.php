<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the app is reached via Cloudflare Tunnel (or similar), the browser uses HTTPS
 * while nginx sees plain HTTP. Trust proxy headers and align generated URLs with the
 * public host so forms and CSRF cookies stay on the same origin.
 */
final class UseForwardedPublicUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldUseForwardedPublicUrl($request)) {
            URL::forceScheme('https');
            URL::forceRootUrl('https://'.$request->getHost());
        }

        return $next($request);
    }

    private function shouldUseForwardedPublicUrl(Request $request): bool
    {
        if (strtolower((string) $request->header('X-Forwarded-Proto')) === 'https') {
            return true;
        }

        return str_ends_with($request->getHost(), '.trycloudflare.com');
    }
}
