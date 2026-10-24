<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CommunityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The event form's save handler used to close the dialog and reload without
 * sending anything, while toasting "Event Created". These cover the endpoints
 * it now posts to, in the payload shape the page sends.
 */
class CalendarPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_create_an_event_that_then_appears_on_the_calendar(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->actingAs($admin)->post('/dashboard/calendar', [
            'title' => 'Community Pool Party',
            'description' => 'Bring the whole family to the clubhouse pool.',
            'start_date' => '2026-10-03T14:00:00.000Z',
            'end_date' => '2026-10-03T18:00:00.000Z',
            'image_url' => null,
        ])->assertRedirect()->assertSessionHas('success', 'Event created.');

        $event = CommunityEvent::sole();
        $this->assertSame('Community Pool Party', $event->title);
        $this->assertSame($admin->id, $event->created_by);

        $this->actingAs($admin)->get('/dashboard/calendar')
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Calendar')
                ->has('events', 1)
                ->where('events.0.title', 'Community Pool Party')
            );
    }

    public function test_an_all_day_event_can_be_created_without_an_end_date(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->actingAs($admin)->post('/dashboard/calendar', [
            'title' => 'Estate Clean-up Day',
            'description' => 'Meet at the front gate with gloves.',
            'start_date' => '2026-10-10T05:00:00.000Z',
            'end_date' => null,
            'image_url' => null,
        ])->assertSessionHasNoErrors();

        $this->assertNull(CommunityEvent::sole()->end_date);
    }

    public function test_an_event_cannot_end_before_it_starts(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->actingAs($admin)->post('/dashboard/calendar', [
            'title' => 'Backwards Event',
            'description' => 'This one ends before it begins.',
            'start_date' => '2026-10-03T18:00:00.000Z',
            'end_date' => '2026-10-03T14:00:00.000Z',
        ])->assertSessionHasErrors('end_date');

        $this->assertDatabaseCount('community_events', 0);
    }

    public function test_an_admin_can_update_an_event(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();
        $event = CommunityEvent::create([
            'title' => 'Board Meeting',
            'description' => 'Quarterly meeting of the estate board.',
            'start_date' => '2026-10-15 18:00:00',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->patch("/dashboard/calendar/{$event->id}", [
            'title' => 'Board Meeting (moved)',
            'description' => 'Quarterly meeting of the estate board.',
            'start_date' => '2026-10-16T18:00:00.000Z',
            'end_date' => null,
            'image_url' => null,
        ])->assertSessionHas('success', 'Event updated.');

        $this->assertSame('Board Meeting (moved)', $event->fresh()->title);
    }

    public function test_a_homeowner_cannot_create_an_event(): void
    {
        $homeowner = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($homeowner)->post('/dashboard/calendar', [
            'title' => 'Unofficial Party',
            'description' => 'Not an estate-sanctioned event.',
            'start_date' => '2026-10-03T14:00:00.000Z',
        ])->assertForbidden();

        $this->assertDatabaseCount('community_events', 0);
    }
}
