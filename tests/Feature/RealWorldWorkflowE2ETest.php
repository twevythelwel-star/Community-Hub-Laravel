<?php

namespace Tests\Feature;

use App\Enums\DenyReason;
use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Models\AccessLogEntry;
use App\Models\Donation;
use App\Models\Fundraiser;
use App\Models\GatePass;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use App\Services\GateScanner;
use App\Services\StripePaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-End Real-World Workflow Verification:
 *
 * 1. Gate Workflow:
 *    Create Pass -> Approve -> Issue -> Scan -> Check In -> Scan Again -> Check Out
 *
 * 2. Rejection Workflow:
 *    Expired QR -> Scan -> Reject -> Audit Log Entry
 *
 * 3. Replay Workflow:
 *    Valid QR -> Device A accepts -> Device B attempts same credential -> Reject -> Audit Log Entry
 *
 * 4. Billing Workflow:
 *    Invoice -> Checkout -> Provider -> Webhook -> Paid -> Receipt -> Reconciliation
 *
 * 5. Fundraising Workflow:
 *    Campaign -> Donation -> Payment -> Webhook -> Donation Recorded -> Receipt -> Campaign Total
 */
class RealWorldWorkflowE2ETest extends TestCase
{
    use RefreshDatabase;

    private const STRIPE_WEBHOOK_SECRET = 'whsec_e2e_real_world_testing';

    private GatePassEngine $engine;

    private GateScanner $scanner;

    private User $guard;

