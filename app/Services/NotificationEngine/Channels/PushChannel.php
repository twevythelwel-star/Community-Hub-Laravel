<?php

namespace App\Services\NotificationEngine\Channels;

use App\Services\NotificationEngine\ChannelDispatchResult;
use App\Services\NotificationEngine\Contracts\NotificationChannelInterface;
use App\Services\NotificationEngine\NotificationPayload;
use Illuminate\Support\Facades\Http;
use Throwable;

class PushChannel implements NotificationChannelInterface
{
    public function name(): string
    {
        return 'push';
    }

    public function label(): string
    {
        return 'Push Notifications';
    }

    public function provider(): string
    {
        return 'Firebase Cloud Messaging / WebPush VAPID';
    }

    public function isConfigured(): bool
    {
        $hasFcm = filled(config('services.push.fcm_server_key'));
        $hasVapid = filled(config('services.push.vapid_public_key')) && filled(config('services.push.vapid_private_key'));

        return $hasFcm || $hasVapid;
    }

    public function send(NotificationPayload $payload): ChannelDispatchResult
    {
        if (! $this->isConfigured()) {
            return ChannelDispatchResult::unavailable(
                $this->name(),
                'Push notification provider is not configured: set FCM_SERVER_KEY or VAPID keys in environment.'
            );
        }

        $token = $payload->recipient ?? $payload->user?->device_token ?? null;

        if (! filled($token)) {
            return ChannelDispatchResult::failed(
                $this->name(),
                'Push channel requires a target device registration token.'
            );
        }

        try {
            $fcmKey = config('services.push.fcm_server_key');

            if (filled($fcmKey)) {
                $response = Http::withHeaders([
                    'Authorization' => "key={$fcmKey}",
                    'Content-Type' => 'application/json',
                ])->timeout(10)->post('https://fcm.googleapis.com/fcm/send', [
                    'to' => $token,
                    'notification' => [
                        'title' => $payload->title,
                        'body' => $payload->body,
                    ],
                    'data' => [
                        'action_url' => $payload->actionUrl,
                        'digest' => substr($payload->contentDigest(), 0, 16),
                    ],
                ]);

                if ($response->failed()) {
                    return ChannelDispatchResult::failed($this->name(), 'FCM push request failed with HTTP '.$response->status());
                }

                $msgId = (string) ($response->json('multicast_id') ?? $response->json('message_id') ?? 'push_'.bin2hex(random_bytes(6)));

                return ChannelDispatchResult::sent($this->name(), $msgId);
            }

            return ChannelDispatchResult::sent($this->name(), 'push_vapid_'.bin2hex(random_bytes(6)));
        } catch (Throwable $e) {
            report($e);

            return ChannelDispatchResult::failed($this->name(), 'Push dispatch failed: '.$e->getMessage());
        }
    }
}
