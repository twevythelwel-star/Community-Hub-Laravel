<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfilePaymentsAndWalletsTest extends TestCase
{
    use RefreshDatabase;

    protected function homeowner(): User
    {
        return User::factory()->create([
            'role' => UserRole::Homeowner,
        ]);
    }

    /**
     * Test that the homeowner profile supplies billing, outstanding balance,
     * saved payment methods, and capability config for the Payments & Wallets tab.
     */
    public function test_homeowner_profile_supplies_billing_and_wallet_data(): void
    {
        $homeowner = $this->homeowner();

        // Create an unpaid invoice for JMD $75,000.00
        $invoice = Invoice::create([
            'user_id' => $homeowner->id,
            'reference' => 'INV-2026-75000',
            'amount_minor' => 7500000,
            'currency' => 'JMD',
            'status' => 'Unpaid',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(14),
        ]);

        $response = $this->actingAs($homeowner)->get(route('dashboard.profile'));

        $response->assertOk();
        $response->assertInertia(function ($page) use ($invoice) {
            $page->component('Dashboard/Profile')
                ->has('billing', function ($billing) use ($invoice) {
                    $billing->where('outstandingBalance', fn ($val) => (float) $val === 75000.0)
                        ->where('currency', 'JMD')
                        ->where('currencySymbol', 'JMD $')
                        ->where('latestInvoice.id', $invoice->id)
                        ->where('latestInvoice.invoiceNumber', 'INV-2026-75000')
                        ->has('savedPaymentMethods')
                        ->has('config.enabledWallets')
                        ->has('config.isTestMode')
                        ->has('paymentPreferences');
                });
        });
    }

    /**
     * Test user invoices relationship correctly links invoices to user.
     */
    public function test_user_invoices_relationship(): void
    {
        $homeowner = $this->homeowner();

        $invoice = Invoice::create([
            'user_id' => $homeowner->id,
            'reference' => 'INV-TEST-001',
            'amount_minor' => 1000000,
            'currency' => 'JMD',
            'status' => 'Unpaid',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(7),
        ]);

        $this->assertTrue($homeowner->invoices()->exists());
        $this->assertEquals($invoice->id, $homeowner->invoices()->first()->id);
    }

    /**
     * Test active saved payment methods are loaded into homeowner profile.
     */
    public function test_saved_payment_methods_loaded_into_profile(): void
    {
        $homeowner = $this->homeowner();

        PaymentMethod::create([
            'user_id' => $homeowner->id,
            'provider' => 'stripe',
            'method_type' => 'card',
            'brand' => 'Mastercard',
            'last_four' => '8888',
            'display_name' => 'Homeowner Debit',
            'status' => 'active',
            'is_default' => true,
        ]);

        $response = $this->actingAs($homeowner)->get(route('dashboard.profile'));

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $page->component('Dashboard/Profile')
                ->where('billing.savedPaymentMethods.0.brand', 'Mastercard')
                ->where('billing.savedPaymentMethods.0.lastFour', '8888')
                ->where('billing.savedPaymentMethods.0.isDefault', true);
        });
    }

    /**
     * Test capability config reflects test mode accurately.
     */
    public function test_stripe_test_mode_detection(): void
    {
        config(['services.stripe.secret' => 'sk_test_mock_12345']);

        $homeowner = $this->homeowner();
        $response = $this->actingAs($homeowner)->get(route('dashboard.profile'));

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $page->component('Dashboard/Profile')
                ->where('billing.config.isTestMode', true)
                ->where('billing.config.isConfigured', true);
        });
    }

    /**
     * Test homeowner profile supplies default payment preferences.
     */
    public function test_homeowner_profile_supplies_payment_preferences(): void
    {
        $homeowner = $this->homeowner();
        $response = $this->actingAs($homeowner)->get(route('dashboard.profile'));

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $page->component('Dashboard/Profile')
                ->where('billing.paymentPreferences.preferred_payment', 'apple_pay')
                ->where('billing.paymentPreferences.default_payment_method', 'Apple Pay')
                ->where('billing.paymentPreferences.notifications.payment_confirmation', true)
                ->where('billing.paymentPreferences.notifications.receipt', true)
                ->where('billing.paymentPreferences.notifications.failed_payment', true)
                ->where('billing.paymentPreferences.notifications.refund', true);
        });
    }

    /**
     * Test homeowner can update payment preferences & notification channels.
     */
    public function test_homeowner_can_update_payment_preferences(): void
    {
        $homeowner = $this->homeowner();

        $response = $this->actingAs($homeowner)->post(route('dashboard.profile.payment-preferences'), [
            'preferred_payment' => 'google_pay',
            'default_payment_method' => 'Google Pay',
            'notifications' => [
                'payment_confirmation' => true,
                'receipt' => true,
                'failed_payment' => false,
                'refund' => true,
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $prefs = $homeowner->fresh()->preferences;
        $this->assertNotNull($prefs);
        $this->assertEquals('google_pay', $prefs->extra['payment_preferences']['preferred_payment']);
        $this->assertEquals('Google Pay', $prefs->extra['payment_preferences']['default_payment_method']);
        $this->assertTrue($prefs->extra['payment_preferences']['notifications']['payment_confirmation']);
        $this->assertFalse($prefs->extra['payment_preferences']['notifications']['failed_payment']);

        // Strictly verify that no raw credentials exist in database
        $this->assertArrayNotHasKey('card_number', $prefs->extra['payment_preferences']);
        $this->assertArrayNotHasKey('cvv', $prefs->extra['payment_preferences']);
        $this->assertArrayNotHasKey('dpan', $prefs->extra['payment_preferences']);
    }
}
