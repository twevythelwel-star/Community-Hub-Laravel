<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiRequestLog;
use App\Services\Api\Versioning\ApiVersionManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ApiLoggingMiddleware
{
    protected const SENSITIVE_FIELDS = [
        'password',
        'password_confirmation',
        'secret',
        'token',
        'api_key',
        'authorization',
        'credit_card',
        'pin',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $requestId = $request->header('X-Request-ID', (string) Str::uuid());
        $request->headers->set('X-Request-ID', $requestId);

        /** @var Response $response */
        $response = $next($request);

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        $version = app(ApiVersionManager::class)->resolve($request);

        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('X-Response-Time', "{$durationMs}ms");
        $response->headers->set('X-Api-Version', $version);

        // Add Sunset / Deprecation headers if version is deprecated
        $versionManager = app(ApiVersionManager::class);
        if ($versionManager->isDeprecated($version)) {
            $response->headers->set('Deprecation', '@true');
            if ($sunset = $versionManager->getSunsetDate($version)) {
                $response->headers->set('Sunset', $sunset);
            }
        }

        // Log request data safely without blocking
        $this->recordLog($request, $response, $requestId, $version, $durationMs);

        return $response;
    }

    protected function recordLog(
        Request $request,
        Response $response,
        string $requestId,
        string $version,
        int $durationMs
    ): void {
        try {
            $user = $request->user();
            $tokenId = null;
            if ($user && method_exists($user, 'currentAccessToken') && $user->currentAccessToken()) {
                $tokenId = (string) $user->currentAccessToken()->id;
            }

            $queryParams = $this->sanitizeData($request->query());

            ApiRequestLog::create([
                'request_id' => $requestId,
                'user_id' => $user?->id,
                'token_id' => $tokenId,
                'version' => $version,
                'method' => strtoupper($request->method()),
                'path' => $request->path(),
                'status_code' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'query_params' => ! empty($queryParams) ? $queryParams : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to persist API request log: '.$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function sanitizeData(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_FIELDS, true)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitizeData($value);
            }
        }

        return $data;
    }
}
