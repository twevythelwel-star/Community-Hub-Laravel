<?php

declare(strict_types=1);

namespace App\Services\Api\RateLimiting;

use App\Enums\UserRole;
use App\Http\Responses\ApiResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class ApiRateLimiter
{
    public static function register(): void
    {
        // General API rate limiter with role-aware and token-aware tiers
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();

            if ($user && in_array($user->role, [UserRole::SystemAdmin, UserRole::Admin, UserRole::Security], true)) {
                return Limit::perMinute(300)->by($user->id)->response(fn (Request $r, array $headers) => self::rateLimitResponse($headers));
            }

            if ($user) {
                return Limit::perMinute(120)->by($user->id)->response(fn (Request $r, array $headers) => self::rateLimitResponse($headers));
            }

            return Limit::perMinute(60)->by($request->ip())->response(fn (Request $r, array $headers) => self::rateLimitResponse($headers));
        });

        // Sensitive auth operations (e.g. login, token creation)
        RateLimiter::for('api.sensitive', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip() ?: 'unknown')
                ->response(fn (Request $r, array $headers) => self::rateLimitResponse($headers));
        });

        // Webhook ingestion
        RateLimiter::for('api.webhooks', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip() ?: 'unknown')
                ->response(fn (Request $r, array $headers) => self::rateLimitResponse($headers));
        });
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected static function rateLimitResponse(array $headers): JsonResponse
    {
        $retryAfter = $headers['Retry-After'] ?? '60';

        return ApiResponse::error(
            message: "Too many requests. Please slow down and retry in {$retryAfter} seconds.",
            code: 'RATE_LIMIT_EXCEEDED',
            status: 429,
            details: ['retry_after_seconds' => (int) $retryAfter],
            headers: $headers
        );
    }
}
