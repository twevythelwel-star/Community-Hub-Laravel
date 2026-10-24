<?php

namespace App\Services\NotificationEngine\Channels;

use App\Services\Messaging\MessageNotSent;
use App\Services\Messaging\PhoneNumber;
use App\Services\Messaging\TwilioClient;
use App\Services\NotificationEngine\ChannelDispatchResult;
use App\Services\NotificationEngine\Contracts\NotificationChannelInterface;
use App\Services\NotificationEngine\NotificationPayload;
use Throwable;

class SmsChannel implements NotificationChannelInterface
{
    public function __construct(
        private readonly TwilioClient $twilio
    ) {}

    public function name(): string
    {
        return 'sms';
    }

    public function label(): string
    {
        return 'SMS Text Messaging';
    }

    public function provider(): string
    {
        return 'Twilio Programmable Messaging';
    }

    public function isConfigured(): bool
    {
        return $this->twilio->hasCredentials() && filled(config('services.twilio.from'));
    }

    public function send(NotificationPayload $payload): ChannelDispatchResult
    {
        if (! $this->isConfigured()) {
            return ChannelDispatchResult::unavailable(
                $this->name(),
                'SMS provider is not configured: set Twilio credentials and sender in environment.'
            );
        }

        $rawContact = $payload->recipient ?? $payload->visitor?->contact ?? $payload->user?->phone;
        $to = PhoneNumber::toE164($rawContact);

        if (! $to) {
            return ChannelDispatchResult::failed(
                $this->name(),
                'SMS channel requires a valid phone number; recipient given is invalid.'
            );
        }

        try {
            $from = (string) config('services.twilio.from');
            $sid = $this->twilio->send('SMS', $to, $from, [
                'Body' => $payload->body,
            ]);

            return ChannelDispatchResult::sent($this->name(), $sid, [
                'sid' => $sid,
            ]);
        } catch (MessageNotSent $e) {
            return ChannelDispatchResult::failed($this->name(), $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return ChannelDispatchResult::failed($this->name(), 'Unexpected SMS error: '.$e->getMessage());
        }
    }
}
