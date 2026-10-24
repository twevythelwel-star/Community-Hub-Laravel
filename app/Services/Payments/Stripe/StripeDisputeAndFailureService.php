<?php

namespace App\Services\Payments\Stripe;

use App\Enums\PaymentState;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\Log;
use Stripe\StripeObject;

class StripeDisputeAndFailureService
{
    public const CHANNEL = 'stripe_card';

    public function __construct(
        protected LedgerService $ledgerService,
    ) {}

    /**
     * A Terminal reader finished (or abandoned) processing a payment.
     */
    public function handleReaderAction(StripeObject $reader, string $eventType, ?string $eventSource = null): string
    {
        $source = $eventSource ?? 'stripe:return';
        $action = $reader->action ?? null;
        $paymentIntent = StripeReferenceUtil::idOf($action?->process_payment_intent?->payment_intent ?? null);
        $payment = $paymentIntent ? StripeReferenceUtil::paymentFor($paymentIntent) : null;

        if (! $payment) {
            return 'ignored';
        }

        if ($eventType === 'terminal.reader.action_succeeded') {
            return 'reader_succeeded';
        }

        if (($action->failure_code ?? null) === 'connection_error') {
            Log::warning('Reader lost its connection mid-payment; waiting on the PaymentIntent', [
                'transaction_id' => $payment->transaction_id,
                'reader' => $reader->id,
            ]);

            return 'reader_connection_error';
        }

        $reason = (string) ($action->api_error->message ?? $action->failure_message ?? 'The reader could not take the payment.');

        return $payment->advanceTo(PaymentState::Failed, $source, $reason, ['failure_reason' => $reason])
            ? 'reader_failed'
            : 'stale';
    }

    /**
     * Record a payment failure event on the ledger and audit trail. Idempotent.
     */
    public function handlePaymentFailure(StripeObject $object, string $eventType, ?string $eventSource = null): string
    {
        $source = $eventSource ?? 'stripe:return';
        $id = $object->id;
        $invoiceId = $object->metadata->invoice_id ?? ($object->client_reference_id ?? null);

        $invoice = $invoiceId && ctype_digit((string) $invoiceId) ? Invoice::find((int) $invoiceId) : null;
        if (! $invoice && $invoiceId) {
            $invoice = Invoice::where('reference', (string) $invoiceId)->first();
        }
        if (! $invoice && isset($object->payment_intent)) {
            $piId = StripeReferenceUtil::idOf($object->payment_intent);
            if ($piId) {
                $invoice = Invoice::where('stripe_payment_intent', $piId)->first();
            }
        }

        $errorMessage = $object->last_payment_error->message
            ?? ($object->cancellation_reason ?? 'Payment failed or expired at processor.');

        $payment = StripeReferenceUtil::paymentFor($object);
        $payment?->advanceTo(
            $eventType === 'checkout.session.expired' ? PaymentState::Expired : PaymentState::Failed,
            $source,
            $errorMessage,
            ['failure_reason' => $errorMessage],
        );

        $amountMinor = (int) ($object->amount ?? ($object->amount_total ?? ($invoice?->amount_minor ?? 0)));

        if ($invoice) {
            Transaction::query()->firstOrCreate(
                ['reference' => "stripe-failed:{$id}"],
                [
                    'payment_id' => $payment?->id,
                    'user_id' => $invoice->user_id,
                    'invoice_id' => $invoice->id,
                    'amount_minor' => $amountMinor,
                    'fee_minor' => 0,
                    'net_amount_minor' => 0,
                    'currency' => strtoupper($invoice->currency),
                    'payment_channel' => self::CHANNEL,
                    'status' => 'failed',
                    'notes' => "Payment failed ({$eventType}): {$errorMessage}",
                ]
            );

            if ($invoice->status === 'Pending') {
                $invoice->update(['status' => 'Unpaid']);
            }

            Log::channel('security')->warning('Stripe payment failure recorded', [
                'invoice' => $invoice->reference,
                'event_type' => $eventType,
                'error' => $errorMessage,
            ]);
        }

        return 'failed';
    }

