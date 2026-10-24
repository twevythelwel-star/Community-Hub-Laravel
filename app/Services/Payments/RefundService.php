<?php

namespace App\Services\Payments;

use App\Enums\PaymentState;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\PaymentRefund;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Messaging\MessageNotSent;
use App\Services\SmsService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Enterprise Refund Lifecycle Engine
 *
 * Implements the non-negotiable invariant:
 * "Do not mark the refund complete merely because CommunityHub sent a refund request."
 *
 * Full Lifecycle:
 *   Resident
 *        ↓
 *   Refund request (PaymentRefund status = pending)
 *        ↓
 *   CommunityHub authorization (Authorized staff approves)
 *        ↓
 *   Transaction Engine (Validates limits & generates idempotency key)
 *        ↓
 *   Provider refund (Dispatched to Stripe / WiPay API; status = processing)
 *        ↓
 *   Provider webhook (Authoritative server-to-server confirmation)
 *        ↓
 *   Refund confirmed (PaymentRefund status = succeeded)
 *        ↓
 *   Ledger reversal (Double-entry reversal DR Revenue / CR Cash)
 *        ↓
 *   Invoice/account adjustment (Reopens invoice / updates balance)
 *        ↓
 *   Receipt/refund notice (Generates PaymentReceipt credit note)
 *        ↓
 *   Twilio (Dispatches SMS/WhatsApp notification after confirmed truth)
 */
class RefundService
{
    public function __construct(
        protected LedgerService $ledger,
    ) {}

    /**
     * Step 1 & 2: Resident / Staff submits a refund request.
     * Creates a PaymentRefund with status 'pending'. Does NOT alter ledger or invoice.
     */
    public function requestRefund(Transaction $transaction, int $amountMinor, User $requester, ?string $reason = null): PaymentRefund
    {
        if ($transaction->status !== Transaction::STATUS_COMPLETED) {
            throw new DomainException('Cannot refund a transaction that is not completed.');
        }

        $refundable = $this->refundableMinor($transaction);

        if ($refundable <= 0) {
            throw new DomainException('This transaction has already been fully refunded.');
        }

        if ($amountMinor < 1 || $amountMinor > $refundable) {
            $formattedMax = number_format($refundable / 100, 2);
            throw new DomainException("Refund amount must be between 0.01 and {$formattedMax} {$transaction->currency}.");
        }

        $refundReference = 'REF-'.now()->format('Y').'-'.strtoupper(Str::random(8));

        return PaymentRefund::create([
            'transaction_id' => $transaction->id,
            'refund_reference' => $refundReference,
            'amount_minor' => $amountMinor,
            'currency' => $transaction->currency,
            'reason' => $reason ?? 'Customer requested refund',
            'status' => 'pending',
            'created_by' => $requester->id,
        ]);
    }

    /**
     * Step 3, 4 & 5: Authorized staff approves and dispatches refund to provider API.
     * Transitions status to 'processing'.
     * CRITICAL RULE: Does NOT mark refund complete and does NOT touch ledger yet!
     */
    public function authorizeAndDispatchToProvider(PaymentRefund $refund, User $authorizer): PaymentRefund
    {
        if ($refund->status !== 'pending') {
            throw new DomainException("Only pending refund requests can be authorized (current status: [{$refund->status}]).");
        }

        if ($refund->created_by !== null && $refund->created_by === $authorizer->id) {
            throw new DomainException('A refund must be authorized by someone other than the person who requested it.');
        }

        $transaction = $refund->transaction;

        return DB::transaction(function () use ($refund, $transaction, $authorizer): PaymentRefund {
            $refund->update([
                'status' => 'processing',
            ]);

            // Dispatch to Provider Gateway
            $providerRefundId = $this->dispatchToProviderGateway($refund, $transaction);

            $refund->update([
                'provider_refund_id' => $providerRefundId,
            ]);

            Log::info("Refund authorized by user {$authorizer->id}, awaiting provider confirmation: [{$refund->refund_reference}]");

            return $refund->fresh();
        });
    }

