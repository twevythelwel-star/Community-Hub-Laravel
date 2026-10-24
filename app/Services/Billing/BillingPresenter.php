<?php

namespace App\Services\Billing;

use App\Enums\PaymentState;
use App\Models\Account;
use App\Models\AutoPaySetting;
use App\Models\BankReconciliation;
use App\Models\BillingSetting;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Models\PaymentPlan;
use App\Models\PaymentTerminal;
use App\Models\Payout;
use App\Models\StripeEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ledger\LedgerService;
use App\Services\Payments\PaymentOrchestratorService;
use App\Services\Payments\Providers\ProviderRegistry;
use App\Services\StripePaymentService;
use Illuminate\Http\Request;

class BillingPresenter
{
    public function __construct(
        protected BillingAdminStatsBuilder $statsBuilder
    ) {}

    public function presentIndex(User $user, Request $request, PaymentOrchestratorService $orchestrator): array
    {
        $isAdmin = $user->can('manageBilling');
        $settings = BillingSetting::current();

        $myInvoices = $user->invoices()
            ->with(['items', 'paymentPlan', 'property'])
            ->latest('period_start')
            ->paginate(12)
            ->withQueryString()
            ->through(fn (Invoice $i) => $this->invoicePayload($i));

        $summary = [
            'outstanding' => (float) ($user->invoices()->outstanding()->sum('amount_minor') / 100),
            'paidThisYear' => (float) ($user->invoices()
                ->where('status', 'Paid')
                ->whereYear('paid_at', now()->year)
                ->sum('amount_minor') / 100),
            'paidCountThisYear' => $user->invoices()
                ->where('status', 'Paid')
                ->whereYear('paid_at', now()->year)
                ->count(),
            'year' => (int) now()->year,
        ];

        $adminSummary = $isAdmin ? $this->statsBuilder->buildAdminSummary() : null;
        $monthlyCollections = $isAdmin ? $this->statsBuilder->buildMonthlyCollections() : null;

        $invoices = $isAdmin
            ? Invoice::with(['user:id,display_name,lot', 'items', 'property'])
                ->latest('due_on')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (Invoice $i) => [
                    ...$this->invoicePayload($i),
                    'homeowner' => $i->user?->display_name,
                    'lot' => $i->user?->lot,
                ])
            : null;

        $wallet = $user->wallet ?? Wallet::firstOrCreate(
            ['user_id' => $user->id],
            ['currency' => $settings->currency ?: 'JMD'],
        );

        $walletData = [
            'available' => (float) ($wallet->available_balance_minor / 100),
            'pending' => (float) ($wallet->pending_balance_minor / 100),
            'rewards' => (float) ($wallet->rewards_balance_minor / 100),
            'totalUsable' => (float) ($wallet->totalUsableMinor() / 100),
            'currency' => $wallet->currency,
            'autoReload' => [
                'enabled' => $wallet->auto_reload_enabled,
                'threshold' => (float) ($wallet->auto_reload_threshold_minor / 100),
                'amount' => (float) ($wallet->auto_reload_amount_minor / 100),
            ],
            'recentTransactions' => $wallet->transactions()->latest()->take(10)->get()->map(fn ($t) => [
                'id' => $t->id,
                'amount' => (float) ($t->amount_minor / 100),
                'currency' => $t->currency,
                'type' => $t->type,
                'balanceType' => $t->balance_type,
                'reference' => $t->reference,
                'description' => $t->description,
                'date' => $t->created_at->format('M d, Y'),
            ]),
        ];

        $activeInvoice = $user->invoices()->outstanding()->with('items')->latest('due_on')->first();
        $itemizedCharges = $activeInvoice ? $activeInvoice->items->map(fn ($item) => [
            'id' => $item->id,
            'category' => $item->category,
            'title' => $item->title,
            'amount' => (float) ($item->amount_minor / 100),
            'status' => $item->status,
        ]) : [];

