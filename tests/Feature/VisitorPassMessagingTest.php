<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SendVisitorPassNotification;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\VisitorPassEmailNotification;
use App\Services\Messaging\MessageNotSent;
use App\Services\Messaging\PhoneNumber;
use App\Services\SmsService;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * SMS and WhatsApp used to log "Would send to <number>: <message>" and return
 * true. These pin the replacement: messages go to Twilio or the caller hears
 * they did not, and logs never carry a full number or the message text.
 */
class VisitorPassMessagingTest extends TestCase
{
    use RefreshDatabase;

    private const TWILIO_URL = 'https://api.twilio.com/2010-04-01/Accounts/AC_test/Messages.json';

    private function configureTwilio(array $overrides = []): void
    {
        config([
            'services.twilio.sid' => 'AC_test',
            'services.twilio.token' => 'secret-token',
            'services.twilio.api_key' => null,
            'services.twilio.api_secret' => null,
            'services.twilio.from' => '+18765550000',
            'services.twilio.whatsapp_from' => '+14155238886',
            'services.twilio.whatsapp_content_sid' => null,
            ...$overrides,
        ]);
    }

    private function twilioAccepts(): void
    {
        Http::fake([self::TWILIO_URL => Http::response(['sid' => 'SM123', 'status' => 'queued'], 201)]);
    }

    /**
     * Captures every log entry written from here on.
     *
     * @return \ArrayObject<int, MessageLogged>
     */
    private function captureLogs(): \ArrayObject
    {
        $entries = new \ArrayObject;
        Event::listen(MessageLogged::class, fn (MessageLogged $e) => $entries->append($e));

        return $entries;
    }

    /** @param  \ArrayObject<int, MessageLogged>  $entries */
    private function flatten(\ArrayObject $entries): string
    {
        return collect($entries)->map(fn (MessageLogged $e) => $e->message.' '.json_encode($e->context))->implode(PHP_EOL);
    }

    // ── Phone numbers ──

    public function test_numbers_are_normalised_to_e164_and_emails_are_not_numbers(): void
    {
        $this->assertSame('+18765551234', PhoneNumber::toE164('876-555-1234'));
        $this->assertSame('+18765551234', PhoneNumber::toE164('(876) 555 1234'));
        $this->assertSame('+447700900123', PhoneNumber::toE164('+44 7700 900123'));
        $this->assertSame('+447700900123', PhoneNumber::toE164('0044 7700 900123'));
        $this->assertNull(PhoneNumber::toE164('liam@example.com'));
        $this->assertNull(PhoneNumber::toE164('555-1234'));
        $this->assertNull(PhoneNumber::toE164(null));
    }

    public function test_masking_keeps_only_the_country_code_and_last_four_digits(): void
    {
        $this->assertSame('+1******1234', PhoneNumber::mask('+18765551234'));
        $this->assertSame('+1******1234', PhoneNumber::mask('whatsapp:+18765551234'));
        $this->assertSame('+1******1234', PhoneNumber::mask('876-555-1234'));
        $this->assertSame('[email address]', PhoneNumber::mask('liam@example.com'));
    }

    // ── SMS ──

