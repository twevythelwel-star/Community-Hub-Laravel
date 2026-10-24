<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StripePaymentService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe Checkout.
 *
 * The whole flow 404s unless a real Stripe key is configured. Without a
 * processor there is nothing here to do but simulate a payment, and
 * simulating one is what made `success()` a way to clear a debt for free.
 */
class StripeCheckoutController extends Controller
{
    public function __construct(
        protected StripePaymentService $stripe
    ) {}

    /**
     * Initiate Stripe Checkout session for an invoice.
     *
     * The Pay button posts here through Inertia, which sends an XHR. An XHR
     * cannot follow a redirect to checkout.stripe.com, so the plain
     * `redirect()->away()` this used to return left the resident on the
     * billing page with nothing happening. Inertia::location() answers an
     * Inertia request with a 409 and X-Inertia-Location, which makes the client
     * do a full-page visit; a non-Inertia request still gets a normal redirect.
     */
    public function checkout(Request $request, Invoice $invoice): Response
    {
        $this->ensureEnabled();
        $this->authorizeInvoice($request->user(), $invoice);

        if (in_array($invoice->status, ['Paid', 'Waived'], true)) {
            return redirect()->route('dashboard.billing')
                ->with('success', "Invoice {$invoice->reference} is already settled.");
        }

        try {
            $checkoutUrl = $this->stripe->createCheckoutSession(
                $invoice,
                route('dashboard.billing.stripe.success', ['invoice' => $invoice->id]),
                route('dashboard.billing.stripe.cancel', ['invoice' => $invoice->id]),
            );
        } catch (ApiErrorException $e) {
            report($e);

            return redirect()->route('dashboard.billing')->with(
                'error',
                "Card checkout for invoice {$invoice->reference} could not be started. Please try again shortly.",
            );
        } catch (DomainException $e) {
            return redirect()->route('dashboard.billing')->with('error', $e->getMessage());
        }

        return Inertia::location($checkoutUrl);
    }

    /**
     * Handle return from Stripe checkout.
     *
     * This was a GET with no authorization of any kind that called
     * completePayment() and announced success unconditionally. Requesting
     *
     *     /dashboard/billing/invoices/{any}/stripe-success?session_id=anything
     *
     * marked any invoice in the estate Paid. Three things were missing and are
     * now present: the feature gate, the ownership check `checkout()` already
     * had, and any regard for whether settlement actually succeeded.
     */
    public function success(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->ensureEnabled();
        $this->authorizeInvoice($request->user(), $invoice);

        $validated = $request->validate([
            'session_id' => ['required', 'string', 'max:255'],
        ]);

        $settled = $this->stripe->completePayment($invoice, $validated['session_id']);

        if (! $settled) {
            return redirect()->route('dashboard.billing')->with(
                'error',
                "We could not confirm payment for invoice {$invoice->reference} with Stripe. "
                .'It has been left unpaid. If you were charged, contact the community office.',
            );
        }

        return redirect()->route('dashboard.billing')
            ->with('success', "Payment received for invoice {$invoice->reference}.");
    }

    /**
     * Handle cancelled checkout.
     */
    public function cancel(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->ensureEnabled();
        $this->authorizeInvoice($request->user(), $invoice);

        return redirect()->route('dashboard.billing')
            ->with('info', "Payment cancelled for invoice {$invoice->reference}. No charges were made.");
    }

    /**
     * Refund some or all of a Stripe payment. Administrators only (route gate
     * `manageBilling`).
     *
     * The ledger row and any change to the invoice are written from Stripe's
     * own record of the charge, the same way the `charge.refunded` webhook
     * does, so the two cannot both record the same refund.
     */
    public function refund(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->ensureEnabled();

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $amountMinor = (int) round($validated['amount'] * 100);

        try {
            $this->stripe->refund($transaction, $amountMinor, $request->user(), $validated['note'] ?? null);
        } catch (DomainException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        } catch (ApiErrorException $e) {
            report($e);

            return back()->withErrors(['amount' => 'Stripe refused the refund: '.$e->getMessage()]);
        }

        return back()->with('success', "Refund of {$transaction->currency} "
            .number_format($amountMinor / 100, 2)." issued for {$transaction->reference}.");
    }

    /** No processor, no checkout. See StripePaymentService::isLive(). */
    protected function ensureEnabled(): void
    {
        abort_unless($this->stripe->isLive(), 404);
    }

    /**
     * An invoice is payable by the household it bills and by the
     * administrators who issue it.
     */
    protected function authorizeInvoice(User $user, Invoice $invoice): void
    {
        abort_unless(
            $invoice->user_id === $user->id || $user->can('manageBilling'),
            403,
            'Unauthorized to pay this statement.',
        );
    }
}
