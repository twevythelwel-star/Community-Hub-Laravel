<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enterprise Idempotency Guard Middleware.
 *
 * Prevents duplicate state mutation (e.g. duplicate payment authorization,
 * duplicate visitor pass issuance, or redundant gate barrier triggers)
 * caused by client retries, flaky cell connectivity, or automated retry agents.
 */
class EnforceIdempotency
{
    public const HEADER_NAME = 'Idempotency-Key';

    public const REPLAY_HEADER = 'X-Idempotent-Replayed';

    public const TTL_SECONDS = 86400; // 24 hours

    public function handle(Request $request, Closure $next): Response
    {
        // Only inspect mutating methods
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $idempotencyKey = $request->header(self::HEADER_NAME)
            ?? $request->header('X-Idempotency-Key');

        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            return $next($request);
        }

        /*
         | Signed-in requests only. An IP address is shared behind NAT, so two
         | visitors sending the same key would be handed each other's response;
         | and a replay cannot restore the cookies the first response set, so
         | a replayed login would sign no one in.
         */
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        $idempotencyKey = trim($idempotencyKey);
        $userScope = $user->id;
        $cacheKey = "idempotency:{$userScope}:{$idempotencyKey}";
        $lockKey = "idempotency:lock:{$userScope}:{$idempotencyKey}";

        // Check if an identical request was already successfully processed
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            $replayedResponse = new Response(
                $cached['content'] ?? '',
                $cached['status'] ?? 200,
                $cached['headers'] ?? []
            );
            $replayedResponse->headers->set(self::REPLAY_HEADER, 'true');
            $replayedResponse->headers->set(self::HEADER_NAME, $idempotencyKey);

            return $replayedResponse;
        }

        // Acquire in-flight lock to guard against simultaneous double-submissions
        $acquired = Cache::add($lockKey, true, now()->addSeconds(30));
        if (! $acquired) {
            return new JsonResponse([
                'error' => 'A request with this idempotency key is currently processing. Please do not retry concurrently.',
                'idempotency_key' => $idempotencyKey,
            ], Response::HTTP_CONFLICT);
        }

        try {
            $response = $next($request);

            // Cache successful responses (2xx and 3xx redirects)
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 400) {
                // Collect cacheable headers
                $cacheableHeaders = [];
                foreach (['Content-Type', 'X-Inertia', 'Location'] as $header) {
                    if ($response->headers->has($header)) {
                        $cacheableHeaders[$header] = $response->headers->get($header);
                    }
                }

                Cache::put($cacheKey, [
                    'status' => $response->getStatusCode(),
                    'content' => $response->getContent(),
                    'headers' => $cacheableHeaders,
                ], now()->addSeconds(self::TTL_SECONDS));
            }

            $response->headers->set(self::REPLAY_HEADER, 'false');
            $response->headers->set(self::HEADER_NAME, $idempotencyKey);

            return $response;
        } finally {
            Cache::forget($lockKey);
        }
    }
}
