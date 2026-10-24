<?php

namespace App\Http\Controllers;

use App\Services\Payments\UniversalPaymentWebhookService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Universal Webhook Endpoint for all payment providers:
 * POST /api/webhooks/payments/{provider}
 *
 * Executes the full 14-step verified processing pipeline:
 * Incoming -> Authenticate -> Verify Sig -> Check Idempotent -> Find Tx
 * -> Verify (Amount, Currency, Merchant, State) -> Process Event -> Ledger -> Invoice -> Receipt -> Notification
 */
class UniversalPaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, UniversalPaymentWebhookService $service): JsonResponse
    {
        try {
            $result = $service->process($request, $provider);

            return response()->json($result, 200);
        } catch (DomainException $e) {
            Log::channel('security')->warning('Payment webhook verification failed', [
                'provider' => $provider,
                'ip' => $request->ip(),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'Processing failed; the provider will retry.',
                'message' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
