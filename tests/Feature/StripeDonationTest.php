<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\Fundraiser;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Card donations through Stripe Checkout.
 *
 * The card driver answered every request with success, so the default donation
 * channel recorded a completed gift — and raised the campaign total — without
 * any money being taken. A card gift is now recorded only from a Checkout
 * Session Stripe reports as paid.
 */
class StripeDonationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_donations';

    private function fundraiser(): Fundraiser
    {
        return Fundraiser::create([
            'title' => 'Playground Resurfacing',
            'description' => 'New safety surface for the children’s playground.',
            'category' => 'Infrastructure',
            'beneficiary' => 'Parents Association',
            'goal_minor' => 1000000,
            'goal_currency' => 'USD',
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => 'Active',
            'created_by' => User::factory()->create()->id,
        ]);
    }

    /** @param  array<string, mixed>  $session */
    private function deliverPaidSession(array $session, string $eventId): void
    {
        config([
            'services.stripe.secret' => 'sk_test_phpunit_not_real',
            'services.stripe.webhook_secret' => self::SECRET,
        ]);

        $payload = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => ['object' => $session],
        ]);

        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", self::SECRET);

        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload)->assertOk();
    }

    /** @return array<string, mixed> */
    private function paidDonationSession(Fundraiser $fundraiser, User $donor, array $metadata = []): array
    {
        return [
            'id' => 'cs_test_donation_'.$fundraiser->id,
            'object' => 'checkout.session',
            'client_reference_id' => "donation-{$fundraiser->id}-{$donor->id}",
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_donation_'.$fundraiser->id,
            'amount_total' => 5000,
            'currency' => 'usd',
            'metadata' => [
                'purpose' => 'donation',
                'fundraiser_id' => (string) $fundraiser->id,
                'user_id' => (string) $donor->id,
                'donor_name' => 'The Morrison Family',
                'is_anonymous' => '0',
                'is_recurring' => '0',
                'frequency' => '',
                ...$metadata,
            ],
        ];
    }

    public function test_a_card_donation_goes_to_stripe_and_records_nothing_until_paid(): void
    {
        $fundraiser = $this->fundraiser();
        $donor = User::factory()->create();

        $this->mock(StripePaymentService::class, function (MockInterface $mock) {
            $mock->shouldReceive('isLive')->andReturn(true);
            $mock->shouldReceive('createDonationCheckoutSession')
                ->once()
                ->withArgs(fn ($f, $d, int $amountMinor) => $amountMinor === 5000)
                ->andReturn('https://checkout.stripe.com/c/pay/cs_test_gift');
        });

        $this->actingAs($donor)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post(route('dashboard.fundraising.donate', $fundraiser), ['amount' => 50, 'channel' => 'card'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://checkout.stripe.com/c/pay/cs_test_gift');

        $this->assertSame(0, Donation::count());
        $this->assertSame(0, $fundraiser->fresh()->raisedMinor());
    }

    public function test_a_card_donation_without_stripe_is_refused(): void
    {
        $fundraiser = $this->fundraiser();

        $this->actingAs(User::factory()->create())
            ->post(route('dashboard.fundraising.donate', $fundraiser), ['amount' => 50])
            ->assertSessionHasErrors('channel');

        $this->assertSame(0, Donation::count());
    }

    public function test_a_paid_donation_session_records_the_gift_once(): void
    {
        $fundraiser = $this->fundraiser();
        $donor = User::factory()->create();
        $session = $this->paidDonationSession($fundraiser, $donor);

        $this->deliverPaidSession($session, 'evt_gift_1');
        $this->deliverPaidSession($session, 'evt_gift_2');

        $this->assertSame(1, Donation::count());

        $donation = Donation::first();
        $this->assertSame(5000, $donation->amount_minor);
        $this->assertSame('completed', $donation->status);
        $this->assertSame($donor->id, $donation->user_id);
        $this->assertSame('The Morrison Family', $donation->donor_name);
        $this->assertStringStartsWith('DON-REC-', $donation->receipt_number);

        $payment = Transaction::where('reference', "stripe:pi_test_donation_{$fundraiser->id}")->sole();
        $this->assertSame($fundraiser->id, $payment->fundraiser_id);
        $this->assertNull($payment->invoice_id);

        $this->assertSame(5000, $fundraiser->fresh()->raisedMinor());
    }

    public function test_an_anonymous_gift_stays_anonymous(): void
    {
        $fundraiser = $this->fundraiser();
        $donor = User::factory()->create();

        $this->deliverPaidSession($this->paidDonationSession($fundraiser, $donor, ['is_anonymous' => '1']), 'evt_anon');

        $this->assertTrue(Donation::sole()->is_anonymous);
    }

    public function test_a_donation_in_another_currency_is_not_recorded(): void
    {
        $fundraiser = $this->fundraiser();
        $session = [...$this->paidDonationSession($fundraiser, User::factory()->create()), 'currency' => 'jmd'];

        $this->deliverPaidSession($session, 'evt_wrong_currency');

        $this->assertSame(0, Donation::count());
    }

    public function test_returning_from_stripe_confirms_the_donation(): void
    {
        $fundraiser = $this->fundraiser();
        $donor = User::factory()->create();

        $this->mock(StripePaymentService::class, function (MockInterface $mock) use ($fundraiser, $donor) {
            $mock->shouldReceive('isLive')->andReturn(true);
            $mock->shouldReceive('completeDonation')
                ->once()
                ->withArgs(fn (Fundraiser $f, User $u, string $sessionId) => $f->is($fundraiser) && $u->is($donor) && $sessionId === 'cs_test_back')
                ->andReturn(true);
        });

        $this->actingAs($donor)
            ->get(route('dashboard.fundraising.donate.stripe.success', ['fundraiser' => $fundraiser, 'session_id' => 'cs_test_back']))
            ->assertRedirect(route('dashboard.fundraising'))
            ->assertSessionHas('success');
    }

    public function test_an_unconfirmed_return_from_stripe_reports_an_error(): void
    {
        $fundraiser = $this->fundraiser();

        $this->mock(StripePaymentService::class, function (MockInterface $mock) {
            $mock->shouldReceive('isLive')->andReturn(true);
            $mock->shouldReceive('completeDonation')->once()->andReturn(false);
        });

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard.fundraising.donate.stripe.success', ['fundraiser' => $fundraiser, 'session_id' => 'cs_someone_elses']))
            ->assertRedirect(route('dashboard.fundraising'))
            ->assertSessionHas('error');
    }

    public function test_the_return_routes_do_not_exist_without_stripe(): void
    {
        $fundraiser = $this->fundraiser();

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard.fundraising.donate.stripe.success', ['fundraiser' => $fundraiser, 'session_id' => 'cs_x']))
            ->assertNotFound();
    }
}
