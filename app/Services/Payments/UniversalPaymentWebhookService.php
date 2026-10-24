<?php

namespace App\Services\Payments;

use App\Enums\PaymentState;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentDispute;
use App\Models\PaymentEvent;
use App\Models\PaymentReceipt;
use App\Models\Transaction;
use App\Services\Ledger\LedgerService;
use App\Services\Payments\Providers\ProviderRegistry;
use App\Services\Payments\Providers\WiPay\WiPayProvider;
use App\Services\StripePaymentService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;
use Throwable;

/**
 * Universal Webhook Processor for CommunityHub.
 *
 * Implements the 14-step payment truth pipeline across all providers:
 *
 *   Incoming webhook (POST /api/webhooks/payments/{provider})
 *          ↓
 *   Authenticate provider
 *          ↓
 *   Verify signature
 *          ↓
 *   Read provider event ID
 *          ↓
 *   Check event already processed?
 *          │
 *       ┌──┴───┐
 *       │      │
 *      YES     NO
 *       │      │
 *     Stop     ↓
 *          Find transaction
 *               ↓
 *          Verify amount
 *          Verify currency
 *          Verify merchant
 *          Verify transaction
 *               ↓
 *          Process event (Canonical Mappings: success, processing, failure, refund, dispute)
 *               ↓
 *          Update status
 *               ↓
 *          Ledger (Double-Entry Posting)
 *               ↓
 *          Invoice (Settlement)
 *               ↓
 *          Receipt (PaymentReceipt)
 *               ↓
 *          Notification (Twilio / Messaging)
 */
class UniversalPaymentWebhookService
{
    public const EVENT_SUCCESS = 'PAYMENT_SUCCESS';

    public const EVENT_PROCESSING = 'PAYMENT_PROCESSING';

    public const EVENT_FAILED = 'PAYMENT_FAILED';

    public const EVENT_REFUNDED = 'PAYMENT_REFUNDED';

    public const EVENT_DISPUTED = 'PAYMENT_DISPUTED';

    public const EVENT_IGNORED = 'PAYMENT_IGNORED';

    public function __construct(
        protected ProviderRegistry $providers,
        protected StripePaymentService $stripe,
        protected LedgerService $ledger,
        protected PaymentOrchestratorService $orchestrator,
    ) {}

