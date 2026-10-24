<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateWebhookSubscriptionApiRequest;
use App\Http\Resources\WebhookSubscriptionResource;
use App\Http\Responses\ApiResponse;
use App\Models\WebhookSubscription;
use App\Services\Webhooks\WebhookDispatcherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookApiController extends Controller
{
    /**
     * Subscriptions send estate events to outside URLs: an integration
     * setting, and WebhookDispatcherService::dispatch() delivers to every
     * active subscription whoever owns it. So they are the System Admin's
     * (`operatePlatform`). incoming() stays public; it verifies a signature.
     */
    public function __construct(
        protected WebhookDispatcherService $dispatcher
    ) {
        $this->middleware(['auth:sanctum', 'active', 'can:operatePlatform'])->except('incoming');
    }

    /**
     * List all webhook subscriptions owned by the authenticated user / estate.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = WebhookSubscription::query()
            ->where('user_id', $user->id);

        if ($request->has('estate_id')) {
            $query->where('estate_id', $request->query('estate_id'));
        }

        $subscriptions = $query->latest()->get();

        return ApiResponse::success(
            WebhookSubscriptionResource::collection($subscriptions),
            'Webhook subscriptions retrieved successfully.'
        );
    }

    /**
     * Create a new webhook subscription.
     */
    public function store(CreateWebhookSubscriptionApiRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $secret = $validated['secret'] ?? WebhookSubscription::generateSecret();

        $subscription = WebhookSubscription::create([
            'user_id' => $user->id,
            'estate_id' => $request->input('estate_id', $user->estate_id ?? null),
            'name' => $validated['name'],
            'url' => $validated['url'],
            'secret' => $secret,
            'events' => $validated['events'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return ApiResponse::success(
            [
                'subscription' => new WebhookSubscriptionResource($subscription),
                'signingSecret' => $secret, // Disclosed upon creation for caller to verify incoming signatures
            ],
            'Webhook subscription created successfully.',
            201
        );
    }

    /**
     * Get a specific webhook subscription.
     */
    public function show(Request $request, WebhookSubscription $subscription): JsonResponse
    {
        $this->authorizeAccess($request, $subscription);

        return ApiResponse::success(
            new WebhookSubscriptionResource($subscription),
            'Webhook subscription retrieved successfully.'
        );
    }

    /**
     * Delete a webhook subscription.
     */
    public function destroy(Request $request, WebhookSubscription $subscription): JsonResponse
    {
        $this->authorizeAccess($request, $subscription);

        $subscription->delete();

        return ApiResponse::success(null, 'Webhook subscription deleted successfully.');
    }

    /**
     * Send a test ping event to the subscribed webhook endpoint.
     */
    public function test(Request $request, WebhookSubscription $subscription): JsonResponse
    {
        $this->authorizeAccess($request, $subscription);

        $result = $this->dispatcher->dispatchDirect(
            $subscription,
            'webhook.test',
            [
                'ping' => 'pong',
                'timestamp' => now()->toIso8601String(),
                'message' => 'Community Hub Webhook Verification Test Event',
            ]
        );

        return ApiResponse::success([
            'delivery_id' => $result->id,
            'status_code' => $result->status_code,
            'is_success' => (bool) $result->is_success,
            'duration_ms' => $result->duration_ms,
        ], 'Webhook test event dispatched.');
    }

    /**
     * Handle incoming webhooks from external providers with signature verification.
     */
    public function incoming(Request $request, string $service): JsonResponse
    {
        $signature = (string) ($request->header('X-Webhook-Signature')
            ?? $request->header('X-Hub-Signature-256')
            ?? $request->header('Stripe-Signature')
            ?? '');

        $payload = $request->getContent();
        // Senders are listed in config/services.php: an env lookup here was null under config:cache.
        $configuredSecret = (string) config('services.webhooks.'.strtolower($service).'.secret');

        // Fail closed: with no secret configured there is nothing to verify
        // against, which used to mean every delivery was accepted unchecked.
        if ($configuredSecret === '') {
            return ApiResponse::error("Webhooks from [{$service}] are not configured.", 'NOT_CONFIGURED', 404);
        }

        $expectedSignature = 'sha256='.hash_hmac('sha256', $payload, $configuredSecret);
        $rawExpected = hash_hmac('sha256', $payload, $configuredSecret);

        $isValid = hash_equals($expectedSignature, $signature) || hash_equals($rawExpected, $signature);

        if (! $isValid) {
            Log::warning("Incoming webhook signature verification failed for service [{$service}].");

            return ApiResponse::error(
                'Invalid webhook signature.',
                'INVALID_SIGNATURE',
                401
            );
        }

        // Not the headers: they carry the sender's credentials and signature.
        Log::info("Incoming webhook received from [{$service}]:", [
            'event' => $request->input('event') ?? $request->input('type') ?? 'unknown',
        ]);

        return ApiResponse::success(
            [
                'received' => true,
                'service' => $service,
                'timestamp' => now()->toIso8601String(),
            ],
            "Webhook for {$service} acknowledged successfully."
        );
    }

    protected function authorizeAccess(Request $request, WebhookSubscription $subscription): void
    {
        // Not tokenCan('*'): a session-authenticated request carries Sanctum's
        // TransientToken, which "can" everything, so that check let any
        // signed-in browser user act on anybody's subscription.
        $user = $request->user();

        if ($user === null || ($subscription->user_id !== $user->id && ! $user->can('operatePlatform'))) {
            abort(403, 'Unauthorized access to this webhook subscription.');
        }
    }
}
