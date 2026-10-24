<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\ReconciliationBatch;
use App\Models\ReconciliationMatch;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Payments\EnterpriseReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnterpriseEndOfDayReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
        ]);
    }

    protected function resident(): User
    {
        return User::factory()->create([
            'role' => UserRole::Homeowner,
        ]);
    }

    /**
     * Test MATCHED: CommunityHub, Provider, and Bank all match on amount, currency, and reference.
     */
    public function test_reconciliation_identifies_matched_records(): void
    {
        $admin = $this->admin();
        $resident = $this->resident();

        $batch = ReconciliationBatch::create([
            'batch_number' => 'RECON-2026-09-001',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'statement_balance_minor' => 0,
            'ledger_balance_minor' => 0,
            'variance_minor' => 0,
            'status' => 'open',
        ]);

        $tx = Transaction::create([
            'user_id' => $resident->id,
            'transaction_id' => 'CH-TXN-2026-MATCH-01',
            'amount_minor' => 2500000, // JMD 25,000.00
            'currency' => 'JMD',
            'payment_channel' => 'stripe_card',
            'provider' => 'stripe',
            'provider_reference' => 'ch_stripe_match_01',
            'status' => Transaction::STATUS_COMPLETED,
        ]);

        $bankRecord = [
            'bank_name' => 'National Commercial Bank',
            'bank_reference' => 'NCB-DEP-99881',
            'transaction_date' => now()->toDateString(),
            'description' => 'Stripe Payout CH-TXN-2026-MATCH-01',
            'payer_reference' => 'CH-TXN-2026-MATCH-01',
            'amount_minor' => 2500000,
            'currency' => 'JMD',
            'entry_type' => 'credit',
        ];

        $providerRecord = [
            'id' => 'ch_stripe_match_01',
            'reference' => 'ch_stripe_match_01',
            'amount_minor' => 2500000,
            'currency' => 'JMD',
            'status' => 'succeeded',
        ];

        $service = app(EnterpriseReconciliationService::class);
        $result = $service->reconcile(
            batch: $batch,
            providerRecords: [$providerRecord],
            bankRecords: [$bankRecord],
            auditor: $admin
        );

        $this->assertSame(1, $result['matched_count']);
        $this->assertSame(0, $result['discrepancy_count']);
        $this->assertSame(0, $result['variance_minor']);
        $this->assertSame(ReconciliationBatch::STATUS_BALANCED, $result['status']);
        $this->assertSame(1, $result['summary_by_category'][EnterpriseReconciliationService::STATUS_MATCHED]);

        $match = ReconciliationMatch::first();
        $this->assertSame(EnterpriseReconciliationService::STATUS_MATCHED, $match->status);
        $this->assertSame($tx->id, $match->transaction_id);
    }

    /**
     * Test MISSING_PROVIDER: Transaction recorded in CommunityHub but missing from provider feed.
     */
    public function test_reconciliation_identifies_missing_provider(): void
    {
        $admin = $this->admin();
        $resident = $this->resident();

        $batch = ReconciliationBatch::create([
            'batch_number' => 'RECON-2026-09-002',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'statement_balance_minor' => 0,
            'ledger_balance_minor' => 0,
            'variance_minor' => 0,
            'status' => 'open',
        ]);

        // CommunityHub has card transaction
        $tx = Transaction::create([
            'user_id' => $resident->id,
            'transaction_id' => 'CH-TXN-2026-CARD-02',
            'amount_minor' => 1500000,
            'currency' => 'JMD',
            'payment_channel' => 'stripe_card',
            'provider' => 'stripe',
            'provider_reference' => 'ch_stripe_missing_ref',
            'status' => Transaction::STATUS_COMPLETED,
        ]);

        // Provider records feed exists, but this transaction is absent
        $providerRecords = [
            [
                'id' => 'ch_stripe_other_ref',
                'reference' => 'ch_stripe_other_ref',
                'amount_minor' => 500000,
                'currency' => 'JMD',
                'status' => 'succeeded',
            ],
        ];

        $service = app(EnterpriseReconciliationService::class);
        $result = $service->reconcile(
            batch: $batch,
            providerRecords: $providerRecords,
            bankRecords: [],
            auditor: $admin
        );

        $this->assertSame(0, $result['matched_count']);
        $this->assertGreaterThan(0, $result['discrepancy_count']);
        $this->assertSame(1, $result['summary_by_category'][EnterpriseReconciliationService::STATUS_MISSING_PROVIDER]);

        $discrepancy = ReconciliationMatch::where('status', EnterpriseReconciliationService::STATUS_MISSING_PROVIDER)->first();
        $this->assertNotNull($discrepancy);
        $this->assertSame($tx->id, $discrepancy->transaction_id);
    }

    /**
     * Test MISSING_COMMUNITYHUB: Direct bank deposit or Zelle payment received with no CommunityHub transaction.
     */
    public function test_reconciliation_identifies_missing_communityhub_and_allows_resolution(): void
    {
        $admin = $this->admin();
        $resident = $this->resident();

        $batch = ReconciliationBatch::create([
            'batch_number' => 'RECON-2026-09-003',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'statement_balance_minor' => 0,
            'ledger_balance_minor' => 0,
            'variance_minor' => 0,
            'status' => 'open',
        ]);

        // Unsolicited bank deposit: Resident sent money directly to NCB bank account
        $bankRecord = [
            'bank_name' => 'National Commercial Bank',
            'bank_reference' => 'NCB-WIRE-773322',
            'transaction_date' => now()->toDateString(),
            'description' => 'Online Banking Transfer Lot 12 HOA Fee',
            'payer_reference' => 'LOT-12-MAINT',
            'amount_minor' => 3000000, // JMD 30,000.00
            'currency' => 'JMD',
            'entry_type' => 'credit',
        ];

        $service = app(EnterpriseReconciliationService::class);
        $result = $service->reconcile(
            batch: $batch,
            providerRecords: [],
            bankRecords: [$bankRecord],
            auditor: $admin
        );

        $this->assertSame(1, $result['summary_by_category'][EnterpriseReconciliationService::STATUS_MISSING_COMMUNITYHUB]);

        $missingChMatch = ReconciliationMatch::where('status', EnterpriseReconciliationService::STATUS_MISSING_COMMUNITYHUB)->first();
        $this->assertNotNull($missingChMatch);
        $this->assertNull($missingChMatch->transaction_id);
        $this->assertNotNull($missingChMatch->bank_transaction_id);

        // Resolve discrepancy: Authorized admin maps the deposit to the resident's account
        $resolvedMatch = $service->resolveDiscrepancy(
            match: $missingChMatch,
            action: 'create_missing_transaction',
            resolver: $admin,
            notes: 'Linked wire to resident Lot 12 after statement identification',
            payload: [
                'user_id' => $resident->id,
                'amount_minor' => 3000000,
                'currency' => 'JMD',
            ]
        );

        $this->assertTrue($resolvedMatch->isResolved());
        $this->assertNotNull($resolvedMatch->transaction_id);
        $this->assertDatabaseHas('transactions', [
            'id' => $resolvedMatch->transaction_id,
            'user_id' => $resident->id,
            'amount_minor' => 3000000,
            'status' => Transaction::STATUS_COMPLETED,
        ]);

        // Double-entry ledger verified
        $this->assertDatabaseHas('ledger_entries', [
            'transaction_id' => $resolvedMatch->transaction_id,
            'entry_type' => LedgerEntry::TYPE_DEBIT,
            'amount_minor' => 3000000,
        ]);
        $this->assertDatabaseHas('ledger_entries', [
            'transaction_id' => $resolvedMatch->transaction_id,
            'entry_type' => LedgerEntry::TYPE_CREDIT,
            'amount_minor' => 3000000,
        ]);
    }

    /**
     * Test AMOUNT_MISMATCH: Bank or provider settled amount differs from CommunityHub.
     */
    public function test_reconciliation_identifies_amount_mismatch(): void
    {
        $admin = $this->admin();
        $resident = $this->resident();

        $batch = ReconciliationBatch::create([
            'batch_number' => 'RECON-2026-09-004',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'statement_balance_minor' => 0,
            'ledger_balance_minor' => 0,
            'variance_minor' => 0,
            'status' => 'open',
        ]);

        $tx = Transaction::create([
            'user_id' => $resident->id,
            'transaction_id' => 'CH-TXN-2026-SHORT-04',
            'amount_minor' => 2000000, // Expected: JMD 20,000.00
            'currency' => 'JMD',
            'payment_channel' => 'bank_wire',
            'provider' => 'bank',
            'provider_reference' => 'NCB-REF-SHORT-04',
            'status' => Transaction::STATUS_COMPLETED,
        ]);

        // Resident short-paid or bank deducted wire fee: Bank only credited 19,500.00
        $bankRecord = [
            'bank_name' => 'National Commercial Bank',
            'bank_reference' => 'NCB-REF-SHORT-04',
            'transaction_date' => now()->toDateString(),
            'description' => 'Wire Transfer CH-TXN-2026-SHORT-04',
            'payer_reference' => 'CH-TXN-2026-SHORT-04',
            'amount_minor' => 1950000, // Actual: JMD 19,500.00
            'currency' => 'JMD',
            'entry_type' => 'credit',
        ];

        $service = app(EnterpriseReconciliationService::class);
        $result = $service->reconcile(
            batch: $batch,
            providerRecords: [],
            bankRecords: [$bankRecord],
            auditor: $admin
        );

        $this->assertSame(1, $result['summary_by_category'][EnterpriseReconciliationService::STATUS_AMOUNT_MISMATCH]);

        $match = ReconciliationMatch::where('status', EnterpriseReconciliationService::STATUS_AMOUNT_MISMATCH)->first();
        $this->assertNotNull($match);
        $this->assertSame(-50000, $match->discrepancy_details['variance_minor']);

        // Admin resolves by adjusting variance
        $resolved = $service->resolveDiscrepancy(
            match: $match,
            action: 'adjust_amount_variance',
            resolver: $admin,
            notes: 'Bank wire fee of JMD 500 deducted at source'
        );

        $this->assertTrue($resolved->isResolved());
        $this->assertSame('adjust_amount_variance', $resolved->resolution_action);
    }

    /**
     * Test CURRENCY_MISMATCH: CommunityHub recorded USD but Bank credited JMD without FX conversion.
     */
    public function test_reconciliation_identifies_currency_mismatch(): void
    {
        $admin = $this->admin();
        $resident = $this->resident();

        $batch = ReconciliationBatch::create([
            'batch_number' => 'RECON-2026-09-005',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'statement_balance_minor' => 0,
            'ledger_balance_minor' => 0,
            'variance_minor' => 0,
            'status' => 'open',
        ]);

        $tx = Transaction::create([
            'user_id' => $resident->id,
            'transaction_id' => 'CH-TXN-2026-FX-05',
            'amount_minor' => 10000, // $100.00 USD
            'currency' => 'USD',
            'payment_channel' => 'bank_wire',
            'provider' => 'bank',
            'provider_reference' => 'NCB-FX-05',
            'status' => Transaction::STATUS_COMPLETED,
        ]);

        $bankRecord = [
            'bank_name' => 'National Commercial Bank',
            'bank_reference' => 'NCB-FX-05',
            'transaction_date' => now()->toDateString(),
            'description' => 'Transfer CH-TXN-2026-FX-05',
            'payer_reference' => 'CH-TXN-2026-FX-05',
            'amount_minor' => 10000,
            'currency' => 'JMD', // Settled in JMD instead of USD
            'entry_type' => 'credit',
        ];

        $service = app(EnterpriseReconciliationService::class);
        $result = $service->reconcile(
            batch: $batch,
            providerRecords: [],
            bankRecords: [$bankRecord],
            auditor: $admin
        );

        $this->assertSame(1, $result['summary_by_category'][EnterpriseReconciliationService::STATUS_CURRENCY_MISMATCH]);

        $match = ReconciliationMatch::where('status', EnterpriseReconciliationService::STATUS_CURRENCY_MISMATCH)->first();
        $this->assertSame('USD', $match->discrepancy_details['expected_currency']);
        $this->assertSame('JMD', $match->discrepancy_details['actual_currency']);
    }

    /**
     * Test DUPLICATE: Same bank transaction reference appears twice on feed.
     */
    public function test_reconciliation_identifies_duplicate_records(): void
    {
        $admin = $this->admin();

        $batch = ReconciliationBatch::create([
            'batch_number' => 'RECON-2026-09-006',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'statement_balance_minor' => 0,
            'ledger_balance_minor' => 0,
            'variance_minor' => 0,
            'status' => 'open',
        ]);

        // Duplicate bank deposits with same reference
        $bankRecords = [
            [
                'bank_reference' => 'NCB-DUP-999',
                'description' => 'Resident deposit',
                'amount_minor' => 500000,
                'currency' => 'JMD',
            ],
            [
                'bank_reference' => 'NCB-DUP-999', // Duplicate entry on statement
                'description' => 'Resident deposit (duplicate)',
                'amount_minor' => 500000,
                'currency' => 'JMD',
            ],
        ];

        $service = app(EnterpriseReconciliationService::class);
        $result = $service->reconcile(
            batch: $batch,
            providerRecords: [],
            bankRecords: $bankRecords,
            auditor: $admin
        );

        $this->assertSame(1, $result['summary_by_category'][EnterpriseReconciliationService::STATUS_DUPLICATE]);

        $dupMatch = ReconciliationMatch::where('status', EnterpriseReconciliationService::STATUS_DUPLICATE)->first();
        $this->assertNotNull($dupMatch);

        // Resolve by voiding duplicate
        $resolved = $service->resolveDiscrepancy(
            match: $dupMatch,
            action: 'void_duplicate',
            resolver: $admin,
            notes: 'Bank feed line duplicate voided'
        );

        $this->assertTrue($resolved->isResolved());
        $this->assertSame('excluded', $resolved->bankTransaction->status);
    }

    /**
     * Test REFUND_MISMATCH: Reversal on CommunityHub not matched by bank or provider.
     */
    public function test_reconciliation_identifies_refund_mismatch(): void
    {
        $admin = $this->admin();
        $resident = $this->resident();

        $batch = ReconciliationBatch::create([
            'batch_number' => 'RECON-2026-09-007',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'statement_balance_minor' => 0,
            'ledger_balance_minor' => 0,
            'variance_minor' => 0,
            'status' => 'open',
        ]);

        // CommunityHub has transaction marked REFUNDED
        $tx = Transaction::create([
            'user_id' => $resident->id,
            'transaction_id' => 'CH-TXN-2026-REFUND-07',
            'amount_minor' => 1200000,
            'currency' => 'JMD',
            'payment_channel' => 'bank_wire',
            'provider' => 'bank',
            'status' => Transaction::STATUS_REFUNDED,
        ]);

        // Bank record has NO reversal/debit
        $service = app(EnterpriseReconciliationService::class);
        $result = $service->reconcile(
            batch: $batch,
            providerRecords: [],
            bankRecords: [],
            auditor: $admin
        );

        $this->assertSame(1, $result['summary_by_category'][EnterpriseReconciliationService::STATUS_REFUND_MISMATCH]);

        $match = ReconciliationMatch::where('status', EnterpriseReconciliationService::STATUS_REFUND_MISMATCH)->first();
        $this->assertNotNull($match);
        $this->assertSame($tx->id, $match->transaction_id);
    }

    /**
     * Test UNRESOLVED: Ambiguous bank transactions that cannot be uniquely matched.
     */
    public function test_reconciliation_identifies_unresolved_ambiguities(): void
    {
        $admin = $this->admin();
        $resident = $this->resident();

        $batch = ReconciliationBatch::create([
            'batch_number' => 'RECON-2026-09-008',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'statement_balance_minor' => 0,
            'ledger_balance_minor' => 0,
            'variance_minor' => 0,
            'status' => 'open',
        ]);

        // CommunityHub has 1 transaction for JMD 10,000 without provider ref
        $tx = Transaction::create([
            'user_id' => $resident->id,
            'transaction_id' => 'CH-TXN-2026-AMBIG-08',
            'amount_minor' => 1000000,
            'currency' => 'JMD',
            'payment_channel' => 'bank_wire',
            'provider' => 'bank',
            'status' => Transaction::STATUS_COMPLETED,
        ]);

        // Bank has 2 different deposits of identical amount (10,000) with generic descriptions
        $bankRecords = [
            [
                'bank_reference' => 'NCB-UNKNOWN-01',
                'description' => 'Generic Counter Deposit 1',
                'amount_minor' => 1000000,
                'currency' => 'JMD',
            ],
            [
                'bank_reference' => 'NCB-UNKNOWN-02',
                'description' => 'Generic Counter Deposit 2',
                'amount_minor' => 1000000,
                'currency' => 'JMD',
            ],
        ];

        $service = app(EnterpriseReconciliationService::class);
        $result = $service->reconcile(
            batch: $batch,
            providerRecords: [],
            bankRecords: $bankRecords,
            auditor: $admin
        );

        $this->assertSame(1, $result['summary_by_category'][EnterpriseReconciliationService::STATUS_UNRESOLVED]);

        $unresolvedMatch = ReconciliationMatch::where('status', EnterpriseReconciliationService::STATUS_UNRESOLVED)->first();
        $this->assertNotNull($unresolvedMatch);
        $this->assertCount(2, $unresolvedMatch->discrepancy_details['candidate_bank_tx_ids']);

        // Auditor manually verifies which one corresponds to the transaction
        $resolved = $service->resolveDiscrepancy(
            match: $unresolvedMatch,
            action: 'manual_verified',
            resolver: $admin,
            notes: 'Auditor confirmed NCB-UNKNOWN-01 with branch teller slip'
        );

        $this->assertTrue($resolved->isResolved());
        $this->assertSame('manual_verified', $resolved->resolution_action);
    }

    private function openMatch(?Transaction $tx = null, string $status = EnterpriseReconciliationService::STATUS_UNRESOLVED): ReconciliationMatch
    {
        $batch = ReconciliationBatch::create([
            'batch_number' => 'RECON-GUARD-'.fake()->unique()->numerify('###'),
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'statement_balance_minor' => 0,
            'ledger_balance_minor' => 0,
            'variance_minor' => 0,
            'status' => 'open',
        ]);

        return ReconciliationMatch::create([
            'reconciliation_batch_id' => $batch->id,
            'transaction_id' => $tx?->id,
            'status' => $status,
            'discrepancy_details' => ['amount_minor' => 50000, 'currency' => 'JMD'],
            'matched_at' => now(),
        ]);
    }

    public function test_an_unknown_resolution_action_is_refused_rather_than_treated_as_verified(): void
    {
        $match = $this->openMatch();

        $this->expectException(\DomainException::class);
        app(EnterpriseReconciliationService::class)->resolveDiscrepancy($match, 'mark_everything_fine', $this->admin());
    }

    public function test_a_missing_deposit_is_never_booked_to_the_auditor_resolving_it(): void
    {
        $match = $this->openMatch(status: EnterpriseReconciliationService::STATUS_MISSING_COMMUNITYHUB);

        try {
            app(EnterpriseReconciliationService::class)->resolveDiscrepancy($match, 'create_missing_transaction', $this->admin());
            $this->fail('A deposit without a household must be refused.');
        } catch (\DomainException) {
            $this->assertSame(0, Transaction::count());
            $this->assertNull($match->fresh()->resolved_at);
        }
    }

    public function test_a_recorded_missing_deposit_takes_a_sequenced_transaction_id(): void
    {
        $resident = $this->resident();
        $match = $this->openMatch(status: EnterpriseReconciliationService::STATUS_MISSING_COMMUNITYHUB);

        $resolved = app(EnterpriseReconciliationService::class)->resolveDiscrepancy(
            $match, 'create_missing_transaction', $this->admin(), payload: ['user_id' => $resident->id],
        );

        $this->assertMatchesRegularExpression('/^CH-\d{4}-\d{10}$/', $resolved->transaction->transaction_id);
        $this->assertSame($resident->id, $resolved->transaction->user_id);
    }

    public function test_post_reversal_books_a_balancing_reversal(): void
    {
        $tx = Transaction::create([
            'user_id' => $this->resident()->id,
            'amount_minor' => 50000,
            'currency' => 'JMD',
            'payment_channel' => 'bank_wire',
            'provider' => 'bank',
            'reference' => 'WIRE-REV-1',
            'status' => Transaction::STATUS_COMPLETED,
            'settled_at' => now(),
        ]);
        app(LedgerService::class)->postTransaction($tx);

        $match = $this->openMatch($tx, EnterpriseReconciliationService::STATUS_REFUND_MISMATCH);

        $resolved = app(EnterpriseReconciliationService::class)->resolveDiscrepancy($match, 'post_reversal', $this->admin(), 'Wire recalled by bank');

        $this->assertTrue($resolved->isResolved());
        $this->assertSame(4, LedgerEntry::count());
        $this->assertSame(
            LedgerEntry::where('entry_type', 'debit')->sum('amount_minor'),
            LedgerEntry::where('entry_type', 'credit')->sum('amount_minor'),
        );

        $this->expectException(\DomainException::class);
        app(EnterpriseReconciliationService::class)->resolveDiscrepancy($resolved, 'post_reversal', $this->admin());
    }
}
