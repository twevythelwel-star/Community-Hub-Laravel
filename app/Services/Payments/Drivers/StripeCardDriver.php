<?php

namespace App\Services\Payments\Drivers;

use App\Services\StripePaymentService;
use LogicException;

/**
 * The card channel's entry in the orchestrator.
 *
 * Card money is taken only by Stripe Checkout, and recorded only when Stripe
 * confirms it — see StripePaymentService and the `card` branches of
 * BillingController::pay() and FundraisingController::donate().
 *
 * initiate() and settle() used to invent a "pi_..._secret_..." client secret
 * and a "STRIPE-..." reference and report success, so any caller that settled
 * the card channel through the orchestrator recorded a completed payment with
 * no money taken. They now refuse, so a new caller fails loudly instead.
 */
class StripeCardDriver implements PaymentDriverInterface
{
    public function __construct(
        protected StripePaymentService $stripeService
    ) {}

    public function key(): string
    {
        return 'card';
    }

    public function label(): string
    {
        return 'Credit / Debit Card';
    }

    public function initiate(array $params): array
    {
        throw new LogicException('Card payments start a Stripe Checkout session; see StripePaymentService.');
    }

    public function settle(array $params): array
    {
        throw new LogicException('Card payments settle only from Stripe\'s confirmation; see StripePaymentService.');
    }
}