        $autoPay = $user->autoPaySetting ?? AutoPaySetting::firstOrCreate(
            ['user_id' => $user->id],
            ['is_active' => false, 'cadence' => 'monthly', 'charge_day_of_month' => 1, 'payment_channel' => 'card']
        );

        $availableChannels = $orchestrator->getAvailableChannels();
        $financialDashboard = $isAdmin ? $this->statsBuilder->buildFinancialDashboard() : null;

        $paymentLinks = $isAdmin ? PaymentLink::latest()->take(10)->get()->map(fn ($l) => [
            'id' => $l->id,
            'token' => $l->token,
            'title' => $l->title,
            'description' => $l->description,
            'amount' => $l->amount_minor ? (float) ($l->amount_minor / 100) : null,
            'currency' => $l->currency,
            'category' => $l->category,
            'active' => $l->active,
            'usesCount' => $l->uses_count,
            'publicUrl' => $l->publicUrl(),
            'posterUrl' => route('pay.poster', ['token' => $l->token]),
        ]) : [];

        $stripe = app(StripePaymentService::class);
        $canRefund = $isAdmin && $stripe->isLive();

        $presentTransaction = function (Transaction $t) use ($stripe, $canRefund): array {
            $refundableMinor = $canRefund ? $stripe->refundableMinor($t) : 0;

            return [
                'id' => $t->id,
                'transactionId' => $t->transaction_id ?? $t->reference,
                'transaction_id' => $t->transaction_id ?? $t->reference,
                'reference' => $t->reference,
                'receiptNumber' => $t->receipt_number,
                'homeowner' => $t->user?->display_name,
                'lot' => $t->user?->lot,
                'amount' => (float) ($t->amount_minor / 100),
                'currency' => $t->currency,
                'channel' => $t->payment_channel,
                'paymentMethod' => $t->payment_method ?? Transaction::formatPaymentMethod($t->payment_channel),
                'userCode' => $t->user_code ?? Transaction::formatUserCode($t->user_id),
                'propertyCode' => $t->property_code,
                'communityCode' => $t->community_code,
                'purpose' => $t->purpose,
                'provider' => $t->provider,
                'providerReference' => $t->provider_reference,
                'device' => $t->device_identifier,
                'status' => $t->status,
                'notes' => $t->notes,
                'date' => $t->created_at->format('M d, Y h:i A'),
                'refundableAmount' => $refundableMinor > 0 ? (float) ($refundableMinor / 100) : null,
                'refundUrl' => $refundableMinor > 0
                    ? route('dashboard.billing.transactions.refund', $t->id)
                    : null,
                'isDonation' => $t->donation_id !== null,
                'slip' => $t->toTransactionCardPayload(),
                'ledgerEntries' => $t->ledgerEntries()->with('account:id,code,name,type')->get()->map(fn ($e) => [
                    'entryId' => $e->entry_id,
                    'accountCode' => $e->account?->code,
                    'accountName' => $e->account?->name,
                    'accountType' => $e->account?->type,
                    'type' => $e->entry_type,
                    'amount' => (float) ($e->amount_minor / 100),
                    'currency' => $e->currency,
                    'description' => $e->description,
                ]),
            ];
        };

        $transactions = Transaction::with('user:id,display_name,lot')
            ->unless($isAdmin, fn ($q) => $q->where('user_id', $user->id))
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through($presentTransaction);

        $presentPayment = fn (Payment $p): array => [
            'id' => $p->id,
            'transactionId' => $p->transaction_id,
            'state' => $p->state->value,
            'stateLabel' => $p->state->label(),
            'channel' => $p->channel,
            'paymentMethod' => Transaction::formatPaymentMethod($p->channel),
            'amount' => (float) ($p->amount_minor / 100),
            'currency' => $p->currency,
            'purpose' => $p->purpose,
            'homeowner' => $p->user?->display_name,
            'lot' => $p->user?->lot,
            'invoiceReference' => $p->invoice?->reference,
            'isDonation' => $p->applies_to === 'donation',
            'payerReference' => $p->payer_reference,
            'bankReference' => $p->bank_reference,
            'receivedBy' => $p->receiver?->display_name,
            'receivedAt' => $p->received_at?->format('M d, Y h:i A'),
            'submittedAt' => $p->updated_at->format('M d, Y h:i A'),
            'receiveUrl' => $isAdmin && $p->state === PaymentState::AwaitingTransfer
                ? route('dashboard.billing.payments.receive', $p->id)
                : null,
            'rejectUrl' => $isAdmin && $p->state->awaitsOffice()
                ? route('dashboard.billing.payments.reject', $p->id)
                : null,
            'canVerify' => $isAdmin && $p->state === PaymentState::Received && $p->received_by !== $user->id,
        ];

