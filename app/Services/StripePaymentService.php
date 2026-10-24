<?php

namespace App\Services;

use App\Enums\PaymentState;
use App\Models\Fundraiser;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\StripeEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\Stripe\StripeCheckoutSessionService;
use App\Services\Payments\Stripe\StripeDisputeAndFailureService;
use App\Services\Payments\Stripe\StripeRefundAndPayoutService;
use App\Services\Payments\Stripe\StripeSettlementService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Stripe\StripeObject;
use Stripe\Webhook;

/**
 * Stripe Checkout, webhooks and refunds.
 *
 * Settlement happens only when Stripe itself says the session was paid, by
 * either of two routes that share one idempotent code path (settleSession):
 *
 *   - the resident's browser returning to the success URL; and
 *   - the `checkout.session.completed` webhook, signed by Stripe.
 *
 * Every Stripe payment and refund is written to the transactions ledger with a
 * reference derived from Stripe's own ids.
 */
class StripePaymentService
{
    /** The ledger channel for card payments taken through Stripe Checkout. */
    public const CHANNEL = 'stripe_card';

    protected ?string $secretKey;

    protected ?string $webhookSecret;

    /** "stripe:evt_…" while a webhook is applied; recorded in payment history. */
    private ?string $eventSource = null;

    protected StripeCheckoutSessionService $checkoutSessionService;

    protected StripeSettlementService $settlementService;

    protected StripeDisputeAndFailureService $disputeService;

    protected StripeRefundAndPayoutService $refundAndPayoutService;

    public function __construct(
        ?StripeCheckoutSessionService $checkoutSessionService = null,
        ?StripeSettlementService $settlementService = null,
        ?StripeDisputeAndFailureService $disputeService = null,
        ?StripeRefundAndPayoutService $refundAndPayoutService = null,
    ) {
        $this->secretKey = config('services.stripe.secret');
        $this->webhookSecret = config('services.stripe.webhook_secret');
        $this->checkoutSessionService = $checkoutSessionService ?? app(StripeCheckoutSessionService::class);
        $this->settlementService = $settlementService ?? app(StripeSettlementService::class);
        $this->disputeService = $disputeService ?? app(StripeDisputeAndFailureService::class);
        $this->refundAndPayoutService = $refundAndPayoutService ?? app(StripeRefundAndPayoutService::class);
    }

    /**
     * Whether a usable Stripe secret key is configured.
     */
    public function isLive(): bool
    {
        return is_string($this->secretKey) && str_starts_with($this->secretKey, 'sk_');
    }

    /** Whether webhooks can be verified. Without a signing secret, none are accepted. */
    public function acceptsWebhooks(): bool
    {
        return $this->isLive() && is_string($this->webhookSecret) && str_starts_with($this->webhookSecret, 'whsec_');
    }

    /**
     * Create a Stripe Checkout Session for an invoice.
     */
    public function createCheckoutSession(
        Invoice $invoice,
        string $successUrl,
        string $cancelUrl,
        ?int $amountMinor = null,
        ?Payment $payment = null,
    ): string {
        if (! $this->isLive()) {
            throw new RuntimeException('Stripe is not configured; refusing to simulate a checkout session.');
        }

        return $this->checkoutSessionService->createCheckoutSession(
            $invoice,
            $successUrl,
            $cancelUrl,
            $amountMinor,
            $payment,
            $this->secretKey,
        );
    }

    /**
     * Create a Stripe Checkout Session for a donation.
     */
    public function createDonationCheckoutSession(
        Fundraiser $fundraiser,
        User $donor,
        int $amountMinor,
        array $details,
        string $successUrl,
        string $cancelUrl,
    ): string {
        if (! $this->isLive()) {
            throw new RuntimeException('Stripe is not configured; refusing to simulate a checkout session.');
        }

        return $this->checkoutSessionService->createDonationCheckoutSession(
            $fundraiser,
            $donor,
            $amountMinor,
            $details,
            $successUrl,
            $cancelUrl,
            $this->secretKey,
        );
    }

    /**
     * Verify a returning donor's session with Stripe and record the donation.
     */
    public function completeDonation(Fundraiser $fundraiser, User $donor, string $sessionId): bool
    {
        if (! $this->isLive()) {
            return false;
        }

        return $this->checkoutSessionService->completeDonation($fundraiser, $donor, $sessionId, $this->secretKey);
    }

    /**
     * Create a Stripe Billing Portal session for self-service payment management.
     */
    public function createCustomerPortalSession(User $user, string $returnUrl): string
    {
        if (! $this->isLive()) {
            throw new RuntimeException('Stripe is not configured; billing portal is unavailable.');
        }

        return $this->checkoutSessionService->createCustomerPortalSession($user, $returnUrl, $this->secretKey);
    }

