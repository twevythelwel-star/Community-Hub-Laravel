<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Models\Visitor;
use App\Services\NotificationEngine\NotificationEngine;
use App\Services\NotificationEngine\NotificationPayload;
use App\Services\NotificationEngine\PrivacyMasker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationEngineTest extends TestCase
{
    use RefreshDatabase;

    private const TWILIO_URL = 'https://api.twilio.com/2010-04-01/Accounts/AC_test/Messages.json';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.twilio.sid' => null,
            'services.twilio.token' => null,
            'services.twilio.from' => null,
            'services.twilio.whatsapp_from' => null,
            'services.push.fcm_server_key' => null,
            'services.push.vapid_public_key' => null,
            'services.push.vapid_private_key' => null,
        ]);
    }

    private function configureTwilio(array $overrides = []): void
    {
        config([
            'services.twilio.sid' => 'AC_test',
            'services.twilio.token' => 'secret-token',
            'services.twilio.from' => '+18765550000',
            'services.twilio.whatsapp_from' => '+14155238886',
            'services.twilio.whatsapp_content_sid' => null,
            ...$overrides,
        ]);
    }

    /** @return \ArrayObject<int, MessageLogged> */
    private function captureLogs(): \ArrayObject
    {
        $entries = new \ArrayObject;
        Event::listen(MessageLogged::class, fn (MessageLogged $e) => $entries->append($e));

        return $entries;
    }

    /** @param \ArrayObject<int, MessageLogged> $entries */
    private function flatten(\ArrayObject $entries): string
    {
        return collect($entries)->map(fn (MessageLogged $e) => $e->message.' '.json_encode($e->context))->implode(PHP_EOL);
    }

    // ── 1. Unconfigured Provider Rejection (Never False Success) ──

    public function test_unconfigured_sms_returns_unavailable_not_sent(): void
    {
        $engine = app(NotificationEngine::class);

        $payload = new NotificationPayload(
            title: 'Test SMS',
            body: 'Your code is 123456',
            recipient: '876-555-1234'
        );

        $results = $engine->send(['sms'], $payload);

        $this->assertArrayHasKey('sms', $results);
        $result = $results['sms'];

        $this->assertSame('unavailable', $result->status);
        $this->assertTrue($result->isUnavailable());
        $this->assertFalse($result->isSent());
        $this->assertStringContainsString('not configured', (string) $result->reason);
    }

    public function test_unconfigured_whatsapp_returns_unavailable_not_sent(): void
    {
        $engine = app(NotificationEngine::class);

        $payload = new NotificationPayload(
            title: 'Test WhatsApp',
            body: 'Hello via WhatsApp',
            recipient: '876-555-1234'
        );

        $results = $engine->send(['whatsapp'], $payload);

        $this->assertArrayHasKey('whatsapp', $results);
        $result = $results['whatsapp'];

        $this->assertSame('unavailable', $result->status);
        $this->assertTrue($result->isUnavailable());
        $this->assertFalse($result->isSent());
        $this->assertStringContainsString('not configured', (string) $result->reason);
    }

    public function test_unconfigured_push_returns_unavailable_not_sent(): void
    {
        $engine = app(NotificationEngine::class);

        $payload = new NotificationPayload(
            title: 'Test Push',
            body: 'New gate pass request',
            recipient: 'device_token_xyz_999'
        );

        $results = $engine->send(['push'], $payload);

        $this->assertArrayHasKey('push', $results);
        $result = $results['push'];

        $this->assertSame('unavailable', $result->status);
        $this->assertTrue($result->isUnavailable());
        $this->assertFalse($result->isSent());
        $this->assertStringContainsString('not configured', (string) $result->reason);
    }

    // ── 2. Configured Deliveries (Real Integration) ──

    public function test_configured_sms_dispatches_to_twilio_and_returns_sent(): void
    {
        $this->configureTwilio();
        Http::fake([self::TWILIO_URL => Http::response(['sid' => 'SM_TEST_123', 'status' => 'queued'], 201)]);

        $engine = app(NotificationEngine::class);

        $payload = new NotificationPayload(
            title: 'Visitor Pass',
            body: 'Your gate pass is active',
            recipient: '876-555-1234'
        );

        $results = $engine->send(['sms'], $payload);

        $this->assertArrayHasKey('sms', $results);
        $result = $results['sms'];

        $this->assertSame('sent', $result->status);
        $this->assertTrue($result->isSent());
        $this->assertSame('SM_TEST_123', $result->reference);

        Http::assertSent(fn (Request $request) => $request->url() === self::TWILIO_URL
            && $request['To'] === '+18765551234'
            && $request['From'] === '+18765550000');
    }

    public function test_configured_whatsapp_dispatches_to_twilio_whatsapp_and_returns_sent(): void
    {
        $this->configureTwilio();
        Http::fake([self::TWILIO_URL => Http::response(['sid' => 'WA_TEST_456', 'status' => 'queued'], 201)]);

        $engine = app(NotificationEngine::class);

        $payload = new NotificationPayload(
            title: 'Visitor Pass',
            body: 'Your gate pass is active',
            recipient: '876-555-1234'
        );

        $results = $engine->send(['whatsapp'], $payload);

        $this->assertArrayHasKey('whatsapp', $results);
        $result = $results['whatsapp'];

        $this->assertSame('sent', $result->status);
        $this->assertTrue($result->isSent());
        $this->assertSame('WA_TEST_456', $result->reference);

        Http::assertSent(fn (Request $request) => $request['To'] === 'whatsapp:+18765551234'
            && $request['From'] === 'whatsapp:+14155238886');
    }

    public function test_in_app_notification_creates_database_record_and_returns_sent(): void
    {
        $admin = User::factory()->create(['role' => 'System Admin']);
        $engine = app(NotificationEngine::class);

        $payload = new NotificationPayload(
            title: 'Annual General Meeting',
            body: 'Meeting scheduled for November 15 at 7 PM.',
            user: $admin,
            targetRoles: ['Homeowner', 'Renter']
        );

        $results = $engine->send(['in_app'], $payload);

        $this->assertArrayHasKey('in_app', $results);
        $result = $results['in_app'];

        $this->assertSame('sent', $result->status);
        $this->assertTrue($result->isSent());

        $this->assertDatabaseHas('notifications', [
            'title' => 'Annual General Meeting',
            'author_id' => $admin->id,
        ]);

        $notice = Notification::where('title', 'Annual General Meeting')->first();
        $this->assertNotNull($notice);
        $this->assertSame(['Homeowner', 'Renter'], $notice->target_roles);
    }

    // ── 3. Privacy & Masking Standards ──

    public function test_privacy_masking_masks_phone_numbers_and_emails(): void
    {
        $this->assertSame('+1******1234', PrivacyMasker::maskRecipient('876-555-1234'));
        $this->assertSame('+1******1234', PrivacyMasker::maskRecipient('+18765551234'));
        $this->assertSame('whatsapp:+1******1234', PrivacyMasker::maskRecipient('whatsapp:+18765551234'));
        $this->assertSame('j***e@example.com', PrivacyMasker::maskRecipient('john.doe@example.com'));
        $this->assertSame('a***n@domain.org', PrivacyMasker::maskRecipient('admin@domain.org'));
    }

    public function test_audit_logging_never_logs_raw_phone_number_or_sensitive_message_content(): void
    {
        $entries = $this->captureLogs();
        $this->configureTwilio();
        Http::fake([self::TWILIO_URL => Http::response(['sid' => 'SM_AUDIT', 'status' => 'queued'], 201)]);

        $resident = User::factory()->create(['display_name' => 'Host Homeowner']);
        $visitor = Visitor::factory()->create([
            'homeowner_id' => $resident->id,
            'homeowner_name' => $resident->display_name,
            'name' => 'John Visitor',
            'contact' => '876-555-1234',
        ]);

        $engine = app(NotificationEngine::class);
        $engine->sendVisitorPass($visitor, ['sms']);

        $logs = $this->flatten($entries);

        // Assert audited output contains masked recipient and digest
        $this->assertStringContainsString('+1******1234', $logs);
        $this->assertStringContainsString('digest', $logs);

        // Assert strictly NEVER logs raw contact or secret pass link content
        $this->assertStringNotContainsString('5551234', $logs);
        $this->assertStringNotContainsString("John's visitor pass", $logs);
        $this->assertStringNotContainsString($visitor->share_token, $logs);
    }

    // ── 4. Channel Health & Readiness Inspection ──

    public function test_channels_status_report_returns_all_five_channels(): void
    {
        $engine = app(NotificationEngine::class);
        $status = $engine->getChannelsStatus();

        $this->assertCount(5, $status);
        $this->assertArrayHasKey('email', $status);
        $this->assertArrayHasKey('sms', $status);
        $this->assertArrayHasKey('whatsapp', $status);
        $this->assertArrayHasKey('push', $status);
        $this->assertArrayHasKey('in_app', $status);

        $this->assertSame('In-App Notification Feed', $status['in_app']['label']);
        $this->assertTrue($status['in_app']['configured']);
        $this->assertSame('available', $status['in_app']['status']);

        // SMS & Push unconfigured in this test environment
        $this->assertFalse($status['sms']['configured']);
        $this->assertSame('unavailable', $status['sms']['status']);
        $this->assertFalse($status['push']['configured']);
        $this->assertSame('unavailable', $status['push']['status']);
    }

    public function test_broadcast_dispatches_to_in_app(): void
    {
        $engine = app(NotificationEngine::class);

        $results = $engine->broadcast(
            'Tropical Storm Advisory',
            'All residents are advised to secure perimeter items.',
            ['Homeowner', 'Renter', 'Security']
        );

        $this->assertArrayHasKey('in_app', $results);
        $this->assertTrue($results['in_app']->isSent());

        $this->assertDatabaseHas('notifications', [
            'title' => 'Tropical Storm Advisory',
        ]);
    }
}