    private User $homeowner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.secret' => 'sk_test_e2e_mock',
            'services.stripe.webhook_secret' => self::STRIPE_WEBHOOK_SECRET,
        ]);

        $this->engine = app(GatePassEngine::class);
        $this->scanner = app(GateScanner::class);

        $this->guard = User::factory()->role(UserRole::Security)->create(['display_name' => 'Guard Officer Davis']);
        $this->homeowner = User::factory()->role(UserRole::Homeowner)->create(['lot' => '142', 'street' => 'Royal Palm Drive']);
        $this->admin = User::factory()->role(UserRole::Admin)->create(['display_name' => 'Admin Controller']);
    }

    // ─────────────────────────────────────────────────────────────────
    // 1. GATE WORKFLOW
    // Create Pass -> Approve -> Issue -> Scan -> Check In -> Scan Again -> Check Out
    // ─────────────────────────────────────────────────────────────────
    public function test_gate_workflow_full_lifecycle(): void
    {
        // 1. Create Pass: A homeowner registers a visitor
        $now = CarbonImmutable::parse('2026-10-07 10:00:00');
        $this->travelTo($now);

        $visitor = Visitor::factory()->create([
            'homeowner_id' => $this->homeowner->id,
            'name' => 'Dr. Robert Jenkins',
            'type' => 'One-time',
            'expected_at' => $now->addMinutes(30),
            'status' => VisitorStatus::Expected,
        ]);

        // A pass request is created in REQUESTED status
        $pass = GatePass::create([
            'pass_id' => 'GP-VIS-E2E-001',
            'visitor_id' => $visitor->id,
            'category' => PassCategory::Visitor,
            'holder_name' => $visitor->name,
            'property' => $this->homeowner->propertyLabel(),
            'access_zone' => 'ZONE-HOST-RESIDENCE',
            'designated_gate' => GateId::Gate01,
            'single_entry' => true,
            'status' => PassStatus::Requested,
            'rotation_seq' => 1,
            'valid_from' => $now->subMinutes(15),
            'valid_until' => $now->addHours(4),
        ]);

        $this->assertSame(PassStatus::Requested, $pass->status);

        // 2. Approve: Administrator or security approves the visitor pass
        $pass->transitionTo(PassStatus::Approved, actor: $this->admin, reason: 'Resident pre-clearance approved');
        $this->assertSame(PassStatus::Approved, $pass->fresh()->status);

        // 3. Issue: Digital Gate Pass Engine issues the active pass with dynamic HMAC credentials
        $pass->transitionTo(PassStatus::Issued, actor: $this->admin, reason: 'QR pass generated for resident distribution');
        $this->assertSame(PassStatus::Issued, $pass->fresh()->status);

        // 4. Scan: Visitor arrives at Gate 01 and guard scans the dynamic QR code
        $tokenData = $this->engine->issueToken($pass->fresh(), GateId::Gate01);
        $this->assertNotEmpty($tokenData['token']);

        $scanResult = $this->scanner->scan($tokenData['token'], GateId::Gate01, $this->guard);
        $this->assertSame('CHECK_IN', $scanResult['decision']);
        $this->assertNotNull($scanResult['scanId']);
        $this->assertTrue($scanResult['report']['checks']['cryptographicSignature']);
        $this->assertTrue($scanResult['report']['checks']['passRegistered']);

        // 5. Check In: Guard confirms physical arrival at Gate 01
        $confirmed = $this->scanner->confirm($scanResult['scanId'], $this->guard);
        $this->assertSame('CHECK_IN', $confirmed['action']);
        $this->assertSame(PassStatus::CheckedIn, $pass->fresh()->status);
        $this->assertSame(VisitorStatus::CheckedIn, $visitor->fresh()->status);
        $this->assertNotNull($visitor->fresh()->checked_in_at);

        // Advance to next time-window slot so the exit token receives a fresh rotation/nonce
        $this->travel((int) config('gatepass.window_seconds', 30) + 5)->seconds();

        // 6. Scan Again: Visitor departs the estate at Gate 01 and guard scans the QR code
        $exitTokenData = $this->engine->issueToken($pass->fresh(), GateId::Gate01);
        $exitScanResult = $this->scanner->scan($exitTokenData['token'], GateId::Gate01, $this->guard);
        $this->assertSame('CHECK_OUT', $exitScanResult['decision']);
        $this->assertNotNull($exitScanResult['scanId']);

        // 7. Check Out: Guard confirms departure
        $exitConfirmed = $this->scanner->confirm($exitScanResult['scanId'], $this->guard);
        $this->assertSame('CHECK_OUT', $exitConfirmed['action']);
        $this->assertSame(PassStatus::CheckedOut, $pass->fresh()->status);
        $this->assertSame(VisitorStatus::CheckedOut, $visitor->fresh()->status);
        $this->assertNotNull($visitor->fresh()->checked_out_at);

        // A single-entry pass cannot be reused to re-enter
        $this->travel((int) config('gatepass.window_seconds', 30) + 5)->seconds();
        $reEntryToken = $this->engine->issueToken($pass->fresh(), GateId::Gate01);
        $reEntryScan = $this->scanner->scan($reEntryToken['token'], GateId::Gate01, $this->guard);
        $this->assertSame('REJECT', $reEntryScan['decision']);
    }

    // ─────────────────────────────────────────────────────────────────
    // 2. REJECTION WORKFLOW
    // Expired QR -> Scan -> Reject -> Audit
    // ─────────────────────────────────────────────────────────────────
    public function test_rejection_workflow_expired_qr_and_audit(): void
    {
        $now = CarbonImmutable::parse('2026-10-07 16:00:00');
        $this->travelTo($now);

        // Create a visitor pass whose validity window expired 2 hours ago
        $visitor = Visitor::factory()->create([
            'homeowner_id' => $this->homeowner->id,
            'name' => 'Expired Guest Smith',
            'type' => 'One-time',
            'expected_at' => $now->subHours(3),
            'status' => VisitorStatus::Expected,
        ]);

        $pass = GatePass::create([
            'pass_id' => 'GP-VIS-EXPIRED-99',
            'visitor_id' => $visitor->id,
            'category' => PassCategory::Visitor,
            'holder_name' => $visitor->name,
            'property' => $this->homeowner->propertyLabel(),
            'access_zone' => 'ZONE-HOST-RESIDENCE',
            'designated_gate' => GateId::Gate01,
            'single_entry' => true,
            'status' => PassStatus::Issued,
            'rotation_seq' => 1,
            'valid_from' => $now->subHours(4),
            'valid_until' => $now->subHours(2), // Lapsed
        ]);

        // Guard attempts scan
        $tokenData = $this->engine->issueToken($pass, GateId::Gate01);
        $scanResult = $this->scanner->scan($tokenData['token'], GateId::Gate01, $this->guard);

        // Verify: REJECT decision and deny reason
        $this->assertSame('REJECT', $scanResult['decision']);
        $this->assertSame(DenyReason::OutsideValidity->value, $scanResult['report']['denyReason']);
        $this->assertNull($scanResult['scanId']);

        // Verify: Audit trail recorded in AccessLogEntry
        $logEntry = AccessLogEntry::where('pass_id', $pass->pass_id)->latest('id')->first();
        $this->assertNotNull($logEntry);
        $this->assertSame('DENY', $logEntry->result);
        $this->assertStringContainsString('OUTSIDE_PASS_VALIDITY', $logEntry->deny_reason);
        $this->assertSame($this->guard->id, $logEntry->scanned_by);
        $this->assertSame('Main Gate', $logEntry->gate);
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. REPLAY WORKFLOW
    // Valid QR -> Device A accepts -> Device B attempts same credential -> Reject -> Audit
    // ─────────────────────────────────────────────────────────────────
    public function test_replay_workflow_valid_qr_device_a_accepts_device_b_rejects_and_audits(): void
    {
        $now = CarbonImmutable::parse('2026-10-07 11:00:00');
        $this->travelTo($now);

        $visitor = Visitor::factory()->create([
            'homeowner_id' => $this->homeowner->id,
            'name' => 'Legitimate Guest Davis',
            'expected_at' => $now->addMinutes(15),
            'status' => VisitorStatus::Expected,
        ]);

        $pass = $this->engine->issueGuestPass($visitor, $this->homeowner);

        // Generate dynamic QR token
        $tokenData = $this->engine->issueToken($pass, GateId::Gate01);
        $rawQrToken = $tokenData['token'];

        // Guard A at Gate 01 scans credential
        $scanDeviceA = $this->scanner->scan($rawQrToken, GateId::Gate01, $this->guard);
        $this->assertSame('CHECK_IN', $scanDeviceA['decision']);
        $this->assertNotNull($scanDeviceA['scanId']);

        // Device B (or a duplicated screenshot at Gate 02) attempts to scan the EXACT SAME credential
        $guardB = User::factory()->role(UserRole::Security)->create(['display_name' => 'Guard Officer Peterson']);
        $scanDeviceB = $this->scanner->scan($rawQrToken, GateId::Gate02, $guardB);

        // Verify: Device B is rejected due to replay attack
        $this->assertSame('REJECT', $scanDeviceB['decision']);
        $this->assertSame(DenyReason::ReplayAttack->value, $scanDeviceB['report']['denyReason']);
        $this->assertNull($scanDeviceB['scanId']);
        $this->assertStringContainsString('Static screenshot duplicate detected', $scanDeviceB['report']['primaryReason']);

        // Verify: Security Audit trail captures both events and flags Device B replay
        $logs = AccessLogEntry::where('pass_id', $pass->pass_id)->orderBy('id')->get();
        $this->assertCount(2, $logs);

        // First scan was ALLOW at Main Gate
        $this->assertSame('ALLOW', $logs[0]->result);
        $this->assertSame('Main Gate', $logs[0]->gate);
        $this->assertSame($this->guard->id, $logs[0]->scanned_by);

        // Second scan was DENY with ReplayAttack at Service Gate
        $this->assertSame('DENY', $logs[1]->result);
        $this->assertSame('Service Gate', $logs[1]->gate);
        $this->assertStringContainsString('REPLAY_ATTACK', $logs[1]->deny_reason);
        $this->assertSame($guardB->id, $logs[1]->scanned_by);
    }

    // ─────────────────────────────────────────────────────────────────
    // 4. BILLING WORKFLOW
    // Invoice -> Checkout -> Provider -> Webhook -> Paid -> Receipt -> Reconciliation
    // ─────────────────────────────────────────────────────────────────
    public function test_billing_workflow_invoice_checkout_webhook_paid_receipt_reconciliation(): void
    {
        // 1. Invoice created for homeowner (Unpaid, $350.00 / 35,000 cents)
        $invoice = Invoice::create([
            'user_id' => $this->homeowner->id,
            'reference' => 'INV-E2E-2026-001',
            'amount_minor' => 35000,
            'currency' => 'USD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(10),
            'status' => 'Unpaid',
            'notes' => 'Q4 Maintenance and Security Assessment Fee',
        ]);

        $this->assertSame('Unpaid', $invoice->status);
        $this->assertNull($invoice->paid_at);

        // 2. Checkout & Payment Provider: Resident initiates checkout, completes payment
        $payload = json_encode([
            'id' => 'evt_e2e_checkout_success',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_e2e_'.$invoice->id,
                    'object' => 'checkout.session',
                    'client_reference_id' => (string) $invoice->id,
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_test_e2e_intent_'.$invoice->id,
                    'amount_total' => $invoice->amount_minor,
                    'currency' => 'usd',
                ],
            ],
        ]);

        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", self::STRIPE_WEBHOOK_SECRET);

        // 3. Webhook received with verified HMAC signature
        $response = $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload);

        $response->assertStatus(200);

        // 4. Paid: Invoice marked PAID in transaction ledger
        $invoice->refresh();
        $this->assertSame('Paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);

        // 5. Receipt & Transaction Ledger row created
        $transaction = Transaction::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($transaction);
        $this->assertSame(35000, $transaction->amount_minor);
        $this->assertSame('completed', $transaction->status);

        // 6. Reconciliation: System calculates zero variance
        $totalPaidInvoices = Invoice::where('status', 'Paid')->sum('amount_minor');
        $totalLedgerTransactions = Transaction::where('status', 'completed')->sum('amount_minor');
        $this->assertSame($totalPaidInvoices, $totalLedgerTransactions);
    }

    // ─────────────────────────────────────────────────────────────────
    // 5. FUNDRAISING WORKFLOW
    // Campaign -> Donation -> Payment -> Webhook -> Donation Recorded -> Receipt -> Campaign Total
    // ─────────────────────────────────────────────────────────────────
    public function test_fundraising_workflow_campaign_donation_webhook_recorded_receipt_total(): void
    {
        // 1. Campaign: Administrator creates a Community Solar Lighting campaign
        $fundraiser = Fundraiser::create([
            'title' => 'Cypress Bay Solar Lighting Initiative',
            'description' => 'Install solar LED floodlights along the northern jogging path.',
            'category' => 'Infrastructure',
            'beneficiary' => 'Community Safety Committee',
            'goal_minor' => 500000, // $5,000.00
            'goal_currency' => 'USD',
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => 'Active',
            'created_by' => $this->admin->id,
        ]);

        $this->assertSame(0, $fundraiser->raisedMinor());

        // 2. Donation: Resident gives $150.00 by card and is sent to Stripe
        //    Checkout. Only the session is stubbed; settlement below runs the
        //    real service. Nothing is recorded until Stripe confirms payment —
        //    the card driver used to record the gift right here, unpaid.
        $checkout = new class extends StripePaymentService
        {
            /** @var array<string, string> */
            public array $metadata = [];

            public function createDonationCheckoutSession($fundraiser, $donor, int $amountMinor, array $details, string $successUrl, string $cancelUrl): string
            {
                $this->metadata = [
                    'purpose' => 'donation',
                    'fundraiser_id' => (string) $fundraiser->id,
                    'user_id' => (string) $donor->id,
                    'donor_name' => $details['donor_name'],
                    'is_anonymous' => $details['is_anonymous'] ? '1' : '0',
                    'is_recurring' => '0',
                    'frequency' => '',
                ];

                return 'https://checkout.stripe.com/c/pay/cs_test_e2e_donation';
            }
        };
        $this->app->instance(StripePaymentService::class, $checkout);

        $this->actingAs($this->homeowner);

        $this->post(route('dashboard.fundraising.donate', $fundraiser->id), [
            'amount' => 150.00,
            'donor_name' => 'Thelwell Household',
            'is_anonymous' => false,
            'channel' => 'card',
        ])->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_e2e_donation');

        $this->assertSame(0, Donation::count());
        $this->assertSame(0, $fundraiser->fresh()->raisedMinor());

        // 3. Webhook: Stripe reports the paid session, twice, as it may.
        $payload = json_encode([
            'id' => 'evt_e2e_donation_paid',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_e2e_donation',
                    'object' => 'checkout.session',
                    'client_reference_id' => "donation-{$fundraiser->id}-{$this->homeowner->id}",
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_test_e2e_donation',
                    'amount_total' => 15000,
                    'currency' => 'usd',
                    'metadata' => $checkout->metadata,
                ],
            ],
        ]);

        foreach ([1, 2] as $attempt) {
            $timestamp = time();
            $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", self::STRIPE_WEBHOOK_SECRET);

            $this->call('POST', '/api/webhooks/stripe', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ], $payload)->assertOk();
        }

        // 4. Donation Recorded once, with a unique receipt number
        $this->assertSame(1, Donation::count());
        $this->assertSame(1, Transaction::where('reference', 'stripe:pi_test_e2e_donation')->count());

        $donation = Donation::where('fundraiser_id', $fundraiser->id)->latest('id')->first();
        $this->assertNotNull($donation);
        $this->assertSame(15000, $donation->amount_minor);
        $this->assertSame('completed', $donation->status);
        $this->assertStringStartsWith('DON-REC-', $donation->receipt_number);
        $this->assertSame($this->homeowner->id, $donation->user_id);
        $this->assertSame('Thelwell Household', $donation->donor_name);
        $this->assertSame('stripe_card', $donation->payment_channel);

        // 5. Receipt generated
        $receiptResponse = $this->get(route('dashboard.fundraising.donation.receipt', $donation->id));
        $receiptResponse->assertStatus(200);

        // 6. Campaign Total: Real-time net balance reflects contribution
        $fundraiser->refresh();
        $this->assertSame(15000, $fundraiser->raisedMinor());
        $this->assertSame(150.00, $fundraiser->raised());
        $this->assertSame(3.0, $fundraiser->progressPercent()); // 150 / 5000 = 3%
    }
}
