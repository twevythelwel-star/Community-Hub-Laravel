<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\PaymentState;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payments\Providers\Contracts\ConfirmsReturns;
use App\Services\Payments\Providers\ProviderRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where a hosted payment page sends the payer back: GET
 * /dashboard/payments/{payment}/return (WiPay's response_url).
 *
 * The query string is the provider's report of the outcome, carried by the
 * payer's browser, so it goes to the provider to verify before anything
 * changes. Only the payer can bring their own payment back.
 */
class PaymentReturnController extends Controller
{
    public function __invoke(Request $request, Payment $payment, ProviderRegistry $providers): RedirectResponse
    {
        abort_unless($payment->user_id === $request->user()->id, 403);

        $provider = $providers->byKey($payment->provider);
        abort_unless($provider instanceof ConfirmsReturns, 404);

        $payment = $provider->confirmPayment($payment, $request->query());
        $destination = $payment->fundraiser_id ? 'dashboard.fundraising' : 'dashboard.billing';

        return match ($payment->state) {
            PaymentState::Paid => redirect()->route($destination)
                ->with('success', $payment->fundraiser_id
                    ? "Thank you! Your gift {$payment->transaction_id} is recorded and your receipt is ready."
                    : "Payment {$payment->transaction_id} received. Thank you."),
            PaymentState::Succeeded => redirect()->route($destination)
                ->with('info', "Payment {$payment->transaction_id} was received, but the statement was already settled. The community office will refund it."),
            PaymentState::Failed => redirect()->route($destination)
                ->with('error', "Payment {$payment->transaction_id} did not go through: ".($payment->failure_reason ?: 'the card was not charged.')),
            default => redirect()->route($destination)
                ->with('error', "We could not confirm payment {$payment->transaction_id}. If you were charged, please contact the community office with that number."),
        };
    }
}
