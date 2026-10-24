<?php

namespace App\Services;

use App\Services\Messaging\MessageNotSent;
use App\Services\Messaging\PhoneNumber;
use App\Services\Messaging\TwilioClient;

/**
 * WhatsApp through Twilio.
 *
 * Like SmsService, this used to log the full number and message and report
 * success without sending anything. It now sends or throws MessageNotSent.
 *
 * WhatsApp only delivers free-form text to someone who has messaged the
 * business in the last 24 hours. A visitor being sent their pass usually has
 * not, so business-initiated messages must use a template approved by Meta.
 * Set TWILIO_WHATSAPP_CONTENT_SID to that template's Content SID and it is
 * used with these variables:
 *
 *   {{1}} visitor name   {{2}} guest-pass URL   {{3}} expected arrival   {{4}} host name
 *
 * Without it the message goes as free text, which works in the Twilio sandbox
 * and inside a 24-hour window but is otherwise refused by WhatsApp (Twilio
 * error 63016). The refusal surfaces as MessageNotSent, not as silence.
 */
class WhatsAppService
{
    public function __construct(private TwilioClient $twilio) {}

    /** Whether WhatsApp can actually be sent. The UI disables the option otherwise. */
    public function isConfigured(): bool
    {
        return $this->twilio->hasCredentials() && filled(config('services.twilio.whatsapp_from'));
    }

    /**
     * @return string Twilio's message SID.
     *
     * @throws MessageNotSent
     */
    public function send(string $phoneNumber, string $message, ?string $mediaUrl = null): string
    {
        return $this->deliver($phoneNumber, array_filter([
            'Body' => $message,
            'MediaUrl' => $mediaUrl,
        ]));
    }

    /**
     * @throws MessageNotSent
     */
    public function sendVisitorPass(string $phoneNumber, string $guestPassUrl, string $qrCodeUrl, string $visitorName, string $hostName, string $expectedAt): string
    {
        $contentSid = config('services.twilio.whatsapp_content_sid');

        if (filled($contentSid)) {
            return $this->deliver($phoneNumber, [
                'ContentSid' => (string) $contentSid,
                'ContentVariables' => json_encode([
                    '1' => $visitorName,
                    '2' => $guestPassUrl,
                    '3' => $expectedAt,
                    '4' => $hostName,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }

        $message = "Hello {$visitorName}, you have been registered as a visitor to the community. Your guest pass is available at: {$guestPassUrl}. Expected arrival: {$expectedAt}. Host: {$hostName}";

        return $this->send($phoneNumber, $message, $qrCodeUrl);
    }

    /**
     * @param  array<string, string>  $params
     *
     * @throws MessageNotSent
     */
    private function deliver(string $phoneNumber, array $params): string
    {
        if (! $this->isConfigured()) {
            throw MessageNotSent::notConfigured('WhatsApp');
        }

        $to = PhoneNumber::toE164($phoneNumber) ?? throw MessageNotSent::invalidNumber('WhatsApp');
        $from = (string) config('services.twilio.whatsapp_from');

        return $this->twilio->send(
            'WhatsApp',
            "whatsapp:{$to}",
            str_starts_with($from, 'whatsapp:') ? $from : "whatsapp:{$from}",
            $params,
        );
    }
}
