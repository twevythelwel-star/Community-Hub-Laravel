<?php

namespace Tests\Feature;

use App\Enums\PaymentState;
use App\Models\BankReconciliation;
use App\Models\Community;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePaymentLinkArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['payments.public_links_enabled' => true]);

        Community::create([
            'name' => 'Cypress Bay Estates',
            'code' => 'CYPRESS',
            'slug' => 'cypress-bay',
            'contact_email' => 'admin@cypressbay.org',
            'currency' => 'JMD',
        ]);
    }

    private function createInvoice(User $resident, string $reference = 'INV-2026-00482'): Invoice
    {
        $invoice = Invoice::create([
            'user_id' => $resident->id,
            'reference' => $reference,
            'amount_minor' => 7500000, // $75,000.00 JMD
            'currency' => 'JMD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(7),
            'status' => 'Unpaid',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'category' => 'hoa_dues',
            'title' => 'Monthly Maintenance Dues',
            'amount_minor' => 7500000,
            'status' => 'Unpaid',
        ]);

        return $invoice;
    }

    public function test_invoice_generates_payment_link_with_opaque_token_and_no_sensitive_data(): void
    {
        $resident = User::factory()->create(['name' => 'Michael Sterling', 'lot' => 'Lot 42']);
        $invoice = $this->createInvoice($resident);

        $link = $invoice->generatePaymentLink();

        // Token must be opaque (random alphanumeric/hex), not containing raw IDs or balances
        $this->assertNotEmpty($link->token);
        $this->assertStringStartsWith('CH-', $link->token);
        $this->assertStringNotContainsString($invoice->reference, $link->token);
        $this->assertStringNotContainsString('75000', $link->token);

        // Model attributes verify internal mapping
        $this->assertEquals($invoice->id, $link->invoice_id);
        $this->assertEquals($resident->id, $link->user_id);
        $this->assertEquals(7500000, $link->amount_minor);
        $this->assertEquals('JMD', $link->currency);
        $this->assertTrue($link->active);

        // Public URL contains ONLY the opaque token
        $publicUrl = $link->publicUrl();
        $this->assertStringContainsString('/pay/'.$link->token, $publicUrl);
        $this->assertStringNotContainsString('invoice_id', $publicUrl);
        $this->assertStringNotContainsString('amount', $publicUrl);
    }

    public function test_resident_opens_opaque_link_via_short_or_full_url(): void
    {
        $resident = User::factory()->create(['name' => 'Michael Sterling', 'lot' => 'Lot 42']);
        $invoice = $this->createInvoice($resident);

        $link = $invoice->generatePaymentLink();

        // 1. Test full URL /pay/{token}
        $response = $this->get('/pay/'.$link->token);
        $response->assertStatus(200);
        $response->assertSee('INV-2026-00482');
        $response->assertSee('75,000.00');

        // 2. Test short URL /p/{token}
        $shortResponse = $this->get('/p/'.$link->token);
        $shortResponse->assertStatus(200);
        $shortResponse->assertSee('INV-2026-00482');
    }

    public function test_when_invoice_is_paid_payment_link_displays_settled_receipt(): void
    {
        $resident = User::factory()->create(['name' => 'Michael Sterling', 'lot' => 'Lot 42']);
        $invoice = $this->createInvoice($resident);

        $link = $invoice->generatePaymentLink();

        // Mark invoice paid
        $invoice->markPaid();

        $response = $this->get('/p/'.$link->token);
        $response->assertStatus(200);
        $response->assertSee('Invoice Settled');
        $response->assertSee('Zero Balance Due');
        $response->assertDontSee('Fast 1-Tap Checkout');
    }

    public function test_processing_payment_link_associates_invoice_and_creates_real_transaction_and_payment(): void
    {
        $resident = User::factory()->create(['name' => 'Michael Sterling', 'lot' => 'Lot 42']);
        $invoice = $this->createInvoice($resident);

        $link = $invoice->generatePaymentLink();

        // Payer submits bank wire transfer via the public link
        $response = $this->post('/pay/'.$link->token.'/process', [
            'channel' => 'bank_wire',
            'payer_name' => 'Michael Sterling',
            'lot' => 'Lot 42',
        ]);

        $response->assertSessionHas('status');

        // Verify Payment was created with invoice association and awaiting transfer state
        $payment = Payment::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals(7500000, $payment->amount_minor);
        $this->assertEquals('invoice', $payment->applies_to);
        $this->assertEquals('bank_wire', $payment->channel);
        $this->assertEquals(PaymentState::AwaitingTransfer, $payment->state);
        $this->assertStringStartsWith('CH-', $payment->transaction_id);

        // Office verifies payment with dual-signoff
        $admin1 = User::factory()->create(['role' => 'Admin']);
        $admin2 = User::factory()->create(['role' => 'Admin']);
        $orchestrator = app(PaymentOrchestratorService::class);

        $orchestrator->markReceived($payment, $admin1, 'NCB-WIRE-9921', 'Bank transfer sighted on statement');
        $this->assertEquals(PaymentState::Received, $payment->fresh()->state);

        $bankRecon = BankReconciliation::create([
            'bank_statement_date' => now()->toDateString(),
            'statement_balance_minor' => 17500000,
            'ledger_balance_minor' => 17500000,
            'difference_minor' => 0,
            'status' => 'completed',
            'reconciled_by' => $admin2->id,
            'notes' => 'Matched NCB wire',
        ]);

        $orchestrator->verifyReceived($payment, $admin2, $bankRecon);
        $this->assertEquals(PaymentState::Paid, $payment->fresh()->state);

        // Transaction record now exists and invoice is marked Paid
        $tx = Transaction::where('transaction_id', $payment->transaction_id)->first();
        $this->assertNotNull($tx);
        $this->assertEquals($invoice->id, $tx->invoice_id);
        $this->assertEquals(7500000, $tx->amount_minor);
        $this->assertEquals('Paid', $invoice->fresh()->status);
    }
}
