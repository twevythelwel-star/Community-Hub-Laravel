<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Billing\ChargeOnTerminalAction;
use App\Actions\Billing\StoreReconciliationAction;
use App\Enums\PaymentState;
use App\Http\Controllers\Controller;
use App\Jobs\SendInvoicePaymentReminderJob;
use App\Http\Requests\Billing\CalculateProrationRequest;
use App\Http\Requests\Billing\CancelTerminalPaymentRequest;
use App\Http\Requests\Billing\ChargeTerminalRequest;
use App\Http\Requests\Billing\InitiatePaymentRequest;
use App\Http\Requests\Billing\PayInvoiceRequest;
use App\Http\Requests\Billing\ProcessCardPaymentRequest;
use App\Http\Requests\Billing\ReceivePaymentRequest;
use App\Http\Requests\Billing\RegisterTerminalRequest;
use App\Http\Requests\Billing\RejectPaymentRequest;
use App\Http\Requests\Billing\RetireTerminalRequest;
use App\Http\Requests\Billing\RetryTerminalPaymentRequest;
use App\Http\Requests\Billing\StorePaymentLinkRequest;
use App\Http\Requests\Billing\StorePaymentPlanRequest;
use App\Http\Requests\Billing\StoreReconciliationRequest;
use App\Http\Requests\Billing\ToggleChannelRequest;
use App\Http\Requests\Billing\TopUpWalletRequest;
use App\Http\Requests\Billing\UpdateAutoPayRequest;
use App\Http\Requests\Billing\UpdateBillingSettingsRequest;
use App\Http\Requests\Billing\ValidateChannelRequest;
use App\Models\AutoPaySetting;
use App\Models\BillingSetting;
use App\Models\Community;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentChannelSetting;
use App\Models\PaymentLink;
use App\Models\PaymentPlan;
use App\Models\PaymentTerminal;
use App\Models\Transaction;
use App\Services\Billing\BillingPaymentExecutionService;
use App\Services\Billing\BillingPresenter;
use App\Services\Payments\PaymentOrchestratorService;
use App\Services\Payments\Providers\Contracts\TakesInPersonPayments;
use App\Services\Payments\Providers\ProviderRegistry;
use App\Services\StripePaymentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillingController extends Controller
{
    public function __construct(
        protected PaymentOrchestratorService $orchestrator,
        protected BillingPresenter $presenter,
        protected BillingPaymentExecutionService $paymentService
    ) {}

    public function index(Request $request): Response
    {
        $props = $this->presenter->presentIndex($request->user(), $request, $this->orchestrator);

        return Inertia::render('Dashboard/Billing', $props);
    }

    public function pay(PayInvoiceRequest $request, ProviderRegistry $providers): HttpResponse
    {
        return $this->paymentService->executePay($request->user(), $request->validated(), $providers);
    }

    public function initiatePayment(InitiatePaymentRequest $request, ProviderRegistry $providers): JsonResponse
    {
        return $this->paymentService->initiatePayment($request->user(), $request->validated(), $this->orchestrator, $providers);
    }

    public function processCardPayment(ProcessCardPaymentRequest $request, StripePaymentService $stripe): JsonResponse
    {
        return $this->paymentService->processCardPayment($request->user(), $request->validated(), $stripe);
    }

    public function registerTerminal(RegisterTerminalRequest $request, ProviderRegistry $providers): RedirectResponse
    {
        $validated = $request->validated();
        $provider = $providers->inPersonProvider();

        if (! $provider) {
            return back()->withErrors(['registration_code' => 'In-person card payments are not set up for this estate.']);
        }

        try {
            $terminal = $provider->registerTerminal($validated['registration_code'], $validated['location_id'], $validated['label'], $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['registration_code' => $e->getMessage()]);
        }

        return back()->with('success', "Reader {$terminal->label} registered ({$terminal->country}).");
    }

    public function retireTerminal(RetireTerminalRequest $request, PaymentTerminal $terminal): RedirectResponse
    {
        $terminal->update(['status' => 'retired']);
        $request->user()->recordActivity("Retired card reader {$terminal->label} ({$terminal->terminal_id})");

        return back()->with('success', "Reader {$terminal->label} retired.");
    }

    public function chargeOnTerminal(ChargeTerminalRequest $request, ChargeOnTerminalAction $action): RedirectResponse
    {
        $result = $action->execute($request->validated(), $request->user());

        return back()->with('success', "{$result['payment']->transaction_id}: {$result['instruction']->message}");
    }

    public function retryOnTerminal(RetryTerminalPaymentRequest $request, Payment $payment, ProviderRegistry $providers): RedirectResponse
    {
        $provider = $providers->inPersonProvider();

        if (! $provider || ! $payment->terminal || $payment->state !== PaymentState::Failed) {
            return back()->withErrors(['terminal_id' => "Payment {$payment->transaction_id} cannot be sent to a reader again."]);
        }

        try {
            $instruction = $provider->startInPersonPayment($payment, $payment->terminal);
        } catch (\DomainException $e) {
            return back()->withErrors(['terminal_id' => $e->getMessage()]);
        }

        return back()->with('success', "{$payment->transaction_id}: {$instruction->message}");
    }

    public function cancelOnTerminal(CancelTerminalPaymentRequest $request, Payment $payment, ProviderRegistry $providers): RedirectResponse
    {
        $provider = $providers->byKey($payment->provider);

        if (! $provider instanceof TakesInPersonPayments) {
            return back()->withErrors(['terminal_id' => "Payment {$payment->transaction_id} is not on a reader."]);
        }

        try {
            $provider->cancelInPersonPayment($payment, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['terminal_id' => $e->getMessage()]);
        }

        return back()->with('success', "Payment {$payment->transaction_id} cleared from the reader.");
    }

    public function receivePayment(ReceivePaymentRequest $request, Payment $payment): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $this->orchestrator->markReceived($payment, $request->user(), $validated['bank_reference'] ?? null, $validated['note'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        return back()->with('success', "Payment {$payment->transaction_id} logged as received. Another administrator verifies it in the next bank reconciliation.");
    }

    public function rejectPayment(RejectPaymentRequest $request, Payment $payment): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $this->orchestrator->rejectPayment($payment, $request->user(), $validated['reason'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        return back()->with('success', "Payment {$payment->transaction_id} marked as not received.");
    }

    public function updateAutoPay(UpdateAutoPayRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        AutoPaySetting::updateOrCreate(
            ['user_id' => $user->id],
            $validated
        );

        $user->recordActivity("Updated AutoPay settings to {$validated['cadence']}");

        return back()->with('success', 'AutoPay preferences updated.');
    }

    public function storePaymentLink(StorePaymentLinkRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        PaymentLink::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'amount_minor' => ! empty($validated['amount']) ? (int) round($validated['amount'] * 100) : null,
            'currency' => strtoupper($validated['currency'] ?? 'JMD'),
            'category' => $validated['category'],
            'created_by' => $user->id,
        ]);

        return back()->with('success', 'Universal Payment Link generated successfully!');
    }

    public function topUpWallet(TopUpWalletRequest $request): RedirectResponse
    {
        return back()->withErrors([
            'amount' => 'Wallet top-ups are unavailable: no card processor is connected to this estate yet. '
                .'Please pay at the community office.',
        ]);
    }

    public function exportTransactions(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="transactions-master-ledger.csv"',
        ];

        return response()->stream(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Reference', 'Receipt', 'Homeowner', 'Lot', 'Amount', 'Currency', 'Channel', 'Status', 'Date', 'Notes']);

            Transaction::with('user')->chunk(100, function ($transactions) use ($handle) {
                foreach ($transactions as $tx) {
                    fputcsv($handle, [
                        $tx->reference,
                        $tx->receipt_number,
                        $tx->user?->name ?? 'Resident',
                        $tx->user?->lot ?? 'N/A',
                        number_format($tx->amount_minor / 100, 2),
                        $tx->currency,
                        $tx->payment_channel,
                        $tx->status,
                        $tx->created_at->toDateTimeString(),
                        $tx->notes,
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }

    public function updateSettings(UpdateBillingSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        BillingSetting::current()->update([
            'monthly_fee_minor' => (int) round($validated['monthly_fee'] * 100),
            'currency' => strtoupper($validated['currency']),
            'due_day_of_month' => $validated['due_day_of_month'],
        ]);

        $request->user()->recordActivity('Updated billing settings');

        return back()->with('success', 'Billing settings updated.');
    }

    public function markPaid(Request $request, Invoice $invoice): RedirectResponse
    {
        if ($invoice->status === 'Paid') {
            return back()->with('error', "Invoice {$invoice->reference} is already marked paid.");
        }

        $invoice->markPaid();
        $request->user()->recordActivity("Marked invoice {$invoice->reference} paid");

        return back()->with('success', "Invoice {$invoice->reference} marked paid.");
    }

    public function sendReminder(Request $request, Invoice $invoice): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        if (! $user->can('manageBilling')) {
            abort(403, 'Unauthorized to dispatch billing reminders.');
        }

        if ($invoice->status === 'Paid') {
            return back()->with('error', "Invoice {$invoice->reference} is already settled in full.");
        }

        SendInvoicePaymentReminderJob::dispatch($invoice);
        $user->recordActivity("Dispatched payment reminder for invoice {$invoice->reference}");

        $homeownerName = $invoice->user?->display_name ?? 'Homeowner';
        $message = "Digital statement and payment reminder dispatched to {$homeownerName} via SMS, email, and in-app channels.";

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'lastRemindedAt' => now()->toIso8601String(),
                'reminderCount' => ($invoice->reminder_count ?? 0) + 1,
            ]);
        }

        return back()->with('success', $message);
    }


    public function validateChannel(ValidateChannelRequest $request, string $channel): RedirectResponse|JsonResponse
    {
        $report = $this->orchestrator->validateChannelIntegration($channel);

        if ($request->wantsJson()) {
            return response()->json($report);
        }

        $statusMsg = $report['is_ready'] ? 'Technical validation PASSED. Ready for production.' : 'Technical validation requires configuration.';

        return back()->with('success', "Channel [{$report['label']}]: {$statusMsg}");
    }

    public function toggleChannel(ToggleChannelRequest $request, string $channel): RedirectResponse
    {
        $setting = PaymentChannelSetting::where('channel_key', $channel)->first();
        $targetEnabled = ! ($setting ? $setting->enabled : true);

        if ($targetEnabled) {
            $validation = $this->orchestrator->validateChannelIntegration($channel);
            if (! $validation['is_ready']) {
                return back()->withErrors([
                    'channel' => "Cannot enable [{$validation['label']}] for production: technical integration requirements not met.",
                ]);
            }
        }

        PaymentChannelSetting::updateOrCreate(
            ['channel_key' => $channel],
            ['enabled' => $targetEnabled]
        );

        $statusWord = $targetEnabled ? 'enabled for production' : 'disabled';
        $request->user()->recordActivity("Payment channel {$channel} {$statusWord}");

        return back()->with('success', "Payment channel {$channel} has been {$statusWord}.");
    }

    public function storePaymentPlan(StorePaymentPlanRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $invoice = Invoice::findOrFail($validated['invoice_id']);
        $installmentAmountMinor = (int) ceil($invoice->amount_minor / $validated['total_installments']);

        PaymentPlan::updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'user_id' => $invoice->user_id,
                'total_installments' => $validated['total_installments'],
                'remaining_installments' => $validated['total_installments'],
                'installment_amount_minor' => $installmentAmountMinor,
                'frequency' => $validated['frequency'],
                'status' => 'Active',
            ]
        );

        $request->user()->recordActivity("Created {$validated['total_installments']}-part payment plan for invoice {$invoice->reference}");

        return back()->with('success', "Payment plan configured for invoice {$invoice->reference}.");
    }

    public function downloadReceipt(Request $request, Transaction $transaction): \Illuminate\Http\Response
    {
        $user = $request->user();
        abort_unless(
            $transaction->user_id === $user->id || $user->can('manageBilling'),
            403,
            'You can only download your own payment receipts.'
        );

        abort_if(
            $transaction->isPending() || in_array($transaction->status, ['rejected'], true),
            409,
            'A receipt is issued once the community office confirms the payment.',
        );

        $transaction->load(['user', 'invoice']);
        $community = Community::first();

        $pdf = Pdf::loadView('pdf.transaction-receipt', [
            'transaction' => $transaction,
            'community' => $community,
        ])->setPaper('a4', 'portrait');

        $ref = $transaction->receipt_number ?? $transaction->reference;

        return $pdf->download("receipt-{$ref}.pdf");
    }

    public function storeReconciliation(StoreReconciliationRequest $request, StoreReconciliationAction $action): RedirectResponse
    {
        try {
            $result = $action->execute($request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['verify_payment_ids' => $e->getMessage()]);
        }

        return back()->with('success', $result['message']);
    }

    public function customerPortal(Request $request, StripePaymentService $stripe): RedirectResponse|HttpResponse
    {
        $user = $request->user();

        if (! $stripe->isLive()) {
            return back()->with('error', 'Stripe payment processor is not configured or in test mode. Self-service billing portal is currently unavailable.');
        }

        try {
            $returnUrl = route('dashboard.billing');
            $url = $stripe->createCustomerPortalSession($user, $returnUrl);

            return Inertia::location($url);
        } catch (\Throwable $e) {
            Log::warning("Could not launch customer portal: {$e->getMessage()}");

            return back()->with('error', 'Unable to initiate Stripe customer portal at this time: '.$e->getMessage());
        }
    }

    public function calculateProration(CalculateProrationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $start = Carbon::parse($validated['start_date']);
        $daysInMonth = $start->daysInMonth;

        $end = ! empty($validated['end_date'])
            ? Carbon::parse($validated['end_date'])
            : $start->copy()->endOfMonth();

        $billedDays = min($daysInMonth, max(1, $start->diffInDays($end) + 1));
        $dailyRate = $validated['monthly_rate'] / $daysInMonth;
        $proratedAmount = round($dailyRate * $billedDays, 2);

        return response()->json([
            'monthlyRate' => (float) $validated['monthly_rate'],
            'daysInMonth' => $daysInMonth,
            'billedDays' => $billedDays,
            'dailyRate' => round($dailyRate, 2),
            'proratedAmount' => $proratedAmount,
            'currency' => 'USD',
        ]);
    }
}
