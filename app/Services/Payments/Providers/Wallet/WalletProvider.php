<?php

namespace App\Services\Payments\Providers\Wallet;

use App\Enums\PaymentState;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\PaymentOrchestratorService;
use App\Services\Payments\Providers\Contracts\PaymentProvider;
use App\Services\Payments\Providers\PaymentInstruction;
use App\Services\Payments\Providers\PaymentRequest;
use DomainException;

/**
 * The Community Wallet: a balance this app holds, so the money is taken and
 * applied in the same request — Created → Succeeded → Paid.
 */
class WalletProvider implements PaymentProvider
{
    public const KEY = 'internal';

    public function __construct(
        protected PaymentOrchestratorService $orchestrator,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Community Wallet';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @throws DomainException when the wallet does not hold the amount; the
     *                         payment is left Failed and nothing is debited
     */
    public function createPayment(PaymentRequest $request): PaymentInstruction
    {
        $payer = $request->payer ?? throw new DomainException('A wallet payment needs a signed-in payer.');

        $payment = $request->payment ?? $this->orchestrator->startPayment([
            'user' => $payer,
            'channel' => 'wallet',
            'invoice' => $request->invoice,
            'fundraiser' => $request->fundraiser,
            'donation' => $request->donation,
            'amount_minor' => $request->amountMinor,
            'currency' => $request->currency,
        ]);

        $payment->update([
            'channel' => 'wallet',
            'provider' => self::KEY,
            'invoice_id' => $request->invoice?->id ?? $payment->invoice_id,
            'donation_id' => $request->donation?->id ?? $payment->donation_id,
            'amount_minor' => $request->amountMinor,
            'invoice_item_ids' => $request->itemIds ?: null,
        ]);

        $settlement = $this->orchestrator->settlePayment('wallet', [
            'amount_minor' => $request->amountMinor,
            'currency' => $request->currency,
            'user_id' => $payer->id,
            'description' => "Payment {$payment->transaction_id}".($request->invoice ? " for invoice {$request->invoice->reference}" : ''),
        ]);

        // The wallet refuses when the balance is short. That refusal was once
        // ignored, and a short wallet recorded a completed payment.
        if (! ($settlement['success'] ?? false)) {
            $reason = 'Your Community Wallet does not hold that much.';
            $payment->transitionTo(PaymentState::Failed, $payer, 'resident', $reason, ['failure_reason' => $reason]);
            $payment->donation?->update(['status' => 'rejected']);

            throw new DomainException($reason);
        }

        $payment->transitionTo(PaymentState::Succeeded, $payer, 'resident', 'Debited from the Community Wallet');

        $this->orchestrator->applyPayment($payment, [
            'reference' => $settlement['reference'] ?? $payment->transaction_id,
            'provider_reference' => $settlement['reference'] ?? null,
            'notes' => 'Paid from the Community Wallet',
        ], $payer, 'resident');

        return PaymentInstruction::completed($payment->fresh(), 'Paid from your Community Wallet.');
    }

    public function getPaymentStatus(Payment $payment): PaymentState
    {
        return $payment->fresh()->state;
    }

    /** Only a wallet payment that never went through can be withdrawn. */
    public function cancelPayment(Payment $payment, ?User $actor = null): Payment
    {
        return $payment->transitionTo(PaymentState::Canceled, $actor, 'resident', 'Withdrawn by the payer');
    }
}
