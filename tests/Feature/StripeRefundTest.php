<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StripePaymentService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The administrator's refund action. The call to Stripe itself is mocked; the
 * ledger bookkeeping it triggers is the same syncRefunds() path covered in
 * StripeWebhookTest.
 */
class StripeRefundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exercises actions behind password.confirm; the prompt itself is PasswordConfirmationTest's.
        $this->confirmPassword();
    }

    private function stripePayment(int $amountMinor = 25000): Transaction
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();
        $invoice = Invoice::create([
            'user_id' => $resident->id,
            'reference' => 'INV-RF-001',
            'amount_minor' => $amountMinor,
            'currency' => 'USD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(15),
            'status' => 'Paid',
            'paid_at' => now(),
            'stripe_payment_intent' => 'pi_refund_test',
        ]);

        return Transaction::create([
            'user_id' => $resident->id,
            'invoice_id' => $invoice->id,
            'amount_minor' => $amountMinor,
            'currency' => 'USD',
            'payment_channel' => StripePaymentService::CHANNEL,
            'reference' => 'stripe:pi_refund_test',
            'status' => 'completed',
        ]);
    }

    public function test_an_admin_refund_is_sent_to_stripe_in_minor_units(): void
    {
        $payment = $this->stripePayment();
        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->mock(StripePaymentService::class, function (MockInterface $mock) use ($payment, $admin) {
            $mock->shouldReceive('isLive')->andReturn(true);
            $mock->shouldReceive('refund')->once()->with(
                Mockery::on(fn ($t) => $t->is($payment)),
                10050,
                Mockery::on(fn ($u) => $u->is($admin)),
                'Duplicate payment',
            );
        });

        $this->actingAs($admin)
            ->post(route('dashboard.billing.transactions.refund', $payment), [
                'amount' => '100.50',
                'note' => 'Duplicate payment',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_a_refund_the_service_refuses_is_reported_back_to_the_form(): void
    {
        $payment = $this->stripePayment();

        $this->mock(StripePaymentService::class, function (MockInterface $mock) {
            $mock->shouldReceive('isLive')->andReturn(true);
            $mock->shouldReceive('refund')->andThrow(new DomainException('This payment has nothing left to refund.'));
        });

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->post(route('dashboard.billing.transactions.refund', $payment), ['amount' => '10'])
            ->assertSessionHasErrors(['amount' => 'This payment has nothing left to refund.']);
    }

    public function test_residents_cannot_refund(): void
    {
        $payment = $this->stripePayment();

        $this->mock(StripePaymentService::class, function (MockInterface $mock) {
            $mock->shouldReceive('isLive')->andReturn(true);
            $mock->shouldNotReceive('refund');
        });

        $this->actingAs($payment->user)
            ->post(route('dashboard.billing.transactions.refund', $payment), ['amount' => '10'])
            ->assertForbidden();
    }

    public function test_refunds_do_not_exist_without_stripe(): void
    {
        $payment = $this->stripePayment();

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->post(route('dashboard.billing.transactions.refund', $payment), ['amount' => '10'])
            ->assertNotFound();
    }

    public function test_the_ledger_offers_refunds_only_on_stripe_payments_with_money_left(): void
    {
        config(['services.stripe.secret' => 'sk_test_phpunit_not_real']);

        $payment = $this->stripePayment();
        Transaction::create([
            'user_id' => $payment->user_id,
            'invoice_id' => $payment->invoice_id,
            'amount_minor' => 5000,
            'currency' => 'USD',
            'payment_channel' => StripePaymentService::CHANNEL,
            'reference' => 'stripe-refund:pi_refund_test:5000',
            'status' => 'refunded',
        ]);
        Transaction::create([
            'user_id' => $payment->user_id,
            'amount_minor' => 1000,
            'currency' => 'USD',
            'payment_channel' => 'cash_office',
            'status' => 'completed',
        ]);

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/dashboard/billing')
            ->assertInertia(function ($page) use ($payment) {
                $rows = collect($page->toArray()['props']['transactions']['data'])->keyBy('id');

                // 250.00 paid less 50.00 already refunded. JSON carries 200.0 as 200.
                $this->assertEquals(200, $rows[$payment->id]['refundableAmount']);
                $this->assertSame(route('dashboard.billing.transactions.refund', $payment->id), $rows[$payment->id]['refundUrl']);
                $this->assertNull($rows->firstWhere('channel', 'cash_office')['refundUrl']);
                $this->assertNull($rows->firstWhere('status', 'refunded')['refundUrl']);
            });

        // A resident sees their own payment, but never a refund control.
        $this->actingAs($payment->user)
            ->get('/dashboard/billing')
            ->assertInertia(function ($page) use ($payment) {
                $row = collect($page->toArray()['props']['transactions']['data'])->firstWhere('id', $payment->id);
                $this->assertNull($row['refundUrl']);
            });
    }
}
