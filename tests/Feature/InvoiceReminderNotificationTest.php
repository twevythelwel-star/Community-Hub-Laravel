<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SendInvoicePaymentReminderJob;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\NotificationEngine\ChannelDispatchResult;
use App\Services\NotificationEngine\NotificationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InvoiceReminderNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function resident(): User
    {
        return User::factory()->role(UserRole::Homeowner)->create([
            'email' => 'homeowner@example.com',
            'phone' => '+18765550199',
            'lot' => '42',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->role(UserRole::Admin)->create();
    }

    private function invoice(User $user, array $attributes = []): Invoice
    {
        return Invoice::create(array_merge([
            'user_id' => $user->id,
            'reference' => 'INV-TEST-'.rand(1000, 9999),
            'amount_minor' => 1500000, // 15,000 JMD
            'currency' => 'JMD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->subDays(5),
            'status' => 'Overdue',
        ], $attributes));
    }

    public function test_administrator_can_dispatch_invoice_payment_reminder(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $resident = $this->resident();
        $invoice = $this->invoice($resident);

        $response = $this->actingAs($admin)
            ->post("/dashboard/billing/invoices/{$invoice->id}/remind");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Queue::assertPushed(SendInvoicePaymentReminderJob::class, function ($job) use ($invoice) {
            return $job->invoice->id === $invoice->id;
        });

        $this->assertDatabaseHas('activity_log_entries', [
            'user_id' => $admin->id,
            'action' => "Dispatched payment reminder for invoice {$invoice->reference}",
        ]);
    }

    public function test_non_admin_homeowner_cannot_dispatch_reminders(): void
    {
        Queue::fake();

        $resident = $this->resident();
        $invoice = $this->invoice($resident);

        $response = $this->actingAs($resident)
            ->post("/dashboard/billing/invoices/{$invoice->id}/remind");

        $response->assertForbidden();
        Queue::assertNotPushed(SendInvoicePaymentReminderJob::class);
    }

    public function test_cannot_remind_an_already_paid_invoice(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $resident = $this->resident();
        $paidInvoice = $this->invoice($resident, ['status' => 'Paid', 'paid_at' => now()]);

        $response = $this->actingAs($admin)
            ->post("/dashboard/billing/invoices/{$paidInvoice->id}/remind");

        $response->assertRedirect();
        $response->assertSessionHas('error');
        Queue::assertNotPushed(SendInvoicePaymentReminderJob::class);
    }

    public function test_reminder_job_dispatches_notifications_and_records_delivery_status(): void
    {
        $resident = $this->resident();
        $invoice = $this->invoice($resident);

        $mockEngine = $this->createMock(NotificationEngine::class);
        $mockEngine->expects($this->any())
            ->method('send')
            ->willReturnCallback(function (array $channels) {
                $results = [];
                foreach ($channels as $ch) {
                    $results[$ch] = match ($ch) {
                        'email' => ChannelDispatchResult::delivered('email', 'MSG-EMAIL-123'),
                        'sms' => ChannelDispatchResult::sent('sms', 'SM-TWILIO-456'),
                        'in_app' => ChannelDispatchResult::delivered('in_app', 'NOTIF-INAPP-789'),
                        default => ChannelDispatchResult::sent($ch),
                    };
                }

                return $results;
            });

        $job = new SendInvoicePaymentReminderJob($invoice, ['email', 'sms', 'in_app']);
        $job->handle($mockEngine);

        // Assert delivery records were created for the channels
        $this->assertDatabaseHas('notification_deliveries', [
            'invoice_id' => $invoice->id,
            'user_id' => $resident->id,
            'channel' => 'email',
            'status' => 'delivered',
            'provider_message_id' => 'MSG-EMAIL-123',
        ]);

        $this->assertDatabaseHas('notification_deliveries', [
            'invoice_id' => $invoice->id,
            'user_id' => $resident->id,
            'channel' => 'sms',
            'status' => 'sent',
            'provider_message_id' => 'SM-TWILIO-456',
        ]);

        // Assert invoice tracking fields updated
        $freshInvoice = $invoice->fresh();
        $this->assertNotNull($freshInvoice->last_reminded_at);
        $this->assertSame(1, $freshInvoice->reminder_count);
    }

    public function test_reminder_job_records_failure_and_handles_retries(): void
    {
        $resident = $this->resident();
        $invoice = $this->invoice($resident);

        $mockEngine = $this->createMock(NotificationEngine::class);
        $mockEngine->expects($this->once())
            ->method('send')
            ->willThrowException(new \RuntimeException('Twilio network timeout'));

        $job = new SendInvoicePaymentReminderJob($invoice, ['sms']);

        try {
            $job->handle($mockEngine);
        } catch (\RuntimeException $e) {
            // Expected initial retry rethrow
        }

        $this->assertDatabaseHas('notification_deliveries', [
            'invoice_id' => $invoice->id,
            'user_id' => $resident->id,
            'channel' => 'sms',
            'status' => 'failed',
            'error_message' => 'Twilio network timeout',
        ]);
    }
}
