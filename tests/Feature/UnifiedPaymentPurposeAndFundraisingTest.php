<?php

namespace Tests\Feature;

use App\Enums\PaymentState;
use App\Enums\UserRole;
use App\Models\Donation;
use App\Models\Fundraiser;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class UnifiedPaymentPurposeAndFundraisingTest extends TestCase
{
    use RefreshDatabase;

    private function resident(): User
    {
        return User::factory()->role(UserRole::Homeowner)->create([
            'phone' => '+18765550123',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->role(UserRole::Admin)->create();
    }

    private function campaign(string $title = 'Community Security Upgrade', int $goalMinor = 1_000_000_00): Fundraiser
    {
        return Fundraiser::create([
            'title' => $title,
            'description' => 'Security gate and perimeter upgrade.',
            'goal_minor' => $goalMinor,
            'goal_currency' => 'USD',
            'status' => 'Active',
            'created_by' => $this->admin()->id,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
    }

    public function test_all_canonical_payment_purposes_are_defined(): void
    {
        $purposes = [
            Transaction::PURPOSE_HOA_ASSESSMENT,
            Transaction::PURPOSE_MAINTENANCE_FEE,
            Transaction::PURPOSE_LATE_FEE,
            Transaction::PURPOSE_AMENITY_BOOKING,
            Transaction::PURPOSE_GATE_ACCESS_FEE,
            Transaction::PURPOSE_EVENT_TICKET,
            Transaction::PURPOSE_FUNDRAISING_DONATION,
            Transaction::PURPOSE_FUNDRAISING_SPONSORSHIP,
            Transaction::PURPOSE_COMMUNITY_PROJECT,
            Transaction::PURPOSE_EMERGENCY_FUND,
        ];

        $this->assertCount(10, $purposes);
        $this->assertContains('Fundraising Donation', $purposes);
        $this->assertContains('HOA Assessment', $purposes);
        $this->assertContains('Community Project', $purposes);
        $this->assertContains('Emergency Fund', $purposes);
    }

    public function test_donation_flows_through_unified_transaction_engine_and_settles_campaign(): void
    {
        $resident = $this->resident();
        $campaign = $this->campaign('Community Security Upgrade', 500_000_00);

        // Fund resident wallet for immediate settlement
        Wallet::create([
            'user_id' => $resident->id,
            'currency' => 'USD',
            'available_balance_minor' => 200_00,
            'pending_balance_minor' => 0,
            'rewards_balance_minor' => 0,
        ]);

        $mockSms = Mockery::mock(SmsService::class);
        $mockSms->shouldReceive('isConfigured')->andReturn(true);
        $mockSms->shouldReceive('send')->once()->withArgs(function ($phone, $message) use ($resident) {
            return $phone === $resident->phone
                && str_contains($message, 'CommunityHub donation received: USD 100.00 for Community Security Upgrade')
                && str_contains($message, 'Receipt CH-');
        })->andReturn(true);
        $this->app->instance(SmsService::class, $mockSms);

        $response = $this->actingAs($resident)
            ->post(route('dashboard.fundraising.donate', $campaign), [
                'amount' => 100,
                'channel' => 'wallet',
                'donor_name' => 'Marcus Vance',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        // Verify transaction in Master Ledger
        $tx = Transaction::where('fundraiser_id', $campaign->id)->sole();
        $this->assertMatchesRegularExpression('/^CH-\d{4}-\d{10}$/', $tx->transaction_id);
        $this->assertSame(Transaction::PURPOSE_FUNDRAISING_DONATION, $tx->purpose);
        $this->assertSame(100_00, $tx->amount_minor);
        $this->assertSame('completed', $tx->status);
        $this->assertNotNull($tx->donation_id);

        // Verify campaign raised total updated
        $this->assertSame(100_00, $campaign->fresh()->raisedMinor());
        $this->assertSame(1, $campaign->fresh()->donorCount());

        // Verify donation record matches transaction
        $donation = Donation::sole();
        $this->assertSame($tx->donation_id, $donation->id);
        $this->assertSame('completed', $donation->status);
        $this->assertSame(100_00, $donation->amount_minor);
    }

    public function test_asynchronous_bank_wire_donation_waits_for_ledger_confirmation_before_counting_and_notifying(): void
    {
        $resident = $this->resident();
        $admin = $this->admin();
        $campaign = $this->campaign('Perimeter Fencing Project', 100_000_00);

        $mockSms = Mockery::mock(SmsService::class);
        $mockSms->shouldReceive('isConfigured')->andReturn(true);
        // Should NOT send during pending creation, only ONCE upon admin confirmation
        $mockSms->shouldReceive('send')->once()->withArgs(function ($phone, $message) use ($resident) {
            return $phone === $resident->phone
                && str_contains($message, 'CommunityHub donation received: USD 250.00 for Perimeter Fencing Project')
                && str_contains($message, 'Receipt CH-');
        })->andReturn(true);
        $this->app->instance(SmsService::class, $mockSms);

        // 1. Resident initiates bank wire donation
        $this->actingAs($resident)
            ->post(route('dashboard.fundraising.donate', $campaign), [
                'amount' => 250,
                'channel' => 'bank_wire',
                'donor_name' => 'Marcus Vance',
            ])
            ->assertSessionHasNoErrors();

        // 2. Pending: the payment awaits the transfer, nothing is on the ledger,
        //    and the campaign has not moved.
        $payment = Payment::where('fundraiser_id', $campaign->id)->sole();
        $this->assertSame(PaymentState::AwaitingTransfer, $payment->state);
        $this->assertSame(0, Transaction::where('fundraiser_id', $campaign->id)->count());
        $this->assertSame(0, $campaign->fresh()->raisedMinor());
        $this->assertSame(0, $campaign->fresh()->donorCount());

        // 3. One administrator logs the wire as received — still not counted.
        $this->actingAs($admin)
            ->post(route('dashboard.billing.payments.receive', $payment))
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $campaign->fresh()->raisedMinor());

        // 4. A second administrator verifies it in a bank reconciliation.
        $auditor = $this->admin();
        $this->actingAs($auditor)
            ->post(route('dashboard.billing.reconciliations.store'), [
                'bank_statement_date' => now()->toDateString(),
                'statement_balance' => 250,
                'verify_payment_ids' => [$payment->id],
            ])
            ->assertSessionHasNoErrors();

        // 5. Ledger and campaign are now settled, under the payment's number.
        $payment->refresh();
        $this->assertSame(PaymentState::Paid, $payment->state);
        $this->assertSame($admin->id, $payment->received_by);
        $this->assertSame($auditor->id, $payment->verified_by);

        $tx = $payment->ledgerPayment();
        $this->assertSame('completed', $tx->status);
        $this->assertSame($payment->transaction_id, $tx->transaction_id);
        $this->assertSame(250_00, $campaign->fresh()->raisedMinor());
        $this->assertSame(1, $campaign->fresh()->donorCount());
    }
}
