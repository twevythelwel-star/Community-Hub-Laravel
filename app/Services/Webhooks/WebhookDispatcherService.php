<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Models\WebhookDeliveryLog;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebhookDispatcherService
{
    /**
     * Dispatch an event to all matching active webhook subscriptions.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, WebhookDeliveryLog>
     */
    public function dispatch(string $event, array $payload): array
    {
        $subscriptions = WebhookSubscription::where('is_active', true)->get();
        $logs = [];

        foreach ($subscriptions as $subscription) {
            if ($subscription->subscribesTo($event)) {
                $logs[] = $this->deliverToSubscription($subscription, $event, $payload);
            }
        }

        return $logs;
    }

    /**
     * Deliver a webhook event to a specific subscription.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatchDirect(
        WebhookSubscription $subscription,
        string $event,
        array $payload
    ): WebhookDeliveryLog {
        return $this->deliverToSubscription($subscription, $event, $payload);
    }

    public function deliverToSubscription(
        WebhookSubscription $subscription,
        string $event,
        array $payload
    ): WebhookDeliveryLog {
        $deliveryId = (string) Str::uuid();
        $timestamp = now()->toIso8601String();

        $envelope = [
            'event' => $event,
            'delivery_id' => $deliveryId,
            'timestamp' => $timestamp,
            'subscription_id' => $subscription->id,
            'data' => $payload,
        ];

        $jsonPayload = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $signature = hash_hmac('sha256', $jsonPayload, $subscription->secret);

        $startTime = microtime(true);
        $statusCode = null;
        $responseBody = null;
        $error = null;

        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'CommunityHub-Webhook/1.0',
                    'X-Webhook-Event' => $event,
                    'X-Webhook-Delivery-Id' => $deliveryId,
                    'X-Webhook-Timestamp' => $timestamp,
                    'X-Webhook-Signature-256' => "sha256={$signature}",
                ])
                ->post($subscription->url, $envelope);

            $statusCode = $response->status();
            $responseBody = substr($response->body(), 0, 1000);

            if ($response->successful()) {
                $subscription->update([
                    'failure_count' => 0,
                    'last_delivered_at' => now(),
                ]);
            } else {
                $subscription->increment('failure_count');
                $error = "HTTP {$statusCode}: {$responseBody}";
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $subscription->increment('failure_count');
            Log::warning("Webhook delivery to {$subscription->url} failed: {$error}");
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);
        $isSuccess = $statusCode !== null && $statusCode >= 200 && $statusCode < 300;

        return WebhookDeliveryLog::create([
            'webhook_subscription_id' => $subscription->id,
            'event' => $event,
            'url' => $subscription->url,
            'status_code' => $statusCode,
            'is_success' => $isSuccess,
            'request_payload' => $envelope,
            'response_body' => $responseBody,
            'duration_ms' => $durationMs,
            'error' => $error,
        ]);
    }

    /**
     * Verify an incoming webhook signature using HMAC-SHA256.
     */
    public static function verifySignature(string $rawPayload, string $headerSignature, string $secret): bool
    {
        $signature = $headerSignature;
        if (str_starts_with($headerSignature, 'sha256=')) {
            $signature = substr($headerSignature, 7);
        }

        $expected = hash_hmac('sha256', $rawPayload, $secret);

        return hash_equals($expected, $signature);
    }
}
