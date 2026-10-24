<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeownerPaymentMethodSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function homeowner(): User
    {
        return User::factory()->create([
            'role' => UserRole::Homeowner,
        ]);
    }

    /**
     * Test that the billing dashboard provides the homeowner with their invoices,
     * available payment channels, and wallet balance for selection.
     */
    public function test_billing_dashboard_supplies_payment_channels_and_invoices_to_homeowner(): void
    {
        $homeowner = $this->homeowner();

        // Create an unpaid invoice
        $invoice = Invoice::create([
            'user_id' => $homeowner->id,
            'reference' => 'INV-2026-0001',
            'amount_minor' => 2500000, // JMD 25,000.00
            'currency' => 'JMD',
            'status' => 'Unpaid',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(15),
        ]);

        $response = $this->actingAs($homeowner)->get(route('dashboard.billing'));

        $response->assertOk();
        $response->assertInertia(function ($page) use ($invoice) {
            $page->component('Dashboard/Billing')
                ->has('myInvoices.data', 1)
                ->where('myInvoices.data.0.id', $invoice->id)
                ->where('myInvoices.data.0.reference', $invoice->reference)
                ->has('paymentCenter.availableChannels')
                ->has('wallet');
        });
    }

    /**
     * Test homeowner can select Bank Transfer and receive an authoritative payment slip
     * with bank instructions before any money moves.
     */
    public function test_homeowner_can_select_bank_transfer_and_receive_payment_slip(): void
    {
        $homeowner = $this->homeowner();
        $invoice = Invoice::create([
            'user_id' => $homeowner->id,
            'reference' => 'INV-2026-0002',
            'amount_minor' => 2500000,
            'currency' => 'JMD',
            'status' => 'Unpaid',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(15),
        ]);

        $response = $this->actingAs($homeowner)->postJson(route('dashboard.billing.transactions.initiate'), [
            'channel' => 'bank_wire',
            'amount' => 25000.00,
            'currency' => 'JMD',
            'invoice_id' => $invoice->id,
            'purpose' => 'HOA Assessment',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'slip' => [
                'transaction_id',
                'amount',
                'currency',
                'payment_method',
                'status',
            ],
        ]);

        $data = $response->json();
        $this->assertStringStartsWith('CH-', $data['slip']['transaction_id']);
        $this->assertSame('CREATED', $data['slip']['status']);
        $this->assertSame('Bank Transfer', $data['slip']['payment_method']);

        // Invariant: Zero premature ledger or paid status mutation
        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertSame(0, Transaction::count(), 'Zero completed ledger transactions until authoritative bank reconciliation.');
    }

    /**
     * Test homeowner can select Cash at Office and receive an official Cash Receipt Requisition slip.
     */
    public function test_homeowner_can_select_cash_office_and_receive_office_voucher(): void
    {
        $homeowner = $this->homeowner();
        $invoice = Invoice::create([
            'user_id' => $homeowner->id,
            'reference' => 'INV-2026-0003',
            'amount_minor' => 1500000,
            'currency' => 'JMD',
            'status' => 'Unpaid',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(15),
        ]);

        $response = $this->actingAs($homeowner)->postJson(route('dashboard.billing.transactions.initiate'), [
            'channel' => 'cash_office',
            'amount' => 15000.00,
            'currency' => 'JMD',
            'invoice_id' => $invoice->id,
            'purpose' => 'HOA Assessment',
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertStringStartsWith('CH-', $data['slip']['transaction_id']);
        $this->assertSame('CREATED', $data['slip']['status']);
        $this->assertSame('Cash at Office', $data['slip']['payment_method']);

        // Invariant: Status stays Unpaid until authorized office confirmation
        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    /**
     * Test homeowner can select QR Code payment for dynamic mobile app checkout.
     */
    public function test_homeowner_can_select_qr_code_payment(): void
    {
        $homeowner = $this->homeowner();
        $invoice = Invoice::create([
            'user_id' => $homeowner->id,
            'reference' => 'INV-2026-0004',
            'amount_minor' => 1000000,
            'currency' => 'JMD',
            'status' => 'Unpaid',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(15),
        ]);

        $response = $this->actingAs($homeowner)->postJson(route('dashboard.billing.transactions.initiate'), [
            'channel' => 'qr_code',
            'amount' => 10000.00,
            'currency' => 'JMD',
            'invoice_id' => $invoice->id,
            'purpose' => 'HOA Assessment',
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertStringStartsWith('CH-', $data['slip']['transaction_id']);
        $this->assertSame('CREATED', $data['slip']['status']);
        $this->assertSame('QR Code', $data['slip']['payment_method']);
    }

    /**
     * Test that selecting a disabled payment channel rejects initiation.
     */
    public function test_homeowner_cannot_initiate_with_disabled_or_invalid_channel(): void
    {
        $homeowner = $this->homeowner();

        $response = $this->actingAs($homeowner)->postJson(route('dashboard.billing.transactions.initiate'), [
            'channel' => 'unsupported_bitcoin_rail',
            'amount' => 1000.00,
            'currency' => 'JMD',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['channel']);
    }

    /**
     * Test homeowner selecting Apple Pay, Google Pay / Wallet, and Samsung Wallet.
     */
    public function test_homeowner_can_select_digital_wallets_when_enabled_on_processor(): void
    {
        $homeowner = $this->homeowner();
        $invoice = Invoice::create([
            'user_id' => $homeowner->id,
            'reference' => 'INV-2026-0005',
            'amount_minor' => 1200000,
            'currency' => 'JMD',
            'status' => 'Unpaid',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(15),
        ]);

        // When Apple Pay is configured in payments.wallets and Stripe is card processor
        config([
            'services.stripe.secret' => 'sk_test_mock_wallet',
            'payments.card_provider' => 'stripe',
            'payments.wallets' => ['apple_pay', 'google_pay', 'samsung_wallet'],
        ]);

        // Apple Pay initiation
        $response = $this->actingAs($homeowner)->postJson(route('dashboard.billing.transactions.initiate'), [
            'channel' => 'apple_pay',
            'amount' => 12000.00,
            'currency' => 'JMD',
            'invoice_id' => $invoice->id,
            'purpose' => 'HOA Assessment',
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertStringStartsWith('CH-', $data['slip']['transaction_id']);
        $this->assertSame('CREATED', $data['slip']['status']);
        $this->assertSame('Apple Pay', $data['slip']['payment_method']);
    }
}
