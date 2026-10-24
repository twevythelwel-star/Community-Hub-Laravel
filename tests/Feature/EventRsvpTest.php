<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BlocklistEntry;
use App\Models\EventInvite;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventRsvpTest extends TestCase
{
    use RefreshDatabase;

    public function test_homeowner_can_generate_event_rsvp_link(): void
    {
        $host = User::factory()->create(['role' => UserRole::Homeowner]);

        $response = $this->actingAs($host)->post('/dashboard/visitors/invites', [
            'title' => 'Family Pool Gathering',
            'expected_at' => now()->addDays(2)->toDateTimeString(),
            'max_guests' => 25,
            'notes' => 'Please bring towels and park on north lot.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('rsvp_url');

        $this->assertDatabaseHas('event_invites', [
            'host_id' => $host->id,
            'title' => 'Family Pool Gathering',
            'max_guests' => 25,
        ]);
    }

    public function test_public_guest_can_rsvp_and_receive_clearance(): void
    {
        $host = User::factory()->create(['role' => UserRole::Homeowner]);
        $invite = EventInvite::create([
            'host_id' => $host->id,
            'title' => 'BBQ Party',
            'token' => 'inv_test123',
            'expected_at' => now()->addDay(),
            'max_guests' => 10,
        ]);

        $mockSms = $this->createMock(SmsService::class);
        $mockSms->method('isConfigured')->willReturn(true);
        $mockSms->expects($this->once())
            ->method('sendVisitorPass')
            ->willReturn('SM_MOCK_RSVP');
        $this->app->instance(SmsService::class, $mockSms);

        // Guest visits public RSVP page
        $pageResponse = $this->get('/rsvp/inv_test123');
        $pageResponse->assertOk();
        $pageResponse->assertSee('BBQ Party');

        // Guest submits RSVP
        $submitResponse = $this->post('/rsvp/inv_test123', [
            'name' => 'Stanley Hudson',
            'contact' => '+18765550203',
            'vehicle' => '9944-CD',
        ]);

        $submitResponse->assertOk();
        $submitResponse->assertSee("You're Pre-Cleared!", false);

        $this->assertDatabaseHas('visitors', [
            'homeowner_id' => $host->id,
            'name' => 'Stanley Hudson',
            'contact' => '+18765550203',
            'vehicle' => '9944-CD',
        ]);
    }

    public function test_blocked_name_cannot_rsvp(): void
    {
        $host = User::factory()->create(['role' => UserRole::Homeowner]);
        $invite = EventInvite::create([
            'host_id' => $host->id,
            'title' => 'Community Social',
            'token' => 'inv_social456',
            'expected_at' => now()->addDay(),
            'max_guests' => 50,
        ]);

        BlocklistEntry::create([
            'name' => 'Roy Anderson',
            'reason' => 'Previous altercation',
            'severity' => 'Critical',
            'date_added' => now()->toDateString(),
            'added_by' => 'Security Admin',
        ]);

        $submitResponse = $this->post('/rsvp/inv_social456', [
            'name' => 'Roy Anderson',
            'contact' => '+18765550204',
        ]);

        $submitResponse->assertSessionHasErrors('name');
        $this->assertDatabaseMissing('visitors', [
            'name' => 'Roy Anderson',
        ]);
    }
}