        $pendingPayments = Payment::with(['user:id,display_name,lot', 'invoice:id,reference', 'receiver:id,display_name'])
            ->inState(PaymentState::AwaitingTransfer, PaymentState::Received)
            ->unless($isAdmin, fn ($q) => $q->where('user_id', $user->id))
            ->oldest('updated_at')
            ->take(100)
            ->get()
            ->map($presentPayment)
            ->values();

        $payouts = $isAdmin ? Payout::latest()->take(10)->get()->map(fn ($p) => [
            'id' => $p->id,
            'vendor' => $p->vendor_name,
            'category' => $p->category,
            'amount' => (float) ($p->amount_minor / 100),
            'currency' => $p->currency,
            'status' => $p->status,
            'scheduledFor' => $p->scheduled_for->format('M d, Y'),
            'reference' => $p->reference,
        ]) : [];

        $reconciliations = $isAdmin ? BankReconciliation::latest()->take(5)->get()->map(fn ($r) => [
            'id' => $r->id,
            'statementDate' => $r->bank_statement_date->format('M d, Y'),
            'statementBalance' => (float) ($r->statement_balance_minor / 100),
            'ledgerBalance' => (float) ($r->ledger_balance_minor / 100),
            'difference' => (float) ($r->difference_minor / 100),
            'status' => $r->status,
            'notes' => $r->notes,
        ]) : [];

        $outstandingQuery = $isAdmin ? Invoice::outstanding() : $user->invoices()->outstanding();
        $outstandingInvoices = $outstandingQuery
            ->with(['user:id,display_name,lot', 'latestReminderDelivery'])
            ->latest('due_on')
            ->take(50)
            ->get()
            ->map(fn (Invoice $inv) => [
                'id' => $inv->id,
                'reference' => $inv->reference,
                'homeowner' => $inv->user?->display_name ?? 'Resident',
                'lot' => $inv->user?->lot ?? 'N/A',
                'amount' => (float) ($inv->amount_minor / 100),
                'currency' => $inv->currency,
                'dueOn' => $inv->due_on->format('M d, Y'),
                'daysOverdue' => max(0, (int) now()->diffInDays($inv->due_on, false) * -1),
                'status' => $inv->isOverdue() ? 'Overdue' : $inv->status,
                'lastRemindedAt' => $inv->last_reminded_at?->diffForHumans(),
                'reminderCount' => $inv->reminder_count ?? 0,
                'reminderStatus' => $inv->latestReminderDelivery?->status,
            ]);

        $plansQuery = $isAdmin ? PaymentPlan::query() : PaymentPlan::where('user_id', $user->id);
        $paymentPlans = $plansQuery
            ->with(['user:id,display_name,lot', 'invoice:id,reference,due_on'])
            ->latest()
            ->take(50)
            ->get()
            ->map(fn (PaymentPlan $plan) => [
                'id' => $plan->id,
                'invoiceId' => $plan->invoice_id,
                'invoiceReference' => $plan->invoice?->reference ?? 'INV-N/A',
                'homeowner' => $plan->user?->display_name ?? 'Resident',
                'lot' => $plan->user?->lot ?? 'N/A',
                'totalInstallments' => $plan->total_installments,
                'remainingInstallments' => $plan->remaining_installments,
                'installmentAmount' => (float) ($plan->installment_amount_minor / 100),
                'frequency' => ucfirst($plan->frequency),
                'status' => $plan->status,
            ]);

