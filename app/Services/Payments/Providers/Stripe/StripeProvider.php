<?php

namespace App\Services\Payments\Providers\Stripe;

use App\Enums\PaymentState;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\Providers\Contracts\HandlesWebhooks;
use App\Services\Payments\Providers\Contracts\OffersWalletPayments;
use App\Services\Payments\Providers\Contracts\PaymentProvider;
use App\Services\Payments\Providers\Contracts\RefundsPayments;
use App\Services\Payments\Providers\PaymentInstruction;
use App\Services\Payments\Providers\PaymentRequest;
use App\Services\StripePaymentService;
use DomainException;
use Illuminate\Http\Request;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\Stripe;
use Stripe\Webhook;
use Throwable;

/**
 * Card payments through Stripe Checkout, for estates whose merchant entity
 * Stripe supports. Stripe's availability does not include Jamaica, so this
 * is one card provider among others (config/payments.php `card_provider`),
 * never the default for an estate that has not chosen it.
 *
 * The Stripe work itself stays in StripePaymentService; this adapts it to
 * the provider contract.
 */
class StripeProvider implements HandlesWebhooks, OffersWalletPayments, PaymentProvider, RefundsPayments
{
    public const KEY = 'stripe';

    public function __construct(
        protected StripePaymentService $stripe,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Stripe';
    }

    public function isAvailable(): bool
    {
        return $this->stripe->isLive();
    }

    /**
     * @throws ApiErrorException when Stripe cannot start the session
     * @throws DomainException when there is nothing card checkout can take
     */
    public function createPayment(PaymentRequest $request): PaymentInstruction
    {
        if ($request->invoice) {
            $url = $this->stripe->createCheckoutSession(
                $request->invoice,
                route('dashboard.billing.stripe.success', ['invoice' => $request->invoice->id]),
                route('dashboard.billing.stripe.cancel', ['invoice' => $request->invoice->id]),
                $request->amountMinor,
                $request->payment,
            );

            return PaymentInstruction::redirect($request->payment?->fresh(), $url);
        }

        if ($request->fundraiser && $request->payer) {
            // The Donation is written from the session metadata once Stripe
            // confirms the money; see StripePaymentService::settleDonationSession().
            $url = $this->stripe->createDonationCheckoutSession(
                $request->fundraiser,
                $request->payer,
                $request->amountMinor,
                [
                    'donor_name' => $request->donor['donor_name'] ?? $request->payer->display_name,
                    'is_anonymous' => (bool) ($request->donor['is_anonymous'] ?? false),
                    'is_recurring' => (bool) ($request->donor['is_recurring'] ?? false),
                    'frequency' => $request->donor['frequency'] ?? null,
                ],
                route('dashboard.fundraising.donate.stripe.success', $request->fundraiser),
                route('dashboard.fundraising.donate.stripe.cancel', $request->fundraiser),
            );

            return PaymentInstruction::redirect(null, $url);
        }

        throw new DomainException('Card checkout needs a statement or a campaign to pay.');
    }

    /**
     * Stripe's hosted Checkout shows Apple Pay and Google Pay itself, on
     * devices that have them, with no domain registration for hosted pages.
     * The payer picks the wallet on Stripe's page; the outcome arrives by
     * webhook like any card payment.
     */
    public function walletMethods(): array
    {
        return ['apple_pay', 'google_pay'];
    }

    public function getPaymentStatus(Payment $payment): PaymentState
    {
        // Stripe reports by webhook; the payment's state is what it last said.
        return $payment->fresh()->state;
    }

    /** Close an unpaid Checkout Session so it can no longer be paid. */
    public function cancelPayment(Payment $payment, ?User $actor = null): Payment
    {
        if ($payment->provider_session_id && $this->isAvailable()) {
            try {
                Stripe::setApiKey((string) config('services.stripe.secret'));
                Session::retrieve($payment->provider_session_id)->expire();
            } catch (ApiErrorException $e) {
                // Already expired or completed: the transition below decides.
                report($e);
            }
        }

        return $payment->transitionTo(PaymentState::Canceled, $actor, 'resident', 'Withdrawn by the payer');
    }

    public function verifyWebhook(Request $request): bool
    {
        if (! $this->stripe->acceptsWebhooks()) {
            return false;
        }

        try {
            Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'), (string) config('services.stripe.webhook_secret'));

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function handleWebhook(Request $request): string
    {
        return $this->stripe->handleWebhook($request->getContent(), $request->header('Stripe-Signature'));
    }

    public function refundPayment(Transaction $ledgerPayment, int $amountMinor, User $actor, ?string $note = null): void
    {
        $this->stripe->refund($ledgerPayment, $amountMinor, $actor, $note);
    }
}
