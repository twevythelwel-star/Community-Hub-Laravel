<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Stripe\Exception\ApiConnectionException;
use Tests\TestCase;

/**
 * Stripe Checkout.
 *
 * The flow previously ran in a "demo" mode whenever no secret key was
 * configured — which was always, because config/services.php had no `stripe`
 * block at all. In that mode createCheckoutSession() redirected straight to
 * the success URL and completePayment() marked the invoice Paid. `success()`
 * was additionally a GET with no ownership check, so
 *
 *     GET /dashboard/billing/invoices/{any}/stripe-success?session_id=x
 *
 * settled any invoice in the estate. These tests pin both halves shut.
 */
class StripeBillingTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceFor(User $user, string $reference = 'INV-TEST-001'): Invoice
    {
        return Invoice::create([
            'user_id' => $user->id,
            'reference' => $reference,
            'amount_minor' => 25000,
            'currency' => 'USD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(15),
            'status' => 'Unpaid',
        ]);
    }

    /** Pretend a real key is configured without letting anything reach Stripe. */
    private function stripeConfigured(?callable $expectations = null): MockInterface
    {
        return $this->mock(StripePaymentService::class, function (MockInterface $mock) use ($expectations) {
            $mock->shouldReceive('isLive')->andReturn(true);

            if ($expectations) {
                $expectations($mock);
            }
        });
    }

    // ── Containment: with no processor configured, the flow does not exist ──

    public function test_checkout_is_not_reachable_when_stripe_is_not_configured(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoiceFor($user);

        $this->actingAs($user)
            ->post(route('dashboard.billing.stripe.checkout', ['invoice' => $invoice->id]))
            ->assertNotFound();
    }

    public function test_success_cannot_settle_an_invoice_when_stripe_is_not_configured(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoiceFor($user);

        $this->actingAs($user)
            ->get(route('dashboard.billing.stripe.success', [
                'invoice' => $invoice->id,
                'session_id' => 'cs_demo_12345',
            ]))
            ->assertNotFound();

        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->paid_at);
    }

    // ── Ownership, on every leg of the flow ──

    public function test_user_cannot_checkout_another_users_invoice(): void
    {
        $this->stripeConfigured();

        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $invoice = $this->invoiceFor($owner, 'INV-TEST-002');

        $this->actingAs($intruder)
            ->post(route('dashboard.billing.stripe.checkout', ['invoice' => $invoice->id]))
            ->assertForbidden();
    }

    public function test_user_cannot_settle_another_users_invoice_through_the_success_url(): void
    {
        $this->stripeConfigured(function (MockInterface $mock) {
            $mock->shouldNotReceive('completePayment');
        });

        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $invoice = $this->invoiceFor($owner, 'INV-TEST-003');

        $this->actingAs($intruder)
            ->get(route('dashboard.billing.stripe.success', [
                'invoice' => $invoice->id,
                'session_id' => 'cs_live_whatever',
            ]))
            ->assertForbidden();

        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    public function test_user_cannot_read_another_users_invoice_reference_through_cancel(): void
    {
        $this->stripeConfigured();

        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $invoice = $this->invoiceFor($owner, 'INV-TEST-004');

        $this->actingAs($intruder)
            ->get(route('dashboard.billing.stripe.cancel', ['invoice' => $invoice->id]))
            ->assertForbidden();
    }

    // ── Settlement follows Stripe, not the browser ──

    public function test_success_leaves_the_invoice_unpaid_when_stripe_does_not_confirm(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoiceFor($user, 'INV-TEST-005');

        $this->stripeConfigured(function (MockInterface $mock) {
            $mock->shouldReceive('completePayment')->once()->andReturn(false);
        });

        $this->actingAs($user)
            ->get(route('dashboard.billing.stripe.success', [
                'invoice' => $invoice->id,
                'session_id' => 'cs_live_unconfirmed',
            ]))
            ->assertRedirect(route('dashboard.billing'))
            ->assertSessionHas('error');

        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->paid_at);
    }

    public function test_success_settles_the_invoice_when_stripe_confirms(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoiceFor($user, 'INV-TEST-006');

        $this->stripeConfigured(function (MockInterface $mock) use ($invoice) {
            $mock->shouldReceive('completePayment')
                ->once()
                ->andReturnUsing(function (Invoice $i) use ($invoice) {
                    $this->assertSame($invoice->id, $i->id);
                    $i->update(['status' => 'Paid', 'paid_at' => now()]);

                    return true;
                });
        });

        $this->actingAs($user)
            ->get(route('dashboard.billing.stripe.success', [
                'invoice' => $invoice->id,
                'session_id' => 'cs_live_confirmed',
            ]))
            ->assertRedirect(route('dashboard.billing'))
            ->assertSessionHas('success');

        $this->assertSame('Paid', $invoice->fresh()->status);
    }

    public function test_success_requires_a_session_id(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoiceFor($user, 'INV-TEST-007');

        $this->stripeConfigured(function (MockInterface $mock) {
            $mock->shouldNotReceive('completePayment');
        });

        $this->actingAs($user)
            ->get(route('dashboard.billing.stripe.success', ['invoice' => $invoice->id]))
            ->assertSessionHasErrors('session_id');

        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    public function test_invoice_pdf_download_streams_pdf(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoiceFor($user, 'INV-TEST-008');
        $invoice->update(['status' => 'Paid', 'paid_at' => now()]);

        $response = $this->actingAs($user)
            ->get(route('dashboard.billing.invoice.pdf', ['invoice' => $invoice->id]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    // ── Reaching Stripe from the app ──

    public function test_an_inertia_checkout_is_sent_to_stripe_as_a_full_page_visit(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoiceFor($user, 'INV-TEST-009');

        $this->stripeConfigured(function (MockInterface $mock) {
            $mock->shouldReceive('createCheckoutSession')->once()->andReturn('https://checkout.stripe.com/c/pay/cs_test_9');
        });

        // The Pay button posts through Inertia (an XHR), which cannot follow a
        // 302 to another origin. Inertia's 409 + X-Inertia-Location makes the
        // client leave the page for Stripe instead.
        $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post(route('dashboard.billing.stripe.checkout', ['invoice' => $invoice->id]))
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://checkout.stripe.com/c/pay/cs_test_9');
    }

    public function test_a_stripe_outage_at_checkout_is_reported_not_thrown(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoiceFor($user, 'INV-TEST-010');

        $this->stripeConfigured(function (MockInterface $mock) {
            $mock->shouldReceive('createCheckoutSession')->andThrow(ApiConnectionException::factory('Stripe is unreachable'));
        });

        $this->actingAs($user)
            ->post(route('dashboard.billing.stripe.checkout', ['invoice' => $invoice->id]))
            ->assertRedirect(route('dashboard.billing'))
            ->assertSessionHas('error');

        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    // ── The Payment Center's card option ──

    public function test_paying_by_card_goes_to_stripe_for_the_chosen_amount_and_records_nothing_yet(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoiceFor($user, 'INV-TEST-011');

        $this->stripeConfigured(function (MockInterface $mock) use ($invoice) {
            $mock->shouldReceive('createCheckoutSession')
                ->once()
                ->withArgs(fn (Invoice $paid, string $success, string $cancel, ?int $amountMinor) => $paid->is($invoice) && $amountMinor === 10000)
                ->andReturn('https://checkout.stripe.com/c/pay/cs_test_11');
        });

        $this->actingAs($user)
            ->post(route('dashboard.billing.pay'), [
                'channel' => 'card',
                'amount' => 100.00,
                'currency' => 'USD',
                'invoice_id' => $invoice->id,
            ])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_11');

        // The card driver used to answer "success" here and settle the invoice.
        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertSame(0, Transaction::count());
    }

    public function test_paying_by_card_without_stripe_is_refused_and_settles_nothing(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoiceFor($user, 'INV-TEST-012');

        $this->actingAs($user)
            ->post(route('dashboard.billing.pay'), [
                'channel' => 'card',
                'amount' => 250.00,
                'currency' => 'USD',
                'invoice_id' => $invoice->id,
            ])
            ->assertSessionHasErrors('channel');

        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertSame(0, Transaction::count());
    }

    public function test_a_card_payment_above_the_balance_is_refused(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoiceFor($user, 'INV-TEST-013');

        $this->stripeConfigured(function (MockInterface $mock) {
            $mock->shouldNotReceive('createCheckoutSession');
        });

        $this->actingAs($user)
            ->post(route('dashboard.billing.pay'), [
                'channel' => 'card',
                'amount' => 300.00,
                'currency' => 'USD',
                'invoice_id' => $invoice->id,
            ])
            ->assertSessionHasErrors('amount');
    }

    public function test_a_card_payment_must_be_in_the_statement_currency(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoiceFor($user, 'INV-TEST-014');

        $this->stripeConfigured(function (MockInterface $mock) {
            $mock->shouldNotReceive('createCheckoutSession');
        });

        $this->actingAs($user)
            ->post(route('dashboard.billing.pay'), [
                'channel' => 'card',
                'amount' => 100.00,
                'currency' => 'JMD',
                'invoice_id' => $invoice->id,
            ])
            ->assertSessionHasErrors('currency');
    }
}
