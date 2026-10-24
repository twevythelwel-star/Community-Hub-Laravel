<?php

namespace App\Services\Payments\Stripe;

use App\Enums\PaymentState;
use App\Models\Fundraiser;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Payments\PaymentOrchestratorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\StripeObject;

class StripeSettlementService
{
    public const CHANNEL = 'stripe_card';

    public function __construct(
        protected ?LedgerService $ledgerService = null,
    ) {
        $this->ledgerService ??= app(LedgerService::class);
    }

    protected function orchestrator(): PaymentOrchestratorService
    {
        return app(PaymentOrchestratorService::class);
    }

    /**
     * Record a paid Checkout Session and settle its invoice. Idempotent.
     */
    public function settleSession(StripeObject $session, ?string $eventSource = null): string
    {
        $source = $eventSource ?? 'stripe:return';

        if ($session->payment_status !== 'paid') {
            // Delayed methods complete the session before the money arrives.
            StripeReferenceUtil::paymentFor($session)?->advanceTo(PaymentState::Processing, $source, 'Checkout completed; payment still processing');

            return 'unpaid';
        }

        if (($session->metadata->purpose ?? null) === 'donation') {
            return $this->settleDonationSession($session, $source);
        }

        $invoice = Invoice::find((int) $session->client_reference_id);

        if (! $invoice) {
            Log::warning('Stripe checkout completed for an invoice that does not exist', [
                'session_id' => $session->id,
                'client_reference_id' => $session->client_reference_id,
            ]);

            return 'unknown_invoice';
        }

        if (strtoupper((string) $session->currency) !== strtoupper($invoice->currency)) {
            Log::channel('security')->warning('Stripe checkout currency does not match its invoice', [
                'invoice_id' => $invoice->id,
                'session_id' => $session->id,
                'session_currency' => $session->currency,
            ]);

            return 'mismatch';
        }

        return $this->settleInvoicePayment(
            $invoice,
            StripeReferenceUtil::idOf($session->payment_intent),
            (int) $session->amount_total,
            0,
            $session,
            $source,
        );
    }

    /**
     * Stripe has an invoice payment's money: record it, and apply it unless
     * the invoice was already settled some other way. Idempotent.
     */
    public function settleInvoicePayment(
        Invoice $invoice,
        string $paymentIntent,
        int $amountMinor,
        int $feeMinor,
        ?StripeObject $session = null,
        ?string $eventSource = null,
    ): string {
        $source = $eventSource ?? 'stripe:return';

        return DB::transaction(function () use ($invoice, $paymentIntent, $amountMinor, $feeMinor, $session, $source): string {
            // Serialises concurrent settlement of the same invoice
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (Transaction::query()->where('reference', StripeReferenceUtil::paymentReference($paymentIntent))->exists()) {
                return 'duplicate';
            }

            $payment = StripeReferenceUtil::paymentFor($session ?? $paymentIntent)
                ?? $this->orchestrator()->startPayment([
                    'user' => $invoice->user,
                    'channel' => 'card',
                    'invoice' => $invoice,
                    'amount_minor' => $amountMinor,
                    'currency' => $invoice->currency,
                    'source' => $source,
                ]);

            $payment->update(array_filter([
                'provider_session_id' => $session?->id ?? $payment->provider_session_id,
                'provider_payment_id' => $paymentIntent,
                'amount_minor' => $amountMinor,
            ]));

            $accepted = $payment->advanceTo(PaymentState::Succeeded, $source, 'Stripe confirmed the payment');

            $ledger = [
                'reference' => StripeReferenceUtil::paymentReference($paymentIntent),
                'provider_reference' => $paymentIntent,
                'provider_status' => 'succeeded',
                'amount_minor' => $amountMinor,
                'fee_minor' => $feeMinor,
                'net_amount_minor' => $amountMinor - $feeMinor,
                'notes' => $session ? "Stripe Checkout {$session->id}" : "Stripe PaymentIntent {$paymentIntent}",
            ];

            if (! $accepted || in_array($invoice->status, ['Paid', 'Waived'], true)) {
                $row = $this->orchestrator()->recordLedgerPayment($payment, $ledger);
                $this->ledgerService->postTransaction($row);

                Log::warning('Stripe payment received that cannot be applied; refund it from the ledger', [
                    'invoice' => $invoice->reference,
                    'transaction_id' => $payment->transaction_id,
                    'payment_state' => $payment->state->value,
                    'payment_intent' => $paymentIntent,
                    'amount_minor' => $amountMinor,
                ]);

                return $accepted ? 'overpaid' : 'unapplied';
            }

            $invoice->forceFill(array_filter([
                'stripe_session_id' => $session?->id,
                'stripe_payment_intent' => $paymentIntent,
            ]))->save();

            $this->orchestrator()->applyPayment($payment, $ledger, null, $source);

            Log::info("Invoice {$invoice->reference} paid via Stripe ({$paymentIntent}), payment {$payment->transaction_id}");

            return 'settled';
        });
    }

