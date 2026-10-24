<?php

namespace Tests\Feature;

use App\Models\BankReconciliation;
use App\Models\Invoice;
use App\Models\StripeEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Payments\Stripe\StripeRefundAndPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ProductionPaymentSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret_for_phpunit';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.secret' => 'sk_test_phpunit_not_real',
            'services.stripe.webhook_secret' => self::SECRET,
        ]);
    }

    private function invoice(array $overrides = []): Invoice
    {
        return Invoice::create([
            'user_id' => User::factory()->create()->id,
            'reference' => 'INV-SAFE-'.fake()->unique()->numerify('####'),
            'amount_minor' => 50000,
            'currency' => 'USD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(15),
            'status' => 'Unpaid',
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $object */
    private function deliver(string $type, array $object, ?string $eventId = null, ?string $secret = null, ?int $timestamp = null): TestResponse
    {
        $payload = json_encode([
            'id' => $eventId ?? 'evt_'.fake()->unique()->bothify('????????'),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ]);

        $timestamp ??= time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret ?? self::SECRET);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload);
    }

    /** A completed Stripe payment that settled the invoice, as settleSession() records it. */
    private function paidByStripe(Invoice $invoice, string $paymentIntent): void
    {
        Transaction::create([
            'user_id' => $invoice->user_id,
            'invoice_id' => $invoice->id,
            'amount_minor' => $invoice->amount_minor,
            'currency' => $invoice->currency,
            'payment_channel' => 'stripe_card',
            'reference' => "stripe:{$paymentIntent}",
            'status' => 'completed',
        ]);

        $invoice->forceFill(['stripe_payment_intent' => $paymentIntent])->save();
        $invoice->markPaid();
    }

    /** @return array<string, mixed> */
    private function dispute(string $id, string $paymentIntent, string $status): array
    {
        return [
            'id' => $id,
            'object' => 'dispute',
            'payment_intent' => $paymentIntent,
            'amount' => 50000,
            'currency' => 'usd',
            'status' => $status,
            'reason' => 'fraudulent',
        ];
    }

    /**
     * Stand in for Stripe's list of the charges a payout carried.
     *
     * @param  array<string, array{fee_minor: int, currency: string}>  $charges
     */
    private function payoutContains(array $charges): void
    {
        $this->app->instance(StripeRefundAndPayoutService::class, new class($charges, app(LedgerService::class)) extends StripeRefundAndPayoutService
        {
            public function __construct(private array $charges, LedgerService $ledgerService)
            {
                parent::__construct($ledgerService);
            }

            public function payoutCharges(string $payoutId, ?string $secretKey = null): array
            {
                return $this->charges;
            }
        });
    }

    // ── 1. Failed Payments ──

    public function test_failed_payment_intent_records_failed_transaction_and_preserves_invoice_status(): void
    {
        $invoice = $this->invoice();

        $this->deliver('payment_intent.payment_failed', [
            'id' => 'pi_failed_test_123',
            'amount' => 50000,
            'currency' => 'usd',
            'metadata' => ['invoice_id' => (string) $invoice->id],
            'last_payment_error' => [
                'code' => 'card_declined',
                'message' => 'Your card has insufficient funds.',
            ],
        ])->assertOk()->assertJson(['outcome' => 'failed']);

        $invoice->refresh();
        $this->assertSame('Unpaid', $invoice->status);

        $tx = Transaction::where('reference', 'stripe-failed:pi_failed_test_123')->first();
        $this->assertNotNull($tx);
        $this->assertSame('failed', $tx->status);
        $this->assertSame(50000, $tx->amount_minor);
        $this->assertStringContainsString('insufficient funds', $tx->notes);

        $evt = StripeEvent::where('type', 'payment_intent.payment_failed')->first();
        $this->assertNotNull($evt);
        $this->assertSame('failed', $evt->status);
    }

    public function test_expired_checkout_session_records_failure_and_leaves_invoice_unpaid(): void
    {
        $invoice = $this->invoice();

        $this->deliver('checkout.session.expired', [
            'id' => 'cs_expired_test_123',
            'client_reference_id' => (string) $invoice->id,
            'amount_total' => 50000,
            'currency' => 'usd',
        ])->assertOk()->assertJson(['outcome' => 'failed']);

        $invoice->refresh();
        $this->assertSame('Unpaid', $invoice->status);

        $tx = Transaction::where('reference', 'stripe-failed:cs_expired_test_123')->first();
        $this->assertNotNull($tx);
        $this->assertSame('failed', $tx->status);
        $this->assertStringContainsString('expired', $tx->notes);
    }

    // ── 2. Direct PaymentIntent Succeeded ──

    public function test_direct_payment_intent_succeeded_settles_invoice_and_sets_settlement_attributes(): void
    {
        $invoice = $this->invoice();

        $this->deliver('payment_intent.succeeded', [
            'id' => 'pi_success_direct_456',
            'status' => 'succeeded',
            'amount' => 50000,
            'currency' => 'usd',
            'metadata' => ['invoice_id' => (string) $invoice->id],
            'application_fee_amount' => 1750,
        ])->assertOk()->assertJson(['outcome' => 'settled']);

        $invoice->refresh();
        $this->assertSame('Paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);

        $tx = Transaction::where('reference', 'stripe:pi_success_direct_456')->first();
        $this->assertNotNull($tx);
        $this->assertSame('completed', $tx->status);
        $this->assertSame(50000, $tx->amount_minor);
        $this->assertSame(1750, $tx->fee_minor);
        $this->assertSame(48250, $tx->net_amount_minor);
        $this->assertNotNull($tx->settled_at);
    }

    // ── 3. Chargebacks and Disputes ──

    public function test_dispute_created_marks_invoice_disputed_and_records_dispute_transaction(): void
    {
        $invoice = $this->invoice(['status' => 'Paid', 'paid_at' => now()]);

        $this->deliver('charge.dispute.created', [
            'id' => 'dp_test_dispute_1',
            'payment_intent' => 'pi_disp_test_1',
            'amount' => 50000,
            'currency' => 'usd',
            'status' => 'under_review',
            'reason' => 'fraudulent',
            'metadata' => ['invoice_id' => (string) $invoice->id],
        ])->assertOk()->assertJson(['outcome' => 'disputed']);

        $invoice->refresh();
        $this->assertSame('Disputed', $invoice->status);

        $tx = Transaction::where('reference', 'stripe-dispute:dp_test_dispute_1:created')->first();
        $this->assertNotNull($tx);
        $this->assertSame('disputed', $tx->status);
        $this->assertSame('fraudulent', $tx->dispute_reason);
    }

    public function test_dispute_closed_won_restores_invoice_to_paid(): void
    {
        $invoice = $this->invoice(['status' => 'Disputed', 'paid_at' => now()->subDays(5)]);

        $this->deliver('charge.dispute.closed', [
            'id' => 'dp_test_dispute_2',
            'payment_intent' => 'pi_disp_test_2',
            'amount' => 50000,
            'currency' => 'usd',
            'status' => 'won',
            'reason' => 'general',
            'metadata' => ['invoice_id' => (string) $invoice->id],
        ])->assertOk()->assertJson(['outcome' => 'dispute_won']);

        $invoice->refresh();
        $this->assertSame('Paid', $invoice->status);

        $tx = Transaction::where('reference', 'stripe-dispute:dp_test_dispute_2:won')->first();
        $this->assertNotNull($tx);
        // Not `completed`: the original payment already is, and a second
        // completed row counted the same money as collected twice.
        $this->assertSame('reinstated', $tx->status);
    }

    public function test_dispute_closed_lost_marks_invoice_unpaid(): void
    {
        $invoice = $this->invoice(['status' => 'Disputed', 'paid_at' => now()->subDays(5)]);

        $this->deliver('charge.dispute.closed', [
            'id' => 'dp_test_dispute_3',
            'payment_intent' => 'pi_disp_test_3',
            'amount' => 50000,
            'currency' => 'usd',
            'status' => 'lost',
            'reason' => 'fraudulent',
            'metadata' => ['invoice_id' => (string) $invoice->id],
        ])->assertOk()->assertJson(['outcome' => 'dispute_lost']);

        $invoice->refresh();
        $this->assertSame('Unpaid', $invoice->status);

        $tx = Transaction::where('reference', 'stripe-dispute:dp_test_dispute_3:lost')->first();
        $this->assertNotNull($tx);
        $this->assertSame('refunded', $tx->status);
        $this->assertNull($invoice->paid_at);
    }

    public function test_a_won_dispute_does_not_count_the_payment_twice(): void
    {
        $invoice = $this->invoice();
        $this->paidByStripe($invoice, 'pi_won_once');

        $this->deliver('charge.dispute.created', $this->dispute('dp_won_once', 'pi_won_once', 'needs_response'))->assertOk();
        $this->deliver('charge.dispute.closed', $this->dispute('dp_won_once', 'pi_won_once', 'won'))->assertOk();

        $this->assertSame('Paid', $invoice->fresh()->status);
        $this->assertSame(50000, (int) Transaction::where('status', 'completed')->sum('amount_minor'));
        $this->assertSame(50000, $invoice->fresh()->amountPaidMinor());
    }

    public function test_a_lost_dispute_takes_the_payment_off_the_invoice(): void
    {
        $invoice = $this->invoice();
        $this->paidByStripe($invoice, 'pi_lost');

        $this->deliver('charge.dispute.closed', $this->dispute('dp_lost', 'pi_lost', 'lost'))->assertOk();

        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->amountPaidMinor());
    }

    public function test_a_dispute_is_matched_to_its_own_payment_not_the_first_one_on_file(): void
    {
        $bystander = $this->invoice();
        $this->paidByStripe($bystander, 'pi_bystander');

        $disputed = $this->invoice();
        $this->paidByStripe($disputed, 'pi_disputed');

        // No metadata: Stripe's dispute object names the PaymentIntent.
        $this->deliver('charge.dispute.created', $this->dispute('dp_matched', 'pi_disputed', 'needs_response'))
            ->assertOk()->assertJson(['outcome' => 'disputed']);

        $this->assertSame('Disputed', $disputed->fresh()->status);
        $this->assertSame('Paid', $bystander->fresh()->status);
    }

    public function test_a_dispute_for_an_unknown_payment_flags_no_invoice(): void
    {
        $bystander = $this->invoice();
        $this->paidByStripe($bystander, 'pi_someone_else');

        $this->deliver('charge.dispute.created', $this->dispute('dp_unknown', 'pi_never_seen', 'needs_response'))
            ->assertOk()->assertJson(['outcome' => 'unknown_invoice']);

        $this->assertSame('Paid', $bystander->fresh()->status);
        $this->assertSame(0, Transaction::where('status', 'disputed')->count());
    }

    // ── 4. Settlement and Payout Tracking ──

    public function test_payout_paid_settles_and_batches_completed_transactions(): void
    {
        $user = User::factory()->create();
        $tx1 = Transaction::create([
            'user_id' => $user->id,
            'amount_minor' => 10000,
            'currency' => 'USD',
            'payment_channel' => 'stripe_card',
            'reference' => 'stripe:pi_batch_1',
            'status' => 'completed',
        ]);

        $tx2 = Transaction::create([
            'user_id' => $user->id,
            'amount_minor' => 15000,
            'currency' => 'USD',
            'payment_channel' => 'stripe_card',
            'reference' => 'stripe:pi_batch_2',
            'status' => 'completed',
        ]);

        $tx3 = Transaction::create([
            'user_id' => $user->id,
            'amount_minor' => 5000,
            'currency' => 'USD',
            'payment_channel' => 'stripe_card',
            'reference' => 'stripe:pi_next_payout',
            'status' => 'completed',
        ]);

        $this->payoutContains([
            'pi_batch_1' => ['fee_minor' => 320, 'currency' => 'usd'],
            'pi_batch_2' => ['fee_minor' => 465, 'currency' => 'usd'],
        ]);

        $this->deliver('payout.paid', [
            'id' => 'po_test_payout_99',
            'amount' => 24215,
            'currency' => 'usd',
            'status' => 'paid',
        ])->assertOk()->assertJson(['outcome' => 'payout_settled']);

        $tx1->refresh();
        $tx2->refresh();
        $this->assertSame('po_test_payout_99', $tx1->payout_reference);
        $this->assertSame('po_test_payout_99', $tx2->payout_reference);
        $this->assertNotNull($tx1->settled_at);
        $this->assertNotNull($tx2->settled_at);

        // Stripe's fee, so net is what reached the bank.
        $this->assertSame(320, $tx1->fee_minor);
        $this->assertSame(9680, $tx1->net_amount_minor);

        // A payment the payout did not carry is left for the payout that does.
        $this->assertNull($tx3->fresh()->payout_reference);
    }

    public function test_a_failed_payout_leaves_the_ledger_unchanged(): void
    {
        $tx = Transaction::create([
            'user_id' => User::factory()->create()->id,
            'amount_minor' => 10000,
            'currency' => 'USD',
            'payment_channel' => 'stripe_card',
            'reference' => 'stripe:pi_failed_payout',
            'status' => 'completed',
        ]);

        $this->payoutContains(['pi_failed_payout' => ['fee_minor' => 320, 'currency' => 'usd']]);

        $this->deliver('payout.failed', [
            'id' => 'po_test_failed',
            'amount' => 9680,
            'currency' => 'usd',
            'status' => 'failed',
            'failure_code' => 'account_closed',
        ])->assertOk()->assertJson(['outcome' => 'payout_failed']);

        $this->assertNull($tx->fresh()->payout_reference);
    }

    // ── Partial payments ──

    public function test_a_card_payment_finishing_a_part_paid_statement_marks_it_paid(): void
    {
        $invoice = $this->invoice(['status' => 'Partially Paid']);

        Transaction::create([
            'user_id' => $invoice->user_id,
            'invoice_id' => $invoice->id,
            'amount_minor' => 20000,
            'currency' => 'USD',
            'payment_channel' => 'cash',
            'reference' => 'CASH-REC-PART',
            'status' => 'completed',
        ]);

        $this->deliver('checkout.session.completed', [
            'id' => 'cs_remainder',
            'object' => 'checkout.session',
            'client_reference_id' => (string) $invoice->id,
            'payment_status' => 'paid',
            'payment_intent' => 'pi_remainder',
            'amount_total' => 30000,
            'currency' => 'usd',
            'metadata' => ['purpose' => 'invoice', 'invoice_id' => (string) $invoice->id],
        ])->assertOk()->assertJson(['outcome' => 'settled']);

        $this->assertSame('Paid', $invoice->fresh()->status);
    }

    public function test_a_failed_delayed_checkout_payment_is_recorded(): void
    {
        $invoice = $this->invoice();

        $this->deliver('checkout.session.async_payment_failed', [
            'id' => 'cs_async_failed',
            'client_reference_id' => (string) $invoice->id,
            'amount_total' => 50000,
            'currency' => 'usd',
        ])->assertOk()->assertJson(['outcome' => 'failed']);

        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertSame('failed', Transaction::where('reference', 'stripe-failed:cs_async_failed')->value('status'));
    }

    // ── 5. Bank Reconciliation Controller & Variance Math ──

    public function test_reconciliation_endpoint_computes_variance_and_stores_balanced_reconciliation(): void
    {
        $admin = User::factory()->create(['role' => 'System Admin']);
        $resident = User::factory()->create(['role' => 'Homeowner']);

        Transaction::create([
            'user_id' => $resident->id,
            'amount_minor' => 30000,
            'currency' => 'USD',
            'payment_channel' => 'stripe_card',
            'reference' => 'stripe:pi_recon_1',
            'status' => 'completed',
            'created_at' => now()->subDays(2),
        ]);

        Transaction::create([
            'user_id' => $resident->id,
            'amount_minor' => 5000,
            'currency' => 'USD',
            'payment_channel' => 'stripe_card',
            'reference' => 'stripe-refund:pi_recon_1:5000',
            'status' => 'refunded',
            'created_at' => now()->subDay(),
        ]);

        // Net completed in ledger = 30000 - 5000 = 25000 minor = 250.00
        $response = $this->actingAs($admin)
            ->post('/dashboard/billing/reconciliations', [
                'bank_statement_date' => now()->format('Y-m-d'),
                'statement_balance' => 250.00,
                'notes' => 'NCB Monthly Settlement Statement #4839',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $recon = BankReconciliation::first();
        $this->assertNotNull($recon);
        $this->assertSame(25000, $recon->statement_balance_minor);
        $this->assertSame(25000, $recon->ledger_balance_minor);
        $this->assertSame(0, $recon->difference_minor);
        $this->assertSame('Reconciled', $recon->status);
        $this->assertSame($admin->id, $recon->reconciled_by);
    }

    public function test_reconciliation_endpoint_detects_discrepancy_variance(): void
    {
        $admin = User::factory()->create(['role' => 'System Admin']);
        $resident = User::factory()->create(['role' => 'Homeowner']);

        Transaction::create([
            'user_id' => $resident->id,
            'amount_minor' => 10000,
            'currency' => 'USD',
            'payment_channel' => 'card',
            'reference' => 'tx:100',
            'status' => 'completed',
            'created_at' => now()->subDays(2),
        ]);

        // Statement says 120.00 (12000 minor), ledger has 10000 minor -> diff is +2000 minor
        $response = $this->actingAs($admin)
            ->post('/dashboard/billing/reconciliations', [
                'bank_statement_date' => now()->format('Y-m-d'),
                'statement_balance' => 120.00,
                'notes' => 'Unreconciled bank deposit detected',
            ]);

        $response->assertRedirect();

        $recon = BankReconciliation::first();
        $this->assertNotNull($recon);
        $this->assertSame(12000, $recon->statement_balance_minor);
        $this->assertSame(10000, $recon->ledger_balance_minor);
        $this->assertSame(2000, $recon->difference_minor);
        $this->assertSame('Discrepancy', $recon->status);
    }

    public function test_non_admin_cannot_access_reconciliation_endpoint(): void
    {
        $resident = User::factory()->create(['role' => 'Homeowner']);

        $response = $this->actingAs($resident)
            ->post('/dashboard/billing/reconciliations', [
                'bank_statement_date' => now()->format('Y-m-d'),
                'statement_balance' => 100.00,
            ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('bank_reconciliations', 0);
    }

    // ── 6. Admin Billing Index Payment Events Feed ──

    public function test_billing_index_returns_payment_events_to_admin(): void
    {
        $admin = User::factory()->create(['role' => 'System Admin']);

        StripeEvent::create([
            'event_id' => 'evt_audit_log_1',
            'type' => 'checkout.session.completed',
            'status' => 'processed',
            'processed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/dashboard/billing');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard/Billing')
            ->has('paymentEvents')
            ->where('paymentEvents.0.eventId', 'evt_audit_log_1')
            ->where('paymentEvents.0.type', 'checkout.session.completed')
            ->where('paymentEvents.0.status', 'processed')
        );
    }

    public function test_proration_endpoint_calculates_daily_rate_and_period(): void
    {
        $resident = User::factory()->create(['role' => 'Homeowner']);

        $response = $this->actingAs($resident)->postJson('/dashboard/billing/prorate', [
            'monthly_rate' => 300.00,
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-15',
        ]);

        $response->assertOk()
            ->assertJsonPath('monthlyRate', 300)
            ->assertJsonPath('daysInMonth', 30)
            ->assertJsonPath('billedDays', 15)
            ->assertJsonPath('proratedAmount', 150);
    }

    public function test_customer_portal_gracefully_handles_unconfigured_stripe(): void
    {
        config(['services.stripe.secret' => null]);
        $resident = User::factory()->create(['role' => 'Homeowner']);

        $response = $this->actingAs($resident)->post('/dashboard/billing/portal');
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }
}