    /**
     * Process chargebacks and dispute lifecycle events. Idempotent.
     */
    public function handleDispute(StripeObject $dispute, string $eventType, ?string $eventSource = null): string
    {
        $source = $eventSource ?? 'stripe:return';
        $disputeId = $dispute->id;
        $amountMinor = (int) $dispute->amount;
        $currency = strtoupper((string) ($dispute->currency ?? ''));
        $invoice = $this->invoiceForDispute($dispute);

        if (! $invoice) {
            Log::warning('Stripe dispute for a payment this application did not record', [
                'dispute' => $disputeId,
                'payment_intent' => StripeReferenceUtil::idOf($dispute->payment_intent ?? null),
            ]);

            return 'unknown_invoice';
        }

        $record = fn (string $step, string $status, int $netMinor, string $notes): Transaction => Transaction::query()->firstOrCreate(
            ['reference' => "stripe-dispute:{$disputeId}:{$step}"],
            [
                'user_id' => $invoice->user_id,
                'invoice_id' => $invoice->id,
                'amount_minor' => $amountMinor,
                'fee_minor' => 0,
                'net_amount_minor' => $netMinor,
                'currency' => $currency ?: strtoupper($invoice->currency),
                'payment_channel' => self::CHANNEL,
                'provider' => 'stripe',
                'status' => $status,
                'dispute_reason' => $dispute->reason ?? 'general',
                'notes' => $notes,
            ],
        );

        $piId = StripeReferenceUtil::idOf($dispute->payment_intent ?? null);
        $ledgerPayment = $piId
            ? Transaction::query()->where('reference', StripeReferenceUtil::paymentReference($piId))->first()
            : null;
        $payment = $ledgerPayment?->payment;

        if (in_array($eventType, ['charge.dispute.created', 'charge.dispute.funds_withdrawn'], true)) {
            $record('created', 'disputed', -$amountMinor, "Chargeback/dispute opened ({$dispute->reason}). Status: {$dispute->status}");
            $payment?->advanceTo(PaymentState::Disputed, $source, "Dispute {$disputeId} opened ({$dispute->reason})");

            $invoice->update(['status' => 'Disputed']);

            Log::channel('security')->alert('Stripe chargeback/dispute created for invoice', [
                'invoice' => $invoice->reference,
                'dispute_id' => $disputeId,
                'amount' => $amountMinor,
                'reason' => $dispute->reason ?? 'unknown',
            ]);

            return 'disputed';
        }

        if ($eventType !== 'charge.dispute.closed') {
            return 'disputed';
        }

        if ($dispute->status === 'won') {
            $record('won', 'reinstated', $amountMinor, 'Dispute won in estate favor. Funds reinstated.');
            $payment?->advanceTo(PaymentState::Paid, $source, "Dispute {$disputeId} won");

            if ($invoice->status === 'Disputed') {
                $invoice->markPaid();
            }

            return 'dispute_won';
        }

        $chargeback = $record('lost', 'refunded', -$amountMinor, 'Dispute lost. Funds returned to cardholder.');
        $payment?->advanceTo(PaymentState::ChargedBack, $source, "Dispute {$disputeId} lost");

        if ($ledgerPayment) {
            $chargeback->update(['payment_id' => $ledgerPayment->payment_id]);
            $this->ledgerService->postReversal($ledgerPayment, $chargeback);
        }

        $invoice->update(['status' => 'Unpaid', 'paid_at' => null]);

        return 'dispute_lost';
    }

    /** The invoice a dispute's payment settled, found through its PaymentIntent. */
    public function invoiceForDispute(StripeObject $dispute): ?Invoice
    {
        $paymentIntent = StripeReferenceUtil::idOf($dispute->payment_intent ?? null);

        if ($paymentIntent) {
            $invoiceId = Transaction::query()
                ->where('reference', StripeReferenceUtil::paymentReference($paymentIntent))
                ->value('invoice_id')
                ?? Invoice::query()->where('stripe_payment_intent', $paymentIntent)->value('id');

            if ($invoiceId) {
                return Invoice::find($invoiceId);
            }
        }

        $invoiceId = $dispute->metadata->invoice_id ?? null;

        return $invoiceId ? Invoice::find((int) $invoiceId) : null;
    }
}
