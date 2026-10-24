<?php

namespace App\Services\Payments\Providers\Contracts;

use App\Models\Payment;
use App\Models\PaymentTerminal;
use App\Models\User;
use App\Services\Payments\Providers\PaymentInstruction;

/**
 * A provider that takes card-present payments — tap (NFC) or insert — on a
 * reader it manages:
 *
 *     CommunityHub POS → reader → processor API → acquirer → card network
 *       → issuer → approved or declined → processor webhook → CommunityHub
 *
 * An NFC-capable phone is not a reader, and a registered reader is not proof
 * a payment can be taken: the processor must support the account's and the
 * reader location's country, and the payment must be in that location's
 * currency. So a reader is usable only after the processor has confirmed it
 * (registerTerminal), and every payment is still accepted or refused by the
 * processor, never assumed.
 */
interface TakesInPersonPayments
{
    /**
     * Register a reader with the processor and record it once the processor
     * has confirmed it exists, on this account, at a known location.
     *
     * @throws \DomainException when the processor refuses the reader or location
     */
    public function registerTerminal(string $registrationCode, string $locationId, string $label, User $admin): PaymentTerminal;

    /**
     * Send a started payment to a reader for the payer to tap or insert a
     * card. The outcome arrives from the processor, not from this call.
     *
     * @throws \DomainException when the reader cannot take it (busy, offline, unverified)
     */
    public function startInPersonPayment(Payment $payment, PaymentTerminal $terminal): PaymentInstruction;

    /** Clear a payment from the reader before a card is presented. */
    public function cancelInPersonPayment(Payment $payment, ?User $actor = null): Payment;
}
