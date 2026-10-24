<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Community;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Multi-Community SaaS Context Resolver Middleware.
 *
 * Resolves the active Community from:
 * 1. Explicit request header (X-Community-Id / X-Community-Code)
 * 2. Authenticated user's community assignment
 * 3. Session state (for multi-estate managers/inspectors)
 * 4. Subdomain or hostname match
 * 5. Primary default community fallback
 *
 * Binds the resolved community singleton into the service container
 * so services and models can inject `Community $community` directly.
 */
class ResolveCommunityContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $community = $this->resolveCommunity($request);

        // Bind singleton instance into Laravel container
        app()->instance(Community::class, $community);
        $request->attributes->set('community', $community);

        $response = $next($request);

        // Append active community code to response headers for API & debugging transparency
        if ($community && isset($community->code)) {
            $response->headers->set('X-Community-Code', $community->code);
        }

        return $response;
    }

    private function resolveCommunity(Request $request): Community
    {
        // 1. Explicit Header
        $codeHeader = $request->header('X-Community-Code') ?? $request->header('X-Community-Id');
        if (is_string($codeHeader) && trim($codeHeader) !== '') {
            $found = Community::query()
                ->where('code', trim($codeHeader))
                ->orWhere('id', trim($codeHeader))
                ->first();
            if ($found) {
                return $found;
            }
        }

        // 2. User community assignment
        $user = $request->user();
        if ($user && isset($user->community_id) && $user->community_id) {
            $found = Community::find($user->community_id);
            if ($found) {
                return $found;
            }
        }

        // 3. Session selection
        $sessionCommunityId = $request->hasSession() ? $request->session()->get('active_community_id') : null;
        if ($sessionCommunityId) {
            $found = Community::find($sessionCommunityId);
            if ($found) {
                return $found;
            }
        }

        // 4. Subdomain resolution
        $host = $request->getHost();
        $parts = explode('.', $host);
        if (count($parts) > 2) {
            $subdomain = strtolower($parts[0]);
            if ($subdomain !== 'www' && $subdomain !== 'api' && $subdomain !== 'admin') {
                // Exact code only, with or without its CID- prefix: a partial
                // match let palm.example.com select "Royal Palms".
                $found = Community::query()
                    ->whereRaw('LOWER(code) IN (?, ?)', [$subdomain, 'cid-'.$subdomain])
                    ->first();
                if ($found) {
                    return $found;
                }
            }
        }

        // 5. Default Fallback
        return Community::default();
    }
}
