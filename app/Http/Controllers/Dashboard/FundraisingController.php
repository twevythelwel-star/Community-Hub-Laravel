<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\Donation;
use App\Models\Fundraiser;
use App\Models\FundraiserUpdate;
use App\Models\Transaction;
use App\Services\Payments\PaymentOrchestratorService;
use App\Services\Payments\Providers\PaymentRequest;
use App\Services\Payments\Providers\ProviderRegistry;
use App\Services\StripePaymentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FundraisingController extends Controller
{
    public function __construct(
        protected PaymentOrchestratorService $orchestrator
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $canManage = $user->can('manageFundraisers');

        $fundraisers = Fundraiser::with(['donations', 'updates'])
            ->orderByRaw("CASE status WHEN 'Active' THEN 1 WHEN 'Upcoming' THEN 2 WHEN 'Completed' THEN 3 ELSE 4 END")
            ->orderBy('end_date')
            ->get()
            ->map(fn (Fundraiser $f) => [
                'id' => $f->id,
                'title' => $f->title,
                'description' => $f->description,
                'beneficiary' => $f->beneficiary ?? 'Community Improvement Fund',
                'coverImageUrl' => $f->cover_image_url,
                'images' => $f->images ?? [],
                'goal' => $f->goal(),
                'raised' => $f->raised(),
                'refunded' => $f->refunded(),
                'progress' => $f->progressPercent(),
                'donorCount' => $f->donorCount(),
                'currency' => $f->goal_currency,
                'startDate' => $f->start_date->toDateString(),
                'endDate' => $f->end_date->toDateString(),
                'status' => $f->status,
                'isOpen' => $f->isOpen(),
                'allowAnonymous' => $f->allow_anonymous,
                'allowRecurring' => $f->allow_recurring,
                'suggestedAmounts' => $f->suggested_amounts ?? [1000, 2500, 5000, 10000],
                'matchingSponsor' => $f->matching_sponsor,
                'fundAllocation' => $f->fund_allocation ?? ['Project Execution' => 70, 'Materials & Equipment' => 20, 'Contingency' => 10],
                'showLeaderboard' => $f->show_leaderboard,
                'updates' => $f->updates->map(fn (FundraiserUpdate $u) => [
                    'id' => $u->id,
                    'title' => $u->title,
                    'content' => $u->content,
                    'imageUrl' => $u->image_url,
                    'date' => $u->created_at->format('M d, Y'),
                ]),
                // Only gifts whose money has arrived; a pending one may yet be rejected.
                'recentDonations' => $f->donations
                    ->filter(fn (Donation $d) => $d->isCounted())
                    ->sortByDesc('donated_at')
                    ->take(10)
                    ->map(fn ($d) => [
                        'id' => $d->id,
                        'donorName' => $d->publicDonorName(),
                        'amount' => $d->amount(),
                        'currency' => $d->currency,
                        'isRecurring' => $d->is_recurring,
                        'status' => $d->status ?? 'completed',
                        'receiptNumber' => $d->receipt_number,
                        'receiptUrl' => route('dashboard.fundraising.donation.receipt', $d->id),
                        'timestamp' => $d->donated_at->toIso8601String(),
                    ])->values(),
            ]);

        $adminStats = null;
        $donorReports = [];
        $reconciliation = null;

        if ($canManage) {
            $totalGoalMinor = Fundraiser::sum('goal_minor');
            $totalRaisedMinor = (int) Donation::counted()->sum('amount_minor');
            $totalRefundedMinor = (int) Donation::where('status', 'refunded')->sum('amount_minor');

            $adminStats = [
                'totalCampaigns' => Fundraiser::count(),
                'activeCampaigns' => Fundraiser::where('status', 'Active')->count(),
                'completedCampaigns' => Fundraiser::where('status', 'Completed')->count(),
                'totalRaised' => (float) ($totalRaisedMinor / 100),
                'totalGoal' => (float) ($totalGoalMinor / 100),
                'totalRefunded' => (float) ($totalRefundedMinor / 100),
                'totalDonors' => Donation::counted()->distinct('user_id')->count('user_id'),
                'averageDonation' => Donation::counted()->avg('amount_minor') ? round((Donation::counted()->avg('amount_minor') / 100), 2) : 0,
            ];

            $donorReports = Donation::with(['fundraiser', 'user'])
                ->latest('donated_at')
                ->take(150)
                ->get()
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'receiptNumber' => $d->receipt_number,
                    'fundraiserId' => $d->fundraiser_id,
                    'fundraiserTitle' => $d->fundraiser?->title ?? 'Unknown Campaign',
                    'donorName' => $d->donor_name ?? ($d->user?->display_name ?? 'Resident'),
                    'publicDonorName' => $d->publicDonorName(),
                    'isAnonymous' => (bool) $d->is_anonymous,
                    'userEmail' => $d->user?->email,
                    'amount' => $d->amount(),
                    'currency' => $d->currency,
                    'channel' => $d->payment_channel ?? 'card',
                    'isRecurring' => (bool) $d->is_recurring,
                    'frequency' => $d->frequency,
                    'status' => $d->status ?? 'completed',
                    'refundedAt' => $d->refunded_at?->toIso8601String(),
                    'refundReason' => $d->refund_reason,
                    'donatedAt' => $d->donated_at->toIso8601String(),
                    'receiptUrl' => route('dashboard.fundraising.donation.receipt', $d->id),
                ]);

            $ledgerTotalMinor = (int) Transaction::whereNotNull('fundraiser_id')
                ->where('status', 'completed')
                ->sum('amount_minor');

            $channelBreakdown = Donation::counted()
                ->selectRaw('payment_channel, count(*) as count, sum(amount_minor) as total_minor')
                ->groupBy('payment_channel')
                ->get()
                ->map(fn ($row) => [
                    'channel' => $row->payment_channel ?? 'card',
                    'count' => (int) $row->count,
                    'total' => round($row->total_minor / 100, 2),
                ]);

            $reconciliation = [
                'totalDonationsMinor' => $totalRaisedMinor,
                'totalRefundsMinor' => $totalRefundedMinor,
                'ledgerTransactionsMinor' => $ledgerTotalMinor,
                'varianceMinor' => abs($totalRaisedMinor - $ledgerTotalMinor),
                'channelBreakdown' => $channelBreakdown,
            ];
        }

        $userDonations = Donation::where('user_id', $user->id)
            ->with('fundraiser')
            ->latest('donated_at')
            ->take(15)
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'fundraiserId' => $d->fundraiser_id,
                'fundraiserTitle' => $d->fundraiser?->title ?? 'Campaign',
                'amount' => $d->amount(),
                'currency' => $d->currency,
                'isAnonymous' => (bool) $d->is_anonymous,
                'isRecurring' => (bool) $d->is_recurring,
                'frequency' => $d->frequency,
                'status' => $d->status ?? 'completed',
                'donatedAt' => $d->donated_at->toIso8601String(),
                'receiptUrl' => route('dashboard.fundraising.donation.receipt', $d->id),
            ]);

        return Inertia::render('Dashboard/Fundraising', [
            'fundraisers' => $fundraisers,
            'canManage' => $canManage,
            'availableChannels' => $this->orchestrator->getAvailableChannels(),
            'adminStats' => $adminStats,
            'donorReports' => $donorReports,
            'reconciliation' => $reconciliation,
            'userDonations' => $userDonations,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:5000'],
            'beneficiary' => ['nullable', 'string', 'max:150'],
            'goal' => ['required', 'numeric', 'min:1'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'status' => ['required', 'in:Active,Upcoming'],
            'cover_image_url' => ['nullable', 'string', 'max:500'],
            'images' => ['nullable'],
            'matching_sponsor' => ['nullable', 'string', 'max:150'],
        ]);

        $images = [];
        if (! empty($validated['images'])) {
            if (is_array($validated['images'])) {
                $images = $validated['images'];
            } elseif (is_string($validated['images'])) {
                $images = array_filter(array_map('trim', explode("\n", $validated['images'])));
            }
        }

        Fundraiser::create([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'beneficiary' => $validated['beneficiary'] ?? 'Cypress Bay Community Improvement Fund',
            'goal_minor' => (int) round($validated['goal'] * 100),
            'goal_currency' => 'JMD',
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'status' => $validated['status'],
            'cover_image_url' => $validated['cover_image_url'] ?? null,
            'images' => $images,
            'matching_sponsor' => $validated['matching_sponsor'] ?? null,
            'suggested_amounts' => [1000, 2500, 5000, 10000],
            'fund_allocation' => ['Project Execution' => 70, 'Materials & Equipment' => 20, 'Contingency' => 10],
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Fundraiser created.');
    }

    public function update(Request $request, Fundraiser $fundraiser): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:Active,Upcoming,Completed,Canceled'],
        ]);

        if ($validated['status'] === 'Active' && $fundraiser->end_date->isPast()) {
            return back()->withErrors([
                'status' => 'This fundraiser ended on '.$fundraiser->end_date->toFormattedDateString().'. Extend the end date before opening it.',
            ]);
        }

        $fundraiser->update($validated);
        $request->user()->recordActivity("Set fundraiser {$fundraiser->title} to {$validated['status']}");

        return back()->with('success', "\"{$fundraiser->title}\" is now {$validated['status']}.");
    }

    /**
     * Give to a campaign.
     *
     * A card gift goes to Stripe Checkout and is recorded only once Stripe
     * confirms it (see StripePaymentService::settleDonationSession). It used to
     * go through the card driver, which returned success for every request, so
     * the default channel recorded a completed donation with no money taken.
     */
    public function donate(Request $request, Fundraiser $fundraiser, ProviderRegistry $providers): SymfonyResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:100000000'],
            'donor_name' => ['nullable', 'string', 'max:120'],
            'is_anonymous' => ['boolean'],
            'is_recurring' => ['nullable', 'boolean'],
            'frequency' => ['nullable', 'string', 'in:monthly,quarterly,annual'],
            // Validated against the registered drivers. Without this an
            // unknown key reached PaymentOrchestratorService::getDriver(),
            // which throws InvalidArgumentException — a 500 from a form post.
            'channel' => ['nullable', 'string', 'in:'.implode(',', $this->orchestrator->channelKeys())],
        ]);

        if (! $fundraiser->isOpen()) {
            return back()->withErrors(['amount' => 'This fundraiser is not currently accepting donations.']);
        }

        $user = $request->user();
        $channel = $validated['channel'] ?? 'card';
        $amountMinor = (int) round($validated['amount'] * 100);
        $amountLabel = "{$fundraiser->goal_currency} ".number_format($validated['amount'], 2);
        $donor = [
            'donor_name' => $validated['donor_name'] ?? $user->display_name,
            'is_anonymous' => $request->boolean('is_anonymous'),
            'is_recurring' => $request->boolean('is_recurring'),
            'frequency' => $validated['frequency'] ?? null,
        ];

        // The estate's card processor, or none; see ProviderRegistry.
        $provider = $providers->forChannel($channel);

        if (! $provider) {
            $method = $channel === 'card' ? 'Card' : Transaction::formatPaymentMethod($channel);

            return back()->withErrors(['channel' => "{$method} donations are not available yet. Please choose another method."]);
        }

        /*
         | A card gift is written as a Donation only once the processor
         | confirms the money, from details carried with the payment. Other
         | gifts are on record from the start as `pending`, which leaves them
         | out of every campaign total, the public donor feed and receipts:
         | the Community Wallet settles at once; bank wire, cash, QR, NFC,
         | digital wallets, Zelle and Cash App wait for the office to log the
         | money received and a second administrator to verify it.
         */
        // Card and device wallets: written on the processor's confirmation.
        $viaProcessor = $channel === 'card' || in_array($channel, ProviderRegistry::WALLET_CHANNELS, true);

        $donation = $viaProcessor ? null : $fundraiser->donations()->create([
            'user_id' => $user->id,
            'amount_minor' => $amountMinor,
            'currency' => $fundraiser->goal_currency,
            'donor_name' => $donor['donor_name'],
            'is_anonymous' => $donor['is_anonymous'],
            'is_recurring' => $donor['is_recurring'],
            'frequency' => $donor['is_recurring'] ? ($donor['frequency'] ?? 'monthly') : null,
            'status' => 'pending',
            'receipt_number' => 'DON-REC-'.date('Ymd').'-'.strtoupper(Str::random(5)),
            'payment_channel' => $channel,
            'donated_at' => now(),
        ]);

        try {
            $instruction = $provider->createPayment(new PaymentRequest(
                payer: $user,
                channel: $channel,
                amountMinor: $amountMinor,
                currency: $fundraiser->goal_currency,
                fundraiser: $fundraiser,
                donation: $donation,
                donor: $donor,
            ));
        } catch (\DomainException $e) {
            return back()->withErrors(['channel' => $e->getMessage()]);
        } catch (ApiErrorException $e) {
            report($e);

            return back()->withErrors(['channel' => 'Card checkout could not be started. Please try again shortly.']);
        }

        if ($instruction->isRedirect()) {
            return Inertia::location($instruction->url);
        }

        if ($instruction->type === 'completed') {
            $user->recordActivity("Donated {$amountLabel} to {$fundraiser->title}");

            return back()->with('success', 'Thank you for your generous donation! Your official receipt is available.');
        }

        $number = $instruction->payment->transaction_id;
        $user->recordActivity("Pledged {$amountLabel} to {$fundraiser->title} via {$channel} ({$number}), awaiting confirmation");

        return back()->with('success', "Thank you! Your gift {$number} is recorded as pending and will count towards the campaign once the community office has received and verified it.");
    }

    /**
     * Administrator refund processing for donations.
     */
    public function refund(Request $request, Donation $donation): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        if ($donation->status === 'refunded') {
            return back()->withErrors(['refund' => 'This donation has already been refunded.']);
        }

        if (! $donation->isCounted()) {
            return back()->withErrors(['refund' => 'Only a confirmed donation can be refunded. Reject a pending one from the billing ledger instead.']);
        }

        $donation->update([
            'status' => 'refunded',
            'refunded_at' => now(),
            'refund_reason' => $validated['reason'],
        ]);

        // Record refund in master ledger
        Transaction::create([
            'user_id' => $donation->user_id,
            'fundraiser_id' => $donation->fundraiser_id,
            'amount_minor' => $donation->amount_minor,
            'fee_minor' => 0,
            'net_amount_minor' => -$donation->amount_minor,
            'currency' => $donation->currency,
            'payment_channel' => $donation->payment_channel ?? 'card',
            'reference' => 'REF-DON-'.strtoupper(Str::random(8)),
            'status' => 'refunded',
            'settled_at' => now(),
            'notes' => "Refund for donation #{$donation->receipt_number}: {$validated['reason']}",
        ]);

        $request->user()->recordActivity("Refunded donation #{$donation->receipt_number} ({$donation->amount()} {$donation->currency})");

        return back()->with('success', "Donation #{$donation->receipt_number} has been refunded.");
    }

    /**
     * Export all donor contribution records as CSV for reporting and compliance.
     */
    public function exportDonations(Request $request): StreamedResponse
    {
        $donations = Donation::with(['fundraiser', 'user'])
            ->latest('donated_at')
            ->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="fundraising-donations-'.date('Ymd-His').'.csv"',
        ];

        return response()->stream(function () use ($donations) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Receipt Number',
                'Date',
                'Campaign Title',
                'Beneficiary',
                'Donor Name',
                'Public Name',
                'Is Anonymous',
                'User Email',
                'Amount',
                'Currency',
                'Payment Channel',
                'Is Recurring',
                'Frequency',
                'Status',
                'Refunded At',
                'Refund Reason',
            ]);

            foreach ($donations as $d) {
                fputcsv($handle, [
                    $d->receipt_number,
                    $d->donated_at->toDateTimeString(),
                    $d->fundraiser?->title ?? 'N/A',
                    $d->fundraiser?->beneficiary ?? 'N/A',
                    $d->donor_name ?? ($d->user?->display_name ?? 'N/A'),
                    $d->publicDonorName(),
                    $d->is_anonymous ? 'Yes' : 'No',
                    $d->user?->email ?? 'N/A',
                    number_format($d->amount(), 2, '.', ''),
                    $d->currency,
                    $d->payment_channel ?? 'card',
                    $d->is_recurring ? 'Yes' : 'No',
                    $d->frequency ?? 'one-time',
                    $d->status ?? 'completed',
                    $d->refunded_at?->toDateTimeString() ?? '',
                    $d->refund_reason ?? '',
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * A donation receipt names the donor and the amount they gave.
     *
     * The route was bound to `{donation}` with no check at all, so any signed-in
     * user could walk the ids and read every gift in the estate — including
     * gifts the donor marked anonymous, since `is_anonymous` only governs the
     * public leaderboard, not this document.
     */
    public function receipt(Request $request, Donation $donation): HttpResponse
    {
        $user = $request->user();

        abort_unless(
            $donation->user_id === $user->id || $user->can('manageFundraisers'),
            403,
            'You can only view your own donation receipts.',
        );

        abort_if(
            in_array($donation->status, ['pending', 'rejected'], true),
            409,
            'A receipt is issued once the community office confirms the donation.',
        );

        $donation->load('fundraiser');
        $community = Community::first();

        $pdf = Pdf::loadView('pdf.donation-receipt', [
            'donation' => $donation,
            'community' => $community,
        ]);

        return $pdf->stream('donation-receipt-'.($donation->receipt_number ?? $donation->id).'.pdf');
    }

    /**
     * The donor returning from Stripe Checkout. The session is checked with
     * Stripe before anything is recorded; see completeDonation().
     */
    public function donationStripeSuccess(Request $request, Fundraiser $fundraiser, StripePaymentService $stripe): RedirectResponse
    {
        abort_unless($stripe->isLive(), 404);

        $validated = $request->validate([
            'session_id' => ['required', 'string', 'max:255'],
        ]);

        if (! $stripe->completeDonation($fundraiser, $request->user(), $validated['session_id'])) {
            return redirect()->route('dashboard.fundraising')->with(
                'error',
                'We could not confirm your donation with Stripe. If you were charged, please contact the community office.',
            );
        }

        return redirect()->route('dashboard.fundraising')
            ->with('success', "Thank you for supporting \"{$fundraiser->title}\"! Your receipt is ready.");
    }

    /** The donor backed out of Stripe Checkout. */
    public function donationStripeCancel(Fundraiser $fundraiser, StripePaymentService $stripe): RedirectResponse
    {
        abort_unless($stripe->isLive(), 404);

        return redirect()->route('dashboard.fundraising')
            ->with('info', 'Donation cancelled. You have not been charged.');
    }

    public function addUpdate(Request $request, Fundraiser $fundraiser): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'content' => ['required', 'string', 'max:2000'],
            'image_url' => ['nullable', 'url', 'max:500'],
        ]);

        $fundraiser->updates()->create($validated);

        return back()->with('success', 'Project update posted.');
    }
}
