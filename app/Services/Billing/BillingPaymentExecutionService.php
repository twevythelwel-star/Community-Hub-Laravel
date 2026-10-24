<?php

namespace App\Services\Billing;

use App\Enums\PaymentState;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentOrchestratorService;
use App\Services\Payments\Providers\PaymentRequest;
use App\Services\Payments\Providers\ProviderRegistry;
use App\Services\StripePaymentService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class BillingPaymentExecutionService
{
    public function executePay(User $user, array $validated, ProviderRegistry $providers): HttpResponse
    {
        $invoice = isset($validated['invoice_id'])
            ? $user->invoices()->whereKey($validated['invoice_id'])->first()
            : $user->invoices()->outstanding()->latest('due_on')->first();

        if (isset($validated['invoice_id']) && ! $invoice) {
            return back()->withErrors(['invoice_id' => 'That statement is not on your account.']);
        }

        $currency = strtoupper($validated['currency'] ?? ($invoice?->currency ?? 'JMD'));
        $amountMinor = (int) round($validated['amount'] * 100);

        $isCard = $validated['channel'] === 'card' || in_array($validated['channel'], ProviderRegistry::WALLET_CHANNELS, true);

        $provider = $providers->forChannel($validated['channel']);

        if (! $provider) {
            $method = Transaction::formatPaymentMethod($validated['channel']);

            return back()->withErrors(['channel' => "{$method} payments are not available yet. Please choose another method or pay at the community office."]);
        }

        if ($isCard) {
            if (! $invoice) {
                return back()->withErrors(['invoice_id' => 'There is no open statement to pay by card.']);
            }

            if ($currency !== strtoupper($invoice->currency)) {
                return back()->withErrors(['currency' => "Card payments are charged in {$invoice->currency}, the statement's currency."]);
            }

            if (! empty($validated['item_ids'])) {
                return back()->withErrors(['item_ids' => 'Individual charges cannot be paid by card. Pay an amount towards the statement instead.']);
            }

            if ($amountMinor > $invoice->balanceRemainingMinor()) {
                return back()->withErrors(['amount' => 'That is more than the balance still owed on this statement.']);
            }
        }

        $itemIds = [];
        if (! empty($validated['item_ids'])) {
            if (! $invoice) {
                return back()->withErrors(['item_ids' => 'Select a statement before paying individual charges.']);
            }

            $itemIds = $invoice->items()->whereKey($validated['item_ids'])->pluck('id')->all();

            if (count($itemIds) !== count($validated['item_ids'])) {
                return back()->withErrors(['item_ids' => 'One or more of those charges is not on this statement.']);
            }
        }

        $payment = null;
        if (! empty($validated['transaction_id'])) {
            $payment = Payment::query()
                ->where('transaction_id', $validated['transaction_id'])
                ->where('user_id', $user->id)
                ->first();

            if (! $payment || $payment->state !== PaymentState::Created) {
                return back()->withErrors(['transaction_id' => 'That payment has already been submitted, or is not yours.']);
            }
        }

        $walletShareMinor = (int) round(($validated['split_wallet_amount'] ?? 0) * 100);

        if ($walletShareMinor > $amountMinor) {
            return back()->withErrors([
                'split_wallet_amount' => 'The wallet portion cannot exceed the payment amount.',
            ]);
        }

        if ($walletShareMinor > 0) {
            try {
                $providers->forChannel('wallet')->createPayment(new PaymentRequest(
                    payer: $user,
                    channel: 'wallet',
                    amountMinor: $walletShareMinor,
                    currency: $currency,
                    invoice: $invoice,
                ));
            } catch (\DomainException $e) {
                return back()->withErrors(['split_wallet_amount' => $e->getMessage()]);
            }

            $amountMinor -= $walletShareMinor;
        }

        $channel = $validated['channel'];
        $amountLabel = "{$currency} ".number_format($validated['amount'], 2);

        if ($amountMinor <= 0) {
            $user->recordActivity("Paid {$amountLabel} from the Community Wallet");

            return back()->with('success', "Payment of {$amountLabel} recorded.");
        }

        try {
            $instruction = $provider->createPayment(new PaymentRequest(
                payer: $user,
                channel: $channel,
                amountMinor: $amountMinor,
                currency: $currency,
                invoice: $invoice,
                payment: $payment,
                itemIds: $itemIds,
                payerReference: $validated['payer_reference'] ?? null,
                deviceIdentifier: $validated['device_identifier'] ?? null,
            ));
        } catch (\DomainException $e) {
            return back()->withErrors([$isCard ? 'amount' : 'channel' => $e->getMessage()]);
        } catch (ApiErrorException $e) {
            report($e);

            return back()->withErrors(['channel' => 'Card checkout could not be started. Please try again shortly.']);
        }

        if ($instruction->isRedirect()) {
            return Inertia::location($instruction->url);
        }

        $number = $instruction->payment->transaction_id;

        if ($instruction->type === 'completed') {
            $user->recordActivity("Paid {$amountLabel} via {$channel} ({$number})");

            return back()->with('success', "Payment of {$amountLabel} recorded.");
        }

        $user->recordActivity("Reported a payment of {$amountLabel} via {$channel} ({$number}), awaiting confirmation");

        return back()->with('success', "Payment {$number} of {$amountLabel} submitted. Your statement updates once the community office has received and verified it.");
    }

    public function initiatePayment(
        User $user,
        array $validated,
        PaymentOrchestratorService $orchestrator,
        ProviderRegistry $providers
    ): JsonResponse {
        if (! $providers->isAvailable($validated['channel'])) {
            $message = Transaction::formatPaymentMethod($validated['channel']).' payments are not available yet.';

            return response()->json(['message' => $message, 'errors' => ['channel' => [$message]]], 422);
        }

        $invoice = isset($validated['invoice_id'])
            ? $user->invoices()->whereKey($validated['invoice_id'])->first()
            : $user->invoices()->outstanding()->latest('due_on')->first();

        if (isset($validated['invoice_id']) && ! $invoice) {
            return response()->json(['message' => 'That statement is not on your account.', 'errors' => ['invoice_id' => ['That statement is not on your account.']]], 422);
        }

        $amountMinor = (int) round($validated['amount'] * 100);

        if ($invoice && $amountMinor > $invoice->balanceRemainingMinor()) {
            return response()->json(['message' => 'That is more than the balance still owed on this statement.', 'errors' => ['amount' => ['That is more than the balance still owed on this statement.']]], 422);
        }

        $payment = $orchestrator->startPayment([
            'user' => $user,
            'channel' => $validated['channel'],
            'invoice' => $invoice,
            'purpose' => $validated['purpose'] ?? null,
            'amount_minor' => $amountMinor,
            'currency' => strtoupper($validated['currency'] ?? ($invoice?->currency ?? 'JMD')),
            'device_identifier' => $validated['device_identifier'] ?? null,
            'metadata' => ['initiated_via' => 'portal_payment_center'],
        ]);

        $slip = $payment->toSlip();

        return response()->json([
            'success' => true,
            'transaction' => $slip,
            'slip' => $slip,
            'transaction_id' => $payment->transaction_id,
            'status' => $payment->state->value,
        ]);
    }

    public function processCardPayment(
        User $user,
        array $validated,
        StripePaymentService $stripe
    ): JsonResponse {
        $invoice = isset($validated['invoice_id'])
            ? $user->invoices()->whereKey($validated['invoice_id'])->first()
            : $user->invoices()->outstanding()->latest('due_on')->first();

        if (isset($validated['invoice_id']) && ! $invoice) {
            return response()->json(['message' => 'That statement is not on your account.', 'errors' => ['invoice_id' => ['That statement is not on your account.']]], 422);
        }

        if (! $invoice) {
            return response()->json(['message' => 'There is no open statement to pay.', 'errors' => ['invoice_id' => ['There is no open statement to pay.']]], 422);
        }

        $amountMinor = (int) round($validated['amount'] * 100);

        if ($amountMinor > $invoice->balanceRemainingMinor()) {
            return response()->json(['message' => 'That is more than the balance still owed on this statement.', 'errors' => ['amount' => ['That is more than the balance still owed on this statement.']]], 422);
        }

        if (! $stripe->isLive()) {
            return response()->json(['message' => 'Card payments are not available yet. Please pay at the community office or by bank transfer.'], 503);
        }

        try {
            $checkoutUrl = $stripe->createCheckoutSession(
                $invoice,
                route('dashboard.billing.stripe.success', ['invoice' => $invoice->id]),
                route('dashboard.billing.stripe.cancel', ['invoice' => $invoice->id]),
                $amountMinor,
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ApiErrorException $e) {
            report($e);

            return response()->json(['message' => 'Card checkout could not be started. Please try again shortly.'], 502);
        }

        return response()->json(['checkout_url' => $checkoutUrl]);
    }
}
