<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Fundraiser;
use App\Models\PaymentChannelSetting;
use App\Models\User;
use App\Services\Payments\PaymentOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Billing and Fundraising must offer the same payment methods.
 *
 * Both read `PaymentOrchestratorService::getAvailableChannels()`, which is
 * backed by `payment_channel_settings` — so an administrator enabling,
 * disabling, renaming or surcharging a channel affects both pages at once.
 */
class PaymentChannelsTest extends TestCase
{
    use RefreshDatabase;

    private function resident(): User
    {
        return User::factory()->role(UserRole::Homeowner)->create();
    }

    private function fundraiser(): Fundraiser
    {
        return Fundraiser::create([
            'title' => 'New Playground Equipment',
            'description' => 'A modern playground for the community children.',
            'goal_minor' => 150000000,
            'goal_currency' => 'JMD',
            'start_date' => now()->subWeek(),
            'end_date' => now()->addMonth(),
            'status' => 'Active',
        ]);
    }

    /** @return array<int, string> */
    private function channelKeysFrom(array $channels): array
    {
        return collect($channels)->pluck('key')->sort()->values()->all();
    }

    public function test_both_pages_offer_the_same_channels(): void
    {
        $this->fundraiser();
        $resident = $this->resident();

        $billing = $this->actingAs($resident)->get('/dashboard/billing');
        $fundraising = $this->actingAs($resident)->get('/dashboard/fundraising');

        $billingChannels = $this->channelKeysFrom(
            $billing->viewData('page')['props']['paymentCenter']['availableChannels'] ?? []
        );

        $fundraisingChannels = $this->channelKeysFrom(
            $fundraising->viewData('page')['props']['availableChannels'] ?? []
        );

        $this->assertNotEmpty($fundraisingChannels, 'fundraising must receive the channel list');
        $this->assertSame($billingChannels, $fundraisingChannels);
    }

    public function test_fundraising_receives_the_channel_list(): void
    {
        $this->fundraiser();

        $this->actingAs($this->resident())
            ->get('/dashboard/fundraising')
            ->assertInertia(fn (Assert $page) => $page
                ->has('availableChannels')
                ->has('availableChannels.0.key')
                ->has('availableChannels.0.label')
                ->has('availableChannels.0.fee_surcharge_percent')
            );
    }

    public function test_disabling_a_channel_removes_it_from_both_pages(): void
    {
        PaymentChannelSetting::updateOrCreate(
            ['channel_key' => 'zelle'],
            ['enabled' => false, 'display_label' => 'Zelle']
        );

        $this->fundraiser();
        $resident = $this->resident();

        $fundraising = $this->actingAs($resident)->get('/dashboard/fundraising');
        $billing = $this->actingAs($resident)->get('/dashboard/billing');

        $this->assertNotContains(
            'zelle',
            $this->channelKeysFrom($fundraising->viewData('page')['props']['availableChannels'] ?? [])
        );

        $this->assertNotContains(
            'zelle',
            $this->channelKeysFrom(
                $billing->viewData('page')['props']['paymentCenter']['availableChannels'] ?? []
            )
        );
    }

    public function test_a_channel_surcharge_reaches_the_donor(): void
    {
        /*
         | `fee_surcharge_percent` has always been on the settings table and was
         | passed to the payment UI without ever being displayed, under a badge
         | reading "Zero Surcharges". It has to reach the page before it can be
         | shown to the person paying it.
         */
        // Card is offered only with a card processor configured.
        config(['services.stripe.secret' => 'sk_test_surcharge']);

        PaymentChannelSetting::updateOrCreate(
            ['channel_key' => 'card'],
            ['enabled' => true, 'display_label' => 'Debit / Credit Card', 'fee_surcharge_percent' => 2.5]
        );

        $this->fundraiser();

        $this->actingAs($this->resident())
            ->get('/dashboard/fundraising')
            ->assertInertia(function (Assert $page) {
                $channels = collect($page->toArray()['props']['availableChannels']);
                $card = $channels->firstWhere('key', 'card');

                $this->assertNotNull($card);
                $this->assertSame(2.5, (float) $card['fee_surcharge_percent']);
            });
    }

    public function test_a_donation_records_the_channel_it_was_given_through(): void
    {
        $fundraiser = $this->fundraiser();

        $this->actingAs($this->resident())
            ->post("/dashboard/fundraising/{$fundraiser->id}/donate", [
                'amount' => 2500,
                'donor_name' => 'Marcus V.',
                'is_anonymous' => false,
                'channel' => 'cash_office',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('cash_office', $fundraiser->donations()->firstOrFail()->payment_channel);
    }

    public function test_an_unknown_channel_is_rejected_rather_than_throwing(): void
    {
        /*
         | `channel` was `['nullable', 'string']`, so an unrecognised key reached
         | PaymentOrchestratorService::getDriver(), which throws
         | InvalidArgumentException — a 500 from an ordinary form post.
         */
        $fundraiser = $this->fundraiser();

        $this->actingAs($this->resident())
            ->post("/dashboard/fundraising/{$fundraiser->id}/donate", [
                'amount' => 100,
                'donor_name' => 'Marcus V.',
                'channel' => 'definitely-not-a-driver',
            ])
            ->assertSessionHasErrors('channel');

        $this->assertDatabaseCount('donations', 0);
    }

    public function test_every_offered_channel_is_one_the_orchestrator_can_settle(): void
    {
        // Otherwise the page offers a button whose POST would 500.
        $this->fundraiser();

        $orchestrator = app(PaymentOrchestratorService::class);
        $known = $orchestrator->channelKeys();

        $response = $this->actingAs($this->resident())->get('/dashboard/fundraising');
        $offered = $this->channelKeysFrom($response->viewData('page')['props']['availableChannels']);

        foreach ($offered as $key) {
            $this->assertContains($key, $known, "offered channel [{$key}] has no driver");
        }
    }
}
