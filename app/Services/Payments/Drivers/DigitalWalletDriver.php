<?php

namespace App\Services\Payments\Drivers;

use Illuminate\Support\Str;

class DigitalWalletDriver implements PaymentDriverInterface
{
    public function __construct(
        protected string $channelKey = 'apple_pay'
    ) {}

    public function key(): string
    {
        return $this->channelKey;
    }

    public function label(): string
    {
        return match ($this->channelKey) {
            'google_pay' => 'Google Pay',
            'samsung_wallet' => 'Samsung Wallet',
            default => 'Apple Pay',
        };
    }

    public function initiate(array $params): array
    {
        $amountMinor = $params['amount_minor'] ?? 0;
        $currency = $params['currency'] ?? 'JMD';

        return [
            'type' => $this->channelKey,
            'merchant_id' => 'merchant.org.communityhub.'.$this->channelKey,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'token_request_url' => url('/api/payments/wallet-token'),
            'instructions' => 'Authorize payment via '.$this->label().' biometric touch / face scan.',
        ];
    }

    public function settle(array $params): array
    {
        return [
            'success' => true,
            'reference' => strtoupper($this->channelKey).'-'.strtoupper(Str::random(10)),
            'notes' => 'Settled via '.$this->label().' Web Checkout tokenization.',
        ];
    }
}
