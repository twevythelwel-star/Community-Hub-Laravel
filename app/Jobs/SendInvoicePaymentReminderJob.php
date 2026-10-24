<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\NotificationEngine\NotificationEngine;
use App\Services\NotificationEngine\NotificationPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendInvoicePaymentReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int> */
    public array $backoff = [5, 30, 120];

    /**
     * @param  array<string>  $channels
     */
    public function __construct(
        public Invoice $invoice,
        public array $channels = ['email', 'sms', 'in_app']
    ) {}

    public function handle(NotificationEngine $engine): void
    {
        $invoice = $this->invoice->fresh(['user', 'property']);
        if (! $invoice || ! in_array($invoice->status, ['Unpaid', 'Overdue', 'Partially Paid'])) {
            Log::info("[SendInvoicePaymentReminderJob] Skipping invoice #{$this->invoice->id}: not outstanding or not found.");

            return;
        }

        $user = $invoice->user;
        if (! $user) {
            Log::warning("[SendInvoicePaymentReminderJob] Invoice #{$invoice->id} has no associated user.");

            return;
        }

        $homeownerName = $user->display_name ?: $user->name;
        $lot = $invoice->property?->lot_number ?? $user->lot ?? 'N/A';
        $dueFormatted = $invoice->due_on ? $invoice->due_on->format('M d, Y') : 'Immediately';
        $amountFormatted = number_format($invoice->balanceRemainingMinor() / 100, 2).' '.($invoice->currency ?? 'JMD');

        // Reuse active payment link or generate a secure new one
        $paymentLink = $invoice->paymentLinks()
            ->where('active', true)
            ->where('expires_at', '>', now())
            ->latest()
            ->first() ?? $invoice->generatePaymentLink();

        $paymentUrl = url("/pay/{$paymentLink->token}");

        $title = "Payment Reminder: Statement #{$invoice->reference}";
        $body = "Dear {$homeownerName}, your assessment balance of {$amountFormatted} for Lot {$lot} (Ref: {$invoice->reference}) was due on {$dueFormatted}. Pay securely online: {$paymentUrl}";

        $deliveries = [];

        foreach ($this->channels as $channelKey) {
            $channelKey = strtolower(trim($channelKey));
            $recipient = match ($channelKey) {
                'email' => $user->email,
                'sms', 'whatsapp' => $user->phone,
                default => (string) $user->id,
            };

            if (! $recipient && $channelKey !== 'in_app') {
                continue;
            }

            $payload = new NotificationPayload(
                title: $title,
                body: $body,
                recipient: $recipient,
                metadata: [
                    'invoice_id' => $invoice->id,
                    'invoice_reference' => $invoice->reference,
                    'amount_minor' => $invoice->balanceRemainingMinor(),
                    'type' => 'billing_reminder',
                    'url' => $paymentUrl,
                ]
            );

            try {
                $results = $engine->send([$channelKey], $payload);
                $result = $results[$channelKey] ?? null;

                $status = $result?->status ?? 'sent';
                $deliveries[] = NotificationDelivery::create([
                    'invoice_id' => $invoice->id,
                    'user_id' => $user->id,
                    'channel' => $channelKey,
                    'recipient' => (string) ($recipient ?: $user->id),
                    'provider' => $channelKey === 'email' ? 'smtp' : ($channelKey === 'sms' ? 'twilio' : 'internal'),
                    'provider_message_id' => $result?->reference,
                    'status' => in_array($status, ['sent', 'delivered']) ? $status : ($status === 'unavailable' ? 'queued' : 'failed'),
                    'error_message' => $result?->reason,
                    'sent_at' => now(),
                    'delivered_at' => $status === 'delivered' ? now() : null,
                ]);
            } catch (Throwable $e) {
                Log::error("[SendInvoicePaymentReminderJob] Channel {$channelKey} dispatch failed for invoice #{$invoice->id}: {$e->getMessage()}");

                $deliveries[] = NotificationDelivery::create([
                    'invoice_id' => $invoice->id,
                    'user_id' => $user->id,
                    'channel' => $channelKey,
                    'recipient' => (string) ($recipient ?: $user->id),
                    'provider' => $channelKey,
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'sent_at' => now(),
                ]);

                // If on initial attempts, rethrow to allow queue retry
                if ($this->attempts() < $this->tries) {
                    throw $e;
                }
            }
        }

        $invoice->update([
            'last_reminded_at' => now(),
            'reminder_count' => ($invoice->reminder_count ?? 0) + 1,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error("[SendInvoicePaymentReminderJob] Final retry exhausted for invoice #{$this->invoice->id}: {$exception->getMessage()}");

        NotificationDelivery::create([
            'invoice_id' => $this->invoice->id,
            'user_id' => $this->invoice->user_id,
            'channel' => 'system',
            'recipient' => (string) ($this->invoice->user?->email ?: $this->invoice->user_id),
            'provider' => 'queue',
            'status' => 'failed',
            'error_message' => "Final attempt failed: {$exception->getMessage()}",
            'sent_at' => now(),
        ]);
    }
}
