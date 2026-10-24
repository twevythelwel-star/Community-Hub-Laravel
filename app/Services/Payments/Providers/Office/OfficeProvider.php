<?php

namespace App\Services\Payments\Providers\Office;

use App\Enums\PaymentState;
use App\Models\Payment;
use App\Models\PaymentChannelSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentOrchestratorService;
use App\Services\Payments\Providers\Contracts\PaymentProvider;
use App\Services\Payments\Providers\PaymentInstruction;
use App\Services\Payments\Providers\PaymentRequest;

/**
 * Payments the app cannot see: bank transfer, cash at the office, QR, NFC,
 * Zelle and Cash App. (Apple, Google and Samsung Pay are not among them: a
 * device wallet is taken only by a card processor; see OffersWalletPayments.)
 *
 * One provider for all of them, because they work the same way: the payer
 * sends the money outside the app, one administrator logs it as received, a
 * different administrator verifies it in a bank reconciliation, and only then
 * is it applied (PaymentOrchestratorService::markReceived / verifyReceived).
 * What differs per channel — account details, instructions, surcharge — is
 * configured per channel in payment_channel_settings, not coded per class.
 *
 * No webhooks, no refunds through a processor, no links, no recurring: the
 * office handles those by hand, so this implements the core contract only.
 */
class OfficeProvider implements PaymentProvider
{
    public const KEY = 'office';

    public function __construct(
        protected PaymentOrchestratorService $orchestrator,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Community office';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function createPayment(PaymentRequest $request): PaymentInstruction
    {
        $payment = $request->payment ?? $this->orchestrator->startPayment([
            'user' => $request->payer,
            'channel' => $request->channel,
            'invoice' => $request->invoice,
            'fundraiser' => $request->fundraiser,
            'donation' => $request->donation,
            'payment_link' => $request->paymentLink,
            'amount_minor' => $request->amountMinor,
            'currency' => $request->currency,
        ]);

        $payment->update([
            'channel' => $request->channel,
            'provider' => self::KEY,
            'invoice_id' => $request->invoice?->id ?? $payment->invoice_id,
            'donation_id' => $request->donation?->id ?? $payment->donation_id,
            'amount_minor' => $request->amountMinor,
            'currency' => $request->currency,
            'invoice_item_ids' => $request->itemIds ?: null,
            'device_identifier' => $request->deviceIdentifier ?? $payment->device_identifier,
        ]);

        $this->orchestrator->awaitTransfer($payment, $request->payer, $request->payerReference);

        return PaymentInstruction::instructions($payment, $this->instructionsFor($payment));
    }

    public function getPaymentStatus(Payment $payment): PaymentState
    {
        // No processor to ask: the office's own record is the status.
        return $payment->fresh()->state;
    }

    /** The payer withdraws before sending the money. */
    public function cancelPayment(Payment $payment, ?User $actor = null): Payment
    {
        $payment->transitionTo(PaymentState::Canceled, $actor, 'resident', 'Withdrawn by the payer before sending');
        $payment->donation?->update(['status' => 'rejected']);

        return $payment;
    }

    /** How to send the money, from the channel's settings, quoting the payment's number. */
    private function instructionsFor(Payment $payment): string
    {
        $setting = PaymentChannelSetting::query()->where('channel_key', $payment->channel)->first();
        $method = $setting?->display_label ?? Transaction::formatPaymentMethod($payment->channel);

        return trim(implode(' ', array_filter([
            "Send {$payment->currency} ".number_format($payment->amount_minor / 100, 2)." by {$method}, quoting {$payment->transaction_id}.",
            $setting?->account_identifier ? "Account: {$setting->account_identifier}." : null,
            $setting?->instructions,
            'It counts once the community office has received and verified it.',
        ])));
    }
}
