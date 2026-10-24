<?php

use App\Http\Controllers\Api\AccessLogApiController;
use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\Api\GateDeviceApiController;
use App\Http\Controllers\Api\GatePassApiController;
use App\Http\Controllers\Api\MapApiController;
use App\Http\Controllers\Api\QueryApiController;
use App\Http\Controllers\Api\V1\DocsApiController;
use App\Http\Controllers\Api\V1\MonitoringApiController;
use App\Http\Controllers\Api\V1\RealtimeApiController;
use App\Http\Controllers\Api\V1\SpreadsheetApiController;
use App\Http\Controllers\Api\V1\TokenApiController;
use App\Http\Controllers\Api\V1\VersionApiController;
use App\Http\Controllers\Api\V1\WebhookApiController;
use App\Http\Controllers\Api\VisitorApiController;
use App\Http\Controllers\ProviderWebhookController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\TwilioWebhookController;
use App\Http\Controllers\UniversalPaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| JSON API
|--------------------------------------------------------------------------
| Backs the Capacitor mobile build and the handheld
| gate scanner. Same controllers-to-services path as the Inertia routes, so
| validation rules and access policy are enforced identically on both.
|
| Auth uses Sanctum tokens rather than session cookies, since a native shell
| has no browser session to share.
*/

Route::post('/auth/login', [AuthApiController::class, 'login'])->middleware('throttle:6,1');

// Stripe calls this, not a user: no session, no token. Authenticity comes from
// the Stripe-Signature header, verified against STRIPE_WEBHOOK_SECRET.
Route::post('/webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');

// Universal Multi-Provider Webhook Processor:
// POST /api/webhooks/payments/{provider}
// Implements the 14-step verified processing pipeline (auth, signature, idempotency,
// transaction search, amount/currency/merchant verification, ledger, invoice, receipt, notification)
Route::post('/webhooks/payments/{provider}', UniversalPaymentWebhookController::class)
    ->where('provider', '[a-z0-9_]+')
    ->name('webhooks.payments.provider');

// Any other provider that reports server-to-server (PaymentProvider with
// HandlesWebhooks). Each verifies its own signature; see ProviderWebhookController.
Route::post('/webhooks/{provider}', ProviderWebhookController::class)
    ->where('provider', '[a-z0-9_]+')
    ->name('webhooks.provider');

// Twilio calls this for messaging status callbacks (queued -> sent -> delivered / failed).
// Verified with X-Twilio-Signature via Twilio SDK RequestValidator.
Route::post('/webhooks/twilio/status', [TwilioWebhookController::class, 'messagingStatus'])
    ->name('webhooks.twilio.status');

