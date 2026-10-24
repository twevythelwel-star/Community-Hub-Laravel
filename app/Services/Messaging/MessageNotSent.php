<?php

namespace App\Services\Messaging;

use RuntimeException;

/**
 * An SMS or WhatsApp message did not go out.
 *
 * The services used to return `true` for every message while only writing it
 * to the log. They now throw this instead, so a caller cannot mistake "not
 * sent" for "sent". The message says why without repeating the recipient's
 * number or the text.
 */
class MessageNotSent extends RuntimeException
{
    public static function notConfigured(string $channel): self
    {
        return new self("{$channel} is not configured: set the Twilio credentials in .env.");
    }

    public static function invalidNumber(string $channel): self
    {
        return new self("{$channel} needs a phone number; the contact given is not one.");
    }

    /**
     * Twilio's own error text is left out on purpose: it often quotes the
     * number ("The 'To' number +1876... is not valid"). The code is enough to
     * look up at twilio.com/docs/api/errors.
     */
    public static function rejected(string $channel, int $status, ?int $code): self
    {
        return new self("{$channel} was refused by Twilio (HTTP {$status}".($code ? ", error {$code}" : '').').');
    }

    public static function unreachable(string $channel): self
    {
        return new self("{$channel} could not reach Twilio.");
    }
}
