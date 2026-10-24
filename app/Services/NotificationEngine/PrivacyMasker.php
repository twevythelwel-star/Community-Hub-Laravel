<?php

namespace App\Services\NotificationEngine;

use App\Services\Messaging\PhoneNumber;

/**
 * Enforces strict data privacy and sanitization standards for logging and audits.
 *
 * Guarantees that raw contact details (e.g. 8765551234, full emails), vehicle plates,
 * ID numbers, and sensitive message text are NEVER written to logs unmasked.
 */
class PrivacyMasker
{
    /**
     * Mask any recipient string (email or phone number).
     */
    public static function maskRecipient(?string $recipient): string
    {
        if ($recipient === null || trim($recipient) === '') {
            return '[empty]';
        }

        $trimmed = trim($recipient);

        if (str_contains($trimmed, '@')) {
            return self::maskEmail($trimmed);
        }

        return self::maskPhone($trimmed);
    }

    /**
     * Mask an email address: john.doe@example.com -> j***e@example.com
     */
    public static function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '[invalid_email]';
        }

        [$local, $domain] = explode('@', $email, 2);

        if (strlen($local) <= 2) {
            $maskedLocal = str_repeat('*', strlen($local));
        } else {
            $maskedLocal = $local[0].'***'.substr($local, -1);
        }

        return "{$maskedLocal}@{$domain}";
    }

    /**
     * Mask a phone number to country code and last four digits: +1******1234
     */
    public static function maskPhone(string $phone): string
    {
        $isWhatsApp = str_starts_with($phone, 'whatsapp:');
        $clean = $isWhatsApp ? substr($phone, 9) : $phone;

        $masked = PhoneNumber::mask($clean);

        return $isWhatsApp ? "whatsapp:{$masked}" : $masked;
    }

    /**
     * Mask a vehicle license plate: "8765-AB" -> "87***AB", "ABC1234" -> "AB***34"
     */
    public static function maskLicensePlate(?string $plate): string
    {
        if ($plate === null || trim($plate) === '') {
            return '[empty]';
        }

        $clean = trim($plate);
        $len = strlen($clean);

        if ($len <= 3) {
            return str_repeat('*', $len);
        }

        if ($len <= 5) {
            return $clean[0].str_repeat('*', $len - 2).substr($clean, -1);
        }

        return substr($clean, 0, 2).str_repeat('*', $len - 4).substr($clean, -2);
    }

    /**
     * Mask a National ID, Driver's License, or TRN number: "123-456-789" -> "***-***-789"
     */
    public static function maskIdNumber(?string $idNumber): string
    {
        if ($idNumber === null || trim($idNumber) === '') {
            return '[empty]';
        }

        $clean = trim($idNumber);
        $len = strlen($clean);

        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 4).substr($clean, -4);
    }

    /**
     * Redact PII (emails, phone numbers) from a free-form message body for safe auditing.
     */
    public static function redactSensitiveText(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        // Redact email addresses
        $redacted = preg_replace_callback(
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
            fn ($matches) => self::maskEmail($matches[0]),
            $text
        ) ?? $text;

        // Redact phone numbers (international or local format with at least 7 digits)
        $redacted = preg_replace_callback(
            '/(\+?\d{1,4}[-.\s]?)?(\(?\d{3}\)?[-.\s]?)?\d{3}[-.\s]?\d{4}/',
            fn ($matches) => self::maskPhone($matches[0]),
            $redacted
        ) ?? $redacted;

        return $redacted;
    }

    /**
     * Mask a sensitive payload or URL for auditing.
     */
    public static function auditSummary(NotificationPayload $payload): array
    {
        return [
            'digest' => substr($payload->contentDigest(), 0, 16),
            'length' => $payload->contentLength(),
            'has_action_url' => filled($payload->actionUrl),
            'has_media' => filled($payload->mediaUrl),
            'template_id' => $payload->templateId,
        ];
    }
}
