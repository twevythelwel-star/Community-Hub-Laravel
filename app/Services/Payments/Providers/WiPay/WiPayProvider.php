<?php

namespace App\Services\Payments\Providers\WiPay;

use App\Enums\PaymentState;
use App\Models\Donation;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Payments\PaymentOrchestratorService;
use App\Services\Payments\Providers\Contracts\ConfirmsReturns;
use App\Services\Payments\Providers\Contracts\PaymentProvider;
use App\Services\Payments\Providers\PaymentInstruction;
use App\Services\Payments\Providers\PaymentRequest;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Card payments through WiPay's hosted payment page (Payments API 1.0.8,
 * https://wipaycaribbean.com/WiPay-API-Documentation.pdf), for JMD and USD
 * in Jamaica, Barbados and Trinidad & Tobago.
 *
 * The flow WiPay offers is:
 *   1. POST the request; WiPay answers with a hosted page URL and assigns
 *      its transaction_id there and then.
 *   2. The payer pays on WiPay's page (3-D Secure included).
 *   3. WiPay sends the payer's browser back to our response_url with the
 *      result in the query string: status, transaction_id, order_id, total,
 *      and — for successes only — hash = md5(transaction_id . original total
 *      . API key).
 *
 * No device wallets: the API's only `method` is credit_card, so Apple Pay
 * and Google Pay are not offered while WiPay is the card processor.
 *
 * That browser return is WiPay's only report: the API has no webhook, no
 * status lookup, no refund and no recurring billing. So this provider
 * implements ConfirmsReturns and nothing more. A payer who closes the tab
 * before returning leaves the payment Processing until someone checks it in
 * the WiPay merchant dashboard; refunds are made there too.
 *
 * The returned parameters pass through the payer's browser, so none is taken
 * on trust. A result counts only if its transaction_id is the one WiPay
 * issued for this payment when it was created — which stops a genuine success
 * for one payment being replayed against another — and, for a success, its
 * hash checks out against the API key, which never leaves the server.
 */
class WiPayProvider implements ConfirmsReturns, PaymentProvider
{
    public const KEY = 'wipay';

    /** Two-letter country → the API host WiPay recommends for accounts there. */
    private const HOSTS = [
        'BB' => 'https://bb.wipayfinancial.com',
        'GY' => 'https://gy.wipayfinancial.com',
        'JM' => 'https://jm.wipayfinancial.com',
        'TT' => 'https://tt.wipayfinancial.com',
    ];

    public function __construct(
        protected PaymentOrchestratorService $orchestrator,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'WiPay';
    }

    public function isAvailable(): bool
    {
        return filled($this->config('account_number'))
            && filled($this->config('api_key'))
            && isset(self::HOSTS[strtoupper((string) $this->config('country_code'))])
            && in_array($this->config('environment'), ['sandbox', 'live'], true);
    }

    /**
     * @throws DomainException when WiPay is not configured or refuses the request
     */
    public function createPayment(PaymentRequest $request): PaymentInstruction
    {
        if (! $this->isAvailable()) {
            throw new DomainException('WiPay is not configured.');
        }

        if (! in_array(strtoupper($request->currency), ['JMD', 'USD', 'TTD'], true)) {
            throw new DomainException("WiPay cannot take payments in {$request->currency}.");
        }

        $payment = $request->payment ?? $this->orchestrator->startPayment([
            'user' => $request->payer,
            'channel' => 'card',
            'invoice' => $request->invoice,
            'fundraiser' => $request->fundraiser,
            'amount_minor' => $request->amountMinor,
            'currency' => $request->currency,
        ]);

        $payment->update([
            'channel' => 'card',
            'provider' => self::KEY,
            'invoice_id' => $request->invoice?->id ?? $payment->invoice_id,
            'fundraiser_id' => $request->fundraiser?->id ?? $payment->fundraiser_id,
            'amount_minor' => $request->amountMinor,
            'currency' => strtoupper($request->currency),
            // A card gift's Donation is written once the money is confirmed.
            'metadata' => [...($payment->metadata ?? []), ...($request->donor ? ['donor' => $request->donor] : [])],
        ]);

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(20)
            ->post($this->endpoint(), array_filter([
                'account_number' => $this->config('account_number'),
                'country_code' => strtoupper((string) $this->config('country_code')),
                'currency' => $payment->currency,
                'environment' => $this->config('environment'),
                'fee_structure' => $this->config('fee_structure'),
                'method' => 'credit_card',
                'order_id' => $this->orderId($payment),
                'origin' => Str::limit((string) $this->config('origin'), 32, ''),
                'response_url' => route('dashboard.payments.return', $payment),
                'total' => $this->formatTotal($payment->amount_minor),
                'email' => $request->payer?->email,
                'name' => $request->payer?->display_name,
            ], fn ($value) => $value !== null && $value !== ''));

        $url = $response->json('url');
        $wipayTransactionId = $response->json('transaction_id');

        if (! $response->successful() || ! $url || ! $wipayTransactionId) {
            $reason = (string) ($response->json('message') ?: 'WiPay could not start the payment.');
            $payment->transitionTo(PaymentState::Failed, $request->payer, 'wipay', $reason, ['failure_reason' => $reason]);

            Log::warning('WiPay refused a payment request', [
                'transaction_id' => $payment->transaction_id,
                'status' => $response->status(),
                'message' => $reason,
            ]);

            throw new DomainException('Card checkout could not be started. Please try again shortly.');
        }

        // WiPay assigns its transaction id at request. Holding it now is what
        // lets confirmPayment() refuse a result that belongs to another payment.
        $payment->update(['provider_payment_id' => (string) $wipayTransactionId]);

        // With the payer until WiPay sends them back. If they never return,
        // it stays here until someone checks the WiPay merchant dashboard.
        $payment->advanceTo(PaymentState::Processing, 'wipay', 'Sent to the WiPay hosted payment page');

        return PaymentInstruction::redirect($payment->fresh(), (string) $url);
    }

