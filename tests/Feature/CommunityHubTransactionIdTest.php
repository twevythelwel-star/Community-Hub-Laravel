<?php

namespace Tests\Feature;

use App\Enums\PaymentState;
use App\Enums\UserRole;
use App\Exceptions\IllegalPaymentTransition;
use App\Models\BankReconciliation;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentOrchestratorService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * CommunityHub payment numbers, codes and slips.
 *
 * A payment gets its CH-YYYY-NNNNNNNNNN number when it is created — before any
 * money moves — and the ledger row written when it is paid carries the same
 * number. The payer's slip is built from what is on record, never filled in.
 */
class CommunityHubTransactionIdTest extends TestCase
{
    use RefreshDatabase;

    private function createInvoice(User $user, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'user_id' => $user->id,
            'reference' => 'INV-2026-00481',
            'amount_minor' => 25000,
            'currency' => 'USD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(10),
            'status' => 'Unpaid',
        ], $overrides));
    }

    private function orchestrator(): PaymentOrchestratorService
    {
        return app(PaymentOrchestratorService::class);
    }

    public function test_transaction_id_format_matches_ch_yyyy_xxxxxxxxxx(): void
    {
        $id = Transaction::generateTransactionId();

        $this->assertMatchesRegularExpression('/^CH-\d{4}-\d{10}$/', $id);
        $this->assertStringStartsWith('CH-'.date('Y').'-', $id);
    }

    public function test_transaction_ids_are_never_handed_out_twice(): void
    {
        // They were "latest + 1" read without a lock, on a unique column, and
        // fell back to the row count — so they could repeat.
        $ids = collect(range(1, 25))->map(fn () => Transaction::generateTransactionId());

        $this->assertCount(25, $ids->unique());

        $sequence = $ids->map(fn (string $id) => (int) substr($id, -10))->values();
        $this->assertSame(range($sequence->first(), $sequence->first() + 24), $sequence->all());
    }

    public function test_user_and_property_codes_format_correctly(): void
    {
        $this->assertSame('USR-000284', Transaction::formatUserCode(284));
        $this->assertSame('PROP-00481', Transaction::formatPropertyCode(null, null, 'PROP-00481'));
        $this->assertSame('PROP-00042', Transaction::formatPropertyCode(User::factory()->make(['lot' => 'Lot 42'])));
        $this->assertSame('COMM-001', Transaction::defaultCommunityCode());
    }

    public function test_an_unknown_property_is_left_blank_not_invented(): void
    {
        // This fell back to PROP-00481 — a lot that belongs to someone else.
        $this->assertNull(Transaction::formatPropertyCode(User::factory()->make(['lot' => null])));
        $this->assertNull(Transaction::formatPropertyCode());
    }

    public function test_a_payment_is_created_with_its_number_and_a_slip_before_any_money_moves(): void
    {
        $user = User::factory()->create(['id' => 284, 'lot' => 'Lot 481']);
        $invoice = $this->createInvoice($user);

        $payment = $this->orchestrator()->startPayment([
            'user' => $user,
            'amount_minor' => 25000,
            'channel' => 'apple_pay',
            'invoice' => $invoice,
            'purpose' => 'HOA Assessment',
            'currency' => 'USD',
        ]);

        $this->assertSame(PaymentState::Created, $payment->state);
        $this->assertMatchesRegularExpression('/^CH-\d{4}-\d{10}$/', $payment->transaction_id);
        $this->assertSame(0, Transaction::count(), 'No money has moved, so nothing is on the ledger.');

        $slip = $payment->toSlip();
        $this->assertSame($payment->transaction_id, $slip['transaction_id']);
        $this->assertSame('USR-000284', $slip['user']);
        $this->assertSame('PROP-00481', $slip['property']);
        $this->assertSame('COMM-001', $slip['community']);
        $this->assertSame('HOA Assessment', $slip['purpose']);
        $this->assertSame('INV-2026-00481', $slip['invoice']);
        $this->assertSame('250.00', $slip['amount']);
        $this->assertSame('USD', $slip['currency']);
        $this->assertSame('CREATED', $slip['status']);
        $this->assertSame('Apple Pay', $slip['payment_method']);
        // Apple Pay is confirmed by the office; Stripe never sees that money.
        $this->assertSame('office', $slip['provider']);
    }

    public function test_nfc_contactless_records_the_device_and_is_office_confirmed(): void
    {
        $user = User::factory()->create(['id' => 105]);

        $payment = $this->orchestrator()->startPayment([
            'user' => $user,
            'amount_minor' => 15000,
            'channel' => 'nfc_pos',
            'device_identifier' => 'SECURITY-TABLET-04',
            'currency' => 'USD',
        ]);

        $this->assertSame('nfc_pos', $payment->channel);
        $this->assertSame('office', $payment->provider);
        $this->assertSame('SECURITY-TABLET-04', $payment->device_identifier);

        $slip = $payment->toSlip();
        $this->assertSame('SECURITY-TABLET-04', $slip['device']);
        $this->assertSame('Tap to Pay / Contactless', $slip['payment_method']);
    }

    public function test_payment_method_registry_stores_non_custodial_provider_tokens(): void
    {
        $user = User::factory()->create();

        $pm = PaymentMethod::resolveForUser(
            user: $user,
            methodType: 'card',
            provider: 'stripe',
            attributes: [
                'provider_payment_method_id' => 'pm_stripe_test_visa_4242',
                'provider_customer_id' => 'cus_stripe_123',
                'last_four' => '4242',
                'brand' => 'Visa',
                'display_name' => 'Visa ending in 4242',
            ]
        );

        $this->assertSame($user->id, $pm->user_id);
        $this->assertSame('stripe', $pm->provider);
        $this->assertSame('card', $pm->method_type);
        $this->assertSame('pm_stripe_test_visa_4242', $pm->provider_payment_method_id);
        $this->assertSame('4242', $pm->last_four);
        $this->assertSame('Visa', $pm->brand);
        $this->assertTrue($pm->is_default);
    }

    public function test_a_bank_transfer_moves_through_enforced_states_and_never_auto_settles(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createInvoice($user, ['amount_minor' => 30000]);
        $clerk = User::factory()->role(UserRole::Admin)->create();
        $auditor = User::factory()->role(UserRole::Admin)->create();

        $payment = $this->orchestrator()->startPayment([
            'user' => $user,
            'amount_minor' => 30000,
            'channel' => 'bank_wire',
            'invoice' => $invoice,
            'currency' => 'USD',
        ]);

        $this->orchestrator()->awaitTransfer($payment, $user);
        $this->assertSame(PaymentState::AwaitingTransfer, $payment->fresh()->state);
        $this->assertSame('Unpaid', $invoice->fresh()->status);

        // An awaiting transfer cannot jump to Paid.
        try {
            $payment->fresh()->transitionTo(PaymentState::Paid);
            $this->fail('An awaiting transfer was allowed to become Paid.');
        } catch (IllegalPaymentTransition) {
            $this->assertSame(PaymentState::AwaitingTransfer, $payment->fresh()->state);
        }

        $this->orchestrator()->markReceived($payment, $clerk, 'NCB-7781');
        $this->assertSame(PaymentState::Received, $payment->fresh()->state);
        $this->assertSame('Unpaid', $invoice->fresh()->status);

        $reconciliation = BankReconciliation::create([
            'bank_statement_date' => now()->toDateString(),
            'statement_balance_minor' => 30000,
            'ledger_balance_minor' => 0,
            'difference_minor' => 0,
            'reconciled_by' => $auditor->id,
            'status' => 'Discrepancy',
        ]);

        $this->orchestrator()->verifyReceived($payment, $auditor, $reconciliation);

        $payment->refresh();
        $this->assertSame(PaymentState::Paid, $payment->state);
        $this->assertSame('Paid', $invoice->fresh()->status);
        $this->assertTrue($payment->ledgerPayment()->isPaid());
        $this->assertSame(
            ['created', 'awaiting_transfer', 'received', 'verified', 'paid'],
            $payment->transitions->map(fn ($t) => $t->to_state->value)->all(),
        );
    }

    public function test_twilio_notification_fires_only_once_the_payment_is_paid(): void
    {
        $user = User::factory()->create(['phone' => '+18765551234']);

        $mockSms = Mockery::mock(SmsService::class);
        $this->app->instance(SmsService::class, $mockSms);

        $payment = $this->orchestrator()->startPayment([
            'user' => $user,
            'amount_minor' => 25000,
            'channel' => 'wallet',
            'currency' => 'USD',
        ]);

        // Started, not paid: nothing to announce.
        $mockSms->shouldNotReceive('send');
        $this->assertSame(PaymentState::Created, $payment->state);

        $mockSms->shouldReceive('isConfigured')->once()->andReturn(true);
        $mockSms->shouldReceive('send')
            ->once()
            ->withArgs(fn ($phone, $message) => $phone === $user->phone
                && str_contains($message, $payment->transaction_id)
                && str_contains($message, '250.00'))
            ->andReturn('SM_TEST_SID');

        $payment->transitionTo(PaymentState::Succeeded);
        $this->orchestrator()->applyPayment($payment);
    }

    public function test_the_initiate_endpoint_starts_a_payment_and_returns_its_slip(): void
    {
        $user = User::factory()->create(['id' => 284, 'role' => 'Homeowner', 'status' => 'Active', 'lot' => 'Lot 481']);
        $invoice = $this->createInvoice($user);

        $response = $this->actingAs($user)->postJson(route('dashboard.billing.transactions.initiate'), [
            'channel' => 'bank_wire',
            'amount' => 250.00,
            'currency' => 'USD',
            'invoice_id' => $invoice->id,
            'purpose' => 'HOA Assessment',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'transaction' => [
                'transaction_id', 'user', 'property', 'community', 'purpose', 'invoice',
                'amount', 'currency', 'status', 'payment_method', 'provider', 'created',
            ],
            'slip',
            'transaction_id',
            'status',
        ]);

        $json = $response->json();
        $this->assertTrue($json['success']);
        $this->assertSame(Payment::sole()->transaction_id, $json['transaction_id']);
        $this->assertSame('created', $json['status']);
        $this->assertSame('USD', $json['transaction']['currency']);
        $this->assertSame('250.00', $json['transaction']['amount']);
        $this->assertSame('HOA Assessment', $json['transaction']['purpose']);
        $this->assertSame('Bank Transfer', $json['transaction']['payment_method']);
        $this->assertSame('office', $json['transaction']['provider']);

        $this->assertSame(0, Transaction::count(), 'A started payment is not on the ledger.');
    }

    public function test_an_apple_pay_slip_names_the_card_processor_not_the_office(): void
    {
        $user = User::factory()->create(['role' => 'Homeowner', 'status' => 'Active']);
        $invoice = $this->createInvoice($user);
        $initiate = fn () => $this->actingAs($user)->postJson(route('dashboard.billing.transactions.initiate'), [
            'channel' => 'apple_pay', 'amount' => 250, 'currency' => 'USD', 'invoice_id' => $invoice->id,
        ]);

        // No card processor: Apple Pay cannot be taken at all.
        config(['payments.card_provider' => null, 'services.stripe.secret' => null]);
        $initiate()->assertUnprocessable()->assertJsonValidationErrors('channel');

        // Through Stripe, the processor Apple Pay actually runs on.
        config(['services.stripe.secret' => 'sk_test_slip', 'payments.wallets' => ['apple_pay']]);
        $initiate()->assertOk()->assertJsonPath('transaction.provider', 'stripe');
    }

    public function test_the_initiate_endpoint_refuses_what_it_cannot_start(): void
    {
        $user = User::factory()->create(['role' => 'Homeowner', 'status' => 'Active']);
        $invoice = $this->createInvoice($user);
        $someoneElses = $this->createInvoice(User::factory()->create(), ['reference' => 'INV-OTHER']);

        $initiate = fn (array $data) => $this->actingAs($user)->postJson(route('dashboard.billing.transactions.initiate'), $data);

        $initiate(['channel' => 'not_a_channel', 'amount' => 10, 'invoice_id' => $invoice->id])->assertUnprocessable();
        $initiate(['channel' => 'bank_wire', 'amount' => 10, 'invoice_id' => $someoneElses->id])->assertUnprocessable();
        $initiate(['channel' => 'bank_wire', 'amount' => 999, 'invoice_id' => $invoice->id])->assertUnprocessable();
        $initiate(['channel' => 'bank_wire', 'amount' => 10, 'invoice_id' => $invoice->id, 'purpose' => 'Anything I like'])->assertUnprocessable();

        $this->assertSame(0, Payment::count());
    }
}
