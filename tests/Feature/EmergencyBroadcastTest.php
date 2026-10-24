<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Models\Notification;
use App\Models\User;
use App\Models\Visitor;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmergencyBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_dispatch_emergency_broadcast_and_sms(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $resident = User::factory()->create([
            'role' => UserRole::Homeowner,
            'phone' => '+18765550201',
        ]);
        $visitor = Visitor::create([
            'homeowner_id' => $resident->id,
            'name' => 'Michael Scott',
            'type' => 'Visitor',
            'contact' => '+18765550202',
            'expected_at' => now(),
            'status' => VisitorStatus::CheckedIn,
        ]);

        $mockSms = $this->createMock(SmsService::class);
        $mockSms->method('isConfigured')->willReturn(true);
        $mockSms->expects($this->atLeastOnce())
            ->method('send')
            ->willReturn('SM_MOCK_BROADCAST');
        $this->app->instance(SmsService::class, $mockSms);

        $response = $this->actingAs($admin)->post('/dashboard/notifications/broadcast', [
            'title' => 'Severe Weather Warning',
            'content' => 'High winds expected near the main gate. Please stay indoors.',
            'severity' => 'emergency',
            'audience' => 'all',
            'send_sms' => true,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('notifications', [
            'title' => '🚨 [EMERGENCY ALERT] Severe Weather Warning',
            'content' => 'High winds expected near the main gate. Please stay indoors.',
        ]);
    }

    public function test_a_residents_broadcast_reaches_homeowners_and_renters_only(): void
    {
        // It named UserRole::Renter, which does not exist (renters are
        // Temporary Homeowners), so every residents-only broadcast crashed.
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->create(['role' => UserRole::Homeowner, 'phone' => '+18765550301']);
        User::factory()->create(['role' => UserRole::TemporaryHomeowner, 'phone' => '+18765550302']);
        User::factory()->create(['role' => UserRole::Security, 'phone' => '+18765550303']);

        $sentTo = [];
        $mockSms = $this->createMock(SmsService::class);
        $mockSms->method('isConfigured')->willReturn(true);
        $mockSms->method('send')->willReturnCallback(function (string $phone) use (&$sentTo) {
            $sentTo[] = $phone;

            return 'SM_MOCK';
        });
        $this->app->instance(SmsService::class, $mockSms);

        $this->actingAs($admin)->post('/dashboard/notifications/broadcast', [
            'title' => 'Water shut-off',
            'content' => 'Mains repair on Royal Palm Drive from 10:00.',
            'severity' => 'advisory',
            'audience' => 'residents',
            'send_sms' => true,
        ])->assertRedirect()->assertSessionHas('success');

        sort($sentTo);
        $this->assertSame(['+18765550301', '+18765550302'], $sentTo);
        $this->assertEqualsCanonicalizing(
            [UserRole::Homeowner->value, UserRole::TemporaryHomeowner->value],
            Notification::latest('id')->value('target_roles'),
        );
    }

    public function test_homeowner_cannot_dispatch_emergency_broadcast(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);

        $response = $this->actingAs($homeowner)->post('/dashboard/notifications/broadcast', [
            'title' => 'Test',
            'content' => 'Unauthorized broadcast',
            'severity' => 'info',
            'audience' => 'all',
        ]);

        $response->assertForbidden();
    }
}
