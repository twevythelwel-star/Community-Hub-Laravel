<?php

namespace App\Services\Payments;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\ReconciliationBatch;
use App\Models\ReconciliationMatch;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnterpriseReconciliationService
{
    public const STATUS_MATCHED = ReconciliationMatch::STATUS_MATCHED;

    public const STATUS_MISSING_PROVIDER = ReconciliationMatch::STATUS_MISSING_PROVIDER;

    public const STATUS_MISSING_COMMUNITYHUB = ReconciliationMatch::STATUS_MISSING_COMMUNITYHUB;

    public const STATUS_AMOUNT_MISMATCH = ReconciliationMatch::STATUS_AMOUNT_MISMATCH;

    public const STATUS_CURRENCY_MISMATCH = ReconciliationMatch::STATUS_CURRENCY_MISMATCH;

    public const STATUS_DUPLICATE = ReconciliationMatch::STATUS_DUPLICATE;

    public const STATUS_REFUND_MISMATCH = ReconciliationMatch::STATUS_REFUND_MISMATCH;

    public const STATUS_UNRESOLVED = ReconciliationMatch::STATUS_UNRESOLVED;

    public function __construct(
        protected LedgerService $ledgerService
    ) {}

    /**
     * Run an end-of-day tri-party reconciliation comparing:
     * 1. CommunityHub (Transactions & Ledger)
     * 2. Provider records (PSP feeds: Stripe, WiPay, Zelle, Cash App)
     * 3. Bank records (Raw bank feed rows: NCB, Scotia, etc.)
     *
     * Identifies:
     * - MATCHED
     * - MISSING_PROVIDER
     * - MISSING_COMMUNITYHUB
     * - AMOUNT_MISMATCH
     * - CURRENCY_MISMATCH
     * - DUPLICATE
     * - REFUND_MISMATCH
     * - UNRESOLVED
     */
    public function reconcile(
        ReconciliationBatch $batch,
        array $providerRecords = [],
        array $bankRecords = [],
        ?User $auditor = null
    ): array {
        return DB::transaction(function () use ($batch, $providerRecords, $bankRecords, $auditor): array {
            // 1. Sync or ingest bank records into BankTransaction rows if raw arrays provided
            $bankTransactions = $this->ingestBankRecords($batch, $bankRecords);

            // 2. Fetch CommunityHub transactions for the batch period & community
            $chTransactions = $this->getCommunityHubTransactions($batch);

            // 3. Normalized collections for tri-party comparison
            $providerRecordsCollection = collect($providerRecords);
            $matchedBankTxIds = collect();
            $matchedProviderRefs = collect();
            $matchedChTxIds = collect();

            $matches = [];
            $discrepancies = [];

            // A. Check for DUPLICATE entries across provider and bank records
            $this->detectDuplicateRecords($batch, $providerRecordsCollection, $bankTransactions, $matches, $discrepancies, $matchedBankTxIds, $matchedProviderRefs);

            // B. Reconcile CommunityHub transactions against Provider & Bank
            foreach ($chTransactions as $tx) {
                $txId = $tx->id;
                $chRef = $tx->transaction_id ?? "TX-{$tx->id}";
                $providerRef = $tx->provider_reference;
                $expectedAmount = (int) $tx->amount_minor;
                $expectedCurrency = strtoupper($tx->currency ?? 'JMD');
                $isRefunded = ($tx->status === Transaction::STATUS_REFUNDED);

                // Find matching provider record
                $providerRecord = $providerRecordsCollection->first(function ($p) use ($tx, $chRef, $providerRef) {
                    $ref = $p['reference'] ?? $p['id'] ?? $p['provider_reference'] ?? null;

                    return $ref && ($ref === $providerRef || $ref === $chRef || ($tx->provider_event_id && $ref === $tx->provider_event_id));
                });

                // Find matching bank transaction
                $bankTx = $bankTransactions->first(function (BankTransaction $bt) use ($tx, $chRef, $providerRef, $matchedBankTxIds) {
                    if ($matchedBankTxIds->contains($bt->id)) {
                        return false;
                    }
                    if ($bt->matched_transaction_id === $tx->id) {
                        return true;
                    }
                    $desc = $bt->description.' '.($bt->payer_reference ?? '').' '.($bt->bank_reference ?? '');

                    return str_contains($desc, $chRef) || ($providerRef && str_contains($desc, $providerRef));
                });

                // --- 1. REFUND_MISMATCH check ---
                if ($isRefunded) {
                    $providerHasRefund = $providerRecord && (! empty($providerRecord['refunded']) || ($providerRecord['status'] ?? '') === 'refunded');
                    $bankHasDebit = $bankTx && $bankTx->entry_type === 'debit';

                    if (! $providerRecord && ! $bankTx) {
                        $match = $this->recordMatch($batch, self::STATUS_REFUND_MISMATCH, $tx, $bankTx, [
                            'issue' => 'Refund recorded in CommunityHub but no corresponding reversal found in provider or bank feed',
                            'tx_amount_minor' => $expectedAmount,
                        ], $auditor);
                        $discrepancies[] = $match;

                        continue;
                    }
                }

                // Check if provider processed a refund/chargeback that CommunityHub hasn't recorded
                if (! $isRefunded && $providerRecord && (! empty($providerRecord['refunded']) || ($providerRecord['status'] ?? '') === 'refunded')) {
                    $match = $this->recordMatch($batch, self::STATUS_REFUND_MISMATCH, $tx, $bankTx, [
                        'issue' => 'Provider processed a refund/chargeback but CommunityHub transaction is not marked refunded',
                        'provider_reference' => $providerRecord['reference'] ?? null,
                    ], $auditor);
                    $discrepancies[] = $match;

                    continue;
                }

                // --- 2. MISSING_PROVIDER check ---
                // For online payment methods (Stripe, WiPay, card), provider record is required.
                $requiresProvider = in_array($tx->provider, ['stripe', 'wipay', 'card']) || in_array($tx->payment_channel, ['stripe_card', 'wipay', 'card']);
                if ($requiresProvider && ! $providerRecord && $providerRecordsCollection->isNotEmpty()) {
                    $match = $this->recordMatch($batch, self::STATUS_MISSING_PROVIDER, $tx, $bankTx, [
                        'issue' => 'Transaction settled in CommunityHub but missing from payment provider settlement feed',
                        'provider' => $tx->provider,
                    ], $auditor);
                    $discrepancies[] = $match;

                    continue;
                }

                // --- 3. Compare with Bank and Provider Records ---
                $targetRecord = $bankTx ?: $providerRecord;

                if (! $targetRecord) {
                    // Check if bank transactions have a possible amount/date match that is ambiguous
                    $potentialBankMatches = $bankTransactions->filter(function (BankTransaction $bt) use ($expectedAmount, $matchedBankTxIds) {
                        return ! $matchedBankTxIds->contains($bt->id) && $bt->amount_minor === $expectedAmount;
                    });

                    if ($potentialBankMatches->count() > 1) {
                        $match = $this->recordMatch($batch, self::STATUS_UNRESOLVED, $tx, null, [
                            'issue' => 'Multiple ambiguous bank records found with identical amount but non-matching references',
                            'candidate_bank_tx_ids' => $potentialBankMatches->pluck('id')->all(),
                        ], $auditor);
                        $discrepancies[] = $match;

                        continue;
                    }

                    // Otherwise, if online and no bank yet, could be in transit, but for end-of-day it is flagged
                    $match = $this->recordMatch($batch, self::STATUS_UNRESOLVED, $tx, null, [
                        'issue' => 'No matching provider or bank record found for transaction',
                    ], $auditor);
                    $discrepancies[] = $match;

                    continue;
                }

                $recordAmount = $bankTx ? (int) $bankTx->amount_minor : (int) ($providerRecord['amount_minor'] ?? ($providerRecord['amount'] * 100));
                $recordCurrency = strtoupper($bankTx ? $bankTx->currency : ($providerRecord['currency'] ?? 'JMD'));

                // --- 4. CURRENCY_MISMATCH check ---
                if ($recordCurrency !== $expectedCurrency) {
                    $match = $this->recordMatch($batch, self::STATUS_CURRENCY_MISMATCH, $tx, $bankTx, [
                        'expected_currency' => $expectedCurrency,
                        'actual_currency' => $recordCurrency,
                        'issue' => "Currency mismatch: expected {$expectedCurrency}, received {$recordCurrency}",
                    ], $auditor);
                    $discrepancies[] = $match;
                    if ($bankTx) {
                        $matchedBankTxIds->push($bankTx->id);
                    }

                    continue;
                }

                // --- 5. AMOUNT_MISMATCH check ---
                if ($recordAmount !== $expectedAmount) {
                    $diffMinor = $recordAmount - $expectedAmount;
                    $match = $this->recordMatch($batch, self::STATUS_AMOUNT_MISMATCH, $tx, $bankTx, [
                        'expected_minor' => $expectedAmount,
                        'actual_minor' => $recordAmount,
                        'variance_minor' => $diffMinor,
                        'issue' => "Amount variance detected: expected {$expectedAmount}, actual {$recordAmount} (diff: {$diffMinor})",
                    ], $auditor);
                    $discrepancies[] = $match;
                    if ($bankTx) {
                        $matchedBankTxIds->push($bankTx->id);
                    }

                    continue;
                }

                // --- 6. MATCHED ---
                $match = $this->recordMatch($batch, self::STATUS_MATCHED, $tx, $bankTx, [
                    'verified' => true,
                    'settled_amount_minor' => $expectedAmount,
                ], $auditor);
                $matches[] = $match;
                $matchedChTxIds->push($tx->id);

                if ($bankTx) {
                    $matchedBankTxIds->push($bankTx->id);
                    $bankTx->update([
                        'matched_transaction_id' => $tx->id,
                        'status' => 'matched',
                    ]);
                }
                if ($providerRecord) {
                    $matchedProviderRefs->push($providerRecord['reference'] ?? $providerRecord['id'] ?? null);
                }
            }

            // C. Identify MISSING_COMMUNITYHUB (Money arrived in Bank/Provider with no CommunityHub transaction)
            foreach ($bankTransactions as $bankTx) {
                if ($matchedBankTxIds->contains($bankTx->id)) {
                    continue;
                }
                if ($bankTx->status === 'matched') {
                    continue;
                }

                $match = $this->recordMatch($batch, self::STATUS_MISSING_COMMUNITYHUB, null, $bankTx, [
                    'issue' => 'Bank credit/deposit received with no matching CommunityHub transaction or invoice payment',
                    'bank_reference' => $bankTx->bank_reference,
                    'payer_reference' => $bankTx->payer_reference,
                    'amount_minor' => $bankTx->amount_minor,
                    'currency' => $bankTx->currency,
                    'description' => $bankTx->description,
                ], $auditor);
                $discrepancies[] = $match;
            }

            foreach ($providerRecordsCollection as $providerRecord) {
                $ref = $providerRecord['reference'] ?? $providerRecord['id'] ?? null;
                if ($ref && ! $matchedProviderRefs->contains($ref)) {
                    // Check if already matched
                    $existsInCh = Transaction::where('provider_reference', $ref)
                        ->orWhere('transaction_id', $ref)
                        ->exists();

                    if (! $existsInCh) {
                        $match = $this->recordMatch($batch, self::STATUS_MISSING_COMMUNITYHUB, null, null, [
                            'issue' => 'Provider record settled at gateway but no transaction exists in CommunityHub',
                            'provider_reference' => $ref,
                            'amount_minor' => (int) ($providerRecord['amount_minor'] ?? ($providerRecord['amount'] * 100)),
                            'currency' => $providerRecord['currency'] ?? 'JMD',
                        ], $auditor);
                        $discrepancies[] = $match;
                    }
                }
            }

            // D. Update Batch Balances & Counters
            $matchedCount = count($matches);
            $discrepancyCount = count($discrepancies);
            $totalStatementBalance = (int) $bankTransactions->where('entry_type', 'credit')->sum('amount_minor');
            $totalLedgerBalance = (int) $chTransactions->where('status', Transaction::STATUS_COMPLETED)->sum('amount_minor')
                - (int) $chTransactions->where('status', Transaction::STATUS_REFUNDED)->sum('amount_minor');
            $variance = $totalStatementBalance - $totalLedgerBalance;

            $batch->update([
                'statement_balance_minor' => $totalStatementBalance,
                'ledger_balance_minor' => $totalLedgerBalance,
                'variance_minor' => $variance,
                'matched_count' => $matchedCount,
                'discrepancy_count' => $discrepancyCount,
                'unmatched_count' => $discrepancyCount,
                'status' => ($variance === 0 && $discrepancyCount === 0) ? ReconciliationBatch::STATUS_BALANCED : ReconciliationBatch::STATUS_DISCREPANCY,
                'conducted_by' => $auditor?->id,
            ]);

            return [
                'batch' => $batch->fresh(['matches']),
                'matched_count' => $matchedCount,
                'discrepancy_count' => $discrepancyCount,
                'variance_minor' => $variance,
                'status' => $batch->status,
                'matches' => $matches,
                'discrepancies' => $discrepancies,
                'summary_by_category' => [
                    self::STATUS_MATCHED => $matchedCount,
                    self::STATUS_MISSING_PROVIDER => collect($discrepancies)->where('status', self::STATUS_MISSING_PROVIDER)->count(),
                    self::STATUS_MISSING_COMMUNITYHUB => collect($discrepancies)->where('status', self::STATUS_MISSING_COMMUNITYHUB)->count(),
                    self::STATUS_AMOUNT_MISMATCH => collect($discrepancies)->where('status', self::STATUS_AMOUNT_MISMATCH)->count(),
                    self::STATUS_CURRENCY_MISMATCH => collect($discrepancies)->where('status', self::STATUS_CURRENCY_MISMATCH)->count(),
                    self::STATUS_DUPLICATE => collect($discrepancies)->where('status', self::STATUS_DUPLICATE)->count(),
                    self::STATUS_REFUND_MISMATCH => collect($discrepancies)->where('status', self::STATUS_REFUND_MISMATCH)->count(),
                    self::STATUS_UNRESOLVED => collect($discrepancies)->where('status', self::STATUS_UNRESOLVED)->count(),
                ],
            ];
        });
    }

    /**
     * Resolve an identified discrepancy with authoritative audit trail.
     */
    public function resolveDiscrepancy(
        ReconciliationMatch $match,
        string $action,
        User $resolver,
        ?string $notes = null,
        array $payload = []
    ): ReconciliationMatch {
        $actions = ['create_missing_transaction', 'adjust_amount_variance', 'void_duplicate', 'post_reversal', 'manual_verified'];

        if (! in_array($action, $actions, true)) {
            throw new DomainException("Unknown reconciliation action [{$action}].");
        }

        if ($match->resolved_at !== null) {
            throw new DomainException('This discrepancy has already been resolved.');
        }

        return DB::transaction(function () use ($match, $action, $resolver, $notes, $payload) {
            $batch = $match->batch;

            switch ($action) {
                case 'create_missing_transaction':
                    // The money belongs to the household that paid it, never to the auditor resolving the line.
                    $resident = isset($payload['user_id'])
                        ? User::find($payload['user_id'])
                        : null;

                    if (! $resident) {
                        throw new DomainException('Choose the household this deposit belongs to before recording it.');
                    }

                    $amountMinor = (int) ($payload['amount_minor'] ?? $match->discrepancy_details['amount_minor'] ?? $match->bankTransaction?->amount_minor ?? 0);

                    if ($amountMinor < 1) {
                        throw new DomainException('A missing transaction needs a positive amount.');
                    }

                    $currency = $payload['currency'] ?? $match->discrepancy_details['currency'] ?? $match->bankTransaction?->currency ?? 'JMD';
                    $reference = $payload['reference'] ?? $match->bankTransaction?->bank_reference ?? "RECON-ADJ-{$match->id}";

                    $tx = Transaction::create([
                        'user_id' => $resident->id,
                        'transaction_id' => Transaction::generateTransactionId(),
                        'amount_minor' => $amountMinor,
                        'currency' => $currency,
                        'payment_channel' => $payload['payment_channel'] ?? 'bank_wire',
                        'provider' => $payload['provider'] ?? 'bank',
                        'provider_reference' => $reference,
                        'status' => Transaction::STATUS_COMPLETED,
                        'settled_at' => now(),
                    ]);

                    // Post double-entry ledger entries: DR 1030 Bank Operating / CR 4010 HOA Revenue
                    $operatingAcc = Account::firstOrCreate(
                        ['code' => Account::CODE_BANK_OPERATING],
                        ['name' => 'Bank Operating (NCB)', 'type' => Account::TYPE_ASSET, 'currency' => $currency]
                    );
                    $revenueAcc = Account::firstOrCreate(
                        ['code' => Account::CODE_HOA_REVENUE],
                        ['name' => 'Community Revenue - HOA Assessments', 'type' => Account::TYPE_REVENUE, 'currency' => $currency]
                    );

                    LedgerEntry::create([
                        'transaction_id' => $tx->id,
                        'account_id' => $operatingAcc->id,
                        'entry_type' => LedgerEntry::TYPE_DEBIT,
                        'amount_minor' => $amountMinor,
                        'currency' => $currency,
                        'description' => "Reconciliation adjustment: Direct deposit credit for {$tx->transaction_id}",
                    ]);

                    LedgerEntry::create([
                        'transaction_id' => $tx->id,
                        'account_id' => $revenueAcc->id,
                        'entry_type' => LedgerEntry::TYPE_CREDIT,
                        'amount_minor' => $amountMinor,
                        'currency' => $currency,
                        'description' => "Reconciliation adjustment: Revenue recognized for {$tx->transaction_id}",
                    ]);

                    if ($match->bankTransaction) {
                        $match->bankTransaction->update([
                            'matched_transaction_id' => $tx->id,
                            'status' => 'matched',
                        ]);
                    }

                    $match->update([
                        'transaction_id' => $tx->id,
                        'resolution_action' => $action,
                        'resolution_notes' => $notes ?? 'Created missing CommunityHub transaction and balanced ledger entry',
                        'resolved_by' => $resolver->id,
                        'resolved_at' => now(),
                    ]);
                    break;

                case 'adjust_amount_variance':
                    // Post adjusting ledger entry for fee withholding or variance
                    $varianceMinor = $match->discrepancy_details['variance_minor'] ?? 0;
                    $match->update([
                        'resolution_action' => $action,
                        'resolution_notes' => $notes ?? "Variance adjusted for difference of {$varianceMinor} minor units",
                        'resolved_by' => $resolver->id,
                        'resolved_at' => now(),
                    ]);
                    break;

                case 'void_duplicate':
                    if ($match->bankTransaction) {
                        $match->bankTransaction->update(['status' => 'excluded']);
                    }
                    $match->update([
                        'resolution_action' => $action,
                        'resolution_notes' => $notes ?? 'Duplicate record voided from reconciliation',
                        'resolved_by' => $resolver->id,
                        'resolved_at' => now(),
                    ]);
                    break;

                case 'post_reversal':
                    $original = $match->transaction;

                    if (! $original || $original->status !== Transaction::STATUS_COMPLETED) {
                        throw new DomainException('Only a completed transaction can be reversed.');
                    }

                    $reversal = Transaction::create([
                        'payment_id' => $original->payment_id,
                        'user_id' => $original->user_id,
                        'invoice_id' => $original->invoice_id,
                        'purpose' => $original->purpose,
                        'amount_minor' => $original->amount_minor,
                        'fee_minor' => 0,
                        'net_amount_minor' => -$original->amount_minor,
                        'currency' => $original->currency,
                        'payment_channel' => $original->payment_channel,
                        'provider' => $original->provider,
                        'provider_reference' => $original->provider_reference,
                        'reference' => "reversal:{$original->transaction_id}:recon-{$match->id}",
                        // Money leaving the estate books as `refunded`, as chargebacks do.
                        'status' => Transaction::STATUS_REFUNDED,
                        'settled_at' => now(),
                        'reviewed_by' => $resolver->id,
                        'reviewed_at' => now(),
                        'notes' => 'Reconciliation reversal'.($notes ? ": {$notes}" : ''),
                    ]);

                    $this->ledgerService->postReversal($original, $reversal);
                    $match->update([
                        'resolution_action' => $action,
                        'resolution_notes' => $notes ?? 'Reversal executed on ledger',
                        'resolved_by' => $resolver->id,
                        'resolved_at' => now(),
                    ]);
                    break;

                case 'manual_verified':
                default:
                    $match->update([
                        'resolution_action' => $action,
                        'resolution_notes' => $notes ?? 'Manually verified by authorized auditor',
                        'resolved_by' => $resolver->id,
                        'resolved_at' => now(),
                    ]);
                    break;
            }

            // Recalculate batch resolution counters
            $resolvedCount = $batch->matches()->whereNotNull('resolved_at')->count();
            $batch->update([
                'resolved_count' => $resolvedCount,
                'status' => ($resolvedCount === $batch->discrepancy_count && $batch->variance_minor === 0)
                    ? ReconciliationBatch::STATUS_BALANCED
                    : $batch->status,
            ]);

            return $match->fresh();
        });
    }

    /**
     * Check for DUPLICATE bank or provider entries.
     */
    protected function detectDuplicateRecords(
        ReconciliationBatch $batch,
        Collection $providerRecords,
        Collection $bankTransactions,
        array &$matches,
        array &$discrepancies,
        Collection &$matchedBankTxIds,
        Collection &$matchedProviderRefs
    ): void {
        // Bank references appearing more than once
        $bankRefGroups = $bankTransactions->filter(fn ($bt) => ! empty($bt->bank_reference))->groupBy('bank_reference');
        foreach ($bankRefGroups as $ref => $group) {
            if ($group->count() > 1) {
                foreach ($group as $idx => $bt) {
                    if ($idx > 0) { // First is original, subsequent are duplicates
                        $match = $this->recordMatch($batch, self::STATUS_DUPLICATE, null, $bt, [
                            'issue' => "Duplicate bank reference detected on feed: {$ref}",
                            'duplicate_of_id' => $group->first()->id,
                        ]);
                        $discrepancies[] = $match;
                        $matchedBankTxIds->push($bt->id);
                    }
                }
            }
        }

        // Provider references appearing more than once
        $providerRefGroups = $providerRecords->filter(fn ($p) => ! empty($p['reference'] ?? $p['id']))->groupBy(fn ($p) => $p['reference'] ?? $p['id']);
        foreach ($providerRefGroups as $ref => $group) {
            if ($group->count() > 1) {
                foreach ($group as $idx => $p) {
                    if ($idx > 0) {
                        $match = $this->recordMatch($batch, self::STATUS_DUPLICATE, null, null, [
                            'issue' => "Duplicate provider transaction reference detected: {$ref}",
                        ]);
                        $discrepancies[] = $match;
                        $matchedProviderRefs->push($ref);
                    }
                }
            }
        }
    }

    /**
     * Ingest or fetch bank feed records for the batch.
     */
    protected function ingestBankRecords(ReconciliationBatch $batch, array $bankRecords): Collection
    {
        if (empty($bankRecords)) {
            return $batch->bankTransactions()->get();
        }

        $records = collect();
        foreach ($bankRecords as $record) {
            if ($record instanceof BankTransaction) {
                $records->push($record);

                continue;
            }

            $records->push(BankTransaction::create([
                'reconciliation_batch_id' => $batch->id,
                'bank_name' => $record['bank_name'] ?? 'National Commercial Bank',
                'bank_reference' => $record['bank_reference'] ?? ('NCB-'.strtoupper(Str::random(10))),
                'transaction_date' => $record['transaction_date'] ?? $batch->period_end,
                'description' => $record['description'] ?? 'Bank deposit',
                'payer_reference' => $record['payer_reference'] ?? null,
                'amount_minor' => (int) ($record['amount_minor'] ?? ($record['amount'] * 100)),
                'currency' => strtoupper($record['currency'] ?? 'JMD'),
                'entry_type' => $record['entry_type'] ?? 'credit',
                'matched_transaction_id' => $record['matched_transaction_id'] ?? null,
                'status' => 'unmatched',
            ]));
        }

        return $records;
    }

    /**
     * Retrieve CommunityHub transactions for the reconciliation batch window.
     */
    protected function getCommunityHubTransactions(ReconciliationBatch $batch): Collection
    {
        return Transaction::query()
            ->whereDate('created_at', '>=', $batch->period_start)
            ->whereDate('created_at', '<=', $batch->period_end)
            ->get();
    }

    /**
     * Record a reconciliation match or discrepancy.
     */
    protected function recordMatch(
        ReconciliationBatch $batch,
        string $status,
        ?Transaction $tx = null,
        ?BankTransaction $bankTx = null,
        array $details = [],
        ?User $auditor = null
    ): ReconciliationMatch {
        return ReconciliationMatch::create([
            'reconciliation_batch_id' => $batch->id,
            'transaction_id' => $tx?->id,
            'bank_transaction_id' => $bankTx?->id,
            'match_type' => $status === self::STATUS_MATCHED ? 'exact_reference' : 'discrepancy_audit',
            'status' => $status,
            'provider' => $tx?->provider ?? $details['provider'] ?? null,
            'provider_reference' => $tx?->provider_reference ?? $details['provider_reference'] ?? null,
            'confidence_score' => $status === self::STATUS_MATCHED ? 100 : 0,
            'discrepancy_details' => $details,
            'matched_by' => $auditor?->id,
            'matched_at' => now(),
        ]);
    }
}