    /**
     * Main entry point for POST /api/webhooks/payments/{provider}.
     *
     * @return array{received: bool, outcome: string, event_id: string, message?: string}
     */
    public function process(Request $request, string $provider): array
    {
        $provider = strtolower(trim($provider));

        // 1. Authenticate Provider
        $this->authenticateProvider($provider);

        if ($provider === WiPayProvider::KEY && ! $request->hasHeader('X-WiPay-Signature')) {
            return $this->confirmWiPayReturn($request);
        }

        // 2. Verify Signature
        if (! $this->verifySignature($request, $provider)) {
            Log::channel('security')->warning("Rejected webhook: invalid signature for provider [{$provider}]", [
                'provider' => $provider,
                'ip' => $request->ip(),
            ]);

            throw new DomainException("Invalid signature or payload for provider [{$provider}].");
        }

        // 3. Read Provider Event ID & Payload
        $payload = $this->parsePayload($request);
        $eventId = $this->extractEventId($request, $payload, $provider);
        $rawEventType = $this->extractEventType($request, $payload, $provider);

        // 4. Check Event Already Processed? (Idempotency Guard)
        $existingEvent = PaymentEvent::query()
            ->where('provider', $provider)
            ->where('event_id', $eventId)
            ->where('status', 'processed')
            ->first();

        if ($existingEvent) {
            Log::info("Idempotent webhook delivery ignored: provider [{$provider}], event [{$eventId}] already processed.");

            return [
                'received' => true,
                'outcome' => 'duplicate',
                'event_id' => $eventId,
                'message' => 'Event already processed.',
            ];
        }

        // For Stripe, if already in stripe_events table, respect that idempotency lock
        if ($provider === 'stripe' && DB::table('stripe_events')->where('event_id', $eventId)->where('status', '!=', 'pending')->exists()) {
            return [
                'received' => true,
                'outcome' => 'duplicate',
                'event_id' => $eventId,
                'message' => 'Stripe event already processed.',
            ];
        }

        // 5. Special Handler for Native Stripe Service Delegation
        if ($provider === 'stripe' && $this->stripe->acceptsWebhooks()) {
            $outcome = $this->stripe->handleWebhook($request->getContent(), $request->header('Stripe-Signature'));

            // Record canonical PaymentEvent for uniform enterprise telemetry
            PaymentEvent::query()->updateOrCreate(
                ['provider' => 'stripe', 'event_id' => $eventId],
                [
                    'event_type' => $rawEventType,
                    'status' => $outcome === 'duplicate' ? 'duplicate' : 'processed',
                    'payload' => $payload,
                    'signature' => (string) $request->header('Stripe-Signature'),
                    'processed_at' => now(),
                ]
            );

            return [
                'received' => true,
                'outcome' => $outcome,
                'event_id' => $eventId,
            ];
        }

        // 6. Map Provider Event Type to Canonical Event
        $canonicalEvent = $this->mapToCanonicalEvent($provider, $rawEventType, $payload);

        // 7. Atomic Execution of Pipeline: Find, Verify, Process, Ledger, Invoice, Receipt, Notification
        return DB::transaction(function () use ($provider, $eventId, $rawEventType, $canonicalEvent, $payload, $request): array {
            // Record pending event
            $eventRecord = PaymentEvent::query()->updateOrCreate(
                ['provider' => $provider, 'event_id' => $eventId],
                [
                    'event_type' => $rawEventType,
                    'status' => 'received',
                    'payload' => $payload,
                    'signature' => $this->extractSignatureHeader($request, $provider),
                ]
            );

            if ($canonicalEvent === self::EVENT_IGNORED) {
                $eventRecord->update(['status' => 'ignored', 'processed_at' => now()]);

                return ['received' => true, 'outcome' => 'ignored', 'event_id' => $eventId];
            }

            // Step 8: Find Transaction / Payment — only among this provider's own payments,
            // so one provider's signed event can never settle another provider's payment.
            $payment = $this->findPayment($provider, $payload);

            if (! $payment) {
                $eventRecord->update(['status' => 'unmatched', 'error_message' => 'Transaction not found for provider payload', 'processed_at' => now()]);
                Log::warning("Webhook received for unknown transaction: provider [{$provider}], event [{$eventId}]");

                return ['received' => true, 'outcome' => 'unmatched', 'event_id' => $eventId];
            }

            $eventRecord->update(['payment_id' => $payment->id]);

            // Step 9: Verify Amount (a refund may be partial, so it is checked against the refundable balance instead)
            if ($canonicalEvent !== self::EVENT_REFUNDED) {
                $this->verifyAmount($payment, $payload, $provider, required: $canonicalEvent === self::EVENT_SUCCESS);
            }

            // Step 10: Verify Currency
            $this->verifyCurrency($payment, $payload, $provider);

            // Step 11: Verify Merchant
            $this->verifyMerchant($payment, $payload, $provider);

            // Step 12: Verify Transaction State Eligibility
            $this->verifyTransactionState($payment, $canonicalEvent);

            // Step 13: Process Event & Update Status
            $tx = $this->applyCanonicalEvent($payment, $canonicalEvent, $provider, $eventId, $payload);

            // Step 14: Finalize Event Audit Record
            $eventRecord->update([
                'transaction_id' => $tx?->id,
                'status' => 'processed',
                'processed_at' => now(),
            ]);

            return [
                'received' => true,
                'outcome' => 'processed',
                'event_id' => $eventId,
                'canonical_event' => $canonicalEvent,
                'transaction_id' => $tx?->transaction_id ?? $payment->transaction_id,
            ];
        });
    }

