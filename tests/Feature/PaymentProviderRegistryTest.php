<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Payments\Providers\Contracts\ConfirmsReturns;
use App\Services\Payments\Providers\Contracts\HandlesWebhooks;
use App\Services\Payments\Providers\Contracts\RefundsPayments;
use App\Services\Payments\Providers\ProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Which provider handles each channel. Card goes to the processor the estate
 * configured — nothing is hard-wired to Stripe, whose availability does not
 * include Jamaica — and is off when none is set up.
 */
class PaymentProviderRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function registry(): ProviderRegistry
    {
        return app(ProviderRegistry::class);
    }

    private function wipayConfigured(): void
    {
        config(['payments.providers.wipay' => [
            'account_number' => '1234567890', 'api_key' => '123', 'environment' => 'sandbox',
            'country_code' => 'JM', 'fee_structure' => 'merchant_absorb', 'origin' => 'CommunityHub',
        ]]);
    }

    public function test_with_nothing_configured_card_is_off(): void
    {
        config(['payments.card_provider' => null, 'services.stripe.secret' => null]);

        $this->assertNull($this->registry()->cardProvider());

        $user = User::factory()->role(UserRole::Homeowner)->create();
        $invoice = Invoice::create([
            'user_id' => $user->id, 'reference' => 'INV-REG-1', 'amount_minor' => 10_000, 'currency' => 'JMD',
            'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(), 'due_on' => now()->addDays(5), 'status' => 'Unpaid',
        ]);

        $this->actingAs($user)
            ->post(route('dashboard.billing.pay'), ['channel' => 'card', 'amount' => 100, 'invoice_id' => $invoice->id])
            ->assertSessionHasErrors('channel');

        $this->actingAs($user)
            ->get(route('dashboard.billing'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.cardCheckout', false)
                ->where('payments.cardProvider', null));
    }

    public function test_stripe_is_used_only_where_its_keys_are_set(): void
    {
        config(['payments.card_provider' => null, 'services.stripe.secret' => 'sk_test_registry']);

        $this->assertSame('stripe', $this->registry()->cardProvider()?->key());
    }

    public function test_the_configured_processor_wins(): void
    {
        $this->wipayConfigured();
        config(['payments.card_provider' => 'wipay', 'services.stripe.secret' => 'sk_test_registry']);

        $this->assertSame('wipay', $this->registry()->cardProvider()?->key());

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard.billing'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.cardCheckout', true)
                ->where('payments.cardProvider', 'WiPay'));
    }

    public function test_a_chosen_processor_that_is_not_set_up_does_not_fall_back_to_another(): void
    {
        // WiPay chosen but its keys missing: card is off, not silently Stripe.
        config(['payments.card_provider' => 'wipay', 'payments.providers.wipay.api_key' => null, 'services.stripe.secret' => 'sk_test_registry']);

        $this->assertNull($this->registry()->cardProvider());
    }

    public function test_the_office_and_the_wallet_cannot_be_chosen_as_the_card_processor(): void
    {
        config(['payments.card_provider' => 'office']);

        $this->assertNull($this->registry()->cardProvider());
    }

    public function test_every_other_channel_goes_to_its_provider(): void
    {
        foreach (['bank_wire', 'cash_office', 'qr_code', 'zelle', 'cash_app'] as $channel) {
            $this->assertSame('office', $this->registry()->forChannel($channel)->key(), $channel);
        }

        // NFC is never the office's: it needs an in-person provider's reader.
        config(['payments.in_person_provider' => null]);
        $this->assertNull($this->registry()->forChannel('nfc_pos'));

        $this->assertSame('internal', $this->registry()->forChannel('wallet')->key());
    }

    public function test_device_wallets_go_only_to_a_card_processor_that_offers_them(): void
    {
        // No processor: no wallets. They are never the office's to confirm.
        config(['payments.card_provider' => null, 'services.stripe.secret' => null]);
        foreach (['apple_pay', 'google_pay', 'samsung_wallet'] as $channel) {
            $this->assertNull($this->registry()->forChannel($channel), $channel);
        }

        // Stripe's hosted Checkout offers Apple Pay and Google Pay — once the
        // estate has validated its merchant account accepts them.
        config(['services.stripe.secret' => 'sk_test_wallets', 'payments.wallets' => ['apple_pay', 'google_pay', 'samsung_wallet']]);
        $this->assertSame('stripe', $this->registry()->forChannel('apple_pay')?->key());
        $this->assertSame('stripe', $this->registry()->forChannel('google_pay')?->key());
        $this->assertNull($this->registry()->forChannel('samsung_wallet'), 'No provider here offers Samsung Pay.');

        // WiPay's Payments API takes credit_card only.
        $this->wipayConfigured();
        config(['payments.card_provider' => 'wipay']);
        $this->assertSame('wipay', $this->registry()->forChannel('card')?->key());
        $this->assertNull($this->registry()->forChannel('apple_pay'));
        $this->assertNull($this->registry()->forChannel('google_pay'));
    }

    public function test_a_wallet_the_processor_supports_is_not_offered_until_the_merchant_account_is_validated(): void
    {
        config(['services.stripe.secret' => 'sk_test_wallets', 'payments.wallets' => []]);

        // Stripe supports both; this estate has validated neither.
        $this->assertNull($this->registry()->forChannel('apple_pay'));
        $this->assertNull($this->registry()->forChannel('google_pay'));

        // Validated Google Pay only.
        config(['payments.wallets' => ['google_pay']]);
        $this->assertSame('stripe', $this->registry()->forChannel('google_pay')?->key());
        $this->assertNull($this->registry()->forChannel('apple_pay'));
    }

    public function test_listing_a_wallet_the_processor_does_not_support_offers_nothing(): void
    {
        // Validation is for the account, not a way round the processor.
        config(['payments.providers.wipay' => [
            'account_number' => '1234567890', 'api_key' => '123', 'environment' => 'sandbox',
            'country_code' => 'JM', 'fee_structure' => 'merchant_absorb', 'origin' => 'CommunityHub',
        ], 'payments.card_provider' => 'wipay', 'payments.wallets' => ['apple_pay', 'google_pay']]);

        $this->assertNull($this->registry()->forChannel('apple_pay'));
        $this->assertNull($this->registry()->forChannel('google_pay'));
    }

    public function test_unavailable_wallets_are_not_offered_to_payers(): void
    {
        config(['payments.card_provider' => null, 'services.stripe.secret' => null]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard.billing'))
            ->assertInertia(function (Assert $page) {
                $keys = collect($page->toArray()['props']['paymentCenter']['availableChannels'])->pluck('key');

                $this->assertNotContains('apple_pay', $keys);
                $this->assertNotContains('google_pay', $keys);
                $this->assertNotContains('card', $keys);
                $this->assertContains('bank_wire', $keys);
            });
    }

    public function test_providers_declare_only_what_they_can_do(): void
    {
        $stripe = $this->registry()->byKey('stripe');
        $wipay = $this->registry()->byKey('wipay');
        $office = $this->registry()->byKey('office');

        $this->assertInstanceOf(HandlesWebhooks::class, $stripe);
        $this->assertInstanceOf(RefundsPayments::class, $stripe);

        // WiPay's Payments API has no webhooks and no refunds.
        $this->assertInstanceOf(ConfirmsReturns::class, $wipay);
        $this->assertNotInstanceOf(HandlesWebhooks::class, $wipay);
        $this->assertNotInstanceOf(RefundsPayments::class, $wipay);

        $this->assertNotInstanceOf(HandlesWebhooks::class, $office);
        $this->assertNotInstanceOf(RefundsPayments::class, $office);
    }

    public function test_a_provider_without_webhooks_has_no_webhook_endpoint(): void
    {
        $this->wipayConfigured();

        $this->postJson('/api/webhooks/wipay', ['status' => 'success'])->assertNotFound();
        $this->postJson('/api/webhooks/office', [])->assertNotFound();
        $this->postJson('/api/webhooks/nobody', [])->assertNotFound();
    }
}
