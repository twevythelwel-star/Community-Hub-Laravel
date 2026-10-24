<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Events\Billing\AssessmentInvoicesGeneratedEvent;
use App\Models\AutoPaySetting;
use App\Models\BillingSetting;
use App\Models\InAppNotification;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentLink;
use App\Models\User;
use App\Services\Billing\AssessmentBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AutomatedMonthlyAssessmentGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        BillingSetting::create([
            'monthly_fee_minor' => 500000, // 5,000.00 JMD
            'currency' => 'JMD',
            'due_day_of_month' => 5,
        ]);
    }

    public function test_dry_run_mode_simulates_without_database_mutations(): void
    {
        Event::fake([AssessmentInvoicesGeneratedEvent::class]);

        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'lot' => 'Lot 101',
        ]);

        $service = app(AssessmentBillingService::class);
        $result = $service->generateMonthlyAssessments(
            targetMonth: '2026-11',
            dryRun: true
        );

        $this->assertTrue($result->isDryRun);
        $this->assertEquals(1, $result->totalEligible);
        $this->assertEquals(1, $result->invoicesGenerated);
        $this->assertEquals(0, $result->invoicesSkipped);
        $this->assertEquals(500000, $result->totalBilledMinor);

        // Verify zero database records created
        $this->assertEquals(0, Invoice::count());
        $this->assertEquals(0, InvoiceItem::count());
        $this->assertEquals(0, PaymentLink::count());
        $this->assertEquals(0, InAppNotification::count());

        Event::assertNotDispatched(AssessmentInvoicesGeneratedEvent::class);
    }

    public function test_assessment_generation_creates_invoices_items_payment_links_and_notifications(): void
    {
        Event::fake([AssessmentInvoicesGeneratedEvent::class]);

        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'lot' => 'Lot 202',
        ]);

        $service = app(AssessmentBillingService::class);
        $result = $service->generateMonthlyAssessments(
            targetMonth: '2026-11',
            dryRun: false
        );

        $this->assertFalse($result->isDryRun);
        $this->assertEquals(1, $result->invoicesGenerated);
        $this->assertCount(1, $result->generatedInvoiceIds);

        // Assert Invoice
        $invoice = Invoice::first();
        $this->assertNotNull($invoice);
        $this->assertEquals($homeowner->id, $invoice->user_id);
        $this->assertEquals(500000, $invoice->amount_minor);
        $this->assertEquals('JMD', $invoice->currency);
        $this->assertEquals('Unpaid', $invoice->status);
        $this->assertEquals('2026-11-01', $invoice->period_start->toDateString());
        $this->assertEquals('2026-11-30', $invoice->period_end->toDateString());
        $this->assertEquals('2026-11-05', $invoice->due_on->toDateString());

        // Assert Invoice Item
        $item = InvoiceItem::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($item);
        $this->assertEquals('hoa_dues', $item->category);
        $this->assertEquals(500000, $item->amount_minor);
        $this->assertStringContainsString('November 2026', $item->title);

        // Assert PaymentLink
        $paymentLink = PaymentLink::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($paymentLink);
        $this->assertStringStartsWith('CH-', $paymentLink->token);
        $this->assertEquals(500000, $paymentLink->amount_minor);

        // Assert InAppNotification
        $notification = InAppNotification::where('user_id', $homeowner->id)->first();
        $this->assertNotNull($notification);
        $this->assertEquals('billing', $notification->category);
        $this->assertEquals('high', $notification->priority);
        $this->assertStringContainsString('5,000.00 JMD', $notification->body);

        Event::assertDispatched(AssessmentInvoicesGeneratedEvent::class);
    }

    public function test_deduplication_prevents_double_billing_unless_force_is_used(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'lot' => 'Lot 303',
        ]);

        $service = app(AssessmentBillingService::class);

        // Run 1: Should bill
        $result1 = $service->generateMonthlyAssessments('2026-11');
        $this->assertEquals(1, $result1->invoicesGenerated);
        $this->assertEquals(0, $result1->invoicesSkipped);
        $this->assertEquals(1, Invoice::count());

        // Run 2 (Same Month): Should skip
        $result2 = $service->generateMonthlyAssessments('2026-11');
        $this->assertEquals(0, $result2->invoicesGenerated);
        $this->assertEquals(1, $result2->invoicesSkipped);
        $this->assertEquals(1, Invoice::count());

        // Run 3 with force = true: Should create second invoice
        $result3 = $service->generateMonthlyAssessments('2026-11', force: true);
        $this->assertEquals(1, $result3->invoicesGenerated);
        $this->assertEquals(0, $result3->invoicesSkipped);
        $this->assertEquals(2, Invoice::count());
    }

    public function test_autopay_schedule_next_run_is_updated_for_enrolled_residents(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'lot' => 'Lot 404',
        ]);

        $autoPay = AutoPaySetting::create([
            'user_id' => $homeowner->id,
            'is_active' => true,
            'cadence' => 'monthly',
            'charge_day_of_month' => 5,
        ]);

        $service = app(AssessmentBillingService::class);
        $result = $service->generateMonthlyAssessments('2026-11');

        $this->assertEquals(1, $result->autoPayEnrolledCount);
        $autoPay->refresh();
        $this->assertEquals('2026-11-05', $autoPay->next_run_at?->toDateString());
    }

    public function test_artisan_command_executes_successfully(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'lot' => 'Lot 505',
        ]);

        $this->artisan('billing:generate-assessments', [
            '--month' => '2026-12',
            '--amount' => 750000, // 7,500.00 JMD override
            '--due-day' => 10,
        ])
            // Dues are per property, so the run counts properties, not people.
            ->expectsOutputToContain('Billable Properties')
            ->expectsOutputToContain('7,500.00 JMD')
            ->assertSuccessful();

        $invoice = Invoice::first();
        $this->assertNotNull($invoice);
        $this->assertEquals(750000, $invoice->amount_minor);
        $this->assertEquals('2026-12-10', $invoice->due_on->toDateString());
    }
}