    /**
     * Step 6, 7, 8, 9, 10 & 11: Authoritative Provider Webhook confirms refund.
     *
     * Transitions status to 'succeeded', posts double-entry ledger reversal,
     * adjusts invoice status/balance, generates credit note receipt, and triggers Twilio.
     */
    public function confirmRefundFromWebhook(
        string $provider,
        string $providerRefundId,
        int $amountMinor,
        string $currency,
        ?string $reason = null,
        array $metadata = []
    ): PaymentRefund {
        return DB::transaction(function () use ($provider, $providerRefundId, $amountMinor, $currency, $reason, $metadata): PaymentRefund {
            // Find existing processing or pending refund record, or create one for provider-initiated refunds
            $refund = PaymentRefund::query()
                ->where('provider_refund_id', $providerRefundId)
                ->first();

            if (! $refund) {
                // Locate original transaction
                $tx = $this->findOriginalTransaction($provider, $providerRefundId, $metadata);

                if (! $tx) {
                    throw new DomainException("Cannot locate original transaction for provider refund [{$providerRefundId}].");
                }

                if (strtoupper($currency) !== strtoupper($tx->currency)) {
                    throw new DomainException("Refund currency [{$currency}] does not match the payment's [{$tx->currency}].");
                }

                // A refund staff authorized in the provider's portal, now confirmed.
                $refund = PaymentRefund::query()
                    ->where('transaction_id', $tx->id)
                    ->where('status', 'processing')
                    ->whereNull('provider_refund_id')
                    ->where('amount_minor', $amountMinor)
                    ->oldest('id')
                    ->first();

                if ($refund) {
                    $refund->update(['provider_refund_id' => $providerRefundId]);
                } elseif ($amountMinor < 1 || $amountMinor > $this->refundableMinor($tx)) {
                    throw new DomainException("Provider refund [{$providerRefundId}] exceeds what is left to refund on {$tx->transaction_id}.");
                }
            }

            if (! $refund) {
                $refund = PaymentRefund::create([
                    'transaction_id' => $tx->id,
                    'refund_reference' => 'REF-'.now()->format('Y').'-'.strtoupper(Str::random(8)),
                    'provider_refund_id' => $providerRefundId,
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                    'reason' => $reason ?? 'Confirmed provider refund',
                    'status' => 'processing',
                ]);
            }

            if ($refund->status === 'succeeded') {
                // Idempotent duplicate delivery
                return $refund;
            }

            $originalTx = $refund->transaction;

            // 1. Mark Refund Succeeded
            $refund->update([
                'status' => 'succeeded',
                'refunded_at' => now(),
            ]);

            // 2. Create Refund Ledger Transaction Row
            $refundTx = Transaction::create([
                'payment_id' => $originalTx->payment_id,
                'user_id' => $originalTx->user_id,
                'invoice_id' => $originalTx->invoice_id,
                'fundraiser_id' => $originalTx->fundraiser_id,
                'donation_id' => $originalTx->donation_id,
                'purpose' => $originalTx->purpose,
                'amount_minor' => $amountMinor,
                'fee_minor' => 0,
                'net_amount_minor' => -$amountMinor,
                'currency' => strtoupper($currency),
                'payment_channel' => $originalTx->payment_channel,
                'provider' => $provider,
                'provider_reference' => $originalTx->provider_reference,
                'provider_event_id' => $providerRefundId,
                'idempotency_key' => "idem:refund:{$providerRefundId}",
                'reference' => "refund:{$originalTx->transaction_id}:{$providerRefundId}",
                'status' => Transaction::STATUS_REFUNDED,
                'settled_at' => now(),
                'refunded_at' => now(),
                'notes' => "Refund confirmed by {$provider} ({$providerRefundId})",
            ]);

            // 3. Post Double-Entry Ledger Reversal (DR Revenue / CR Cash)
            $this->ledger->postReversal($originalTx, $refundTx);

            // 4. Update Payment State Machine
            $payment = $originalTx->payment;
            if ($payment) {
                $fullyRefunded = $this->refundableMinor($originalTx) <= 0;
                $payment->advanceTo(
                    $fullyRefunded ? PaymentState::Refunded : PaymentState::PartiallyRefunded,
                    "webhook:{$provider}:{$providerRefundId}",
                    "Confirmed refund of {$amountMinor} {$currency}"
                );
            }

            // 5. Invoice / Account Adjustment
            $invoice = $originalTx->invoice;
            if ($invoice && in_array($invoice->status, ['Paid', 'Partially Paid'], true)) {
                $remainingRefundable = $this->refundableMinor($originalTx);

                if ($remainingRefundable <= 0) {
                    $invoice->update([
                        'status' => 'Unpaid',
                        'paid_at' => null,
                    ]);
                } else {
                    $invoice->update([
                        'status' => 'Partially Paid',
                    ]);
                }
            }

            // 6. Generate Formal Refund Notice / Credit Receipt
            $receipt = $this->createRefundReceipt($refund, $refundTx);

            // 7. Dispatch Customer Notification via Twilio
            $this->dispatchRefundTwilioNotification($refund, $refundTx, $receipt);

            Log::info("Refund fully confirmed and settled: [{$refund->refund_reference}], amount: [{$amountMinor} {$currency}]");

            return $refund->fresh();
        });
    }