    /**
     * Verify a completed checkout with Stripe and settle the invoice.
     */
    public function completePayment(Invoice $invoice, string $sessionId): bool
    {
        return $this->checkoutSessionService->completePayment($invoice, $sessionId, $this->secretKey);
    }

    /**
     * Verify and apply one webhook delivery.
     */
    public function handleWebhook(string $payload, ?string $signature): string
    {
        if (! $this->acceptsWebhooks()) {
            throw new RuntimeException('Stripe webhooks are not configured.');
        }

        $event = Webhook::constructEvent($payload, (string) $signature, $this->webhookSecret);
        $this->eventSource = "stripe:{$event->id}";

        return DB::transaction(function () use ($event): string {
            $now = now();

            $inserted = StripeEvent::query()->insertOrIgnore([
                'event_id' => $event->id,
                'type' => $event->type,
                'status' => 'pending',
                'payload' => json_encode($event->data->object),
                'processed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 0) {
                return 'duplicate';
            }

            $object = $event->data->object;

            $outcome = match ($event->type) {
                'checkout.session.completed',
                'checkout.session.async_payment_succeeded' => $this->settleSession($object),
                'payment_intent.succeeded' => $this->settlePaymentIntent($object),
                'payment_intent.requires_action' => $this->handleIntentProgress($object, PaymentState::RequiresAction),
                'payment_intent.processing' => $this->handleIntentProgress($object, PaymentState::Processing),
                'terminal.reader.action_succeeded',
                'terminal.reader.action_failed' => $this->handleReaderAction($object, $event->type),
                'payment_intent.payment_failed',
                'checkout.session.async_payment_failed',
                'checkout.session.expired' => $this->handlePaymentFailure($object, $event->type),
                'charge.refunded' => $this->syncRefunds($object),
                'charge.dispute.created',
                'charge.dispute.closed',
                'charge.dispute.funds_withdrawn',
                'charge.dispute.funds_reinstated' => $this->handleDispute($object, $event->type),
                'payout.paid',
                'payout.failed' => $this->handlePayout($object, $event->type),
                default => 'ignored',
            };

            StripeEvent::where('event_id', $event->id)->update([
                'status' => $outcome,
            ]);

            return $outcome;
        });
    }

    /**
     * Record a paid Checkout Session and settle its invoice. Idempotent.
     */
    public function settleSession(StripeObject $session): string
    {
        return $this->settlementService->settleSession($session, $this->eventSource);
    }

    /**
     * Settle an invoice when a PaymentIntent succeeds directly. Idempotent.
     */
    public function settlePaymentIntent(StripeObject $paymentIntent): string
    {
        return $this->settlementService->settlePaymentIntent($paymentIntent, $this->eventSource);
    }

    /**
     * A payment needs the cardholder (3-D Secure) or is processing.
     */
    public function handleIntentProgress(StripeObject $paymentIntent, PaymentState $state): string
    {
        return $this->settlementService->handleIntentProgress($paymentIntent, $state, $this->eventSource);
    }

    /**
     * A Terminal reader finished (or abandoned) processing a payment.
     */
    public function handleReaderAction(StripeObject $reader, string $eventType): string
    {
        return $this->disputeService->handleReaderAction($reader, $eventType, $this->eventSource);
    }

    /**
     * Record a payment failure event on the ledger and audit trail. Idempotent.
     */
    public function handlePaymentFailure(StripeObject $object, string $eventType): string
    {
        return $this->disputeService->handlePaymentFailure($object, $eventType, $this->eventSource);
    }

    /**
     * Process chargebacks and dispute lifecycle events. Idempotent.
     */
    public function handleDispute(StripeObject $dispute, string $eventType): string
    {
        return $this->disputeService->handleDispute($dispute, $eventType, $this->eventSource);
    }

    /**
     * Mark the payments a payout carried to the bank. Idempotent.
     */
    public function handlePayout(StripeObject $payout, string $eventType): string
    {
        return $this->refundAndPayoutService->handlePayout($payout, $eventType, $this->secretKey);
    }

    /**
     * Bring the ledger into line with a charge's refunds. Idempotent.
     */
    public function syncRefunds(StripeObject $charge): string
    {
        return $this->refundAndPayoutService->syncRefunds($charge, $this->eventSource);
    }

    /**
     * How much of a recorded Stripe payment can still be refunded, in minor units.
     */
    public function refundableMinor(Transaction $payment): int
    {
        return $this->refundAndPayoutService->refundableMinor($payment);
    }

    /**
     * Refund some or all of a Stripe payment.
     */
    public function refund(Transaction $payment, int $amountMinor, User $actor, ?string $note = null): void
    {
        if (! $this->isLive()) {
            throw new RuntimeException('Stripe is not configured.');
        }

        $this->refundAndPayoutService->refund($payment, $amountMinor, $actor, $note, $this->secretKey);
    }
}
