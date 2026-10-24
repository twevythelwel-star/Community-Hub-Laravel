<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Services\Api\Versioning\ApiVersionManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ApiResponse
{
    /**
     * Return a standardized success JSON response.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public static function success(
        mixed $data = null,
        string $message = 'Operation successful',
        int $status = 200,
        array $meta = [],
        array $headers = []
    ): JsonResponse {
        $requestId = request()->header('X-Request-ID', (string) Str::uuid());
        $version = app(ApiVersionManager::class)->resolve(request());

        $payload = [
            'success' => true,
            'version' => $version,
            'status' => $status,
            'message' => $message,
            'data' => $data instanceof JsonResource ? $data->response()->getData(true)['data'] ?? $data : $data,
            'meta' => array_merge([
                'timestamp' => now()->toIso8601String(),
                'request_id' => $requestId,
            ], $meta),
        ];

        return response()->json($payload, $status, array_merge([
            'X-Request-ID' => $requestId,
            'X-Api-Version' => $version,
        ], $headers));
    }

    /**
     * Return a standardized error JSON response (RFC 7807 compatible).
     *
     * @param  array<string, mixed>|null  $details
     * @param  array<string, string>  $headers
     */
    public static function error(
        string $message,
        string $code = 'BAD_REQUEST',
        int $status = 400,
        ?array $details = null,
        array $headers = []
    ): JsonResponse {
        $requestId = request()->header('X-Request-ID', (string) Str::uuid());
        $version = app(ApiVersionManager::class)->resolve(request());

        $errorPayload = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null) {
            $errorPayload['details'] = $details;
        }

        $payload = [
            'success' => false,
            'version' => $version,
            'status' => $status,
            'error' => $errorPayload,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $requestId,
            ],
        ];

        return response()->json($payload, $status, array_merge([
            'X-Request-ID' => $requestId,
            'X-Api-Version' => $version,
        ], $headers));
    }

    /**
     * Return a standardized paginated JSON response with clean metadata.
     *
     * @param  array<string, mixed>  $extraMeta
     */
    public static function paginated(
        LengthAwarePaginator $paginator,
        ?string $resourceClass = null,
        string $message = 'Records retrieved successfully',
        array $extraMeta = []
    ): JsonResponse {
        $items = $paginator->items();

        if ($resourceClass && class_exists($resourceClass)) {
            $data = $resourceClass::collection($items)->resolve();
        } else {
            $data = $items;
        }

        $paginationMeta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'has_more' => $paginator->hasMorePages(),
        ];

        $links = [
            'first' => $paginator->url(1),
            'last' => $paginator->url($paginator->lastPage()),
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];

        return self::success(
            data: $data,
            message: $message,
            status: 200,
            meta: array_merge(['pagination' => $paginationMeta, 'links' => $links], $extraMeta)
        );
    }
}