    public function test_sms_refuses_rather_than_pretends_when_twilio_is_not_configured(): void
    {
        Http::fake();
        config(['services.twilio.sid' => null, 'services.twilio.token' => null]);

        $this->assertFalse(app(SmsService::class)->isConfigured());

        $this->expectException(MessageNotSent::class);

        try {
            app(SmsService::class)->send('876-555-1234', 'Hello');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_sms_is_posted_to_twilio_and_returns_the_message_sid(): void
    {
        $this->configureTwilio();
        $this->twilioAccepts();

        $sid = app(SmsService::class)->send('876-555-1234', 'Your pass is ready');

        $this->assertSame('SM123', $sid);
        Http::assertSent(fn (Request $request) => $request->url() === self::TWILIO_URL
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('AC_test:secret-token'))
            && $request['To'] === '+18765551234'
            && $request['From'] === '+18765550000'
            && $request['Body'] === 'Your pass is ready');
    }

    public function test_an_email_address_is_refused_as_an_sms_recipient(): void
    {
        $this->configureTwilio();
        Http::fake();

        $this->expectExceptionMessage('needs a phone number');

        try {
            app(SmsService::class)->send('liam@example.com', 'Hello');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_twilio_refusal_is_an_exception_that_does_not_repeat_the_number(): void
    {
        $this->configureTwilio();
        Http::fake([self::TWILIO_URL => Http::response([
            'code' => 21211,
            'message' => "The 'To' number +18765551234 is not a valid phone number.",
            'status' => 400,
        ], 400)]);

        try {
            app(SmsService::class)->send('876-555-1234', 'Hello');
            $this->fail('Expected MessageNotSent.');
        } catch (MessageNotSent $e) {
            $this->assertStringContainsString('error 21211', $e->getMessage());
            $this->assertStringNotContainsString('5551234', $e->getMessage());
        }
    }

    public function test_logs_carry_a_masked_number_and_never_the_message(): void
    {
        $entries = $this->captureLogs();
        $this->configureTwilio();
        $this->twilioAccepts();

        app(SmsService::class)->sendVisitorPass(
            '876-555-1234',
            'https://hub.test/guest-pass/secret-share-token',
            'Liam Visitor',
            'Marcus Host',
            'Friday',
        );

        $logged = $this->flatten($entries);
        $this->assertNotSame('', $logged);
        $this->assertStringContainsString('+1******1234', $logged);
        $this->assertStringNotContainsString('5551234', $logged);
        $this->assertStringNotContainsString('secret-share-token', $logged);
        $this->assertStringNotContainsString('Liam Visitor', $logged);
    }

    // ── WhatsApp ──

    public function test_whatsapp_uses_the_whatsapp_address_form_and_attaches_the_qr_image(): void
    {
        $this->configureTwilio();
        $this->twilioAccepts();

        app(WhatsAppService::class)->sendVisitorPass('876-555-1234', 'https://hub.test/p/abc', 'https://hub.test/qr.png', 'Liam', 'Marcus', 'Friday');

        Http::assertSent(fn (Request $request) => $request['To'] === 'whatsapp:+18765551234'
            && $request['From'] === 'whatsapp:+14155238886'
            && $request['MediaUrl'] === 'https://hub.test/qr.png'
            && str_contains($request['Body'], 'https://hub.test/p/abc'));
    }

    public function test_whatsapp_uses_the_approved_template_when_one_is_configured(): void
    {
        $this->configureTwilio(['services.twilio.whatsapp_content_sid' => 'HX0123456789']);
        $this->twilioAccepts();

        app(WhatsAppService::class)->sendVisitorPass('876-555-1234', 'https://hub.test/p/abc', 'https://hub.test/qr.png', 'Liam', 'Marcus', 'Friday');

        Http::assertSent(function (Request $request) {
            $variables = json_decode($request['ContentVariables'], true);

            return $request['ContentSid'] === 'HX0123456789'
                && ! isset($request['Body'])
                && $variables === ['1' => 'Liam', '2' => 'https://hub.test/p/abc', '3' => 'Friday', '4' => 'Marcus'];
        });
    }

    public function test_whatsapp_refuses_when_it_has_no_sender(): void
    {
        $this->configureTwilio(['services.twilio.whatsapp_from' => null]);
        Http::fake();

        $this->assertFalse(app(WhatsAppService::class)->isConfigured());
        $this->expectException(MessageNotSent::class);

        app(WhatsAppService::class)->send('876-555-1234', 'Hello');
    }

    // ── The job ──

    private function visitor(array $overrides = []): Visitor
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        return Visitor::factory()->create([
            'homeowner_id' => $resident->id,
            'homeowner_name' => $resident->display_name,
            'contact' => '876-555-1234',
            'notify_email' => false,
            'notify_sms' => true,
            'notify_whatsapp' => false,
            ...$overrides,
        ]);
    }

    public function test_the_job_texts_the_pass_link_without_needing_a_qr_image(): void
    {
        $this->configureTwilio();
        $this->twilioAccepts();
        $visitor = $this->visitor();

        app()->call([new SendVisitorPassNotification($visitor, ['sms']), 'handle']);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_contains($request['Body'], $visitor->guestPassUrl()));
    }

    public function test_a_failed_channel_is_logged_and_does_not_fail_the_job(): void
    {
        /*
         | Rethrowing made the queue retry the whole job, re-sending every
         | other channel each time.
         */
        $entries = $this->captureLogs();
        $this->configureTwilio();
        Http::fake([self::TWILIO_URL => Http::response(['code' => 20003, 'message' => 'Authenticate'], 401)]);
        $visitor = $this->visitor();

        app()->call([new SendVisitorPassNotification($visitor, ['sms']), 'handle']);

        $warning = collect($entries)->first(fn (MessageLogged $e) => $e->message === 'Visitor pass not sent by sms');

        $this->assertNotNull($warning, 'Logged instead: '.$this->flatten($entries));
        $this->assertSame('+1******1234', $warning->context['contact']);
        $this->assertStringContainsString('error 20003', $warning->context['reason']);
        $this->assertStringNotContainsString('5551234', $this->flatten($entries));
    }

    public function test_the_job_emails_an_email_contact(): void
    {
        /*
         | The email step used to call notify() on an anonymous class with no
         | Notifiable trait: a fatal error before any other channel was tried.
         */
        Notification::fake();
        $visitor = $this->visitor([
            'contact' => 'liam@example.com',
            'notify_email' => true,
            'notify_sms' => false,
        ]);

        app()->call([new SendVisitorPassNotification($visitor, ['email']), 'handle']);

        Notification::assertSentOnDemand(
            VisitorPassEmailNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'liam@example.com',
        );
    }

    // ── What the forms are told ──

    public function test_the_forms_are_told_which_channels_can_send(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        config(['services.twilio.sid' => null, 'services.twilio.token' => null]);
        $this->actingAs($resident)->get('/dashboard/visitors')
            ->assertInertia(fn ($page) => $page->where('messaging', ['sms' => false, 'whatsapp' => false]));

        $this->configureTwilio(['services.twilio.whatsapp_from' => null]);
        $this->actingAs($resident)->get('/dashboard/visitors')
            ->assertInertia(fn ($page) => $page->where('messaging', ['sms' => true, 'whatsapp' => false]));
    }
}
