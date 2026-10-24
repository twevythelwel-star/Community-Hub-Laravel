<?php

namespace App\Services\Payments\Providers;

use App\Models\Payment;

/**
 * What the payer does next, from a provider's createPayment().
 *
 *   redirect      go to the provider's hosted page at `url`
 *   instructions  send the money as `message` says; the office confirms it
 *   completed     nothing — the money was taken and applied already
 *   terminal      tap or insert a card on the reader staff sent it to
 */
final class PaymentInstruction
{
    private function __construct(
        public readonly string $type,
        public readonly ?Payment $payment,
        public readonly ?string $url = null,
        public readonly ?string $message = null,
    ) {}

    public static function redirect(?Payment $payment, string $url): self
    {
        return new self('redirect', $payment, url: $url);
    }

    public static function instructions(Payment $payment, string $message): self
    {
        return new self('instructions', $payment, message: $message);
    }

    public static function onTerminal(Payment $payment, string $message): self
    {
        return new self('terminal', $payment, message: $message);
    }

    public static function completed(Payment $payment, string $message): self
    {
        return new self('completed', $payment, message: $message);
    }

    public function isRedirect(): bool
    {
        return $this->type === 'redirect';
    }
}
