<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ChangelogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChangelogPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_admin_sees_database_changelog_entries_newest_first(): void
    {
        $sysAdmin = User::factory()->role(UserRole::SystemAdmin)->create();

        ChangelogEntry::create([
            'version' => 'v2.0.0',
            'released_on' => '2026-08-01',
            'title' => 'Initial Major Release',
            'body' => 'Core systems operational.',
        ]);

        ChangelogEntry::create([
            'version' => 'v2.1.0',
            'released_on' => '2026-09-01',
            'title' => 'Gate Pass Security Overhaul',
            'body' => 'HMAC profile shapes and security audit logging.',
        ]);

        $this->actingAs($sysAdmin)->get('/dashboard/changelog')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Changelog')
                ->has('entries', 2)
                ->where('entries.0.version', 'v2.1.0')
                ->where('entries.1.version', 'v2.0.0')
                ->where('canManage', true)
            );
    }

    public function test_system_admin_can_publish_new_release_notes(): void
    {
        $sysAdmin = User::factory()->role(UserRole::SystemAdmin)->create();

        $this->actingAs($sysAdmin)->post('/dashboard/changelog', [
            'version' => 'v2.2.0',
            'released_on' => '2026-09-23',
            'title' => 'Billing & Payment Orchestration Engine',
            'body' => 'Added 10 payment channels with automated technical readiness checks.',
        ])->assertRedirect()->assertSessionHas('success', 'Release notes published.');

        $entry = ChangelogEntry::where('version', 'v2.2.0')->firstOrFail();
        $this->assertSame('Billing & Payment Orchestration Engine', $entry->title);
    }

    public function test_non_system_admin_cannot_access_or_post_to_changelog(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();
        $homeowner = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($admin)->get('/dashboard/changelog')->assertForbidden();
        $this->actingAs($homeowner)->get('/dashboard/changelog')->assertForbidden();

        $this->actingAs($admin)->post('/dashboard/changelog', [
            'version' => 'v9.9.9',
            'released_on' => '2026-09-23',
            'title' => 'Hacked',
            'body' => 'Should fail.',
        ])->assertForbidden();

        $this->assertDatabaseMissing('changelog_entries', ['version' => 'v9.9.9']);
    }
}
