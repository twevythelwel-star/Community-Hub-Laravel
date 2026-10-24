<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Warning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers the safety-alert feed: real vote tallies, who may raise an alert, the
 * rate limit that comes with broadcasting to everybody, and removal of a false
 * alarm.
 */
class WarningPageTest extends TestCase
{
    use RefreshDatabase;

    private function warning(array $overrides = []): Warning
    {
        return Warning::create(array_merge([
            'title' => 'Unidentified vehicle on Royal Palm Drive',
            'description' => 'A dark sedan without plates was seen circling after 23:00.',
            'author_name' => 'Security Dispatch',
            'issued_at' => now()->subHours(5),
        ], $overrides));
    }

    private function resident(array $attributes = []): User
    {
        return User::factory()->role(UserRole::Homeowner)->create($attributes);
    }

    // ── Reading ──────────────────────────────────────────────────────

    public function test_any_signed_in_user_can_read_the_feed(): void
    {
        $this->warning();

        foreach (UserRole::cases() as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get('/dashboard/warnings')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Dashboard/Warnings')
                    ->has('warnings.data', 1)
                );
        }
    }

    public function test_tallies_are_counted_from_votes_not_stored_counters(): void
    {
        $warning = $this->warning();

        foreach (range(1, 3) as $_) {
            $warning->responses()->create([
                'user_id' => $this->resident()->id,
                'response' => 'confirmed',
            ]);
        }

        $warning->responses()->create([
            'user_id' => $this->resident()->id,
            'response' => 'denied',
        ]);

        $this->actingAs($this->resident())
            ->get('/dashboard/warnings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('warnings.data.0.confirms', 3)
                ->where('warnings.data.0.denies', 1)
                // The viewer has not voted, so their own status is empty.
                ->where('warnings.data.0.userStatus', null)
            );
    }

    public function test_the_feed_reports_the_viewers_own_vote(): void
    {
        $warning = $this->warning();
        $voter = $this->resident();

        $warning->responses()->create(['user_id' => $voter->id, 'response' => 'denied']);

        $this->actingAs($voter)
            ->get('/dashboard/warnings')
            ->assertInertia(fn (Assert $page) => $page->where('warnings.data.0.userStatus', 'denied'));
    }

    public function test_only_security_roles_are_offered_removal(): void
    {
        $this->warning();

        foreach ([UserRole::SystemAdmin, UserRole::Admin, UserRole::Security] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get('/dashboard/warnings')
                ->assertInertia(fn (Assert $page) => $page->where('can.remove', true));
        }

        foreach ([UserRole::Homeowner, UserRole::TemporaryHomeowner, UserRole::Staff] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get('/dashboard/warnings')
                ->assertInertia(fn (Assert $page) => $page->where('can.remove', false));
        }
    }

    public function test_alerts_are_listed_newest_first(): void
    {
        $this->warning(['title' => 'Older alert', 'issued_at' => now()->subWeek()]);
        $this->warning(['title' => 'Newer alert', 'issued_at' => now()->subMinute()]);

        $this->actingAs($this->resident())
            ->get('/dashboard/warnings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('warnings.data.0.title', 'Newer alert')
                ->where('warnings.data.1.title', 'Older alert')
            );
    }

    // ── Raising an alert ─────────────────────────────────────────────

    public function test_a_resident_can_raise_an_alert(): void
    {
        /*
         * An earlier revision gated this on `manageSecurity`, so the Send Alert
         * button the page showed every resident returned 403. The page is built
         * around resident reports — see WarningController's docblock.
         */
        $resident = $this->resident(['display_name' => 'Marcus Vance']);

        $this->actingAs($resident)
            ->post('/dashboard/warnings', [
                'title' => 'Lost Golden Retriever',
                'description' => 'Buddy went missing near the park. Friendly, blue collar.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('warnings', [
            'title' => 'Lost Golden Retriever',
            'author_id' => $resident->id,
            'author_name' => 'Marcus Vance',
        ]);

        $this->assertDatabaseHas('activity_log_entries', [
            'user_id' => $resident->id,
            'action' => 'Raised safety alert: Lost Golden Retriever',
        ]);
    }

    public function test_security_can_also_raise_an_alert(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->post('/dashboard/warnings', [
                'title' => 'Perimeter fence damage',
                'description' => 'North section under repair, please avoid the area.',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('warnings', 1);
    }

    public function test_a_title_and_description_must_be_substantial(): void
    {
        // Mirrors the zod rules on the form: 5 and 10 characters.
        $this->actingAs($this->resident())
            ->post('/dashboard/warnings', ['title' => 'Hi', 'description' => 'Short'])
            ->assertSessionHasErrors(['title', 'description']);

        $this->assertDatabaseCount('warnings', 0);
    }

    public function test_raising_alerts_is_rate_limited(): void
    {
        /*
         * An alert notifies the whole community, and any resident can now send
         * one. Three per hour, then the route refuses.
         */
        $resident = $this->resident();

        foreach (range(1, 3) as $n) {
            $this->actingAs($resident)
                ->post('/dashboard/warnings', [
                    'title' => "Genuine alert number {$n}",
                    'description' => 'Something worth telling the community about.',
                ])
                ->assertRedirect();
        }

        $this->actingAs($resident)
            ->post('/dashboard/warnings', [
                'title' => 'Fourth alert in an hour',
                'description' => 'This one should not get through.',
            ])
            ->assertStatus(429);

        $this->assertDatabaseCount('warnings', 3);
    }

    // ── Voting ───────────────────────────────────────────────────────

    public function test_a_vote_is_stored_rather_than_counted_in_the_browser(): void
    {
        $warning = $this->warning();
        $voter = $this->resident();

        $this->actingAs($voter)
            ->post("/dashboard/warnings/{$warning->id}/respond", ['response' => 'confirmed'])
            ->assertRedirect();

        $this->assertDatabaseHas('warning_responses', [
            'warning_id' => $warning->id,
            'user_id' => $voter->id,
            'response' => 'confirmed',
        ]);
    }

    public function test_a_vote_can_be_changed_but_not_stacked(): void
    {
        $warning = $this->warning();
        $voter = $this->resident();

        foreach (['confirmed', 'confirmed', 'denied'] as $response) {
            $this->actingAs($voter)
                ->post("/dashboard/warnings/{$warning->id}/respond", ['response' => $response]);
        }

        $this->assertSame(1, $warning->fresh()->responses()->count());
        $this->assertSame(0, $warning->fresh()->confirmsCount());
        $this->assertSame(1, $warning->fresh()->deniesCount());
    }

    public function test_an_author_cannot_corroborate_their_own_alert(): void
    {
        $author = $this->resident();

        $warning = $this->warning([
            'author_id' => $author->id,
            'author_name' => $author->display_name,
        ]);

        $this->actingAs($author)
            ->post("/dashboard/warnings/{$warning->id}/respond", ['response' => 'confirmed'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('warning_responses', 0);
    }

    public function test_the_feed_flags_the_viewers_own_alert(): void
    {
        $author = $this->resident();

        $this->warning(['author_id' => $author->id, 'author_name' => $author->display_name]);

        $this->actingAs($author)
            ->get('/dashboard/warnings')
            ->assertInertia(fn (Assert $page) => $page->where('warnings.data.0.isMine', true));

        $this->actingAs($this->resident())
            ->get('/dashboard/warnings')
            ->assertInertia(fn (Assert $page) => $page->where('warnings.data.0.isMine', false));
    }

    public function test_an_unknown_response_value_is_rejected(): void
    {
        $warning = $this->warning();

        $this->actingAs($this->resident())
            ->post("/dashboard/warnings/{$warning->id}/respond", ['response' => 'maybe'])
            ->assertSessionHasErrors('response');

        $this->assertDatabaseCount('warning_responses', 0);
    }

    // ── Removing a false alarm ───────────────────────────────────────

    public function test_security_can_remove_an_alert_and_its_votes(): void
    {
        $warning = $this->warning();

        $warning->responses()->create([
            'user_id' => $this->resident()->id,
            'response' => 'confirmed',
        ]);

        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->delete("/dashboard/warnings/{$warning->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseCount('warnings', 0);
        // warning_responses cascades on delete.
        $this->assertDatabaseCount('warning_responses', 0);
    }

    public function test_a_resident_cannot_remove_an_alert(): void
    {
        $warning = $this->warning();

        $this->actingAs($this->resident())
            ->delete("/dashboard/warnings/{$warning->id}")
            ->assertForbidden();

        $this->assertDatabaseCount('warnings', 1);
    }

    public function test_an_author_cannot_delete_their_own_alert_without_the_permission(): void
    {
        // Removal is a moderation action, not an ownership one — otherwise the
        // person who raised a false alarm decides whether it stays on record.
        $author = $this->resident();
        $warning = $this->warning(['author_id' => $author->id]);

        $this->actingAs($author)
            ->delete("/dashboard/warnings/{$warning->id}")
            ->assertForbidden();

        $this->assertDatabaseCount('warnings', 1);
    }
}
