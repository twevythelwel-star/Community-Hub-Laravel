<?php

namespace App\Services\Payments\Providers\Contracts;

use App\Models\Transaction;
use App\Models\User;

/** A provider that can return money to the payer itself. */
interface RefundsPayments
{
    /**
     * Refund part or all of a recorded payment, in minor units.
     *
     * @throws \DomainException when the payment cannot be refunded by that amount
     */
    public function refundPayment(Transaction $ledgerPayment, int $amountMinor, User $actor, ?string $note = null): void;
}
