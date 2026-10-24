<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CommunityUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The page used to render three hardcoded updates and add new ones to React
 * state only. These cover the server data it now reads and the endpoint its
 * form now posts to.
 */
class CommunityUpdatesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_residents_see_real_updates_newest_first_and_cannot_manage_them(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();
        CommunityUpdate::create(['title' => 'Older meeting', 'date' => '2026-05-10', 'summary' => 'Minutes.', 'created_by' => $admin->id]);
        CommunityUpdate::create(['title' => 'Newer meeting', 'date' => '2026-08-15', 'summary' => 'Minutes.', 'created_by' => $admin->id]);

        $homeowner = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($homeowner)->get('/dashboard/updates')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Updates')
                ->has('updates.data', 2)
                ->where('updates.data.0.title', 'Newer meeting')
                ->where('updates.data.0.date', '2026-08-15')
                ->where('canManage', false)
            );
    }

    public function test_an_admin_can_post_an_update(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->actingAs($admin)->post('/dashboard/updates', [
            'title' => 'Q3 Board Meeting Summary',
            'date' => '2026-09-20',
            'summary' => 'The board approved resurfacing the main road.',
        ])->assertRedirect()->assertSessionHas('success', 'Update posted.');

        $update = CommunityUpdate::sole();
        $this->assertSame('2026-09-20', $update->date->toDateString());
        $this->assertSame($admin->id, $update->created_by);
    }

    public function test_a_homeowner_cannot_post_an_update(): void
    {
        $homeowner = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($homeowner)->post('/dashboard/updates', [
            'title' => 'Unofficial minutes',
            'date' => '2026-09-20',
            'summary' => 'Not from the board.',
        ])->assertForbidden();

        $this->assertDatabaseCount('community_updates', 0);
    }
}
