<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Api\Versioning\ApiVersionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DocsApiController extends Controller
{
    public function __construct(
        protected ApiVersionManager $versionManager
    ) {}

    /**
     * Return OpenAPI 3.0 specification JSON for the requested API version.
     */
    public function openApiJson(Request $request, string $version = 'v1'): JsonResponse
    {
        $version = in_array(strtolower($version), ApiVersionManager::SUPPORTED_VERSIONS, true)
            ? strtolower($version)
            : 'v1';

        $spec = $this->buildOpenApiSpec($version);

        return response()->json($spec, 200, [
            'Content-Type' => 'application/json',
            'Access-Control-Allow-Origin' => '*',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Render the interactive Swagger UI / OpenAPI documentation interface.
     */
    public function ui(Request $request, string $version = 'v1'): Response
    {
        $version = in_array(strtolower($version), ApiVersionManager::SUPPORTED_VERSIONS, true)
            ? strtolower($version)
            : 'v1';

        $specUrl = url("/api/{$version}/openapi.json");
        $title = "Community Hub API Reference ({$version})";

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <link rel="stylesheet" type="text/css" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
    <link rel="icon" type="image/png" href="https://unpkg.com/swagger-ui-dist@5/favicon-32x32.png" sizes="32x32" />
    <style>
        html { box-sizing: border-box; overflow: -moz-scrollbars-vertical; overflow-y: scroll; }
        *, *:before, *:after { box-sizing: inherit; }
        body { margin:0; background: #0f172a; color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .topbar-header {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border-bottom: 1px solid #334155;
            padding: 16px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .topbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.15rem;
            font-weight: 700;
            color: #38bdf8;
            letter-spacing: -0.02em;
        }
        .version-badge {
            background: rgba(56, 189, 248, 0.15);
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.3);
            border-radius: 9999px;
            padding: 2px 10px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .version-switcher {
            display: flex;
            gap: 8px;
        }
        .version-btn {
            background: #1e293b;
            color: #94a3b8;
            border: 1px solid #334155;
            padding: 6px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.825rem;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        .version-btn:hover, .version-btn.active {
            background: #38bdf8;
            color: #0f172a;
            border-color: #38bdf8;
        }
        #swagger-ui {
            background: #ffffff;
            border-radius: 8px;
            margin: 20px auto;
            max-width: 1400px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5), 0 8px 10px -6px rgba(0, 0, 0, 0.5);
            overflow: hidden;
        }
    </style>
</head>
<body>
    <header class="topbar-header">
        <div class="topbar-brand">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
            <span>Community Hub Enterprise API</span>
            <span class="version-badge">{$version}</span>
        </div>
        <div class="version-switcher">
            <a href="/api/v1/docs" class="version-btn {$this->activeClass($version, 'v1')}">v1 (Current)</a>
            <a href="/api/v2/docs" class="version-btn {$this->activeClass($version, 'v2')}">v2 (Next Gen)</a>
            <a href="/docs/api" class="version-btn" target="_blank">Scramble UI &rarr;</a>
        </div>
    </header>

    <div id="swagger-ui"></div>

    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js"></script>
    <script>
    window.onload = function() {
        window.ui = SwaggerUIBundle({
            url: "{$specUrl}",
            dom_id: '#swagger-ui',
            deepLinking: true,
            presets: [
                SwaggerUIBundle.presets.apis,
                SwaggerUIStandalonePreset
            ],
            plugins: [
                SwaggerUIBundle.plugins.DownloadUrl
            ],
            layout: "BaseLayout",
            persistAuthorization: true,
            defaultModelsExpandDepth: 2,
            defaultModelExpandDepth: 2,
            displayRequestDuration: true,
            filter: true
        });
    };
    </script>
</body>
</html>
HTML;

        return response($html, 200, ['Content-Type' => 'text/html']);
    }

    private function activeClass(string $current, string $target): string
    {
        return $current === $target ? 'active' : '';
    }

    /**
     * Build the structured OpenAPI 3.0.3 specification contract.
     *
     * @return array<string, mixed>
     */
    protected function buildOpenApiSpec(string $version): array
    {
        $baseUrl = url("/api/{$version}");

        $v1Paths = [
            // Tokens
            '/tokens' => [
                'get' => [
                    'tags' => ['API Tokens'],
                    'summary' => 'List active personal access tokens',
                    'description' => 'Retrieves all API tokens issued to the authenticated user.',
                    'security' => [['SanctumBearer' => []]],
                    'responses' => [
                        '200' => [
                            'description' => 'List of active tokens',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/SuccessEnvelope'],
                                ],
                            ],
                        ],
                    ],
                ],
                'post' => [
                    'tags' => ['API Tokens'],
                    'summary' => 'Create a new personal access token with scoped abilities',
                    'security' => [['SanctumBearer' => []]],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['token_name'],
                                    'properties' => [
                                        'token_name' => ['type' => 'string', 'example' => 'CI-CD-Deployment-Key'],
                                        'abilities' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string'],
                                            'example' => ['gate-pass:read', 'visitors:verify'],
                                        ],
                                        'expires_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => ['description' => 'Token generated successfully with plainTextToken.'],
                        '422' => ['$ref' => '#/components/responses/ValidationError'],
                    ],
                ],
                'delete' => [
                    'tags' => ['API Tokens'],
                    'summary' => 'Revoke all personal access tokens',
                    'security' => [['SanctumBearer' => []]],
                    'responses' => [
                        '200' => ['description' => 'All tokens revoked.'],
                    ],
                ],
            ],
            '/tokens/{tokenId}' => [
                'delete' => [
                    'tags' => ['API Tokens'],
                    'summary' => 'Revoke a specific token by ID',
                    'security' => [['SanctumBearer' => []]],
                    'parameters' => [
                        [
                            'name' => 'tokenId',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'integer'],
                        ],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Token revoked.'],
                        '404' => ['description' => 'Token not found.'],
                    ],
                ],
            ],

            // Webhooks
            '/webhooks/subscriptions' => [
                'get' => [
                    'tags' => ['Webhooks'],
                    'summary' => 'List webhook subscriptions',
                    'security' => [['SanctumBearer' => []]],
                    'responses' => ['200' => ['description' => 'List of subscriptions']],
                ],
                'post' => [
                    'tags' => ['Webhooks'],
                    'summary' => 'Create a new webhook subscription',
                    'security' => [['SanctumBearer' => []]],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['name', 'url', 'events'],
                                    'properties' => [
                                        'name' => ['type' => 'string', 'example' => 'Security Audit Hook'],
                                        'url' => ['type' => 'string', 'format' => 'uri', 'example' => 'https://api.myestate.io/webhooks'],
                                        'events' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string'],
                                            'example' => ['visitor.checked_in', 'gate_pass.created'],
                                        ],
                                        'secret' => ['type' => 'string', 'nullable' => true],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => ['description' => 'Webhook subscription created with HMAC signing secret.'],
                    ],
                ],
            ],
            '/webhooks/subscriptions/{subscriptionId}/test' => [
                'post' => [
                    'tags' => ['Webhooks'],
                    'summary' => 'Send test ping payload to a webhook subscription',
                    'security' => [['SanctumBearer' => []]],
                    'parameters' => [
                        [
                            'name' => 'subscriptionId',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'integer'],
                        ],
                    ],
                    'responses' => ['200' => ['description' => 'Ping payload dispatched.']],
                ],
            ],
            '/webhooks/incoming/{service}' => [
                'post' => [
                    'tags' => ['Webhooks'],
                    'summary' => 'Receive and verify incoming third-party webhook',
                    'parameters' => [
                        [
                            'name' => 'service',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'string', 'example' => 'stripe'],
                        ],
                        [
                            'name' => 'X-Webhook-Signature',
                            'in' => 'header',
                            'required' => false,
                            'schema' => ['type' => 'string'],
                        ],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Webhook acknowledged.'],
                        '401' => ['description' => 'Invalid HMAC-SHA256 signature.'],
                    ],
                ],
            ],

            // System & Monitoring
            '/health' => [
                'get' => [
                    'tags' => ['System & Monitoring'],
                    'summary' => 'System health check',
                    'description' => 'Evaluates database connectivity, cache response, storage readiness, and memory footprint.',
                    'responses' => [
                        '200' => ['description' => 'System operational.'],
                        '503' => ['description' => 'Service degraded.'],
                    ],
                ],
            ],
            '/metrics' => [
                'get' => [
                    'tags' => ['System & Monitoring'],
                    'summary' => 'API telemetry metrics',
                    'description' => 'Returns aggregate requests, average response latency, HTTP status code distribution, and webhook success rates over the last 24 hours.',
                    'responses' => ['200' => ['description' => 'Metrics summary.']],
                ],
            ],
            '/version' => [
                'get' => [
                    'tags' => ['System & Monitoring'],
                    'summary' => 'Current API version information',
                    'responses' => ['200' => ['description' => 'Version metadata.']],
                ],
            ],
        ];

        $v2Paths = [
            '/version' => [
                'get' => [
                    'tags' => ['System & Monitoring'],
                    'summary' => 'API v2 specification and experimental roadmap',
                    'responses' => ['200' => ['description' => 'v2 status.']],
                ],
            ],
            '/health' => [
                'get' => [
                    'tags' => ['System & Monitoring'],
                    'summary' => 'v2 High-availability health probe',
                    'responses' => ['200' => ['description' => 'Health status.']],
                ],
            ],
        ];

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Community Hub Enterprise API',
                'description' => 'Standardized, multi-versioned REST API architecture for Community Hub with personal access token auth, HMAC-SHA256 signed webhooks, rate limiting, and telemetry monitoring.',
                'version' => $version === 'v2' ? '2.0.0-beta' : '1.0.0',
                'contact' => [
                    'name' => 'API Support Team',
                    'email' => 'api-support@communityhub.io',
                ],
            ],
            'servers' => [
                [
                    'url' => $baseUrl,
                    'description' => strtoupper($version).' Production / Staging Server',
                ],
            ],
            'tags' => [
                ['name' => 'API Tokens', 'description' => 'Personal access token creation, scoping, and revocation via Sanctum'],
                ['name' => 'Webhooks', 'description' => 'Outgoing HMAC-SHA256 signed webhooks and incoming verification'],
                ['name' => 'System & Monitoring', 'description' => 'Health checks, telemetry latency, and version negotiation'],
            ],
            'paths' => $version === 'v2' ? $v2Paths : $v1Paths,
            'components' => [
                'securitySchemes' => [
                    'SanctumBearer' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Sanctum Token',
                        'description' => 'Enter your Personal Access Token in the format: Bearer <token>',
                    ],
                ],
                'schemas' => [
                    'SuccessEnvelope' => [
                        'type' => 'object',
                        'properties' => [
                            'success' => ['type' => 'boolean', 'example' => true],
                            'version' => ['type' => 'string', 'example' => $version],
                            'status' => ['type' => 'integer', 'example' => 200],
                            'message' => ['type' => 'string', 'example' => 'Operation completed successfully.'],
                            'data' => ['type' => 'object'],
                            'meta' => [
                                'type' => 'object',
                                'properties' => [
                                    'request_id' => ['type' => 'string', 'example' => 'req_651a02fb4c91'],
                                    'timestamp' => ['type' => 'string', 'format' => 'date-time'],
                                ],
                            ],
                        ],
                    ],
                    'ErrorEnvelope' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'example' => 'about:blank'],
                            'title' => ['type' => 'string', 'example' => 'Validation Error'],
                            'status' => ['type' => 'integer', 'example' => 422],
                            'detail' => ['type' => 'string', 'example' => 'The given data was invalid.'],
                            'code' => ['type' => 'string', 'example' => 'VALIDATION_ERROR'],
                            'invalid_params' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'request_id' => ['type' => 'string'],
                        ],
                    ],
                ],
                'responses' => [
                    'ValidationError' => [
                        'description' => 'Validation Failed (RFC 7807 problem details)',
                        'content' => [
                            'application/problem+json' => [
                                'schema' => ['$ref' => '#/components/schemas/ErrorEnvelope'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