    public function confirmPayment(Payment $payment, array $parameters): Payment
    {
        return DB::transaction(function () use ($payment, $parameters): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->provider !== self::KEY || $payment->state === PaymentState::Paid) {
                return $payment;
            }

            $status = (string) ($parameters['status'] ?? '');
            $wipayTransactionId = (string) ($parameters['transaction_id'] ?? '');

            if ($wipayTransactionId === '' || $wipayTransactionId !== (string) $payment->provider_payment_id
                || (string) ($parameters['order_id'] ?? '') !== $this->orderId($payment)) {
                $this->refuse($payment, 'WiPay result does not belong to this payment', $parameters);

                return $payment;
            }

            if ($status !== 'success') {
                $message = Str::limit((string) ($parameters['message'] ?? 'Card payment was not completed.'), 250);
                $payment->advanceTo(PaymentState::Failed, 'wipay:return', $message, ['failure_reason' => $message]);

                return $payment;
            }

            $expected = md5($wipayTransactionId.$this->formatTotal($payment->amount_minor).$this->config('api_key'));

            if (! hash_equals($expected, strtolower((string) ($parameters['hash'] ?? '')))
                || strtoupper((string) ($parameters['currency'] ?? $payment->currency)) !== $payment->currency) {
                $this->refuse($payment, 'WiPay result failed verification', $parameters);

                return $payment;
            }

            $payment->advanceTo(PaymentState::Succeeded, 'wipay:return', 'WiPay confirmed the payment');

            $finalTotalMinor = (int) round(((float) ($parameters['total'] ?? 0)) * 100);
            $ledger = [
                'reference' => "wipay:{$wipayTransactionId}",
                'provider_reference' => $wipayTransactionId,
                'provider_status' => 'success',
                'notes' => trim('WiPay '.($parameters['message'] ?? '').' '.($parameters['card'] ?? '')),
                'metadata' => ['wipay_final_total_minor' => $finalTotalMinor, 'wipay_date' => $parameters['date'] ?? null],
            ];

            if ($payment->fundraiser_id && ! $payment->donation_id) {
                $payment->update(['donation_id' => $this->recordDonation($payment)->id]);
            }

            // Money received for a statement already settled some other way is
            // recorded, not applied, and left Succeeded for a refund.
            if ($payment->invoice && in_array($payment->invoice->status, ['Paid', 'Waived'], true)) {
                app(LedgerService::class)->postTransaction($this->orchestrator->recordLedgerPayment($payment, $ledger));

                return $payment;
            }

            $this->orchestrator->applyPayment($payment, $ledger, null, 'wipay:return');

            return $payment->fresh();
        });
    }

    public function getPaymentStatus(Payment $payment): PaymentState
    {
        // WiPay has no status API: this is what the payer's return reported.
        return $payment->fresh()->state;
    }

    public function cancelPayment(Payment $payment, ?User $actor = null): Payment
    {
        return $payment->transitionTo(PaymentState::Canceled, $actor, 'resident', 'Withdrawn by the payer');
    }

    /**
     * WiPay's order_id: at most 16 characters on one of its card processors,
     * alphanumeric at both ends. CH-2026-0000000015 → CH20260000000015.
     */
    public function orderId(Payment $payment): string
    {
        return str_replace('-', '', $payment->transaction_id);
    }

    private function formatTotal(int $amountMinor): string
    {
        return number_format($amountMinor / 100, 2, '.', '');
    }

    private function endpoint(): string
    {
        return self::HOSTS[strtoupper((string) $this->config('country_code'))].'/plugins/payments/request';
    }

    private function config(string $key): mixed
    {
        return config("payments.providers.wipay.{$key}");
    }

    /** @param  array<string, mixed>  $parameters */
    private function refuse(Payment $payment, string $reason, array $parameters): void
    {
        Log::channel('security')->warning($reason, [
            'transaction_id' => $payment->transaction_id,
            'expected_wipay_transaction' => $payment->provider_payment_id,
            'returned' => array_intersect_key($parameters, array_flip(['status', 'transaction_id', 'order_id', 'total', 'currency'])),
        ]);
    }

    /** The Donation for a card gift, written now that the money is confirmed. */
    private function recordDonation(Payment $payment): Donation
    {
        $donor = $payment->metadata['donor'] ?? [];
        $isRecurring = (bool) ($donor['is_recurring'] ?? false);

        return $payment->fundraiser->donations()->create([
            'user_id' => $payment->user_id,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'donor_name' => ($donor['donor_name'] ?? null) ?: $payment->user?->display_name,
            'is_anonymous' => (bool) ($donor['is_anonymous'] ?? false),
            'is_recurring' => $isRecurring,
            'frequency' => $isRecurring ? (($donor['frequency'] ?? null) ?: 'monthly') : null,
            // Completed by applyPayment(), in the same transaction.
            'status' => 'pending',
            'receipt_number' => 'DON-REC-'.date('Ymd').'-'.strtoupper(Str::random(5)),
            'payment_channel' => 'card',
            'donated_at' => now(),
            'notes' => "WiPay payment {$payment->transaction_id}",
        ]);
    }
}