    /**
     * A WiPay result in WiPay's own return format: status, transaction_id,
     * order_id, total and hash = md5(transaction_id . total . API key).
     *
     * That hash covers two fields and reaches us through the payer's browser,
     * so every other field is the sender's to choose. The payment is found by
     * the signed transaction_id alone, then WiPayProvider::confirmPayment()
     * binds order_id and re-derives the hash from the payment's own amount.
     * Only successes are taken: a failure carries no hash, so accepting one
     * would let anyone fail another resident's payment.
     *
     * @return array{received: bool, outcome: string, event_id: string, message?: string}
     */
    protected function confirmWiPayReturn(Request $request): array
    {
        $payload = $this->parsePayload($request);
        $wipayTransactionId = (string) ($payload['transaction_id'] ?? '');

        if ($wipayTransactionId === '' || (string) ($payload['hash'] ?? '') === '' || ($payload['status'] ?? null) !== 'success') {
            throw new DomainException('Invalid signature or payload for provider [wipay].');
        }

        $wipay = $this->providers->byKey(WiPayProvider::KEY);

        if (! $wipay instanceof WiPayProvider || ! $wipay->isAvailable()) {
            throw new DomainException('WiPay is not configured on this server.');
        }

        $payment = Payment::query()
            ->where('provider', WiPayProvider::KEY)
            ->where('provider_payment_id', $wipayTransactionId)
            ->first();

        if (! $payment) {
            throw new DomainException('Invalid signature or payload for provider [wipay].');
        }

        $eventId = "wipay:{$wipayTransactionId}";

        if ($payment->state === PaymentState::Paid) {
            return ['received' => true, 'outcome' => 'duplicate', 'event_id' => $eventId, 'message' => 'Event already processed.'];
        }

        $payment = $wipay->confirmPayment($payment, $payload);

        if (! in_array($payment->state, [PaymentState::Paid, PaymentState::Succeeded], true)) {
            throw new DomainException('Invalid signature or payload for provider [wipay].');
        }

        PaymentEvent::query()->updateOrCreate(
            ['provider' => WiPayProvider::KEY, 'event_id' => $eventId],
            [
                'event_type' => 'success',
                'status' => 'processed',
                'payload' => $payload,
                'payment_id' => $payment->id,
                'transaction_id' => $payment->ledgerPayment()?->id,
                'processed_at' => now(),
            ]
        );

        return ['received' => true, 'outcome' => 'processed', 'event_id' => $eventId];
    }

    /**
     * Authenticate whether the provider is recognized and active.
     */
    protected function authenticateProvider(string $provider): void
    {
        // Office channels (cash, bank transfer) are confirmed by two admins,
        // never by a webhook, so they have no entry here.
        $supported = ['stripe', 'wipay', 'paypal', 'ncb', 'scotiabank'];

        if (! in_array($provider, $supported, true)) {
            throw new DomainException("Unsupported payment provider [{$provider}].");
        }

        // Check if config exists or provider is active
        $providerConfig = config("payments.providers.{$provider}");
        if ($provider === 'stripe' && ! config('services.stripe.webhook_secret') && ! config('services.stripe.secret')) {
            throw new DomainException('Stripe is not configured on this server.');
        }
    }

    /**
     * Verify cryptographic signature for incoming request based on provider standard.
     */
    public function verifySignature(Request $request, string $provider): bool
    {
        $content = $request->getContent();

        switch ($provider) {
            case 'stripe':
                $secret = config('services.stripe.webhook_secret');
                if (! $secret) {
                    return false;
                }
                $signature = (string) $request->header('Stripe-Signature');
                try {
                    Webhook::constructEvent($content, $signature, $secret);

                    return true;
                } catch (Throwable) {
                    return false;
                }

            case 'wipay':
                // Only an HMAC over the whole body is accepted here, because
                // the pipeline below trusts every field of the body. WiPay's
                // own md5 hash covers two fields and goes through
                // confirmWiPayReturn() instead.
                $apiKey = config('payments.providers.wipay.api_key');
                $headerSig = (string) $request->header('X-WiPay-Signature');

                if (! $apiKey || $headerSig === '') {
                    return false;
                }

                return hash_equals(hash_hmac('sha256', $content, $apiKey), $headerSig);

            case 'paypal':
                // Check PayPal webhook signature header
                $paypalSig = (string) ($request->header('PAYPAL-TRANSMISSION-SIG') ?? $request->header('X-PayPal-Signature'));
                $secret = config('payments.providers.paypal.webhook_secret');
                if (! $secret || $paypalSig === '') {
                    return false;
                }

                return hash_equals(hash_hmac('sha256', $content, $secret), $paypalSig);

            default:
                // Generic provider webhook signature verification (HMAC-SHA256)
                $secret = config("payments.providers.{$provider}.webhook_secret", config("payments.providers.{$provider}.api_key"));
                $headerSig = (string) ($request->header('X-Signature') ?? $request->header('X-Webhook-Signature'));

                if (! $secret || ! $headerSig) {
                    return false;
                }

                return hash_equals(hash_hmac('sha256', $content, $secret), $headerSig);
        }
    }

