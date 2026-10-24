<?php

namespace App\Http\Controllers;

use App\Services\StripePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Throwable;
use UnexpectedValueException;

/**
 * Receives Stripe webhooks: POST /api/webhooks/stripe.
 *
 * Point a Stripe webhook endpoint at that URL, subscribed to the events
 * StripePaymentService::handleWebhook() acts on, and put the endpoint's
 * signing secret in STRIPE_WEBHOOK_SECRET:
 *
 *   checkout.session.completed            checkout.session.async_payment_succeeded
 *   checkout.session.async_payment_failed checkout.session.expired
 *   payment_intent.succeeded              payment_intent.payment_failed
 *   charge.refunded                       charge.dispute.created
 *   charge.dispute.funds_withdrawn        charge.dispute.funds_reinstated
 *   charge.dispute.closed                 payout.paid
 *   payout.failed                         terminal.reader.action_succeeded
 *   terminal.reader.action_failed
 *
 * Locally, `stripe listen --forward-to <app>/api/webhooks/stripe` delivers
 * them all and prints the signing secret to use.
 *
 * Status codes are what Stripe acts on: any 2xx stops retries, anything else
 * is retried with backoff for up to three days. So a bad signature is a 400
 * (it will never succeed), a processing failure is a 500 (it might, and the
 * event row was rolled back with it), and a duplicate or an event type this
 * app ignores is a 200.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripePaymentService $stripe): JsonResponse
    {
        abort_unless($stripe->acceptsWebhooks(), 404);

        try {
            $outcome = $stripe->handleWebhook($request->getContent(), $request->header('Stripe-Signature'));
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            Log::channel('security')->warning('Rejected a Stripe webhook that failed verification', [
                'ip' => $request->ip(),
                'reason' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Invalid signature or payload.'], 400);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['error' => 'Processing failed; Stripe will retry.'], 500);
        }

        return response()->json(['received' => true, 'outcome' => $outcome]);
    }
}