// ── Physical Gate Devices & Scanners ──
Route::middleware('gate.device')->prefix('gate-devices')->group(function () {
    Route::post('/heartbeat', [GateDeviceApiController::class, 'heartbeat'])->name('api.gate-devices.heartbeat');
    Route::post('/sync-scans', [GateDeviceApiController::class, 'syncScans'])->name('api.gate-devices.sync-scans');
    Route::post('/sensor-events', [GateDeviceApiController::class, 'sensorEvent'])
        ->middleware('throttle:120,1')
        ->name('api.gate-devices.sensor-events');
    Route::get('/revocations', [GateDeviceApiController::class, 'revocations'])->name('api.gate-devices.revocations');
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {

    Route::post('/auth/logout', [AuthApiController::class, 'logout']);
    Route::get('/auth/me', [AuthApiController::class, 'me']);

    // ── Gate pass ──
    // `ability:` checks the token's own scope (see TokenAbility) on top of the
    // Gate checks below, which check the user's role. A token narrows what its
    // holder can do even when the account behind it could do more.
    Route::middleware('ability:gate-pass:read')->group(function () {
        Route::get('/gate-pass', [GatePassApiController::class, 'show']);
        Route::get('/gate-pass/token', [GatePassApiController::class, 'token']);
        Route::post('/gate-pass/rotate', [GatePassApiController::class, 'rotate']);
    });

    // Scanning is the security-critical endpoint: it both validates and writes
    // the access-log entry, so a scanner cannot record an entry it did not verify.
    Route::post('/gate-pass/validate', [GatePassApiController::class, 'validateToken'])
        ->middleware(['can:scanPasses', 'ability:gate-pass:scan']);
    Route::post('/gate-pass/scans/{scan}/confirm', [GatePassApiController::class, 'confirmScan'])
        ->middleware(['can:scanPasses', 'ability:gate-pass:scan', 'throttle:60,1'])
        ->whereUuid('scan');

    // ── Visitors ──
    Route::middleware('ability:visitors:manage')->group(function () {
        Route::get('/visitors', [VisitorApiController::class, 'index']);
        Route::post('/visitors', [VisitorApiController::class, 'store']);
    });
    Route::post('/visitors/{visitor}/check-in', [VisitorApiController::class, 'checkIn'])
        ->middleware(['can:manageSecurity', 'ability:security:manage']);
    Route::post('/visitors/{visitor}/check-out', [VisitorApiController::class, 'checkOut'])
        ->middleware(['can:manageSecurity', 'ability:security:manage']);

    // ── Access log ──
    Route::get('/access-log', [AccessLogApiController::class, 'index'])
        ->middleware(['can:manageSecurity', 'ability:security:manage']);

    // ── Map & geofence ──
    Route::middleware('ability:map:read')->group(function () {
        Route::get('/map/boundary', [MapApiController::class, 'boundary']);
        Route::get('/map/landmarks', [MapApiController::class, 'landmarks']);
        Route::post('/map/check-position', [MapApiController::class, 'checkPosition']);
    });
});

/*
|--------------------------------------------------------------------------
| Spatie Query Builder RESTful API (v1)
|--------------------------------------------------------------------------
| Declarative filtering (?filter[...]), sorting (?sort=...),
| relationship inclusion (?include=...), sparse fieldsets (?fields[...]=...),
| and pagination (?page[number]=...&page[size]=... or ?per_page=...).
|
| Token holders only, and each entity behind its own gate (EntityAccess,
| checked in QueryApiController) — these list residents, the ledger, and
| the estate's passes and visitors.
*/
Route::prefix('v1')->middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/query-meta', [QueryApiController::class, 'meta'])->name('api.v1.query.meta');
    Route::get('/gate-passes', [QueryApiController::class, 'gatePasses'])->name('api.v1.gate-passes');
    Route::get('/visitors', [QueryApiController::class, 'visitors'])->name('api.v1.visitors');
    Route::get('/transactions', [QueryApiController::class, 'transactions'])->name('api.v1.transactions');
    Route::get('/users', [QueryApiController::class, 'users'])->name('api.v1.users');
    Route::get('/warnings', [QueryApiController::class, 'warnings'])->name('api.v1.warnings');
    Route::get('/query/{entity}', [QueryApiController::class, 'query'])->name('api.v1.query.entity');
});

/*
|--------------------------------------------------------------------------
| Standard Enterprise API Architecture Layer
|--------------------------------------------------------------------------
| OpenAPI Documentation, API Versioning (/api/v1, /api/v2),
| Health & Telemetry Metrics, Sanctum Token Lifecycle & Scoped Abilities,
| Outgoing & Incoming Webhooks.
*/

// Root API Discovery & Docs
Route::get('/docs', [DocsApiController::class, 'ui'])->name('api.docs');
Route::get('/openapi.json', [DocsApiController::class, 'openApiJson'])->name('api.openapi');
Route::get('/versions', [VersionApiController::class, 'index'])->name('api.versions');
Route::get('/version', [VersionApiController::class, 'show'])->name('api.version');
Route::get('/health', [MonitoringApiController::class, 'health'])->name('api.health');

