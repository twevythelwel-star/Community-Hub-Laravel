<?php

namespace App\Services\Payments\Stripe;

use App\Enums\PaymentState;
use App\Models\Fundraiser;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentOrchestratorService;
use DomainException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Stripe\BillingPortal\Session as BillingPortalSession;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\Stripe;

class StripeCheckoutSessionService
{
    public function __construct(
        protected ?StripeSettlementService $settlementService = null,
    ) {
        $this->settlementService ??= app(StripeSettlementService::class);
    }

    protected function orchestrator(): PaymentOrchestratorService
    {
        return app(PaymentOrchestratorService::class);
    }

    /**
     * Create a Stripe Checkout Session for an invoice.
     * Returns the redirect URL for the user to complete payment.
     */
    public function createCheckoutSession(
        Invoice $invoice,
        string $successUrl,
        string $cancelUrl,
        ?int $amountMinor = null,
        ?Payment $payment = null,
        ?string $secretKey = null,
    ): string {
        $balanceMinor = $invoice->balanceRemainingMinor();
        $amountMinor ??= $balanceMinor;

        if ($balanceMinor < 1) {
            throw new DomainException("Invoice {$invoice->reference} has nothing left to pay.");
        }

        if ($amountMinor < 1 || $amountMinor > $balanceMinor) {
            throw new DomainException('The card payment must be between 0.01 and the balance still owed.');
        }

        if ($payment && ($payment->state !== PaymentState::Created || $payment->invoice_id !== $invoice->id)) {
            throw new DomainException("Payment {$payment->transaction_id} cannot be sent to card checkout.");
        }

        $payment ??= $this->orchestrator()->startPayment([
            'user' => $invoice->user,
            'channel' => 'card',
            'invoice' => $invoice,
            'amount_minor' => $amountMinor,
            'currency' => $invoice->currency,
        ]);

        $payment->update(['channel' => 'card', 'provider' => 'stripe', 'amount_minor' => $amountMinor]);

        Stripe::setApiKey($secretKey);

        if ($payment->provider_session_id) {
            try {
                $existing = Session::retrieve($payment->provider_session_id);

                if ($existing->status === 'open' && $existing->url && (int) $existing->amount_total === $amountMinor) {
                    return $existing->url;
                }
            } catch (ApiErrorException) {
                // Unknown or from another account: fall through and make a new one.
            }
        }

        $metadata = Transaction::buildProcessorMetadata(
            payment: $payment,
            user: $invoice->user,
            invoice: $invoice,
            paymentType: 'HOA_ASSESSMENT',
            extra: [
                'purpose' => 'invoice',
            ],
        );

        $session = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($invoice->currency),
                    'product_data' => [
                        'name' => "{$payment->purpose} — Ref: {$invoice->reference}",
                        'description' => "CommunityHub payment {$payment->transaction_id}",
                    ],
                    'unit_amount' => $amountMinor,
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'customer_email' => $invoice->user->email,
            'client_reference_id' => (string) $invoice->id,
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
            'success_url' => $successUrl.'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
        ], [
            'idempotency_key' => "invoice-{$invoice->id}-checkout-".($invoice->stripe_session_id ?? 'initial')."-{$amountMinor}",
        ]);

        $this->attachSession($payment, $session->id);
        $invoice->update(['stripe_session_id' => $session->id]);

        return $session->url;
    }

    /**
     * Record which Checkout Session a payment went to.
     */
    public function attachSession(Payment $payment, string $sessionId): void
    {
        $owner = Payment::query()->where('provider_session_id', $sessionId)->first();

        if ($owner && ! $owner->is($payment)) {
            $payment->advanceTo(PaymentState::Expired, 'stripe:checkout', "Duplicate request; Checkout Session {$sessionId} belongs to {$owner->transaction_id}");

            return;
        }

        $payment->update(['provider_session_id' => $sessionId]);
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
        ?string $secretKey = null,
    ): string {
        $payment = $this->orchestrator()->startPayment([
            'user' => $donor,
            'channel' => 'card',
            'fundraiser' => $fundraiser,
            'amount_minor' => $amountMinor,
            'currency' => $fundraiser->goal_currency,
        ]);

        Stripe::setApiKey($secretKey);

        $metadata = Transaction::buildProcessorMetadata(
            payment: $payment,
            user: $donor,
            invoice: null,
            paymentType: 'DONATION',
            extra: [
                'purpose' => 'donation',
                'fundraiser_id' => (string) $fundraiser->id,
                'donor_name' => Str::limit($details['donor_name'], 120, ''),
                'is_anonymous' => $details['is_anonymous'] ? '1' : '0',
                'is_recurring' => $details['is_recurring'] ? '1' : '0',
                'frequency' => (string) ($details['frequency'] ?? ''),
            ],
        );

        $session = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($fundraiser->goal_currency),
                    'product_data' => [
                        'name' => "Donation — {$fundraiser->title}",
                        'description' => "CommunityHub payment {$payment->transaction_id}",
                    ],
                    'unit_amount' => $amountMinor,
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'customer_email' => $donor->email,
            'client_reference_id' => "donation-{$fundraiser->id}-{$donor->id}",
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
            'success_url' => $successUrl.'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
        ]);

        $this->attachSession($payment, $session->id);

        return $session->url;
    }

    /**
     * Verify a returning donor's session with Stripe and record the donation.
     */
    public function completeDonation(
        Fundraiser $fundraiser,
        User $donor,
        string $sessionId,
        ?string $secretKey = null,
    ): bool {
        Stripe::setApiKey($secretKey);
        $session = Session::retrieve($sessionId);

        $metadata = $session->metadata;

        if (($metadata->purpose ?? null) !== 'donation'
            || (string) ($metadata->fundraiser_id ?? '') !== (string) $fundraiser->id
            || (string) ($metadata->user_id ?? '') !== (string) $donor->id) {
            Log::channel('security')->warning('Stripe session presented for a donation it does not describe', [
                'fundraiser_id' => $fundraiser->id,
                'user_id' => $donor->id,
                'session_id' => $sessionId,
            ]);

            return false;
        }

        return in_array($this->settlementService->settleSession($session), ['settled', 'duplicate'], true);
    }

    /**
     * Create a Stripe Billing Portal session for self-service payment management.
     */
    public function createCustomerPortalSession(User $user, string $returnUrl, ?string $secretKey = null): string
    {
        Stripe::setApiKey($secretKey);

        $customerId = null;
        try {
            $existing = Customer::all(['email' => $user->email, 'limit' => 1]);
            if (! empty($existing->data)) {
                $customerId = $existing->data[0]->id;
            } else {
                $created = Customer::create([
                    'email' => $user->email,
                    'name' => $user->display_name,
                    'metadata' => [
                        'user_id' => (string) $user->id,
                        'lot' => $user->lot ?? 'unassigned',
                    ],
                ]);
                $customerId = $created->id;
            }
        } catch (ApiErrorException $e) {
            throw new RuntimeException("Unable to resolve Stripe customer: {$e->getMessage()}", 0, $e);
        }

        $session = BillingPortalSession::create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);

        return $session->url;
    }

    /**
     * Verify a completed checkout with Stripe and settle the invoice.
     */
    public function completePayment(Invoice $invoice, string $sessionId, ?string $secretKey = null): bool
    {
        if ($invoice->status === 'Paid') {
            return true;
        }

        Stripe::setApiKey($secretKey);
        $session = Session::retrieve($sessionId);

        if ((string) $session->client_reference_id !== (string) $invoice->id) {
            Log::channel('security')->warning('Stripe session does not belong to the invoice it was presented for', [
                'invoice_id' => $invoice->id,
                'session_id' => $sessionId,
                'session_client_reference_id' => $session->client_reference_id,
            ]);

            return false;
        }

        return in_array($this->settlementService->settleSession($session), ['settled', 'duplicate'], true);
    }
}
