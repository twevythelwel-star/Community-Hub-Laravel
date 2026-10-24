<?php

namespace App\Services\Payments\Providers;

use App\Models\Donation;
use App\Models\Fundraiser;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Models\User;

/**
 * What a payer wants to pay, handed to a provider's createPayment().
 *
 * `payment` is the Created payment behind the slip the payer was shown, when
 * there is one; a provider uses it instead of starting another. `donation`
 * is a pending gift already on record (office and wallet gifts); `donor`
 * carries the details a card donation needs to write its Donation once the
 * money is confirmed (donor_name, is_anonymous, is_recurring, frequency).
 */
final class PaymentRequest
{
    /**
     * @param  list<int>  $itemIds
     * @param  array{donor_name?: string, is_anonymous?: bool, is_recurring?: bool, frequency?: ?string}  $donor
     */
    public function __construct(
        public readonly ?User $payer,
        public readonly string $channel,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?Invoice $invoice = null,
        public readonly ?Fundraiser $fundraiser = null,
        public readonly ?Donation $donation = null,
        public readonly ?PaymentLink $paymentLink = null,
        public readonly ?Payment $payment = null,
        public readonly array $itemIds = [],
        public readonly array $donor = [],
        public readonly ?string $payerReference = null,
        public readonly ?string $deviceIdentifier = null,
    ) {}
}