    /**
     * Map provider-specific event types to canonical domain events.
     */
    public function mapToCanonicalEvent(string $provider, string $rawType, array $payload): string
    {
        $type = strtolower(trim($rawType));

        return match ($provider) {
            'stripe' => match ($type) {
                'payment_intent.succeeded', 'checkout.session.completed', 'checkout.session.async_payment_succeeded' => self::EVENT_SUCCESS,
                'payment_intent.processing', 'payment_intent.requires_action' => self::EVENT_PROCESSING,
                'payment_intent.payment_failed', 'checkout.session.expired', 'checkout.session.async_payment_failed' => self::EVENT_FAILED,
                'charge.refunded' => self::EVENT_REFUNDED,
                'charge.dispute.created', 'charge.dispute.funds_withdrawn' => self::EVENT_DISPUTED,
                default => self::EVENT_IGNORED,
            },
            'wipay' => match ($type) {
                'approved', 'success', 'transaction.approved', '1-r1' => self::EVENT_SUCCESS,
                'pending', 'processing' => self::EVENT_PROCESSING,
                'failed', 'declined', 'failed_charge' => self::EVENT_FAILED,
                'refunded', 'reversed' => self::EVENT_REFUNDED,
                default => (isset($payload['status']) && strtolower((string) $payload['status']) === 'success') ? self::EVENT_SUCCESS : self::EVENT_IGNORED,
            },
            'paypal' => match (strtoupper($rawType)) {
                'PAYMENT.CAPTURE.COMPLETED', 'CHECKOUT.ORDER.APPROVED' => self::EVENT_SUCCESS,
                'PAYMENT.CAPTURE.PENDING' => self::EVENT_PROCESSING,
                'PAYMENT.CAPTURE.DENIED', 'CHECKOUT.ORDER.EXPIRED' => self::EVENT_FAILED,
                'PAYMENT.CAPTURE.REFUNDED' => self::EVENT_REFUNDED,
                'CUSTOMER.DISPUTE.CREATED' => self::EVENT_DISPUTED,
                default => self::EVENT_IGNORED,
            },
            default => match ($type) {
                'payment.success', 'payment.succeeded', 'paid', 'success' => self::EVENT_SUCCESS,
                'payment.processing', 'pending' => self::EVENT_PROCESSING,
                'payment.failed', 'failed', 'declined' => self::EVENT_FAILED,
                'payment.refunded', 'refunded' => self::EVENT_REFUNDED,
                'payment.disputed', 'disputed' => self::EVENT_DISPUTED,
                default => self::EVENT_IGNORED,
            },
        };
    }

    /**
     * Find target payment record using provider payment ID or CommunityHub transaction ID.
     */
    protected function findPayment(string $provider, array $payload): ?Payment
    {
        $providerPaymentId = $payload['provider_payment_id']
            ?? $payload['transaction_id']
            ?? $payload['id']
            ?? $payload['payment_intent']
            ?? null;

        $orderId = $payload['order_id'] ?? $payload['client_reference_id'] ?? null;

        $payments = Payment::query()->where('provider', $provider);

        if ($providerPaymentId) {
            $payment = (clone $payments)->where('provider_payment_id', (string) $providerPaymentId)->first();
            if ($payment) {
                return $payment;
            }
        }

        if ($orderId) {
            $chTxId = (string) $orderId;

            // WiPay's order_id is the CH id without dashes (16-character limit).
            return (clone $payments)->where('transaction_id', $chTxId)->first()
                ?? (clone $payments)->whereRaw("REPLACE(transaction_id, '-', '') = ?", [$chTxId])->first();
        }

        return null;
    }

