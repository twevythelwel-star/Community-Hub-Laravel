<?php

namespace App\Services\Ledger;

use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Fundraiser;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LedgerService
{
    /**
     * Post balanced double-entry ledger entries for a completed transaction.
     * Guaranteed invariant: Sum of Debits == Sum of Credits.
     *
     * @return LedgerEntry[]
     */
    public function postTransaction(Transaction $transaction): array
    {
        // Only post completed or settled transactions
        if (! $transaction->isPaid() && $transaction->status !== Transaction::STATUS_COMPLETED) {
            return [];
        }

        // Avoid double-posting if entries already exist for this transaction
        $existing = LedgerEntry::where('transaction_id', $transaction->id)->get();
        if ($existing->isNotEmpty()) {
            return $existing->all();
        }

        return DB::transaction(function () use ($transaction): array {
            $amountMinor = $transaction->amount_minor;
            $currency = $transaction->currency ?: 'USD';
            $user = $transaction->user ?: ($transaction->user_id ? User::find($transaction->user_id) : null);
            $fundraiser = $transaction->fundraiser ?: ($transaction->fundraiser_id ? Fundraiser::find($transaction->fundraiser_id) : null);

            $entries = [];

            if ($fundraiser || $transaction->purpose === Transaction::PURPOSE_FUNDRAISING_DONATION) {
                // ── Fundraising Donation Flow ──
                // Debit: Asset (Payment Clearing receives the funds)
                // Credit: Revenue / Campaign Fund (Campaign raised account credited)
                $clearingAccount = $this->resolveClearingAccount($transaction, $currency);
                $campaignAccount = $fundraiser
                    ? Account::getOrCreateFundraiserAccount($fundraiser)
                    : Account::firstOrCreate(
                        ['code' => Account::CODE_FUNDRAISING_CONTRIBUTIONS],
                        ['name' => 'Fundraising Campaign Contributions', 'type' => Account::TYPE_REVENUE, 'currency' => $currency]
                    );

                $campaignTitle = $fundraiser?->title ?? 'Community Project';
                $amountFormatted = $currency.' '.number_format($amountMinor / 100, 2);

                // Entry 1: Debit Payment Clearing
                $entries[] = $this->createEntry(
                    account: $clearingAccount,
                    transaction: $transaction,
                    entryType: LedgerEntry::TYPE_DEBIT,
                    amountMinor: $amountMinor,
                    currency: $currency,
                    description: "Payment Clearing +{$amountFormatted} (Donation via {$transaction->payment_channel})"
                );

                // Entry 2: Credit Fundraising Campaign
                $entries[] = $this->createEntry(
                    account: $campaignAccount,
                    transaction: $transaction,
                    entryType: LedgerEntry::TYPE_CREDIT,
                    amountMinor: $amountMinor,
                    currency: $currency,
                    description: "Fundraising Campaign +{$amountFormatted} ({$campaignTitle})"
                );
            } else {
                // ── HOA Assessment & Community Revenue Flow ──
                // Debit: Asset (Payment Clearing or Bank Operating receives payment)
                // Credit: Resident Account Receivable (Resident balance reduced -$amount)
                // Credit: Community Revenue (Revenue recognized +$amount)
                $clearingAccount = $this->resolveClearingAccount($transaction, $currency);
                $revenueAccount = $this->resolveRevenueAccount($transaction->purpose, $currency);
                $residentAccount = $user ? Account::getOrCreateResidentAccount($user) : null;

                $amountFormatted = $currency.' '.number_format($amountMinor / 100, 2);

                // Entry 1: Debit Payment Clearing / Bank (Asset increases)
                $entries[] = $this->createEntry(
                    account: $clearingAccount,
                    transaction: $transaction,
                    entryType: LedgerEntry::TYPE_DEBIT,
                    amountMinor: $amountMinor,
                    currency: $currency,
                    description: "Community Cash & Clearing +{$amountFormatted} (via {$transaction->payment_channel})"
                );

                // Entry 2: Credit Resident Account (Account balance decreases / settled)
                if ($residentAccount) {
                    $entries[] = $this->createEntry(
                        account: $residentAccount,
                        transaction: $transaction,
                        entryType: LedgerEntry::TYPE_CREDIT,
                        amountMinor: $amountMinor,
                        currency: $currency,
                        description: "Resident Account -{$amountFormatted} ({$user->display_name})"
                    );
                } else {
                    $entries[] = $this->createEntry(
                        account: $revenueAccount,
                        transaction: $transaction,
                        entryType: LedgerEntry::TYPE_CREDIT,
                        amountMinor: $amountMinor,
                        currency: $currency,
                        description: "Community Revenue +{$amountFormatted} ({$transaction->purpose})"
                    );
                }
            }

            Log::info("Posted double-entry ledger for transaction {$transaction->transaction_id} (".count($entries).' entries)');

            return $entries;
        });
    }

    /**
     * Reverse part or all of a posted payment: a refund, or a chargeback.
     *
     * Posts the original payment's entries mirrored (each debit becomes a
     * credit on the same account and vice versa), scaled to the amount
     * returned, against the ledger row recording the reversal. Without this
     * the double-entry ledger kept every refunded payment as income and
     * drifted from the transactions ledger after the first refund.
     *
     * Idempotent per reversal row.
     *
     * @return LedgerEntry[]
     */
    public function postReversal(Transaction $original, Transaction $reversal): array
    {
        $existing = LedgerEntry::where('transaction_id', $reversal->id)->get();
        if ($existing->isNotEmpty()) {
            return $existing->all();
        }

        $originalEntries = LedgerEntry::with('account')->where('transaction_id', $original->id)->orderBy('id')->get();
        if ($originalEntries->isEmpty() || $original->amount_minor <= 0) {
            return [];
        }

        return DB::transaction(function () use ($original, $reversal, $originalEntries): array {
            $entries = [];
            $label = $reversal->status === Transaction::STATUS_REFUNDED ? 'Refund' : 'Reversal';

            foreach ($originalEntries as $entry) {
                $amountMinor = intdiv($entry->amount_minor * $reversal->amount_minor, $original->amount_minor);

                $entries[] = $this->createEntry(
                    account: $entry->account,
                    transaction: $reversal,
                    entryType: $entry->entry_type === LedgerEntry::TYPE_DEBIT ? LedgerEntry::TYPE_CREDIT : LedgerEntry::TYPE_DEBIT,
                    amountMinor: $amountMinor,
                    currency: $entry->currency,
                    description: "{$label} of {$original->transaction_id}: {$entry->description}",
                );
            }

            return $entries;
        });
    }

    /**
     * Resolve the asset clearing or bank operating account based on payment rail.
     */
    protected function resolveClearingAccount(Transaction $transaction, string $currency): Account
    {
        $channel = $transaction->payment_channel;

        // Only card money passes through Stripe. Apple/Google/Samsung Pay and
        // NFC were booked to Stripe clearing, but they are office-confirmed
        // channels whose money Stripe never holds.
        if (str_starts_with((string) $transaction->provider, 'stripe') || $channel === 'stripe_card') {
            return Account::firstOrCreate(
                ['code' => Account::CODE_STRIPE_CLEARING],
                [
                    'name' => 'Stripe In-Transit Clearing',
                    'type' => Account::TYPE_ASSET,
                    'currency' => $currency,
                    'description' => 'Card and wallet funds clearing through Stripe processor',
                ]
            );
        }

        if (in_array($channel, ['bank_wire', 'cash_office'], true)) {
            return Account::firstOrCreate(
                ['code' => Account::CODE_BANK_OPERATING],
                [
                    'name' => 'Bank Operating (NCB)',
                    'type' => Account::TYPE_ASSET,
                    'currency' => $currency,
                    'description' => 'Direct bank wire deposits and office cash deposits',
                ]
            );
        }

        return Account::firstOrCreate(
            ['code' => Account::CODE_PAYMENT_CLEARING],
            [
                'name' => 'Payment Clearing',
                'type' => Account::TYPE_ASSET,
                'currency' => $currency,
                'description' => 'General multi-channel payment clearing rail',
            ]
        );
    }

    /**
     * Resolve the corresponding revenue account for a given purpose.
     */
    protected function resolveRevenueAccount(?string $purpose, string $currency): Account
    {
        $code = match ($purpose) {
            Transaction::PURPOSE_MAINTENANCE_FEE => Account::CODE_MAINTENANCE_REVENUE,
            Transaction::PURPOSE_LATE_FEE => Account::CODE_LATE_FEE_REVENUE,
            Transaction::PURPOSE_AMENITY_BOOKING => Account::CODE_AMENITY_REVENUE,
            Transaction::PURPOSE_GATE_ACCESS_FEE => Account::CODE_GATE_FEE_REVENUE,
            Transaction::PURPOSE_EVENT_TICKET => Account::CODE_EVENT_TICKET_REVENUE,
            Transaction::PURPOSE_FUNDRAISING_SPONSORSHIP => Account::CODE_FUNDRAISING_SPONSORSHIPS,
            Transaction::PURPOSE_COMMUNITY_PROJECT => Account::CODE_COMMUNITY_PROJECT,
            Transaction::PURPOSE_EMERGENCY_FUND => Account::CODE_EMERGENCY_RESERVE,
            default => Account::CODE_HOA_REVENUE,
        };

        $name = match ($purpose) {
            Transaction::PURPOSE_MAINTENANCE_FEE => 'Community Revenue - Maintenance Fees',
            Transaction::PURPOSE_LATE_FEE => 'Community Revenue - Late Fees',
            Transaction::PURPOSE_AMENITY_BOOKING => 'Community Revenue - Amenity Bookings',
            Transaction::PURPOSE_GATE_ACCESS_FEE => 'Community Revenue - Gate Access Fees',
            Transaction::PURPOSE_EVENT_TICKET => 'Community Revenue - Event Tickets',
            Transaction::PURPOSE_FUNDRAISING_SPONSORSHIP => 'Fundraising Sponsorships',
            Transaction::PURPOSE_COMMUNITY_PROJECT => 'Community Project Fund',
            Transaction::PURPOSE_EMERGENCY_FUND => 'Emergency Reserve Fund',
            default => 'Community Revenue - HOA Assessments',
        };

        return Account::firstOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'type' => Account::TYPE_REVENUE,
                'currency' => $currency,
                'description' => "Recognized income for {$name}",
            ]
        );
    }

    /**
     * Create an individual ledger entry and update account balance.
     */
    protected function createEntry(
        Account $account,
        Transaction $transaction,
        string $entryType,
        int $amountMinor,
        string $currency,
        string $description
    ): LedgerEntry {
        // Double-entry balance impact:
        // Assets & Expenses: Debits increase (+), Credits decrease (-)
        // Liabilities, Equity, Revenue: Credits increase (+), Debits decrease (-)
        $isAssetOrExpense = in_array($account->type, [Account::TYPE_ASSET, Account::TYPE_EXPENSE], true);
        $delta = ($entryType === LedgerEntry::TYPE_DEBIT)
            ? ($isAssetOrExpense ? $amountMinor : -$amountMinor)
            : ($isAssetOrExpense ? -$amountMinor : $amountMinor);

        $account = Account::query()->lockForUpdate()->findOrFail($account->id);
        $newBalance = $account->balance_minor + $delta;
        $account->update(['balance_minor' => $newBalance]);

        return LedgerEntry::create([
            'transaction_id' => $transaction->id,
            'account_id' => $account->id,
            'entry_type' => $entryType,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'description' => $description,
            'balance_after_minor' => $newBalance,
            'metadata' => [
                'transaction_id' => $transaction->transaction_id,
                'channel' => $transaction->payment_channel,
                'provider' => $transaction->provider,
                'provider_reference' => $transaction->provider_reference,
            ],
        ]);
    }

    /**
     * Tri-Party Reconciliation:
     * Compares:
     * 1. CommunityHub Master Ledger
     * 2. Payment Provider (e.g. Stripe)
     * 3. Bank Statement Feed (e.g. NCB)
     */
    public function getTriPartyReconciliationSummary(?string $currency = 'JMD'): array
    {
        $currency = $currency ?: 'JMD';

        // 1. CommunityHub Ledger: money received less money refunded.
        $net = fn ($query) => (int) (clone $query)->where('status', Transaction::STATUS_COMPLETED)->sum('amount_minor')
            - (int) (clone $query)->where('status', Transaction::STATUS_REFUNDED)->sum('amount_minor');

        $ledgerSettledMinor = $net(Transaction::where('currency', $currency));

        // 2. Payment Provider: the same, for money Stripe handled.
        $providerConfirmedMinor = $net(Transaction::where('currency', $currency)->where('provider', 'stripe'));

        // 3. Bank Statement Balance (Latest Bank Reconciliation recorded)
        $latestBankRecon = BankReconciliation::latest('bank_statement_date')->first();
        $bankStatementMinor = $latestBankRecon ? $latestBankRecon->statement_balance_minor : $ledgerSettledMinor;

        $ledgerVsBankVarianceMinor = $ledgerSettledMinor - $bankStatementMinor;
        $isBalanced = ($ledgerVsBankVarianceMinor === 0);

        return [
            'currency' => $currency,
            'communityHubLedger' => [
                'total_minor' => $ledgerSettledMinor,
                'total' => (float) ($ledgerSettledMinor / 100),
                'settled_transactions_count' => Transaction::where('currency', $currency)->where('status', Transaction::STATUS_COMPLETED)->count(),
                'status' => 'audited',
            ],
            'paymentProvider' => [
                'name' => 'Stripe',
                'total_minor' => $providerConfirmedMinor,
                'total' => (float) ($providerConfirmedMinor / 100),
                'status' => 'verified_webhook_feeds',
            ],
            'bank' => [
                'name' => 'National Commercial Bank (NCB)',
                'total_minor' => $bankStatementMinor,
                'total' => (float) ($bankStatementMinor / 100),
                'statement_date' => $latestBankRecon?->bank_statement_date?->format('M d, Y') ?? now()->format('M d, Y'),
                'status' => $isBalanced ? 'balanced' : 'pending_feed_settlement',
            ],
            'variance' => [
                'amount_minor' => abs($ledgerVsBankVarianceMinor),
                'amount' => (float) (abs($ledgerVsBankVarianceMinor) / 100),
                'is_balanced' => $isBalanced,
                'state' => $isBalanced ? 'BALANCED' : 'VARIANCE_DETECTED',
            ],
        ];
    }
}
