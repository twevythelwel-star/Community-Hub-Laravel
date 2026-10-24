<?php

namespace App\Services\NotificationEngine;

use App\Models\Visitor;
use App\Services\NotificationEngine\Channels\EmailChannel;
use App\Services\NotificationEngine\Channels\InAppChannel;
use App\Services\NotificationEngine\Channels\PushChannel;
use App\Services\NotificationEngine\Channels\SmsChannel;
use App\Services\NotificationEngine\Channels\WhatsAppChannel;
use App\Services\NotificationEngine\Contracts\NotificationChannelInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Enterprise Multi-Channel Notification Engine.
 *
 * Guarantees that:
 *  1. Any unconfigured provider returns 'unavailable' or 'failed', never a false 'sent'.
 *  2. Logs strictly mask contact info (+1******1234, j***e@domain.com) and NEVER
 *     log raw phone numbers or sensitive message text.
 *  3. Centralized status reports across Email, SMS, WhatsApp, Push, and In-App.
 */
class NotificationEngine
{
    /** @var array<string, NotificationChannelInterface> */
    protected array $channels = [];

    public function __construct(
        EmailChannel $email,
        SmsChannel $sms,
        WhatsAppChannel $whatsApp,
        PushChannel $push,
        InAppChannel $inApp,
    ) {
        $this->channels = [
            'email' => $email,
            'sms' => $sms,
            'whatsapp' => $whatsApp,
            'push' => $push,
            'in_app' => $inApp,
        ];
    }

    /**
     * Retrieve a registered channel instance by key.
     */
    public function channel(string $name): NotificationChannelInterface
    {
        $key = strtolower($name);

        if (! isset($this->channels[$key])) {
            throw new InvalidArgumentException("Notification channel [{$name}] is not supported.");
        }

        return $this->channels[$key];
    }

    /**
     * Check whether a specific channel's external provider is configured and ready.
     */
    public function isChannelConfigured(string $name): bool
    {
        $key = strtolower($name);

        return isset($this->channels[$key]) && $this->channels[$key]->isConfigured();
    }

    /**
     * Comprehensive health and configuration report across all 5 channels.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getChannelsStatus(): array
    {
        $report = [];

        foreach ($this->channels as $key => $channel) {
            $isConfigured = $channel->isConfigured();
            $report[$key] = [
                'channel' => $key,
                'label' => $channel->label(),
                'provider' => $channel->provider(),
                'configured' => $isConfigured,
                'status' => $isConfigured ? 'available' : 'unavailable',
            ];
        }

        return $report;
    }

    /**
     * Dispatch a notification payload to one or more channels.
     *
     * @param  array<string>  $channelKeys
     * @return array<string, ChannelDispatchResult>
     */
    public function send(array $channelKeys, NotificationPayload $payload): array
    {
        $results = [];

        foreach ($channelKeys as $key) {
            $key = strtolower(trim($key));

            if (! isset($this->channels[$key])) {
                $results[$key] = ChannelDispatchResult::failed($key, "Unknown notification channel [{$key}].");

                continue;
            }

            $channel = $this->channels[$key];

            if (! $channel->isConfigured()) {
                $result = ChannelDispatchResult::unavailable(
                    $key,
                    "Channel [{$channel->label()}] provider is not configured."
                );
            } else {
                $result = $channel->send($payload);
            }

            $results[$key] = $result;
            $this->auditLog($key, $result->status, $payload->recipient, $payload, $result->reason);
        }

        return $results;
    }

