<?php

namespace App\Http\Controllers;

use App\Models\BrandingSetting;
use App\Models\Community;
use App\Models\PaymentLink;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentOrchestratorService;
use App\Services\QrCodePng;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Public, unauthenticated payment links.
 *
 * Disabled unless `PAYMENT_PUBLIC_LINKS_ENABLED` is set. No payment driver in
 * App\Services\Payments\Drivers takes money — each returns a simulated
 * reference — and `process()` then records a Transaction with status
 * `completed`. While this is reachable, anyone holding a link can fabricate a
 * settled payment against the community ledger without signing in.
 *
 * See config/payments.php.
 */
class UniversalPaymentLinkController extends Controller
{
    public function __construct(
        protected PaymentOrchestratorService $orchestrator
    ) {}

    /** Public links stay off until a driver genuinely settles funds. */
    protected function ensureEnabled(): void
    {
        abort_unless(config('payments.public_links_enabled'), 404);
    }

    public function show(string $token): View
    {
        $this->ensureEnabled();

        $paymentLink = PaymentLink::where('token', $token)->where('active', true)->firstOrFail();
        $community = Community::first();
        $branding = BrandingSetting::current();

        return view('blade.public-pay', [
            'paymentLink' => $paymentLink,
            'community' => $community,
            'branding' => $branding,
        ]);
    }

    /**
     * Printable poster whose QR opens the public payment page.
     *
     * The QR is rendered here and inlined as a PNG data: URI, which DomPDF
     * draws with remote fetching disabled. 220 px matches .qr-img in
     * pdf/qr-poster.blade.php; margin 4 is the standard quiet zone for print.
     */
    public function poster(string $token, QrCodePng $qr): Response
    {
        $this->ensureEnabled();

        $paymentLink = PaymentLink::where('token', $token)->firstOrFail();
        $community = Community::first();

        $pdf = Pdf::loadView('pdf.qr-poster', [
            'paymentLink' => $paymentLink,
            'community' => $community,
            'qrImage' => 'data:image/png;base64,'.base64_encode($qr->render($paymentLink->publicUrl(), 220, 4)),
        ]);

        return $pdf->stream('payment-poster-'.$token.'.pdf');
    }

    public function process(Request $request, string $token): RedirectResponse
    {
        $this->ensureEnabled();

        $paymentLink = PaymentLink::where('token', $token)->where('active', true)->firstOrFail();

        $validated = $request->validate([
            // Office-confirmed channels only: a stranger has no Community
            // Wallet, and card needs a Stripe Checkout flow this page lacks.
            // An unregistered key used to reach getDriver() and throw a 500.
            'channel' => ['required', 'string', Rule::in(array_values(array_filter(
                $this->orchestrator->channelKeys(),
                fn (string $key) => $this->orchestrator->requiresOfficeConfirmation($key),
            )))],
            'payer_name' => ['required', 'string', 'max:100'],
            'lot' => ['required', 'string', 'max:50'],
            'custom_amount' => ['nullable', 'numeric', 'min:1'],
        ]);

        $amountMinor = $paymentLink->amount_minor
            ?? (int) (round((float) $validated['custom_amount'], 2) * 100);

        /*
         | No user is attached. This was
         | `User::where('name', 'like', '%'.$payer_name.'%')->first()`, so a
         | payer name typed on a public form credited whichever resident's name
         | happened to match it first — a stranger could post a payment against
         | someone else's account, and a common surname could do it by accident.
         | The payer's name and lot are kept with the payment instead.
         |
         | The payment waits for the office like any other office channel: it
         | counts only once one administrator logs it received and another
         | verifies it in a bank reconciliation.
         */
        $payment = $this->orchestrator->startPayment([
            'channel' => $validated['channel'],
            'payment_link' => $paymentLink,
            'invoice' => $paymentLink->invoice,
            'user' => $paymentLink->user ?? $paymentLink->invoice?->user,
            'applies_to' => $paymentLink->invoice_id ? 'invoice' : 'payment_link',
            'purpose' => $paymentLink->invoice_id ? Transaction::PURPOSE_HOA_ASSESSMENT : Transaction::PURPOSE_COMMUNITY_PROJECT,
            'amount_minor' => $amountMinor,
            'currency' => $paymentLink->currency,
            'metadata' => ['payer_name' => $validated['payer_name'], 'lot' => $validated['lot']],
            'source' => 'public_link',
        ]);

        $this->orchestrator->awaitTransfer($payment);
        $paymentLink->increment('uses_count');

        return back()->with(
            'status',
            "Payment {$payment->transaction_id} of ".$paymentLink->formattedAmount().' recorded. The community office will confirm it.',
        );
    }
}
