<?php

namespace App\Services\NotificationEngine\Channels;

use App\Services\Messaging\MessageNotSent;
use App\Services\Messaging\PhoneNumber;
use App\Services\Messaging\TwilioClient;
use App\Services\NotificationEngine\ChannelDispatchResult;
use App\Services\NotificationEngine\Contracts\NotificationChannelInterface;
use App\Services\NotificationEngine\NotificationPayload;
use Throwable;

class WhatsAppChannel implements NotificationChannelInterface
{
    public function __construct(
        private readonly TwilioClient $twilio
    ) {}

    public function name(): string
    {
        return 'whatsapp';
    }

    public function label(): string
    {
        return 'WhatsApp Messaging';
    }

    public function provider(): string
    {
        return 'Twilio WhatsApp Business API';
    }

    public function isConfigured(): bool
    {
        return $this->twilio->hasCredentials() && filled(config('services.twilio.whatsapp_from'));
    }

    public function send(NotificationPayload $payload): ChannelDispatchResult
    {
        if (! $this->isConfigured()) {
            return ChannelDispatchResult::unavailable(
                $this->name(),
                'WhatsApp provider is not configured: set Twilio credentials and WhatsApp sender.'
            );
        }

        $rawContact = $payload->recipient ?? $payload->visitor?->contact ?? $payload->user?->phone;
        $to = PhoneNumber::toE164($rawContact);

        if (! $to) {
            return ChannelDispatchResult::failed(
                $this->name(),
                'WhatsApp channel requires a valid phone number; recipient given is invalid.'
            );
        }

        $from = (string) config('services.twilio.whatsapp_from');
        $fromFormatted = str_starts_with($from, 'whatsapp:') ? $from : "whatsapp:{$from}";
        $toFormatted = "whatsapp:{$to}";

        $templateSid = $payload->templateId ?: config('services.twilio.whatsapp_content_sid');

        if (filled($templateSid) && ! empty($payload->templateVariables)) {
            $params = [
                'ContentSid' => (string) $templateSid,
                'ContentVariables' => json_encode($payload->templateVariables, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        } else {
            $params = array_filter([
                'Body' => $payload->body,
                'MediaUrl' => $payload->mediaUrl,
            ]);
        }

        try {
            $sid = $this->twilio->send('WhatsApp', $toFormatted, $fromFormatted, $params);

            return ChannelDispatchResult::sent($this->name(), $sid, [
                'sid' => $sid,
            ]);
        } catch (MessageNotSent $e) {
            return ChannelDispatchResult::failed($this->name(), $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return ChannelDispatchResult::failed($this->name(), 'Unexpected WhatsApp error: '.$e->getMessage());
        }
    }
}
