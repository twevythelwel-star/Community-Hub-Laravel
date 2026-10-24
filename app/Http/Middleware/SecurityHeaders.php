<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser security headers on every response.
 *
 * The app sent none: any site could frame the sign-in page or the dashboard
 * (clickjacking), and browsers were free to sniff an uploaded file into HTML.
 * No Content-Security-Policy here — the Blade pages carry inline scripts, so a
 * policy needs nonces first.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff', false);
        $headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        // The gate scanner uses the camera and the map uses location; nothing uses the microphone.
        $headers->set('Permissions-Policy', 'camera=(self), geolocation=(self), microphone=()', false);

        // Only over HTTPS: browsers ignore it otherwise, and it must not pin
        // a local http:// host.
        if ($request->isSecure() && app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains', false);
        }

        return $response;
    }
}