    /**
     * Verify incoming amount matches payment amount in minor units.
     */
    protected function verifyAmount(Payment $payment, array $payload, string $provider, bool $required = false): void
    {
        $amountMinor = $this->payloadAmountMinor($payload);

        if ($amountMinor === null && $required) {
            throw new DomainException('The provider event carries no amount, so it cannot confirm this payment.');
        }

        if ($amountMinor !== null && $amountMinor !== (int) $payment->amount_minor) {
            Log::channel('security')->error('Payment amount mismatch on webhook', [
                'expected_minor' => $payment->amount_minor,
                'incoming_minor' => $amountMinor,
                'provider' => $provider,
                'payment_id' => $payment->id,
            ]);

            throw new DomainException("Amount mismatch: expected [{$payment->amount_minor}], received [{$amountMinor}].");
        }
    }

    /**
     * The event's amount in minor units, or null when it carries none.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function payloadAmountMinor(array $payload): ?int
    {
        return match (true) {
            isset($payload['amount_minor']) => (int) $payload['amount_minor'],
            isset($payload['amount_total']) => (int) $payload['amount_total'],
            isset($payload['total']) => (int) round(((float) $payload['total']) * 100),
            isset($payload['amount']) => (int) round(((float) $payload['amount']) * 100),
            default => null,
        };
    }

    /**
     * Verify currency matches expected currency.
     */
    protected function verifyCurrency(Payment $payment, array $payload, string $provider): void
    {
        $currency = strtoupper((string) ($payload['currency'] ?? ''));

        if ($currency !== '' && $currency !== strtoupper($payment->currency)) {
            Log::channel('security')->error('Payment currency mismatch on webhook', [
                'expected' => $payment->currency,
                'incoming' => $currency,
                'provider' => $provider,
            ]);

            throw new DomainException("Currency mismatch: expected [{$payment->currency}], received [{$currency}].");
        }
    }

    /**
     * Verify merchant identifier matches configured merchant.
     */
    protected function verifyMerchant(Payment $payment, array $payload, string $provider): void
    {
        $merchantId = (string) ($payload['account_number'] ?? $payload['merchant_id'] ?? $payload['account'] ?? '');

        if ($merchantId !== '') {
            $configured = config("payments.providers.{$provider}.account_number")
                ?? config("payments.providers.{$provider}.merchant_id");

            if ($configured && (string) $configured !== $merchantId) {
                Log::channel('security')->warning('Merchant account mismatch on webhook', [
                    'expected' => $configured,
                    'incoming' => $merchantId,
                    'provider' => $provider,
                ]);

                throw new DomainException("Merchant mismatch for provider [{$provider}].");
            }
        }
    }

    /**
     * Verify state machine transition eligibility.
     */
    protected function verifyTransactionState(Payment $payment, string $canonicalEvent): void
    {
        if ($canonicalEvent === self::EVENT_SUCCESS && $payment->state === PaymentState::Paid) {
            // Already paid, idempotent
            return;
        }

        if ($canonicalEvent === self::EVENT_SUCCESS && ! $payment->state->canTransitionTo(PaymentState::Paid) && ! $payment->state->canTransitionTo(PaymentState::Succeeded)) {
            throw new DomainException("Illegal payment state transition from [{$payment->state->value}] to [Paid].");
        }
    }

