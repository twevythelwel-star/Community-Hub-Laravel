<?php

namespace App\Services\Messaging;

/**
 * Normalising and masking phone numbers.
 *
 * A visitor's `contact` field is free text and holds either an email address
 * or a phone number, so SMS and WhatsApp must first establish that it is a
 * number at all.
 */
final class PhoneNumber
{
    /**
     * The number in E.164 form (+18765551234), or null if it is not one.
     *
     * A 10-digit number without a country code gets the configured default,
     * which is 1 (the North American Numbering Plan, which covers Jamaica), so
     * 876-555-1234 works as residents write it.
     */
    public static function toE164(?string $value): ?string
    {
        if ($value === null || str_contains($value, '@')) {
            return null;
        }

        $trimmed = trim($value);
        $hasPlus = str_starts_with($trimmed, '+');
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if (! $hasPlus) {
            if (strlen($digits) === 10) {
                $digits = config('services.twilio.default_country_code', '1').$digits;
            } elseif (str_starts_with($digits, '00')) {
                $digits = substr($digits, 2);
            }
        }

        // E.164 allows at most 15 digits; nothing real is shorter than 8.
        if (strlen($digits) < 8 || strlen($digits) > 15 || str_starts_with($digits, '0')) {
            return null;
        }

        return '+'.$digits;
    }

    /**
     * For logs: country code and last four digits only, e.g. +1******1234.
     * Enough to match a log line to a complaint, not enough to call anyone.
     */
    public static function mask(?string $value): string
    {
        if ($value !== null && str_contains($value, '@')) {
            return '[email address]';
        }

        // Normalise first, so 876-555-1234 and +18765551234 mask alike.
        $value = str_starts_with((string) $value, 'whatsapp:') ? substr((string) $value, 9) : $value;
        $digits = preg_replace('/\D+/', '', self::toE164($value) ?? (string) $value) ?? '';

        if (strlen($digits) <= 4) {
            return str_repeat('*', strlen($digits));
        }

        $countryCode = strlen($digits) > 10 ? substr($digits, 0, strlen($digits) - 10) : '';
        $hidden = strlen($digits) - strlen($countryCode) - 4;

        return ($countryCode !== '' ? '+'.$countryCode : '').str_repeat('*', $hidden).substr($digits, -4);
    }
}
