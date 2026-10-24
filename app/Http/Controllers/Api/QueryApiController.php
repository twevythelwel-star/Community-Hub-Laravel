<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Query\ApiQueryService;
use App\Support\EntityAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QueryApiController extends Controller
{
    public function __construct(
        protected ApiQueryService $queryService
    ) {}

    /**
     * Query any supported entity dynamically with Spatie Query Builder parameters.
     */
    public function query(string $entity, Request $request): JsonResponse
    {
        abort_unless(EntityAccess::allows($request->user(), $entity), 403);

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);

        $paginator = $this->queryService
            ->forEntity($entity, $request)
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json([
            'success' => true,
            'entity' => $entity,
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    /**
     * Query GatePass models with filters, sorts, includes, fields, and pagination.
     */
    public function gatePasses(Request $request): JsonResponse
    {
        return $this->query('gate_passes', $request);
    }

    /**
     * Query Visitor models with filters, sorts, includes, fields, and pagination.
     */
    public function visitors(Request $request): JsonResponse
    {
        return $this->query('visitors', $request);
    }

    /**
     * Query Transaction models with filters, sorts, includes, fields, and pagination.
     */
    public function transactions(Request $request): JsonResponse
    {
        return $this->query('transactions', $request);
    }

    /**
     * Query User models with filters, sorts, includes, fields, and pagination.
     */
    public function users(Request $request): JsonResponse
    {
        return $this->query('users', $request);
    }

    /**
     * Query Warning models with filters, sorts, includes, fields, and pagination.
     */
    public function warnings(Request $request): JsonResponse
    {
        return $this->query('warnings', $request);
    }

    /**
     * Return schema metadata of allowed filters, sorts, includes, and fields across all models.
     */
    public function meta(Request $request): JsonResponse
    {
        $visible = EntityAccess::visibleTo($request->user());

        return response()->json([
            'success' => true,
            'meta' => array_intersect_key($this->queryService->getEntitiesMeta(), array_flip($visible)),
            'supported_entities' => array_values(array_intersect($this->queryService->getSupportedEntities(), $visible)),
            'conventions' => [
                'filter' => '?filter[attribute]=value or ?filter[name]=John,Jane',
                'sort' => '?sort=created_at (asc) or ?sort=-created_at (desc)',
                'include' => '?include=user,visitor',
                'fields' => '?fields[gate_passes]=id,pass_id,holder_name',
                'pagination' => '?page[number]=1&page[size]=15 or ?per_page=15',
            ],
        ]);
    }
}