    /**
     * Process event, update status, post to General Ledger, settle Invoice, create Receipt, notify customer.
     */
    protected function applyCanonicalEvent(Payment $payment, string $canonicalEvent, string $provider, string $eventId, array $payload): ?Transaction
    {
        $source = "webhook:{$provider}:{$eventId}";

        switch ($canonicalEvent) {
            case self::EVENT_SUCCESS:
                // Advance payment state machine
                $payment->advanceTo(PaymentState::Succeeded, $source, "Confirmed via {$provider} webhook {$eventId}");

                $providerRef = (string) ($payload['provider_payment_id'] ?? $payload['transaction_id'] ?? $eventId);

                $ledgerData = [
                    'reference' => "{$provider}:{$providerRef}",
                    'provider_reference' => $providerRef,
                    'provider_event_id' => $eventId,
                    'idempotency_key' => "idem:{$provider}:{$eventId}",
                    'provider_status' => 'succeeded',
                    'notes' => "Settled via {$provider} webhook {$eventId}",
                ];

                // 1. Post to General Ledger and update Invoice
                $tx = $this->orchestrator->applyPayment($payment, $ledgerData, null, $source);

                // 2. Generate PaymentReceipt record
                $this->createPaymentReceipt($payment, $tx);

                return $tx;

            case self::EVENT_PROCESSING:
                $payment->advanceTo(PaymentState::Processing, $source, "Processing reported by {$provider}");

                return $payment->ledgerPayment();

            case self::EVENT_FAILED:
                $reason = (string) ($payload['failure_reason'] ?? $payload['message'] ?? 'Payment failed at provider');
                $payment->advanceTo(PaymentState::Failed, $source, $reason, ['failure_reason' => $reason]);

                return $payment->ledgerPayment();

            case self::EVENT_REFUNDED:
                $refund = app(RefundService::class)->confirmRefundFromWebhook(
                    provider: $provider,
                    providerRefundId: (string) ($payload['refund_id'] ?? $eventId),
                    amountMinor: $this->payloadAmountMinor($payload)
                        ?? throw new DomainException('The refund event carries no amount.'),
                    currency: $payment->currency,
                    reason: (string) ($payload['reason'] ?? 'Confirmed provider refund'),
                    metadata: ['transaction_id' => $payment->transaction_id]
                );

                return $refund->transaction;

            case self::EVENT_DISPUTED:
                $payment->advanceTo(PaymentState::Disputed, $source, "Dispute created via {$provider}");
                $tx = $payment->ledgerPayment();
                if ($tx) {
                    PaymentDispute::query()->firstOrCreate(
                        ['transaction_id' => $tx->id, 'provider_dispute_id' => $eventId],
                        [
                            'amount_minor' => $payment->amount_minor,
                            'currency' => $payment->currency,
                            'status' => 'needs_response',
                        ]
                    );
                }

                return $tx;

            default:
                return $payment->ledgerPayment();
        }
    }

    /**
     * Create verified PaymentReceipt entity.
     */
    protected function createPaymentReceipt(Payment $payment, Transaction $tx): PaymentReceipt
    {
        $prefix = $payment->channel === 'cash_office' ? 'CR-' : 'REC-';
        $receiptNumber = $prefix.now()->format('Y').'-'.str_pad((string) $tx->id, 5, '0', STR_PAD_LEFT);

        return PaymentReceipt::query()->firstOrCreate(
            ['transaction_id' => $tx->id],
            [
                'receipt_number' => $receiptNumber,
                'receipt_type' => $payment->channel === 'cash_office' ? 'cash_desk' : ($payment->channel === 'bank_transfer' ? 'bank_transfer' : 'digital'),
                'amount_minor' => $payment->amount_minor,
                'currency' => $payment->currency,
                'payer_name' => $payment->user?->name ?? 'Community Resident',
                'payer_lot' => $payment->user?->lot_number ?? null,
                'issued_by_name' => 'CommunityHub Automated Payment System',
                'issued_at' => now(),
            ]
        );
    }

    protected function parsePayload(Request $request): array
    {
        $json = $request->json()->all();
        if (! empty($json)) {
            return $json;
        }

        $all = $request->all();
        if (! empty($all)) {
            return $all;
        }

        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function extractEventId(Request $request, array $payload, string $provider): string
    {
        return (string) (
            $payload['id']
            ?? $payload['event_id']
            ?? $payload['transaction_id']
            ?? $request->header('X-Event-Id')
            ?? 'evt_'.hash('sha256', $provider.$request->getContent())
        );
    }

    protected function extractEventType(Request $request, array $payload, string $provider): string
    {
        return (string) (
            $payload['type']
            ?? $payload['event_type']
            ?? $payload['status']
            ?? $request->header('X-Event-Type')
            ?? 'unknown'
        );
    }

    protected function extractSignatureHeader(Request $request, string $provider): ?string
    {
        return $request->header('Stripe-Signature')
            ?? $request->header('X-WiPay-Signature')
            ?? $request->header('X-PayPal-Signature')
            ?? $request->header('PAYPAL-TRANSMISSION-SIG')
            ?? $request->header('X-Signature')
            ?? $request->header('X-Webhook-Signature')
            ?? null;
    }
}
