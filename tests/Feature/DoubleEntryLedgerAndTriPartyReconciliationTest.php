<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Fundraiser;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoubleEntryLedgerAndTriPartyReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function resident(): User
    {
        return User::factory()->role(UserRole::Homeowner)->create();
    }

    private function admin(): User
    {
        return User::factory()->role(UserRole::Admin)->create();
    }

    public function test_hoa_assessment_payment_posts_balanced_double_entry_ledger_records(): void
    {
        $resident = $this->resident();
        $ledgerService = app(LedgerService::class);

        $invoice = Invoice::create([
            'user_id' => $resident->id,
            'reference' => 'INV-2026-TEST01',
            'amount_minor' => 25_000_00, // $250.00
            'currency' => 'USD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(5),
            'status' => 'Unpaid',
        ]);

        $tx = Transaction::create([
            'user_id' => $resident->id,
            'invoice_id' => $invoice->id,
            'amount_minor' => 25_000_00,
            'currency' => 'USD',
            'payment_channel' => 'card',
            'provider' => 'stripe',
            'purpose' => Transaction::PURPOSE_HOA_ASSESSMENT,
            'status' => Transaction::STATUS_COMPLETED,
        ]);

        $entries = $ledgerService->postTransaction($tx);

        $this->assertCount(2, $entries);

        // Check Debit entry (Asset / Clearing account increases by +$250)
        $debitEntry = collect($entries)->firstWhere('entry_type', LedgerEntry::TYPE_DEBIT);
        $this->assertNotNull($debitEntry);
        $this->assertSame(25_000_00, $debitEntry->amount_minor);
        $this->assertSame(Account::CODE_STRIPE_CLEARING, $debitEntry->account->code);

        // Check Credit entry (Resident Account balance credited -$250)
        $creditEntry = collect($entries)->firstWhere('entry_type', LedgerEntry::TYPE_CREDIT);
        $this->assertNotNull($creditEntry);
        $this->assertSame(25_000_00, $creditEntry->amount_minor);
        $this->assertSame("1200-{$resident->id}", $creditEntry->account->code);

        // Invariant: Total Debits == Total Credits
        $totalDebits = collect($entries)->where('entry_type', LedgerEntry::TYPE_DEBIT)->sum('amount_minor');
        $totalCredits = collect($entries)->where('entry_type', LedgerEntry::TYPE_CREDIT)->sum('amount_minor');
        $this->assertSame($totalDebits, $totalCredits);
    }

    public function test_fundraising_donation_posts_balanced_double_entry_to_campaign_fund(): void
    {
        $resident = $this->resident();
        $admin = $this->admin();
        $ledgerService = app(LedgerService::class);

        $campaign = Fundraiser::create([
            'title' => 'Community Security Upgrade',
            'description' => 'Security cameras and gate barrier upgrades.',
            'goal_minor' => 1_000_000_00,
            'goal_currency' => 'USD',
            'status' => 'Active',
            'created_by' => $admin->id,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $tx = Transaction::create([
            'user_id' => $resident->id,
            'fundraiser_id' => $campaign->id,
            'amount_minor' => 10_000_00, // $100.00
            'currency' => 'USD',
            'payment_channel' => 'apple_pay',
            'provider' => 'stripe',
            'purpose' => Transaction::PURPOSE_FUNDRAISING_DONATION,
            'status' => Transaction::STATUS_COMPLETED,
        ]);

        $entries = $ledgerService->postTransaction($tx);

        $this->assertCount(2, $entries);

        // Debit: Payment Clearing / Stripe Clearing
        $debitEntry = collect($entries)->firstWhere('entry_type', LedgerEntry::TYPE_DEBIT);
        $this->assertNotNull($debitEntry);
        $this->assertSame(10_000_00, $debitEntry->amount_minor);

        // Credit: Fundraising Campaign Fund
        $creditEntry = collect($entries)->firstWhere('entry_type', LedgerEntry::TYPE_CREDIT);
        $this->assertNotNull($creditEntry);
        $this->assertSame(10_000_00, $creditEntry->amount_minor);
        $this->assertSame("4070-{$campaign->id}", $creditEntry->account->code);
        $this->assertStringContainsString('Community Security Upgrade', $creditEntry->account->name);

        // Invariant: Total Debits == Total Credits
        $this->assertSame($debitEntry->amount_minor, $creditEntry->amount_minor);
    }

    public function test_tri_party_reconciliation_compares_ledger_vs_provider_vs_bank(): void
    {
        $admin = $this->admin();
        $resident = $this->resident();
        $ledgerService = app(LedgerService::class);

        // Create completed transaction
        Transaction::create([
            'user_id' => $resident->id,
            'amount_minor' => 50_000, // $500.00 = 50,000 cents
            'currency' => 'USD',
            'payment_channel' => 'card',
            'provider' => 'stripe',
            'status' => Transaction::STATUS_COMPLETED,
        ]);

        // Record matching bank statement
        BankReconciliation::create([
            'bank_statement_date' => now(),
            'statement_balance_minor' => 50_000,
            'ledger_balance_minor' => 50_000,
            'difference_minor' => 0,
            'reconciled_by' => $admin->id,
            'status' => 'Reconciled',
            'notes' => 'Monthly bank feed fully verified',
        ]);

        $recon = $ledgerService->getTriPartyReconciliationSummary('USD');

        $this->assertSame(500.00, $recon['communityHubLedger']['total']);
        $this->assertSame(500.00, $recon['paymentProvider']['total']);
        $this->assertSame(500.00, $recon['bank']['total']);
        $this->assertTrue($recon['variance']['is_balanced']);
        $this->assertSame('BALANCED', $recon['variance']['state']);
        $this->assertSame(0.0, $recon['variance']['amount']);
    }

    public function test_billing_index_passes_chart_of_accounts_and_tri_party_reconciliation_to_admin(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->get(route('dashboard.billing'));

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $page->component('Dashboard/Billing')
                ->has('chartOfAccounts')
                ->has('triPartyReconciliation');
        });
    }
}