        $receiptsQuery = Transaction::where('status', 'completed')
            ->unless($isAdmin, fn ($q) => $q->where('user_id', $user->id));
        $receipts = $receiptsQuery
            ->with('user:id,display_name,lot')
            ->latest()
            ->take(50)
            ->get()
            ->map(fn (Transaction $tx) => [
                'id' => $tx->id,
                'receiptNumber' => $tx->receipt_number ?? ('REC-'.str_pad($tx->id, 6, '0', STR_PAD_LEFT)),
                'reference' => $tx->reference,
                'homeowner' => $tx->user?->display_name ?? 'Resident',
                'lot' => $tx->user?->lot ?? 'N/A',
                'amount' => (float) ($tx->amount_minor / 100),
                'currency' => $tx->currency,
                'channel' => str_replace('_', ' ', $tx->payment_channel),
                'date' => $tx->created_at->format('M d, Y h:i A'),
                'receiptUrl' => route('dashboard.billing.transactions.receipt', $tx->id),
            ]);

        $refundsQuery = Transaction::where('status', 'refunded')
            ->unless($isAdmin, fn ($q) => $q->where('user_id', $user->id));
        $refundsList = $refundsQuery
            ->with('user:id,display_name,lot')
            ->latest()
            ->take(50)
            ->get()
            ->map(fn (Transaction $tx) => [
                'id' => $tx->id,
                'reference' => $tx->reference,
                'homeowner' => $tx->user?->display_name ?? 'Resident',
                'lot' => $tx->user?->lot ?? 'N/A',
                'amount' => (float) ($tx->amount_minor / 100),
                'currency' => $tx->currency,
                'channel' => str_replace('_', ' ', $tx->payment_channel),
                'date' => $tx->updated_at->format('M d, Y h:i A'),
                'notes' => $tx->notes,
            ]);

        $autoPayPortfolio = $isAdmin ? [
            'enrolledCount' => AutoPaySetting::where('is_active', true)->count(),
            'cadenceBreakdown' => [
                'monthly' => AutoPaySetting::where('is_active', true)->where('cadence', 'monthly')->count(),
                'quarterly' => AutoPaySetting::where('is_active', true)->where('cadence', 'quarterly')->count(),
                'annual' => AutoPaySetting::where('is_active', true)->where('cadence', 'annual')->count(),
            ],
            'nextRunDate' => now()->startOfMonth()->addDays((int) ($autoPay->charge_day_of_month ?: 1) - 1)->format('M d, Y'),
        ] : null;

        $paymentChannels = $isAdmin ? $orchestrator->getChannelReadinessReport() : [];

