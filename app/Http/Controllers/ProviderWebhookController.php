<?php

namespace App\Http\Controllers;

use App\Services\Payments\Providers\Contracts\HandlesWebhooks;
use App\Services\Payments\Providers\ProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server-to-server reports from payment providers: POST
 * /api/webhooks/{provider}, for any provider that implements HandlesWebhooks.
 * (Stripe keeps its long-standing /api/webhooks/stripe route and controller.)
 *
 * A provider without webhooks, or not configured, is a 404. A delivery that
 * fails the provider's verification is a 400, which providers do not retry;
 * a processing failure is a 500, which they do.
 */
class ProviderWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, ProviderRegistry $providers): JsonResponse
    {
        $handler = $providers->byKey($provider);

        abort_unless($handler instanceof HandlesWebhooks && $handler->isAvailable(), 404);

        if (! $handler->verifyWebhook($request)) {
            Log::channel('security')->warning('Rejected a payment webhook that failed verification', [
                'provider' => $provider,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Invalid signature or payload.'], 400);
        }

        try {
            $outcome = $handler->handleWebhook($request);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['error' => 'Processing failed; the provider will retry.'], 500);
        }

        return response()->json(['received' => true, 'outcome' => $outcome]);
    }
}
