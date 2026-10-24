<?php

namespace App\Actions\Billing;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentTerminal;
use App\Models\User;
use App\Services\Payments\PaymentOrchestratorService;
use App\Services\Payments\Providers\Contracts\Instruction;
use App\Services\Payments\Providers\ProviderRegistry;
use Illuminate\Validation\ValidationException;

class ChargeOnTerminalAction
{
    public function __construct(
        private readonly PaymentOrchestratorService $orchestrator,
        private readonly ProviderRegistry $providers,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{payment: Payment, instruction: Instruction}
     */
    public function execute(array $validated, User $actor): array
    {
        $provider = $this->providers->inPersonProvider();
        $terminal = PaymentTerminal::findOrFail($validated['terminal_id']);
        $invoice = Invoice::findOrFail($validated['invoice_id']);
        $amountMinor = (int) round($validated['amount'] * 100);

        if (! $provider || $provider->key() !== $terminal->provider || ! $terminal->isUsable()) {
            throw ValidationException::withMessages([
                'terminal_id' => "Reader {$terminal->label} cannot take payments: it is not a confirmed reader of the estate's in-person provider.",
            ]);
        }

        if ($amountMinor > $invoice->balanceRemainingMinor()) {
            throw ValidationException::withMessages([
                'amount' => 'That is more than the balance still owed on this statement.',
            ]);
        }

        $payment = $this->orchestrator->startPayment([
            'user' => $invoice->user,
            'channel' => 'nfc_pos',
            'invoice' => $invoice,
            'amount_minor' => $amountMinor,
            'currency' => $invoice->currency,
            'source' => 'staff',
        ]);

        try {
            $instruction = $provider->startInPersonPayment($payment, $terminal);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages([
                'terminal_id' => $e->getMessage(),
            ]);
        }

        $actor->recordActivity("Sent payment {$payment->transaction_id} for {$invoice->reference} to reader {$terminal->label}");

        return [
            'payment' => $payment,
            'instruction' => $instruction,
        ];
    }
}