        return [
            'settings' => [
                'monthlyFee' => $settings->monthlyFee(),
                'currency' => $settings->currency,
                'dueDayOfMonth' => $settings->due_day_of_month,
            ],
            'myInvoices' => $myInvoices,
            'summary' => $summary,
            'adminSummary' => $adminSummary,
            'monthlyCollections' => $monthlyCollections,
            'invoices' => $invoices,
            'canManage' => $isAdmin,
            'financialDashboard' => $financialDashboard,
            'paymentCenter' => [
                'amountDue' => $summary['outstanding'],
                'itemizedCharges' => $itemizedCharges,
                'availableChannels' => $availableChannels,
                'autoPay' => [
                    'isActive' => $autoPay->is_active,
                    'cadence' => $autoPay->cadence,
                    'chargeDay' => $autoPay->charge_day_of_month,
                    'paymentChannel' => $autoPay->payment_channel,
                ],
            ],
            'wallet' => $walletData,
            'paymentLinks' => $paymentLinks,
            'transactions' => $transactions,
            'pendingPayments' => $pendingPayments,
            'inPerson' => $isAdmin ? $this->inPersonProps() : null,
            'payouts' => $payouts,
            'reconciliations' => $reconciliations,
            'paymentEvents' => $isAdmin ? StripeEvent::latest()->take(25)->get()->map(fn ($e) => [
                'id' => $e->id,
                'eventId' => $e->event_id,
                'type' => $e->type,
                'status' => $e->status ?? 'processed',
                'errorMessage' => $e->error_message,
                'date' => $e->created_at->format('M d, Y h:i:s A'),
            ]) : [],
            'outstanding' => $outstandingInvoices,
            'paymentPlans' => $paymentPlans,
            'receipts' => $receipts,
            'refundsList' => $refundsList,
            'autoPayPortfolio' => $autoPayPortfolio,
            'paymentChannels' => $paymentChannels,
            'triPartyReconciliation' => $isAdmin ? app(LedgerService::class)->getTriPartyReconciliationSummary($settings->currency ?: 'JMD') : null,
            'chartOfAccounts' => $isAdmin ? Account::withCount('entries')->orderBy('code')->get()->map(fn ($a) => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'type' => $a->type,
                'currency' => $a->currency,
                'balance' => (float) ($a->balance_minor / 100),
                'entriesCount' => $a->entries_count,
                'description' => $a->description,
            ]) : [],
        ];
    }

    public function inPersonProps(): array
    {
        $provider = app(ProviderRegistry::class)->inPersonProvider();

        return [
            'available' => $provider !== null,
            'provider' => $provider?->label(),
            'registerUrl' => route('dashboard.billing.terminals.store'),
            'chargeUrl' => route('dashboard.billing.terminals.charge'),
            'terminals' => PaymentTerminal::query()->where('status', 'active')->orderBy('label')->get()->map(fn (PaymentTerminal $t) => [
                'id' => $t->id,
                'label' => $t->label,
                'terminalId' => $t->terminal_id,
                'deviceId' => $t->device_id,
                'deviceType' => $t->device_type,
                'locationId' => $t->location_id,
                'country' => $t->country,
                'provider' => $t->provider,
                'usable' => $t->isUsable() && $provider?->key() === $t->provider,
                'retireUrl' => route('dashboard.billing.terminals.retire', $t->id),
            ])->values(),
            'onReaders' => Payment::with(['terminal:id,label', 'user:id,display_name', 'invoice:id,reference'])
                ->where('channel', 'nfc_pos')
                ->inState(PaymentState::Created, PaymentState::Processing, PaymentState::Failed)
                ->whereNotNull('payment_terminal_id')
                ->latest('updated_at')
                ->take(20)
                ->get()
                ->map(fn (Payment $p) => [
                    'id' => $p->id,
                    'transactionId' => $p->transaction_id,
                    'state' => $p->state->value,
                    'stateLabel' => $p->state->label(),
                    'amount' => (float) ($p->amount_minor / 100),
                    'currency' => $p->currency,
                    'homeowner' => $p->user?->display_name,
                    'invoiceReference' => $p->invoice?->reference,
                    'terminal' => $p->terminal?->label,
                    'failureReason' => $p->failure_reason,
                    'retryUrl' => $p->state === PaymentState::Failed ? route('dashboard.billing.terminals.retry', $p->id) : null,
                    'cancelUrl' => route('dashboard.billing.terminals.cancel', $p->id),
                ])->values(),
        ];
    }

    public function invoicePayload(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'reference' => $invoice->reference,
            'property' => $invoice->property?->label(),
            'amount' => $invoice->amount(),
            'currency' => $invoice->currency,
            'periodStart' => $invoice->period_start->toDateString(),
            'periodEnd' => $invoice->period_end->toDateString(),
            'dueOn' => $invoice->due_on->toDateString(),
            'status' => $invoice->isOverdue() ? 'Overdue' : $invoice->status,
            'paidAt' => $invoice->paid_at?->toIso8601String(),
            'pdfUrl' => route('dashboard.billing.invoice.pdf', $invoice->id),
            'checkoutUrl' => app(StripePaymentService::class)->isLive()
                ? route('dashboard.billing.stripe.checkout', $invoice->id)
                : null,
        ];
    }
}
