<?php

namespace App\Services\Payments\Providers\StripeTerminal;

use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\Terminal\Location;
use Stripe\Terminal\Reader;

/**
 * The Stripe Terminal API calls the provider makes, in one place, so tests
 * can stand a fake reader in for Stripe. Server-driven integration: smart
 * readers (BBPOS WisePOS E, Stripe Reader S700/S710) driven over the API.
 * Bluetooth readers and Tap to Pay on a phone need Stripe's native mobile SDK
 * and cannot be driven from this web app.
 *
 * https://docs.stripe.com/terminal/payments/collect-card-payment?terminal-sdk-platform=server-driven
 */
class StripeTerminalClient
{
    private function authenticate(): void
    {
        Stripe::setApiKey((string) config('services.stripe.secret'));
    }

    /**
     * Register a smart reader by the pairing code shown on its screen.
     *
     * @return array{id: string, serial_number: ?string, device_type: ?string, location: string, label: ?string}
     */
    public function registerReader(string $registrationCode, string $locationId, string $label): array
    {
        $this->authenticate();

        $reader = Reader::create([
            'registration_code' => $registrationCode,
            'location' => $locationId,
            'label' => $label,
        ]);

        return [
            'id' => $reader->id,
            'serial_number' => $reader->serial_number,
            'device_type' => $reader->device_type,
            'location' => is_string($reader->location) ? $reader->location : $reader->location?->id,
            'label' => $reader->label,
        ];
    }

    /** The two-letter country of a Terminal Location's address. */
    public function locationCountry(string $locationId): string
    {
        $this->authenticate();

        return strtoupper((string) Location::retrieve($locationId)->address->country);
    }

    /**
     * A PaymentIntent for a card-present payment, captured on authorisation.
     *
     * @param  array<string, string>  $metadata
     */
    public function createCardPresentIntent(int $amountMinor, string $currency, array $metadata, string $idempotencyKey): string
    {
        $this->authenticate();

        return PaymentIntent::create([
            'amount' => $amountMinor,
            'currency' => strtolower($currency),
            'payment_method_types' => ['card_present'],
            'capture_method' => 'automatic',
            'metadata' => $metadata,
        ], ['idempotency_key' => $idempotencyKey])->id;
    }

    /** Hand a PaymentIntent to a reader; the reader then prompts for the card. */
    public function processOnReader(string $readerId, string $paymentIntentId): void
    {
        $this->authenticate();

        Reader::retrieve($readerId)->processPaymentIntent([
            'payment_intent' => $paymentIntentId,
            'process_config' => ['enable_customer_cancellation' => true],
        ]);
    }

    /** Reset a reader that has not yet taken a card. */
    public function cancelReaderAction(string $readerId): void
    {
        $this->authenticate();

        Reader::retrieve($readerId)->cancelAction();
    }
}
