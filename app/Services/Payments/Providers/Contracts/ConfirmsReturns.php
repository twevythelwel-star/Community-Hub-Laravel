<?php

namespace App\Services\Payments\Providers\Contracts;

use App\Models\Payment;

/**
 * A provider whose only report of the outcome is the payer's browser coming
 * back to the app (WiPay's response_url). What it brings is untrusted until
 * the provider's signature over it checks out.
 */
interface ConfirmsReturns
{
    /**
     * Verify the returned parameters and move the payment accordingly.
     * Returns the payment in its new state; anything that fails
     * verification leaves it untouched.
     *
     * @param  array<string, mixed>  $parameters  the query string the payer came back with
     */
    public function confirmPayment(Payment $payment, array $parameters): Payment;
}
