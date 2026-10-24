<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ApiRequestLog;
use App\Models\WebhookDeliveryLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

class MonitoringApiController extends Controller
{
    /** health() stays public for probes; metrics() is the System Admin's. */
    public function __construct()
    {
        $this->middleware(['auth:sanctum', 'active', 'can:operatePlatform'])->only('metrics');
    }

    /**
     * System health check endpoint.
     */
    public function health(): JsonResponse
    {
        $status = 'healthy';
        $checks = [];

        // 1. Database Check
        try {
            DB::connection()->getPdo();
            $checks['database'] = [
                'status' => 'ok',
                'latency_ms' => $this->measure(fn () => DB::select('SELECT 1')),
            ];
        } catch (\Throwable $e) {
            $status = 'degraded';
            $checks['database'] = [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }

        // 2. Cache Check
        try {
            $key = 'health_check_ping_'.microtime(true);
            Cache::put($key, 'pong', 5);
            $val = Cache::get($key);
            Cache::forget($key);

            $checks['cache'] = [
                'status' => $val === 'pong' ? 'ok' : 'error',
                'driver' => config('cache.default'),
            ];
        } catch (\Throwable $e) {
            $status = 'degraded';
            $checks['cache'] = [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }

        // 3. Storage Check
        try {
            $storageOk = Storage::disk('local')->put('.health_test', 'test');
            Storage::disk('local')->delete('.health_test');

            $checks['storage'] = [
                'status' => $storageOk ? 'ok' : 'error',
            ];
        } catch (\Throwable $e) {
            $checks['storage'] = [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }

        // 4. Memory & Environment
        $memoryUsageBytes = memory_get_usage(true);
        $checks['system'] = [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'memory_usage' => round($memoryUsageBytes / 1024 / 1024, 2).' MB',
            'server_time' => now()->toIso8601String(),
        ];

        $statusCode = $status === 'healthy' ? 200 : 503;

        return ApiResponse::success(
            [
                'status' => $status,
                'timestamp' => now()->toIso8601String(),
                'checks' => $checks,
            ],
            $status === 'healthy' ? 'System is fully operational.' : 'System components degraded.',
            $statusCode
        );
    }

    /**
     * API Telemetry & Performance Metrics.
     */
    public function metrics(): JsonResponse
    {
        $since = now()->subHours(24);

        $totalRequests = ApiRequestLog::where('created_at', '>=', $since)->count();
        $avgDuration = ApiRequestLog::where('created_at', '>=', $since)->avg('duration_ms');

        $statusCounts = ApiRequestLog::where('created_at', '>=', $since)
            ->selectRaw('
                COUNT(CASE WHEN status_code >= 200 AND status_code < 300 THEN 1 END) as success_2xx,
                COUNT(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 END) as client_error_4xx,
                COUNT(CASE WHEN status_code >= 500 THEN 1 END) as server_error_5xx
            ')
            ->first();

        $activeTokens = PersonalAccessToken::count();

        $webhookDeliveries = WebhookDeliveryLog::where('created_at', '>=', $since)->count();
        $successfulDeliveries = WebhookDeliveryLog::where('created_at', '>=', $since)
            ->where('is_success', true)
            ->count();

        $webhookSuccessRate = $webhookDeliveries > 0
            ? round(($successfulDeliveries / $webhookDeliveries) * 100, 2)
            : 100.0;

        return ApiResponse::success(
            [
                'period' => '24_hours',
                'api' => [
                    'total_requests' => $totalRequests,
                    'average_latency_ms' => round((float) $avgDuration, 2),
                    'status_breakdown' => [
                        '2xx_success' => (int) ($statusCounts->success_2xx ?? 0),
                        '4xx_client_error' => (int) ($statusCounts->client_error_4xx ?? 0),
                        '5xx_server_error' => (int) ($statusCounts->server_error_5xx ?? 0),
                    ],
                ],
                'tokens' => [
                    'active_count' => $activeTokens,
                ],
                'webhooks' => [
                    'total_deliveries' => $webhookDeliveries,
                    'successful_deliveries' => $successfulDeliveries,
                    'success_rate_percent' => $webhookSuccessRate,
                ],
                'generated_at' => now()->toIso8601String(),
            ],
            'API telemetry and performance metrics retrieved.'
        );
    }

    protected function measure(callable $callback): float
    {
        $start = microtime(true);
        $callback();

        return round((microtime(true) - $start) * 1000, 2);
    }
}