    /**
     * Record a paid donation Checkout Session. Idempotent.
     */
    public function settleDonationSession(StripeObject $session, ?string $eventSource = null): string
    {
        $source = $eventSource ?? 'stripe:return';
        $metadata = $session->metadata;
        $fundraiser = Fundraiser::find((int) ($metadata->fundraiser_id ?? 0));

        if (! $fundraiser) {
            Log::warning('Stripe donation checkout completed for a campaign that does not exist', [
                'session_id' => $session->id,
                'fundraiser_id' => $metadata->fundraiser_id ?? null,
            ]);

            return 'unknown_fundraiser';
        }

        if (strtoupper((string) $session->currency) !== strtoupper($fundraiser->goal_currency)) {
            Log::channel('security')->warning('Stripe donation currency does not match its campaign', [
                'fundraiser_id' => $fundraiser->id,
                'session_id' => $session->id,
                'session_currency' => $session->currency,
            ]);

            return 'mismatch';
        }

        $paymentIntent = StripeReferenceUtil::idOf($session->payment_intent);
        $amountMinor = (int) $session->amount_total;
        $donor = User::find((int) ($metadata->user_id ?? 0));
        $isAnonymous = ($metadata->is_anonymous ?? '0') === '1';
        $isRecurring = ($metadata->is_recurring ?? '0') === '1';
        $donorName = (string) ($metadata->donor_name ?? '');

        return DB::transaction(function () use ($fundraiser, $session, $paymentIntent, $amountMinor, $donor, $isAnonymous, $isRecurring, $donorName, $metadata, $source): string {
            if (Transaction::query()->where('reference', StripeReferenceUtil::paymentReference($paymentIntent))->exists()) {
                return 'duplicate';
            }

            $payment = StripeReferenceUtil::paymentFor($session)
                ?? $this->orchestrator()->startPayment([
                    'user' => $donor,
                    'channel' => 'card',
                    'fundraiser' => $fundraiser,
                    'amount_minor' => $amountMinor,
                    'currency' => $fundraiser->goal_currency,
                    'source' => $source,
                ]);

            $donation = $fundraiser->donations()->create([
                'user_id' => $donor?->id,
                'amount_minor' => $amountMinor,
                'currency' => strtoupper($fundraiser->goal_currency),
                'donor_name' => $donorName ?: null,
                'is_anonymous' => $isAnonymous,
                'is_recurring' => $isRecurring,
                'frequency' => $isRecurring ? (($metadata->frequency ?? '') ?: 'monthly') : null,
                'status' => 'pending',
                'receipt_number' => 'DON-REC-'.date('Ymd').'-'.strtoupper(Str::random(5)),
                'payment_channel' => self::CHANNEL,
                'donated_at' => now(),
                'notes' => "Stripe PaymentIntent {$paymentIntent}; payment {$payment->transaction_id}",
            ]);

            $payment->update([
                'provider_session_id' => $session->id,
                'provider_payment_id' => $paymentIntent,
                'amount_minor' => $amountMinor,
                'donation_id' => $donation->id,
            ]);

            $payment->advanceTo(PaymentState::Succeeded, $source, 'Stripe confirmed the donation');

            $this->orchestrator()->applyPayment($payment, [
                'reference' => StripeReferenceUtil::paymentReference($paymentIntent),
                'provider_reference' => $paymentIntent,
                'provider_status' => 'succeeded',
                'notes' => "Donation to {$fundraiser->title} by ".($isAnonymous ? 'Anonymous' : $donorName)." (Stripe Checkout {$session->id})",
            ], null, $source);

            Log::info("Donation to fundraiser {$fundraiser->id} received via Stripe ({$session->id}), payment {$payment->transaction_id}");

            return 'settled';
        });
    }

    /**
     * Settle an invoice when a PaymentIntent succeeds directly. Idempotent.
     */
    public function settlePaymentIntent(StripeObject $paymentIntent, ?string $eventSource = null): string
    {
        $source = $eventSource ?? 'stripe:return';
        $piId = $paymentIntent->id;

        if (($paymentIntent->metadata->purpose ?? null) === 'donation') {
            if ($paymentIntent->status === 'succeeded') {
                StripeReferenceUtil::paymentFor($paymentIntent)?->advanceTo(PaymentState::Succeeded, $source, 'Stripe confirmed the donation', ['provider_payment_id' => $piId]);
            }

            return 'ignored';
        }

        $invoiceId = $paymentIntent->metadata->invoice_id ?? null;

        $invoice = null;
        if ($invoiceId) {
            $invoice = ctype_digit((string) $invoiceId)
                ? Invoice::find((int) $invoiceId)
                : Invoice::where('reference', (string) $invoiceId)->first();
        }
        if (! $invoice) {
            $invoice = Invoice::where('stripe_payment_intent', $piId)->first();
        }

        if (! $invoice) {
            return 'unknown_invoice';
        }

        if ($paymentIntent->status !== 'succeeded') {
            return 'unpaid';
        }

        return $this->settleInvoicePayment(
            $invoice,
            $piId,
            (int) $paymentIntent->amount,
            (int) ($paymentIntent->application_fee_amount ?? 0),
            $paymentIntent,
            $source,
        );
    }

    /**
     * A payment needs the cardholder (3-D Secure) or is processing.
     */
    public function handleIntentProgress(StripeObject $paymentIntent, PaymentState $state, ?string $eventSource = null): string
    {
        $source = $eventSource ?? 'stripe:return';
        $payment = StripeReferenceUtil::paymentFor($paymentIntent);

        if (! $payment) {
            return 'ignored';
        }

        return $payment->advanceTo($state, $source, null, ['provider_payment_id' => $paymentIntent->id])
            ? $state->value
            : 'stale';
    }
}