// ── API v1 ──
Route::prefix('v1')->group(function () {
    // Documentation & Version Meta
    Route::get('/docs', [DocsApiController::class, 'ui'])->defaults('version', 'v1')->name('api.v1.docs');
    Route::get('/openapi.json', [DocsApiController::class, 'openApiJson'])->defaults('version', 'v1')->name('api.v1.openapi');
    Route::get('/version', [VersionApiController::class, 'show'])->name('api.v1.version');
    Route::get('/health', [MonitoringApiController::class, 'health'])->name('api.v1.health');

    // External incoming webhooks (signature verified, rate-limited)
    Route::post('/webhooks/incoming/{service}', [WebhookApiController::class, 'incoming'])
        ->middleware('throttle:api.webhooks')
        ->name('api.v1.webhooks.incoming');

    // Authenticated API v1 routes
    Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
        // Personal Access Token Management (creation, scoped abilities, revocation)
        Route::get('/tokens', [TokenApiController::class, 'index'])->name('api.v1.tokens.index');
        Route::post('/tokens', [TokenApiController::class, 'store'])->name('api.v1.tokens.store');
        Route::delete('/tokens', [TokenApiController::class, 'destroyAll'])->name('api.v1.tokens.destroy-all');
        Route::delete('/tokens/{tokenId}', [TokenApiController::class, 'destroy'])->name('api.v1.tokens.destroy');

        // Webhook Subscriptions & Testing (System Admin: outbound integrations)
        Route::middleware('can:operatePlatform')->group(function () {
            Route::get('/webhooks/subscriptions', [WebhookApiController::class, 'index'])->name('api.v1.webhooks.subscriptions.index');
            Route::post('/webhooks/subscriptions', [WebhookApiController::class, 'store'])->name('api.v1.webhooks.subscriptions.store');
            Route::get('/webhooks/subscriptions/{subscription}', [WebhookApiController::class, 'show'])->name('api.v1.webhooks.subscriptions.show');
            Route::delete('/webhooks/subscriptions/{subscription}', [WebhookApiController::class, 'destroy'])->name('api.v1.webhooks.subscriptions.destroy');
            Route::post('/webhooks/subscriptions/{subscription}/test', [WebhookApiController::class, 'test'])->name('api.v1.webhooks.subscriptions.test');
        });

        // Real-Time WebSockets (Laravel Reverb & Echo)
        Route::post('/realtime/broadcast', [RealtimeApiController::class, 'broadcast'])->name('api.v1.realtime.broadcast');
    });

    /*
    | Platform metrics. System Admin tokens only (`operatePlatform`). Load
    | balancers keep Laravel's /up, and /api/health above, for liveness.
    */
    Route::middleware(['auth:sanctum', 'active', 'throttle:api', 'can:operatePlatform'])->group(function () {
        Route::get('/metrics', [MonitoringApiController::class, 'metrics'])->name('api.v1.metrics');
    });

    // Real-Time WebSocket Echo Config & Channels Catalog
    Route::get('/realtime/config', [RealtimeApiController::class, 'config'])->name('api.v1.realtime.config');
    Route::get('/realtime/channels', [RealtimeApiController::class, 'channels'])->name('api.v1.realtime.channels');

    /*
    | Spreadsheets hand out the resident roster and the ledger. Token holders
    | only, gated per blueprint in SpreadsheetApiController.
    */
    Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
        // Excel & CSV Data Processing Module (Maatwebsite / Laravel Excel, XLSX/CSV, Imports, Exports, Queued Exports, Large Datasets)
        Route::get('/spreadsheets/blueprints', [SpreadsheetApiController::class, 'blueprints'])->name('api.v1.spreadsheets.blueprints');
        Route::get('/spreadsheets/export/{blueprint}', [SpreadsheetApiController::class, 'export'])->name('api.v1.spreadsheets.export');
        Route::post('/spreadsheets/export-queue/{blueprint}', [SpreadsheetApiController::class, 'queueExport'])->name('api.v1.spreadsheets.export-queue');
        Route::post('/spreadsheets/import/{blueprint}', [SpreadsheetApiController::class, 'import'])->name('api.v1.spreadsheets.import');
        Route::get('/spreadsheets/sample-template/{blueprint}', [SpreadsheetApiController::class, 'sampleTemplate'])->name('api.v1.spreadsheets.sample-template');
    });
});

// ── API v2 (Next Gen Architecture Preview) ──
Route::prefix('v2')->group(function () {
    Route::get('/docs', [DocsApiController::class, 'ui'])->defaults('version', 'v2')->name('api.v2.docs');
    Route::get('/openapi.json', [DocsApiController::class, 'openApiJson'])->defaults('version', 'v2')->name('api.v2.openapi');
    Route::get('/version', [VersionApiController::class, 'show'])->name('api.v2.version');
    Route::get('/health', [MonitoringApiController::class, 'health'])->name('api.v2.health');
});
