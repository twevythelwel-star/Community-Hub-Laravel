<?php

namespace App\Services\Payments\Stripe;

use App\Enums\PaymentState;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\BalanceTransaction;
use Stripe\Charge;
use Stripe\Refund;
use Stripe\Stripe;
use Stripe\StripeObject;

class StripeRefundAndPayoutService
{
    public const CHANNEL = 'stripe_card';

    public function __construct(
        protected LedgerService $ledgerService,
    ) {}

    /**
     * Mark the payments a payout carried to the bank. Idempotent.
     */
    public function handlePayout(StripeObject $payout, string $eventType, ?string $secretKey = null): string
    {
        if ($eventType === 'payout.failed') {
            Log::channel('security')->alert('Stripe payout failed; the ledger was left unchanged', [
                'payout' => $payout->id,
                'amount' => $payout->amount ?? null,
                'failure_code' => $payout->failure_code ?? null,
                'failure_message' => $payout->failure_message ?? null,
            ]);

            return 'payout_failed';
        }

        foreach ($this->payoutCharges($payout->id, $secretKey) as $paymentIntent => $charge) {
            $payment = Transaction::query()
                ->where('reference', StripeReferenceUtil::paymentReference($paymentIntent))
                ->whereNull('payout_reference')
                ->first();

            if (! $payment) {
                continue;
            }

            $feeMinor = strtoupper($charge['currency']) === strtoupper($payment->currency)
                ? $charge['fee_minor']
                : (int) $payment->fee_minor;

            $payment->update([
                'payout_reference' => $payout->id,
                'fee_minor' => $feeMinor,
                'net_amount_minor' => $payment->amount_minor - $feeMinor,
                'settled_at' => now(),
            ]);
        }

        return 'payout_settled';
    }

    /**
     * The card payments a payout contained, from its balance transactions.
     *
     * @return array<string, array{fee_minor: int, currency: string}> keyed by PaymentIntent id
     */
    public function payoutCharges(string $payoutId, ?string $secretKey = null): array
    {
        Stripe::setApiKey($secretKey);

        $charges = [];

        $balanceTransactions = BalanceTransaction::all([
            'payout' => $payoutId,
            'limit' => 100,
            'expand' => ['data.source'],
        ]);

        foreach ($balanceTransactions->autoPagingIterator() as $balanceTransaction) {
            if (! in_array($balanceTransaction->type, ['charge', 'payment'], true)
                || ! $balanceTransaction->source instanceof StripeObject) {
                continue;
            }

            $paymentIntent = StripeReferenceUtil::idOf($balanceTransaction->source->payment_intent ?? null);

            if ($paymentIntent) {
                $charges[$paymentIntent] = [
                    'fee_minor' => (int) $balanceTransaction->fee,
                    'currency' => (string) $balanceTransaction->currency,
                ];
            }
        }

        return $charges;
    }

