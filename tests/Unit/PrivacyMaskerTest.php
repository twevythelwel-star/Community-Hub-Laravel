<?php

namespace Tests\Unit;

use App\Services\NotificationEngine\PrivacyMasker;
use Tests\TestCase;

class PrivacyMaskerTest extends TestCase
{
    public function test_mask_email_handles_standard_and_short_addresses(): void
    {
        $this->assertEquals('j***e@example.com', PrivacyMasker::maskEmail('john.doe@example.com'));
        $this->assertEquals('**@x.com', PrivacyMasker::maskEmail('ab@x.com'));
        $this->assertEquals('[invalid_email]', PrivacyMasker::maskEmail('not-an-email'));
    }

    public function test_mask_phone_and_whatsapp(): void
    {
        $masked = PrivacyMasker::maskPhone('8765551234');
        $this->assertStringEndsWith('1234', $masked);
        $this->assertStringContainsString('*', $masked);

        $whatsapp = PrivacyMasker::maskPhone('whatsapp:+18765551234');
        $this->assertStringStartsWith('whatsapp:', $whatsapp);
        $this->assertStringEndsWith('1234', $whatsapp);
    }

    public function test_mask_license_plate(): void
    {
        $this->assertEquals('[empty]', PrivacyMasker::maskLicensePlate(null));
        $this->assertEquals('[empty]', PrivacyMasker::maskLicensePlate('   '));
        $this->assertEquals('87***AB', PrivacyMasker::maskLicensePlate('8765-AB'));
        $this->assertEquals('AB***34', PrivacyMasker::maskLicensePlate('ABC1234'));
        $this->assertEquals('***', PrivacyMasker::maskLicensePlate('ABC'));
    }

    public function test_mask_id_number(): void
    {
        $this->assertEquals('[empty]', PrivacyMasker::maskIdNumber(null));
        $this->assertEquals('***', PrivacyMasker::maskIdNumber('123'));
        $this->assertEquals('*******6789', PrivacyMasker::maskIdNumber('123-45-6789'));
    }

    public function test_redact_sensitive_text(): void
    {
        $raw = 'Visitor pass generated for john.doe@example.com and phone 876-555-1234.';
        $redacted = PrivacyMasker::redactSensitiveText($raw);

        $this->assertStringNotContainsString('john.doe@example.com', $redacted);
        $this->assertStringContainsString('j***e@example.com', $redacted);
        $this->assertStringNotContainsString('876-555-1234', $redacted);
        $this->assertStringEndsWith('.', $redacted);
    }
}
