<?php

namespace App\Services\Payments\Providers\StripeTerminal;

use App\Enums\PaymentState;
use App\Models\Payment;
use App\Models\PaymentTerminal;
use App\Models\User;
use App\Services\Payments\PaymentOrchestratorService;
use App\Services\Payments\Providers\Contracts\PaymentProvider;
use App\Services\Payments\Providers\Contracts\TakesInPersonPayments;
use App\Services\Payments\Providers\PaymentInstruction;
use App\Services\Payments\Providers\PaymentRequest;
use App\Services\StripePaymentService;
use DomainException;
use Stripe\Exception\ApiErrorException;

/**
 * In-person card payments on Stripe Terminal smart readers.
 *
 * For estates whose Stripe account and reader locations are in a Terminal
 * country — Stripe's list has no Caribbean country, so not Jamaica. Terminal
 * charges in the location's local currency; Stripe refuses anything else, and
 * that refusal is reported, not worked around.
 *
 * The outcome arrives by webhook through StripePaymentService:
 * payment_intent.succeeded settles the payment (as for online card payments,
 * so Stripe refunds, disputes and payouts work unchanged), and
 * terminal.reader.action_failed marks it Failed — from which the same
 * PaymentIntent can be retried on the reader, as Stripe recommends to avoid
 * double charges.
 */
class StripeTerminalProvider implements PaymentProvider, TakesInPersonPayments
{
    public const KEY = 'stripe_terminal';

    public function __construct(
        protected StripeTerminalClient $client,
        protected StripePaymentService $stripe,
        protected PaymentOrchestratorService $orchestrator,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Stripe Terminal';
    }

    public function isAvailable(): bool
    {
        return $this->stripe->isLive();
    }

    /**
     * An in-person payment is started by staff on a chosen reader, not by the
     * payer online: use startInPersonPayment().
     */
    public function createPayment(PaymentRequest $request): PaymentInstruction
    {
        throw new DomainException('In-person payments are taken on a card reader at the community office.');
    }

    public function registerTerminal(string $registrationCode, string $locationId, string $label, User $admin): PaymentTerminal
    {
        if (! $this->isAvailable()) {
            throw new DomainException('Stripe is not configured.');
        }

        try {
            // Both calls must succeed: Stripe accepting the pairing code for
            // this account, and the location existing — which gives the
            // country the reader will charge in.
            $reader = $this->client->registerReader($registrationCode, $locationId, $label);
            $country = $this->client->locationCountry($reader['location']);
        } catch (ApiErrorException $e) {
            throw new DomainException('Stripe did not accept this reader: '.$e->getMessage(), 0, $e);
        }

        $terminal = PaymentTerminal::query()->updateOrCreate(
            ['provider' => self::KEY, 'terminal_id' => $reader['id']],
            [
                'device_id' => $reader['serial_number'],
                'device_type' => $reader['device_type'],
                'location_id' => $reader['location'],
                'country' => $country,
                'label' => $reader['label'] ?: $label,
                'status' => 'active',
                'verified_at' => now(),
                'registered_by' => $admin->id,
            ],
        );

        $admin->recordActivity("Registered card reader {$terminal->label} ({$terminal->terminal_id}, {$terminal->country})");

        return $terminal;
    }

    public function startInPersonPayment(Payment $payment, PaymentTerminal $terminal): PaymentInstruction
    {
        if (! $terminal->isUsable() || $terminal->provider !== self::KEY) {
            throw new DomainException("Reader {$terminal->label} is not registered and confirmed with Stripe.");
        }

        $payment->update([
            'channel' => 'nfc_pos',
            'provider' => self::KEY,
            'payment_terminal_id' => $terminal->id,
            'terminal_id' => $terminal->terminal_id,
            'device_identifier' => $terminal->device_id,
            'location_id' => $terminal->location_id,
        ]);

        try {
            // One PaymentIntent per payment, reused on retry (the idempotency
            // key is the payment), so a declined card and a second attempt can
            // never become two charges.
            $intentId = $payment->provider_payment_id ?: $this->client->createCardPresentIntent(
                $payment->amount_minor,
                $payment->currency,
                array_filter([
                    'payment_id' => (string) $payment->id,
                    'communityhub_transaction_id' => $payment->transaction_id,
                    'invoice_id' => $payment->invoice_id ? (string) $payment->invoice_id : null,
                    'terminal_id' => $terminal->terminal_id,
                    'location_id' => $terminal->location_id,
                ]),
                "terminal-payment-{$payment->id}",
            );

            $payment->update(['provider_payment_id' => $intentId]);

            $this->client->processOnReader($terminal->terminal_id, $intentId);
        } catch (ApiErrorException $e) {
            // Busy, offline, wrong currency for the location: Stripe decides.
            $payment->advanceTo(PaymentState::Failed, 'stripe_terminal', $e->getMessage(), ['failure_reason' => $e->getMessage()]);

            throw new DomainException("The reader could not take the payment: {$e->getMessage()}", 0, $e);
        }

        $payment->advanceTo(PaymentState::Processing, 'stripe_terminal', "Sent to reader {$terminal->label}");

        return PaymentInstruction::onTerminal($payment->fresh(), "Ask the payer to tap or insert their card on {$terminal->label}.");
    }

    public function cancelInPersonPayment(Payment $payment, ?User $actor = null): Payment
    {
        if ($payment->terminal_id) {
            try {
                $this->client->cancelReaderAction($payment->terminal_id);
            } catch (ApiErrorException $e) {
                // Mid-authorisation Stripe refuses to cancel; the webhook decides.
                throw new DomainException("The reader could not be cleared: {$e->getMessage()}", 0, $e);
            }
        }

        return $payment->transitionTo(PaymentState::Canceled, $actor, 'staff', 'Cleared from the reader before a card was presented');
    }

    public function getPaymentStatus(Payment $payment): PaymentState
    {
        return $payment->fresh()->state;
    }

    public function cancelPayment(Payment $payment, ?User $actor = null): Payment
    {
        return $this->cancelInPersonPayment($payment, $actor);
    }
}
