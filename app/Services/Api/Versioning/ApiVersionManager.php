<?php

declare(strict_types=1);

namespace App\Services\Api\Versioning;

use Illuminate\Http\Request;

class ApiVersionManager
{
    public const DEFAULT_VERSION = 'v1';

    public const SUPPORTED_VERSIONS = ['v1', 'v2'];

    /**
     * @var array<string, array{status: string, release_date: string, sunset_date: ?string, changelog_summary: string}>
     */
    protected array $versions = [
        'v1' => [
            'status' => 'current',
            'release_date' => '2026-01-01',
            'sunset_date' => null,
            'changelog_summary' => 'Initial RESTful API release with gate passes, visitors, transactions, universal search, and query builder.',
        ],
        'v2' => [
            'status' => 'preview',
            'release_date' => '2026-10-01',
            'sunset_date' => null,
            'changelog_summary' => 'Enhanced batch operations, streaming telemetry, and event-driven webhook multiplexing.',
        ],
    ];

    public function resolve(Request $request): string
    {
        return $this->resolveVersion($request);
    }

    public function resolveVersion(Request $request): string
    {
        // 1. Check URI prefix first (e.g. /api/v1/..., /api/v2/...)
        $uri = $request->path();
        if (preg_match('#^api/(v\d+)#', $uri, $matches)) {
            if (isset($this->versions[$matches[1]])) {
                return $matches[1];
            }
        }

        // 2. Check X-Api-Version header
        $headerVersion = $request->header('X-Api-Version');
        if ($headerVersion && isset($this->versions[strtolower($headerVersion)])) {
            return strtolower($headerVersion);
        }

        // 3. Check Accept header (e.g. Accept: application/vnd.communityhub.v1+json)
        $accept = $request->header('Accept', '');
        if (preg_match('#application/vnd\.communityhub\.(v\d+)\+json#i', $accept, $matches)) {
            if (isset($this->versions[strtolower($matches[1])])) {
                return strtolower($matches[1]);
            }
        }

        return self::DEFAULT_VERSION;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getVersionsCatalog(): array
    {
        return $this->versions;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getCatalog(): array
    {
        return $this->versions;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getVersionMetadata(string $version): ?array
    {
        return $this->versions[$version] ?? null;
    }

    public function isDeprecated(string $version): bool
    {
        return ($this->versions[$version]['status'] ?? '') === 'deprecated';
    }

    public function getSunsetDate(string $version): ?string
    {
        return $this->versions[$version]['sunset_date'] ?? null;
    }
}