    /**
     * Dispatch refund request to external provider gateway.
     */
    /**
     * Hand the refund to the provider.
     *
     * Stripe refunds already have their own flow (StripePaymentService::refund),
     * which books the reversal from Stripe's own record of the charge; sending
     * them through here as well would book the same money back twice. Providers
     * without a refund API (WiPay) are refunded by staff in the provider's
     * portal, so there is no provider id yet: the refund waits in `processing`
     * until the provider's confirmation arrives.
     *
     * @return string|null The provider's refund id, when it issues one.
     *
     * @throws DomainException
     */
    protected function dispatchToProviderGateway(PaymentRefund $refund, Transaction $transaction): ?string
    {
        $provider = strtolower((string) $transaction->provider);

        if (str_starts_with($provider, 'stripe')) {
            throw new DomainException('Refund Stripe payments with the Stripe refund action; it records the refund from Stripe itself.');
        }

        return null;
    }

    /**
     * Calculate remaining refundable minor units on a transaction.
     */
    public function refundableMinor(Transaction $transaction): int
    {
        $alreadyRefunded = PaymentRefund::query()
            ->where('transaction_id', $transaction->id)
            ->where('status', 'succeeded')
            ->sum('amount_minor');

        return max(0, $transaction->amount_minor - (int) $alreadyRefunded);
    }

    /**
     * Create formal Refund Slip / Credit Note.
     */
    protected function createRefundReceipt(PaymentRefund $refund, Transaction $refundTx): PaymentReceipt
    {
        $receiptNumber = 'RN-'.now()->format('Y').'-'.str_pad((string) $refundTx->id, 5, '0', STR_PAD_LEFT);

        return PaymentReceipt::firstOrCreate(
            ['transaction_id' => $refundTx->id],
            [
                'receipt_number' => $receiptNumber,
                'receipt_type' => 'digital',
                'amount_minor' => $refund->amount_minor,
                'currency' => $refund->currency,
                'payer_name' => $refundTx->user?->name ?? 'Community Resident',
                'payer_lot' => $refundTx->user?->lot_number ?? null,
                'issued_by_name' => 'CommunityHub Refund Automation Engine',
                'issued_at' => now(),
            ]
        );
    }

    /**
     * Text the resident once the refund is committed.
     *
     * A delivery row is written only for a message Twilio accepted, with
     * Twilio's own SID, so the status callback can find it. When SMS is not
     * configured nothing is recorded as sent.
     */
    protected function dispatchRefundTwilioNotification(PaymentRefund $refund, Transaction $refundTx, PaymentReceipt $receipt): void
    {
        $user = $refundTx->user;
        $phone = $user?->phone;

        if (! $phone) {
            return;
        }

        $formattedAmount = number_format($refund->amount_minor / 100, 2);
        $message = "CommunityHub: Your refund of {$refund->currency} {$formattedAmount} has been confirmed by the provider. Refund Notice: {$receipt->receipt_number}.";

        DB::afterCommit(function () use ($refundTx, $user, $phone, $message): void {
            $sms = app(SmsService::class);

            if (! $sms->isConfigured()) {
                return;
            }

            try {
                $sid = $sms->send($phone, $message);
            } catch (MessageNotSent $e) {
                Log::warning('Refund SMS not sent: '.$e->getMessage());

                return;
            }

            NotificationDelivery::create([
                'transaction_id' => $refundTx->id,
                'user_id' => $user->id,
                'channel' => 'sms',
                'recipient' => $phone,
                'provider' => 'twilio',
                'provider_message_id' => $sid,
                'status' => 'queued',
                'sent_at' => now(),
            ]);
        });
    }

    /**
     * The completed payment a provider refund belongs to, by an explicit
     * identifier only: guessing (e.g. "the provider's latest payment") would
     * reverse someone else's money.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function findOriginalTransaction(string $provider, string $providerRefundId, array $metadata = []): ?Transaction
    {
        $payments = Transaction::query()
            ->where('provider', $provider)
            ->where('status', Transaction::STATUS_COMPLETED);

        if (isset($metadata['transaction_id'])) {
            return (clone $payments)->where('transaction_id', $metadata['transaction_id'])->first();
        }

        if (isset($metadata['payment_intent'])) {
            return (clone $payments)->where('provider_reference', $metadata['payment_intent'])->first();
        }

        return null;
    }
}
