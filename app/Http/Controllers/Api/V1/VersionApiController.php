<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Api\Versioning\ApiVersionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VersionApiController extends Controller
{
    public function __construct(
        protected ApiVersionManager $versionManager
    ) {}

    /**
     * Get the catalog of supported API versions.
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            [
                'default_version' => ApiVersionManager::DEFAULT_VERSION,
                'supported_versions' => ApiVersionManager::SUPPORTED_VERSIONS,
                'versions' => $this->versionManager->getCatalog(),
            ],
            'API versions catalog retrieved successfully.'
        );
    }

    /**
     * Get metadata for the currently negotiated API version.
     */
    public function show(Request $request): JsonResponse
    {
        $version = $this->versionManager->resolveVersion($request);
        $metadata = $this->versionManager->getVersionMetadata($version);

        return ApiResponse::success(
            $metadata,
            "Resolved API version: {$version}"
        );
    }
}
