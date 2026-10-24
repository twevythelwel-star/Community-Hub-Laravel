<?php

namespace App\Services\Payments\Providers\Contracts;

use App\Models\PaymentLink;

/**
 * A provider that can host a shareable payment link.
 *
 * No provider implements this yet: WiPay's Payments API has no link
 * endpoint, and the Stripe integration does not use Payment Links. The
 * public link page stays off until one does (config/payments.php).
 */
interface CreatesPaymentLinks
{
    /** The provider-hosted URL for this link. */
    public function createPaymentLink(PaymentLink $link): string;
}
