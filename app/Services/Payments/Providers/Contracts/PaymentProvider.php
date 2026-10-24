<?php

namespace App\Services\Payments\Providers\Contracts;

use App\Enums\PaymentState;
use App\Exceptions\IllegalPaymentTransition;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\Providers\PaymentInstruction;
use App\Services\Payments\Providers\PaymentRequest;

/**
 * Something that moves money for a payment: a card processor (Stripe, WiPay),
 * the Community Wallet, or the community office for channels the app cannot
 * see (bank wire, cash, QR, NFC, digital wallets, Zelle, Cash App).
 *
 * This is the core every provider can honestly implement. What only some
 * can do is a separate capability interface, checked before it is offered:
 *
 *   ConfirmsReturns          the payer's browser brings the result back (WiPay)
 *   HandlesWebhooks          the provider calls the app server-to-server (Stripe)
 *   RefundsPayments          money can be returned through the provider (Stripe)
 *   CreatesPaymentLinks      shareable links the provider hosts
 *   CreatesRecurringPayments scheduled charges the provider runs
 *
 * A provider never marks anything paid on its own say-so: it moves the
 * Payment through App\Enums\PaymentState, and money is applied only by
 * PaymentOrchestratorService::applyPayment() once the payment reaches a
 * state that allows it.
 */
interface PaymentProvider
{
    /** Stable key used in configuration and ledger rows: "stripe", "wipay", "office", "internal". */
    public function key(): string;

    /** Name shown to payers and administrators. */
    public function label(): string;

    /** Whether the provider is configured and may be offered now. */
    public function isAvailable(): bool;

    /**
     * Start a payment and say what the payer does next: go to a hosted page,
     * send money following instructions, or nothing (already settled).
     */
    public function createPayment(PaymentRequest $request): PaymentInstruction;

    /**
     * Where the payment stands, as far as this provider can tell. Providers
     * without a status API report the state last recorded from them.
     */
    public function getPaymentStatus(Payment $payment): PaymentState;

    /**
     * Withdraw a payment that has not been paid.
     *
     * @throws IllegalPaymentTransition when it is past cancelling
     */
    public function cancelPayment(Payment $payment, ?User $actor = null): Payment;
}