    /**
     * Dispatch a visitor pass across requested channels with strict privacy logging.
     *
     * @param  array<string>  $channels
     * @return array<string, ChannelDispatchResult>
     */
    public function sendVisitorPass(Visitor $visitor, array $channels = ['email'], ?string $qrCodePath = null): array
    {
        $expectedAtFormatted = $visitor->expected_at ? $visitor->expected_at->format('l, F j, Y \a\t g:i A') : 'N/A';
        $mediaUrl = $qrCodePath ? Storage::disk('public')->url($qrCodePath) : null;

        $body = "Hello {$visitor->name}, you have been registered as a visitor to the community. "
            ."Your guest pass is available at: {$visitor->guestPassUrl()}. "
            ."Expected arrival: {$expectedAtFormatted}. Host: {$visitor->homeowner_name}";

        $payload = new NotificationPayload(
            title: 'Community Hub Guest Pass',
            body: $body,
            recipient: (string) $visitor->contact,
            visitor: $visitor,
            actionUrl: $visitor->guestPassUrl(),
            mediaUrl: $mediaUrl,
            templateVariables: [
                '1' => $visitor->name,
                '2' => $visitor->guestPassUrl(),
                '3' => $expectedAtFormatted,
                '4' => (string) $visitor->homeowner_name,
            ],
            metadata: [
                'visitor_id' => $visitor->id,
                'pass_type' => 'visitor_pass',
            ]
        );

        return $this->send($channels, $payload);
    }

    /**
     * Dispatch an arrival alert to the resident when a visitor is checked in.
     */
    public function dispatchArrivalNotice(Visitor $visitor, string $gate = 'Main Gate'): array
    {
        $homeowner = $visitor->homeowner;
        $title = "Visitor Arrived: {$visitor->name}";
        $vehicleInfo = $visitor->vehicle ? " (Vehicle: {$visitor->vehicle})" : '';
        $body = "{$visitor->name} has arrived and checked in at {$gate}{$vehicleInfo}.";

        $channels = ['in_app'];
        $recipient = null;

        if ($visitor->notify_sms && $homeowner?->phone) {
            $channels[] = 'sms';
            $recipient = $homeowner->phone;
        }

        if ($visitor->notify_whatsapp && $homeowner?->phone) {
            $channels[] = 'whatsapp';
            $recipient = $homeowner->phone;
        }

        if ($visitor->notify_email && $homeowner?->email) {
            $channels[] = 'email';
            $recipient = $homeowner->email;
        }

        $payload = new NotificationPayload(
            title: $title,
            body: $body,
            recipient: $recipient,
            metadata: [
                'visitor_id' => $visitor->id,
                'gate' => $gate,
                'event' => 'visitor_checked_in',
            ]
        );

        return $this->send($channels, $payload);
    }

    /**
     * Broadcast an in-app or multi-channel notice to residents.
     *
     * @param  array<string>|null  $targetRoles
     * @param  array<string>  $channels
     * @return array<string, ChannelDispatchResult>
     */
    public function broadcast(string $title, string $message, ?array $targetRoles = null, array $channels = ['in_app']): array
    {
        $payload = new NotificationPayload(
            title: $title,
            body: $message,
            targetRoles: $targetRoles,
            metadata: ['broadcast' => true]
        );

        return $this->send($channels, $payload);
    }

    /**
     * Mask contact for logging: phone or email.
     */
    public function maskRecipient(?string $recipient): string
    {
        return PrivacyMasker::maskRecipient($recipient);
    }

    /**
     * Centralized security & privacy audit log.
     *
     * Guarantees raw phone numbers and message text NEVER appear in application logs.
     */
    protected function auditLog(string $channel, string $status, ?string $recipient, NotificationPayload $payload, ?string $reason = null): void
    {
        $maskedContact = PrivacyMasker::maskRecipient($recipient);
        $summary = PrivacyMasker::auditSummary($payload);

        $context = [
            'channel' => $channel,
            'status' => $status,
            'recipient' => $maskedContact,
            'digest' => $summary['digest'],
            'length' => $summary['length'],
        ];

        if ($reason) {
            $context['reason'] = $reason;
        }

        if ($status === 'sent') {
            Log::info("[NotificationEngine] Dispatched {$channel} notification", $context);
        } elseif ($status === 'unavailable') {
            Log::warning("[NotificationEngine] Channel {$channel} unavailable: provider unconfigured", $context);
        } else {
            Log::error("[NotificationEngine] Channel {$channel} dispatch failed", $context);
        }
    }
}
