<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Donation;
use App\Models\Fundraiser;
use App\Models\Invoice;
use App\Models\PaymentLink;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenueEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exercises actions behind password.confirm; the prompt itself is PasswordConfirmationTest's.
        $this->confirmPassword();
        $this->seed();
    }

    public function test_public_universal_payment_link_resolves_and_renders_checkout(): void
    {
        // Public links are off by default — see config/payments.php.
        config(['payments.public_links_enabled' => true]);

        $link = PaymentLink::create([
            'token' => 'test-token-1234',
            'title' => 'Community Security Upgrade',
            'description' => 'Mandatory contribution for perimeter sensor deployment.',
            'amount_minor' => 15000,
            'currency' => 'JMD',
            'category' => 'assessment',
            'active' => true,
        ]);

        $response = $this->get("/pay/{$link->token}");
        $response->assertOk();
        $response->assertSee('Community Security Upgrade');
        $response->assertSee('150.00');
    }

    public function test_public_payment_link_qr_poster_pdf_generates(): void
    {
        // Public links are off by default — see config/payments.php.
        config(['payments.public_links_enabled' => true]);

        $link = PaymentLink::create([
            'token' => 'test-poster-5678',
            'title' => 'Perimeter Lighting Fund',
            'amount_minor' => 5000,
            'currency' => 'JMD',
            'active' => true,
        ]);

        $response = $this->get("/pay/{$link->token}/poster");
        $response->assertOk();
        $this->assertEquals('application/pdf', $response->headers->get('content-type'));
    }

    public function test_resident_can_submit_payment_through_payment_center(): void
    {
        $resident = User::where('role', UserRole::Homeowner)->first() ?? User::factory()->create(['role' => UserRole::Homeowner]);

        $invoice = Invoice::create([
            'user_id' => $resident->id,
            'reference' => 'INV-TEST-001',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(10),
            'amount_minor' => 12500000, // 125,000 JMD
            'currency' => 'JMD',
            'status' => 'Unpaid',
        ]);

        $response = $this->actingAs($resident)->post('/dashboard/billing/pay', [
            'amount' => 50000,
            'payment_channel' => 'zelle',
            'settlement_mode' => 'partial',
            'invoice_id' => $invoice->id,
        ]);

        $response->assertRedirect();

        // Zelle is an office-confirmed channel: the payment waits for the
        // office, and nothing reaches the ledger until the money is verified.
        // (Apple Pay is not: it goes through the card processor.)
        $this->assertDatabaseHas('payments', [
            'user_id' => $resident->id,
            'channel' => 'zelle',
            'provider' => 'office',
            'state' => 'awaiting_transfer',
            'amount_minor' => 5000000,
        ]);
        $this->assertDatabaseMissing('transactions', [
            'user_id' => $resident->id,
            'payment_channel' => 'zelle',
            'amount_minor' => 5000000,
        ]);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    public function test_resident_can_pay_using_community_wallet_split_payment(): void
    {
        $resident = User::where('role', UserRole::Homeowner)->first() ?? User::factory()->create(['role' => UserRole::Homeowner]);

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $resident->id],
            ['currency' => 'JMD', 'available_balance_minor' => 35000, 'pending_balance_minor' => 0, 'rewards_balance_minor' => 0]
        );

        $response = $this->actingAs($resident)->post('/dashboard/billing/pay', [
            'amount' => 500.00,
            'payment_channel' => 'wallet',
            'settlement_mode' => 'split',
            'wallet_amount' => 200.00,
        ]);

        $response->assertRedirect();

        // Assert wallet was debited
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type' => 'debit',
            'amount_minor' => 20000,
        ]);
    }

    public function test_autopay_settings_can_be_updated(): void
    {
        $resident = User::where('role', UserRole::Homeowner)->first() ?? User::factory()->create(['role' => UserRole::Homeowner]);

        $response = $this->actingAs($resident)->patch('/dashboard/billing/autopay', [
            'is_active' => true,
            'cadence' => 'quarterly',
            'charge_day_of_month' => 15,
            'payment_channel' => 'bank_transfer',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('autopay_settings', [
            'user_id' => $resident->id,
            'cadence' => 'quarterly',
            'charge_day_of_month' => 15,
            'payment_channel' => 'bank_transfer',
        ]);
    }

    public function test_export_transactions_csv_downloads_successfully(): void
    {
        $admin = User::where('role', UserRole::SystemAdmin)->first() ?? User::factory()->create(['role' => UserRole::SystemAdmin]);

        $response = $this->actingAs($admin)->get('/dashboard/billing/export-transactions');

        $response->assertOk();
        $this->assertTrue(str_contains($response->headers->get('content-type'), 'text/csv'));
    }

    public function test_donation_tax_receipt_pdf_generates(): void
    {
        $donor = User::first();
        $fundraiser = Fundraiser::first();

        $donation = Donation::create([
            'fundraiser_id' => $fundraiser->id,
            'user_id' => $donor->id,
            'donor_name' => $donor->display_name,
            'amount_minor' => 1000000,
            'currency' => 'JMD',
            'donated_at' => now(),
            'is_anonymous' => false,
            'receipt_number' => 'REC-TAX-TEST-999',
            'payment_channel' => 'card',
        ]);

        $response = $this->actingAs($donor)->get("/dashboard/fundraising/donations/{$donation->id}/receipt");

        $response->assertOk();
        $this->assertEquals('application/pdf', $response->headers->get('content-type'));
    }

    public function test_resident_can_submit_payment_in_foreign_currencies(): void
    {
        $resident = User::where('role', UserRole::Homeowner)->first() ?? User::factory()->create(['role' => UserRole::Homeowner]);

        $currencies = ['USD', 'CAD', 'GBP', 'EUR'];
        foreach ($currencies as $curr) {
            $response = $this->actingAs($resident)->post('/dashboard/billing/pay', [
                'amount' => 150.00,
                'currency' => $curr,
                'payment_channel' => 'zelle',
                'settlement_mode' => 'full',
            ]);

            $response->assertRedirect();

            $this->assertDatabaseHas('payments', [
                'user_id' => $resident->id,
                'currency' => $curr,
                'amount_minor' => 15000,
                'channel' => 'zelle',
                'state' => 'awaiting_transfer',
            ]);
        }
    }

    public function test_admin_can_create_payment_link_in_custom_currency(): void
    {
        $admin = User::where('role', UserRole::SystemAdmin)->first() ?? User::factory()->create(['role' => UserRole::SystemAdmin]);

        $response = $this->actingAs($admin)->post('/dashboard/billing/payment-links', [
            'title' => 'Overseas Dues Portal - USD',
            'description' => 'International dues collection link.',
            'amount' => 250.00,
            'currency' => 'USD',
            'category' => 'Maintenance',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('payment_links', [
            'title' => 'Overseas Dues Portal - USD',
            'currency' => 'USD',
            'amount_minor' => 25000,
        ]);
    }

    public function test_export_transactions_csv_contains_currency_header_and_data(): void
    {
        $admin = User::where('role', UserRole::SystemAdmin)->first() ?? User::factory()->create(['role' => UserRole::SystemAdmin]);

        // Create a distinct USD transaction
        Transaction::create([
            'user_id' => $admin->id,
            'reference' => 'TX-CURR-TEST-001',
            'receipt_number' => 'REC-CURR-001',
            'payment_channel' => 'card',
            'status' => 'completed',
            'amount_minor' => 35000,
            'currency' => 'USD',
            'notes' => 'USD international collection',
        ]);

        $response = $this->actingAs($admin)->get('/dashboard/billing/export-transactions');

        $response->assertOk();
        $this->assertTrue(str_contains($response->headers->get('content-type'), 'text/csv'));

        // Verify CSV stream output contains Currency column and USD transaction
        $csvContent = $response->streamedContent();
        $this->assertStringContainsString('Currency', $csvContent);
        $this->assertStringContainsString('TX-CURR-TEST-001', $csvContent);
        $this->assertStringContainsString('USD', $csvContent);
    }

    /**
     * The card endpoint starts a Stripe Checkout; it never settles a
     * statement itself. Without Stripe configured there is no card payment.
     */
    public function test_card_payment_endpoint_does_not_settle_without_the_processor(): void
    {
        $homeowner = User::where('role', UserRole::Homeowner)->first() ?? User::factory()->create(['role' => UserRole::Homeowner]);

        $invoice = Invoice::create([
            'user_id' => $homeowner->id,
            'reference' => 'INV-CARD-TEST-75000',
            'amount_minor' => 7500000,
            'currency' => 'JMD',
            'status' => 'Unpaid',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(14),
        ]);

        $cardRowsBefore = Transaction::where('user_id', $homeowner->id)->count();

        $response = $this->actingAs($homeowner)->postJson('/dashboard/billing/card-pay', [
            'invoice_id' => $invoice->id,
            'amount' => 75000.00,
            'currency' => 'JMD',
            'cardholder_name' => 'Alexander Wright',
            'last_four' => '4242',
            'brand' => 'Visa',
            'save_card' => true,
        ]);

        $response->assertStatus(503);

        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertSame(7500000, $invoice->fresh()->balanceRemainingMinor());
        $this->assertSame($cardRowsBefore, Transaction::where('user_id', $homeowner->id)->count());
    }
}
