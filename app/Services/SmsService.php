<?php

namespace App\Services;

use App\Services\Messaging\MessageNotSent;
use App\Services\Messaging\PhoneNumber;
use App\Services\Messaging\TwilioClient;

/**
 * SMS through Twilio.
 *
 * This used to write "Would send to <full number>: <full message>" to the
 * application log and return true, so every SMS was reported as sent, none
 * was, and the log collected visitors' phone numbers alongside working
 * guest-pass links. It now either hands the message to Twilio or throws
 * MessageNotSent, and logs only a masked number.
 */
class SmsService
{
    public function __construct(private TwilioClient $twilio) {}

    /** Whether SMS can actually be sent. The UI disables the option otherwise. */
    public function isConfigured(): bool
    {
        return $this->twilio->hasCredentials() && filled(config('services.twilio.from'));
    }

    /**
     * @return string Twilio's message SID.
     *
     * @throws MessageNotSent
     */
    public function send(string $phoneNumber, string $message): string
    {
        if (! $this->isConfigured()) {
            throw MessageNotSent::notConfigured('SMS');
        }

        $to = PhoneNumber::toE164($phoneNumber) ?? throw MessageNotSent::invalidNumber('SMS');

        return $this->twilio->send('SMS', $to, (string) config('services.twilio.from'), ['Body' => $message]);
    }

    /**
     * @throws MessageNotSent
     */
    public function sendVisitorPass(string $phoneNumber, string $guestPassUrl, string $visitorName, string $hostName, string $expectedAt): string
    {
        $message = "Hello {$visitorName}, you have been registered as a visitor to the community. Your guest pass is available at: {$guestPassUrl}. Expected arrival: {$expectedAt}. Host: {$hostName}";

        return $this->send($phoneNumber, $message);
    }
}
