<?php

namespace App\Services\Payments\Providers\Contracts;

use App\Models\AutoPaySetting;

/**
 * A provider that can charge a payer on a schedule.
 *
 * No provider implements this yet: WiPay's Payments API has no recurring
 * billing, and the Stripe integration does not use subscriptions. AutoPay
 * preferences are stored, but nothing charges them until a provider does.
 */
interface CreatesRecurringPayments
{
    /** Register the schedule with the provider; returns its reference. */
    public function createRecurringPayment(AutoPaySetting $schedule): string;
}