    /**
     * Bring the ledger into line with a charge's refunds. Idempotent.
     */
    public function syncRefunds(StripeObject $charge, ?string $eventSource = null): string
    {
        $source = $eventSource ?? 'stripe:return';
        $paymentIntent = StripeReferenceUtil::idOf($charge->payment_intent);

        if (! $paymentIntent) {
            return 'ignored';
        }

        $ledgerPayment = $this->ledgerPaymentFor($paymentIntent);

        if (! $ledgerPayment) {
            Log::warning('Stripe refund for a payment this application did not record', [
                'charge' => $charge->id,
                'payment_intent' => $paymentIntent,
            ]);

            return 'unknown_invoice';
        }

        return DB::transaction(function () use ($charge, $paymentIntent, $ledgerPayment, $source): string {
            $invoice = $ledgerPayment->invoice_id ? Invoice::query()->lockForUpdate()->find($ledgerPayment->invoice_id) : null;

            $totalRefunded = (int) $charge->amount_refunded;
            $delta = $totalRefunded - $this->refundedMinor($paymentIntent);

            if ($delta <= 0) {
                return 'duplicate';
            }

            $refundRow = Transaction::create([
                'payment_id' => $ledgerPayment->payment_id,
                'user_id' => $ledgerPayment->user_id,
                'invoice_id' => $ledgerPayment->invoice_id,
                'fundraiser_id' => $ledgerPayment->fundraiser_id,
                'donation_id' => $ledgerPayment->donation_id,
                'purpose' => $ledgerPayment->purpose,
                'amount_minor' => $delta,
                'fee_minor' => 0,
                'net_amount_minor' => $delta * -1,
                'settled_at' => now(),
                'refunded_at' => now(),
                'currency' => strtoupper((string) $charge->currency),
                'payment_channel' => self::CHANNEL,
                'provider' => 'stripe',
                'provider_reference' => $paymentIntent,
                'reference' => StripeReferenceUtil::refundReference($paymentIntent, $totalRefunded),
                'status' => 'refunded',
                'notes' => "Stripe refund on charge {$charge->id}",
            ]);

            $this->ledgerService->postReversal($ledgerPayment, $refundRow);

            $fullyRefunded = $totalRefunded >= $ledgerPayment->amount_minor;

            $ledgerPayment->payment?->advanceTo(
                $fullyRefunded ? PaymentState::Refunded : PaymentState::PartiallyRefunded,
                $source,
                "Stripe refunded {$totalRefunded} of {$ledgerPayment->amount_minor} minor units",
            );

            if ($fullyRefunded && $ledgerPayment->donation_id) {
                $ledgerPayment->donation?->update(['status' => 'refunded', 'refunded_at' => now()]);
            }

            if ($invoice
                && $invoice->stripe_payment_intent === $paymentIntent
                && in_array($invoice->status, ['Paid', 'Partially Paid'], true)) {
                $netMinor = (int) $charge->amount - $totalRefunded;

                if ($netMinor <= 0) {
                    $invoice->update(['status' => 'Unpaid', 'paid_at' => null]);
                    $invoice->items()->update(['status' => 'Unpaid']);
                } elseif ($netMinor < $invoice->amount_minor) {
                    $invoice->update(['status' => 'Partially Paid']);
                }
            }

            Log::info("Stripe refund of {$delta} minor units recorded against {$ledgerPayment->transaction_id}");

            return 'refunded';
        });
    }

    /**
     * How much of a recorded Stripe payment can still be refunded, in minor units.
     */
    public function refundableMinor(Transaction $payment): int
    {
        $paymentIntent = StripeReferenceUtil::paymentIntentOf($payment);

        if ($paymentIntent === null || $payment->status !== 'completed') {
            return 0;
        }

        return max(0, $payment->amount_minor - $this->refundedMinor($paymentIntent));
    }

    /**
     * Refund some or all of a Stripe payment.
     */
    public function refund(Transaction $payment, int $amountMinor, User $actor, ?string $note = null, ?string $secretKey = null): void
    {
        $paymentIntent = StripeReferenceUtil::paymentIntentOf($payment);
        $refundable = $this->refundableMinor($payment);

        if ($paymentIntent === null || $refundable === 0) {
            throw new DomainException('This payment has nothing left to refund.');
        }

        if ($amountMinor < 1 || $amountMinor > $refundable) {
            throw new DomainException('The refund must be between 0.01 and the amount still refundable.');
        }

        $alreadyRefunded = $payment->amount_minor - $refundable;

        Stripe::setApiKey($secretKey);

        $refund = Refund::create([
            'payment_intent' => $paymentIntent,
            'amount' => $amountMinor,
            'metadata' => array_filter([
                'invoice_id' => (string) $payment->invoice_id,
                'refunded_by' => (string) $actor->id,
                'note' => $note,
            ]),
        ], [
            'idempotency_key' => "refund-{$paymentIntent}-after-{$alreadyRefunded}-amount-{$amountMinor}",
        ]);

        $this->syncRefunds(Charge::retrieve(StripeReferenceUtil::idOf($refund->charge)));

        $actor->recordActivity("Refunded {$amountMinor} minor units of Stripe payment {$payment->reference}");
    }

    /** The completed ledger row a PaymentIntent's money was recorded on. */
    public function ledgerPaymentFor(?string $paymentIntent): ?Transaction
    {
        return $paymentIntent
            ? Transaction::query()->where('reference', StripeReferenceUtil::paymentReference($paymentIntent))->first()
            : null;
    }

    /** Refunds already on the ledger for a PaymentIntent, in minor units. */
    public function refundedMinor(string $paymentIntent): int
    {
        return (int) Transaction::query()
            ->where('status', 'refunded')
            ->where('reference', 'like', StripeReferenceUtil::refundReference($paymentIntent, '').'%')
            ->sum('amount_minor');
    }
}
