<?php

namespace Tests\Feature;

use App\Enums\PaymentState;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\NotificationDelivery;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\PaymentRefund;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Payments\RefundService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Enterprise Refund Lifecycle Test Suite
 *
 * Enforces the non-negotiable invariant:
 * "Do not mark the refund complete merely because CommunityHub sent a refund request."
 *
 * Full Lifecycle:
 *   Resident -> Refund request -> CommunityHub authorization -> Transaction Engine ->
 *   Provider refund -> Provider webhook -> Refund confirmed -> Ledger reversal ->
 *   Invoice/account adjustment -> Receipt/refund notice -> Twilio
 */
class EnterpriseRefundArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private function createPaidTransaction(User $resident, int $amountMinor = 50000): array
    {
        $invoice = Invoice::create([
            'user_id' => $resident->id,
            'reference' => 'INV-REF-'.fake()->unique()->numerify('#####'),
            'amount_minor' => $amountMinor,
            'currency' => 'JMD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(14),
            'status' => 'Paid',
            'paid_at' => now(),
        ]);

        $payment = Payment::create([
            'user_id' => $resident->id,
            'invoice_id' => $invoice->id,
            'applies_to' => 'invoice',
            'purpose' => Transaction::PURPOSE_MAINTENANCE_FEE,
            'transaction_id' => 'CH-2026-'.fake()->unique()->numerify('##########'),
            'idempotency_key' => 'idem_pay_'.uniqid(),
            'provider' => 'wipay',
            'provider_payment_id' => 'WPY-TX-'.uniqid(),
            'channel' => 'card',
            'amount_minor' => $amountMinor,
            'currency' => 'JMD',
            'state' => PaymentState::Paid,
            'paid_at' => now(),
        ]);

        $tx = Transaction::create([
            'payment_id' => $payment->id,
            'user_id' => $resident->id,
            'invoice_id' => $invoice->id,
            'transaction_id' => $payment->transaction_id,
            'purpose' => Transaction::PURPOSE_MAINTENANCE_FEE,
            'amount_minor' => $amountMinor,
            'currency' => 'JMD',
            'payment_channel' => 'card',
            'provider' => 'wipay',
            'provider_reference' => $payment->provider_payment_id,
            'reference' => 'wipay:'.$payment->provider_payment_id,
            'status' => Transaction::STATUS_COMPLETED,
            'settled_at' => now(),
        ]);

        // Post original balanced journal entries
        app(LedgerService::class)->postTransaction($tx);

        return [$invoice, $payment, $tx];
    }

    /**
     * Test 1: Refund Request and Authorization leave transaction and invoice intact.
     * Sending a refund request to provider NEVER marks the refund complete!
     */
    public function test_refund_request_and_authorization_do_not_mark_refund_complete(): void
    {
        $resident = User::factory()->create(['phone' => '+18765550199']);
        $admin = User::factory()->create();
        [$invoice, $payment, $tx] = $this->createPaidTransaction($resident, 50000);

        $refundService = app(RefundService::class);

        // ── STEP 1 & 2: Resident / Staff initiates refund request ──
        $refund = $refundService->requestRefund($tx, 50000, $resident, 'Duplicate assessment charge');

        $this->assertSame('pending', $refund->status);
        $this->assertNull($refund->refunded_at);
        $this->assertSame(1, PaymentRefund::count());

        // Invariants at Request stage:
        $this->assertSame('completed', $tx->fresh()->status, 'Transaction must remain completed!');
        $this->assertSame('Paid', $invoice->fresh()->status, 'Invoice must remain Paid!');
        $this->assertSame(2, LedgerEntry::count(), 'Zero ledger reversals posted!');
        $this->assertSame(0, NotificationDelivery::count(), 'Zero Twilio notifications sent!');

        // ── STEP 3, 4 & 5: Authorized staff approves and dispatches to Provider ──
        $refund = $refundService->authorizeAndDispatchToProvider($refund, $admin);

        $this->assertSame('processing', $refund->status);
        $this->assertNull($refund->provider_refund_id, 'WiPay has no refund API: no provider id exists until it confirms');
        $this->assertNull($refund->refunded_at);

        // NON-NEGOTIABLE INVARIANT AT AUTHORIZATION STAGE:
        // Do not mark the refund complete merely because CommunityHub sent a refund request!
        $this->assertSame('completed', $tx->fresh()->status, 'Transaction must NOT be marked refunded!');
        $this->assertSame('Paid', $invoice->fresh()->status, 'Invoice must NOT be adjusted yet!');
        $this->assertSame(2, LedgerEntry::count(), 'Double-entry ledger must NOT be reversed yet!');
        $this->assertSame(0, NotificationDelivery::count(), 'Twilio must NOT notify customer yet!');
    }

    /**
     * Test 2: Full End-to-End Lifecycle upon Provider Webhook Confirmation.
     * Succeeded refund reverses ledger, reopens invoice, generates credit note, and sends Twilio.
     */
    public function test_authoritative_provider_webhook_confirms_refund_and_executes_reversals(): void
    {
        $resident = User::factory()->create(['phone' => '+18765550199']);
        $admin = User::factory()->create();
        [$invoice, $payment, $tx] = $this->createPaidTransaction($resident, 50000);

        $refundService = app(RefundService::class);

        // Request & Authorize
        $refund = $refundService->requestRefund($tx, 50000, $resident, 'Service canceled');
        $refund = $refundService->authorizeAndDispatchToProvider($refund, $admin);

        config([
            'services.twilio.sid' => 'AC_test_account_sid',
            'services.twilio.token' => 'test_token',
            'services.twilio.from' => '+18765550000',
        ]);
        Http::fake(['https://api.twilio.com/*' => Http::response(['sid' => 'SM_refund_notice_1', 'status' => 'queued'], 201)]);

        // ── STEP 6 & 7: Provider Webhook delivers authoritative refund confirmation ──
        $confirmedRefund = $refundService->confirmRefundFromWebhook(
            provider: 'wipay',
            providerRefundId: 'WPY-RF-50000',
            amountMinor: 50000,
            currency: 'JMD',
            reason: 'Card network settled refund',
            metadata: ['transaction_id' => $tx->transaction_id],
        );

        $this->assertSame($refund->id, $confirmedRefund->id, 'the authorized refund is the one confirmed');
        $this->assertSame('WPY-RF-50000', $confirmedRefund->provider_refund_id);
        $this->assertSame('succeeded', $confirmedRefund->status);
        $this->assertNotNull($confirmedRefund->refunded_at);

        // ── STEP 8: Double-Entry Ledger Reversal Executed ──
        // Original posting had 2 rows (DR 1010 + CR 4010). Reversal adds 2 mirrored rows!
        $allLedgerRows = LedgerEntry::all();
        $this->assertSame(4, $allLedgerRows->count(), 'Ledger reversal must add exactly 2 balancing entries!');
        $reversalRows = LedgerEntry::where('transaction_id', '!=', $tx->id)->get();
        $this->assertSame(2, $reversalRows->count());

        // Net general ledger balance must be zero:
        $totalDebit = $allLedgerRows->where('entry_type', 'debit')->sum('amount_minor');
        $totalCredit = $allLedgerRows->where('entry_type', 'credit')->sum('amount_minor');
        $this->assertEquals(100000, $totalDebit);
        $this->assertEquals(100000, $totalCredit);

        // ── STEP 9: Invoice Reopened / Adjusted ──
        $invoice->refresh();
        $this->assertSame('Unpaid', $invoice->status, 'Fully refunded invoice must return to Unpaid!');
        $this->assertNull($invoice->paid_at);

        // ── STEP 10: Formal Refund Notice / Credit Receipt Generated ──
        $receipt = PaymentReceipt::where('receipt_number', 'like', 'RN-%')->first();
        $this->assertNotNull($receipt, 'Refund Notice receipt must be generated!');
        $this->assertEquals(50000, $receipt->amount_minor);
        $this->assertSame('JMD', $receipt->currency);

        // ── STEP 11: Twilio Customer Notification Dispatched ──
        $delivery = NotificationDelivery::where('channel', 'sms')->first();
        $this->assertNotNull($delivery, 'Twilio SMS notification must be queued!');
        $this->assertSame('+18765550199', $delivery->recipient);
        $this->assertSame('twilio', $delivery->provider);
        $this->assertSame('SM_refund_notice_1', $delivery->provider_message_id, "Twilio's own SID, not a made-up one");
        $this->assertSame('queued', $delivery->status);
    }

    public function test_no_delivery_is_recorded_when_sms_is_not_configured(): void
    {
        config(['services.twilio.sid' => null, 'services.twilio.token' => null, 'services.twilio.from' => null]);

        $resident = User::factory()->create(['phone' => '+18765550199']);
        [, , $tx] = $this->createPaidTransaction($resident, 50000);

        app(RefundService::class)->confirmRefundFromWebhook(
            provider: 'wipay',
            providerRefundId: 'WPY-RF-NOSMS',
            amountMinor: 50000,
            currency: 'JMD',
            metadata: ['transaction_id' => $tx->transaction_id],
        );

        $this->assertSame(0, NotificationDelivery::count());
    }

    public function test_a_refund_cannot_be_authorized_by_its_requester(): void
    {
        $admin = User::factory()->create();
        [, , $tx] = $this->createPaidTransaction(User::factory()->create(), 50000);

        $refundService = app(RefundService::class);
        $refund = $refundService->requestRefund($tx, 50000, $admin);

        $this->expectException(DomainException::class);
        $refundService->authorizeAndDispatchToProvider($refund, $admin);
    }

    public function test_stripe_payments_are_not_refunded_through_this_engine(): void
    {
        $resident = User::factory()->create();
        [, , $tx] = $this->createPaidTransaction($resident, 50000);
        $tx->update(['provider' => 'stripe']);

        $refundService = app(RefundService::class);
        $refund = $refundService->requestRefund($tx, 50000, $resident);

        try {
            $refundService->authorizeAndDispatchToProvider($refund, User::factory()->create());
            $this->fail('A Stripe refund must go through the Stripe refund action.');
        } catch (DomainException) {
            $this->assertSame('pending', $refund->fresh()->status, 'nothing changed');
        }
    }

    public function test_a_provider_refund_without_an_identifiable_payment_is_refused(): void
    {
        $resident = User::factory()->create();
        $this->createPaidTransaction($resident, 50000);

        $this->expectException(DomainException::class);

        // Used to fall back to "the provider's latest payment" and reverse it.
        app(RefundService::class)->confirmRefundFromWebhook(
            provider: 'wipay',
            providerRefundId: 'WPY-RF-UNKNOWN',
            amountMinor: 50000,
            currency: 'JMD',
        );
    }

    public function test_a_provider_refund_larger_than_the_refundable_balance_is_refused(): void
    {
        $resident = User::factory()->create();
        [, , $tx] = $this->createPaidTransaction($resident, 50000);

        $this->expectException(DomainException::class);

        app(RefundService::class)->confirmRefundFromWebhook(
            provider: 'wipay',
            providerRefundId: 'WPY-RF-TOO-BIG',
            amountMinor: 60000,
            currency: 'JMD',
            metadata: ['transaction_id' => $tx->transaction_id],
        );
    }

    /**
     * Test 3: Partial Refund Adjusts Invoice to Partially Paid without premature closure.
     */
    public function test_partial_refund_adjusts_invoice_to_partially_paid(): void
    {
        $resident = User::factory()->create();
        $admin = User::factory()->create();
        [$invoice, $payment, $tx] = $this->createPaidTransaction($resident, 60000);

        $refundService = app(RefundService::class);

        // Partial refund of 20,000 out of 60,000
        $refund = $refundService->requestRefund($tx, 20000, $resident, 'Partial fee adjustment');
        $refund = $refundService->authorizeAndDispatchToProvider($refund, $admin);

        $refundService->confirmRefundFromWebhook(
            provider: 'wipay',
            providerRefundId: 'WPY-RF-20000',
            amountMinor: 20000,
            currency: 'JMD',
            metadata: ['transaction_id' => $tx->transaction_id],
        );

        // Invoice status must be Partially Paid because 40,000 remains settled!
        $invoice->refresh();
        $this->assertSame('Partially Paid', $invoice->status);

        // Remaining refundable is now 40,000
        $this->assertEquals(40000, $refundService->refundableMinor($tx));
    }

    /**
     * Test 4: Cannot request refund exceeding refundable balance.
     */
    public function test_cannot_request_refund_exceeding_refundable_balance(): void
    {
        $resident = User::factory()->create();
        [$invoice, $payment, $tx] = $this->createPaidTransaction($resident, 10000);

        $refundService = app(RefundService::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Refund amount must be between 0.01 and 100.00 JMD.');

        // Attempt to refund 15,000 on a 10,000 transaction:
        $refundService->requestRefund($tx, 15000, $resident);
    }
}
