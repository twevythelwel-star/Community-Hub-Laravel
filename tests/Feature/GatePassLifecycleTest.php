<?php

namespace Tests\Feature;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\QRShape;
use App\Enums\UserRole;
use App\Exceptions\InvalidPassTransition;
use App\Models\GatePass;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pass lifecycle (REQUESTED → APPROVED → ISSUED → ACTIVE → CHECKED_IN →
 * CHECKED_OUT, with REJECTED, CANCELLED, REVOKED, EXPIRED, SUSPENDED as
 * exits), profile shapes and colours, and issuance for accounts and guests.
 */
class GatePassLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function engine(): GatePassEngine
    {
        return app(GatePassEngine::class);
    }

    private function visitor(array $overrides = []): Visitor
    {
        return Visitor::factory()->create([
            'type' => 'One-time',
            'expected_at' => now()->addHours(2),
            ...$overrides,
        ]);
    }

    // ── Profiles ──

    public function test_every_profile_has_its_own_shape_including_visitors_and_contractors(): void
    {
        $expected = [
            'SYSADMIN' => QRShape::Star8,
            'ADMIN' => QRShape::Octagon,
            'HOMEOWNER' => QRShape::Hexagon,
            'RENTER' => QRShape::RoundedSquare,
            'STAFF' => QRShape::Diamond,
            'SECURITY' => QRShape::Shield,
            'HOMEOWNER_STAFF' => QRShape::HouseHex,
            'VISITOR' => QRShape::Circle,
            'CONTRACTOR' => QRShape::Pentagon,
        ];

        foreach ($expected as $category => $shape) {
            $this->assertSame($shape, PassCategory::from($category)->shape(), $category);
        }

        $this->assertCount(count(PassCategory::cases()), array_unique(array_map(
            fn (PassCategory $c) => $c->shape()->value, PassCategory::cases(),
        )));
    }

    public function test_every_profile_has_visual_config_a_palette_and_a_policy(): void
    {
        foreach (PassCategory::cases() as $category) {
            $this->assertNotNull(config("gatepass.categories.{$category->value}"), "{$category->value} has no visual config");
            $this->assertSame($category->shape()->value, config("gatepass.categories.{$category->value}.shape"));
            $this->assertNotEmpty(config("gatepass.palettes.{$category->value}"), "{$category->value} has no palette");
            $this->assertNotNull(config("gatepass.policies.{$category->value}"), "{$category->value} has no policy");
        }
    }

    public function test_every_palette_colour_really_meets_wcag_aa_against_white(): void
    {
        // Computed from the hex, so a wrong `contrast_ratio` in config cannot hide a failing colour.
        $luminance = function (string $hex): float {
            $channel = function (int $value): float {
                $c = $value / 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            };
            [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%02x%02x%02x');

            return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
        };

        foreach (config('gatepass.palettes') as $category => $palette) {
            foreach ($palette as $variant) {
                $ratio = 1.05 / ($luminance($variant['hex']) + 0.05);
                $this->assertGreaterThanOrEqual(4.5, $ratio, "{$category}/{$variant['id']} is {$ratio}:1");
            }
        }
    }

    // ── Colour ──

    public function test_a_new_pass_gets_a_random_colour_from_its_approved_palette(): void
    {
        $pass = $this->engine()->issuePassFor(User::factory()->create());
        $paletteIds = array_column(config('gatepass.palettes.HOMEOWNER'), 'id');

        $this->assertContains($pass->color_variant, $paletteIds);
        $this->assertSame($pass->color_variant, $this->engine()->variantFor($pass)['id']);
    }

    public function test_colours_vary_between_passes(): void
    {
        $colours = collect(range(1, 20))
            ->map(fn () => $this->engine()->issuePassFor(User::factory()->create())->color_variant)
            ->unique();

        $this->assertGreaterThan(1, $colours->count());
    }

    public function test_rotation_changes_the_colour_and_the_sequence(): void
    {
        $pass = $this->engine()->issuePassFor(User::factory()->create());
        $before = $pass->color_variant;

        $this->engine()->rotateVisualIdentity($pass);

        $this->assertSame(2, $pass->fresh()->rotation_seq);
        $this->assertNotSame($before, $pass->fresh()->color_variant);
    }

    // ── Account passes ──

    public function test_an_account_pass_is_issued_active_and_multi_entry_with_a_history(): void
    {
        $pass = $this->engine()->issuePassFor(User::factory()->create());

        $this->assertSame(PassStatus::Active, $pass->status);
        $this->assertFalse($pass->single_entry);
        $this->assertNull($pass->valid_until);
        $this->assertSame(PassStatus::Active, $pass->transitions()->sole()->to_status);
    }

    public function test_a_revoked_pass_is_not_silently_reissued(): void
    {
        $user = User::factory()->create();
        $pass = $this->engine()->issuePassFor($user);
        $pass->revoke(User::factory()->role(UserRole::Security)->create(), 'Phone stolen');

        $again = $this->engine()->issuePassFor($user);

        $this->assertTrue($again->is($pass));
        $this->assertSame(PassStatus::Revoked, $again->status);
    }

    public function test_security_can_reissue_after_revocation_and_the_new_pass_gets_its_own_id(): void
    {
        $user = User::factory()->create();
        $guard = User::factory()->role(UserRole::Security)->create();
        $old = $this->engine()->issuePassFor($user);
        $old->revoke($guard, 'Phone stolen');

        $new = $this->engine()->reissuePassFor($user, $guard);

        $this->assertSame(PassStatus::Active, $new->status);
        $this->assertNotSame($old->pass_id, $new->pass_id);
        $this->assertTrue($this->engine()->issuePassFor($user)->is($new));
    }

    public function test_a_live_pass_cannot_be_reissued_over(): void
    {
        $user = User::factory()->create();
        $this->engine()->issuePassFor($user);

        $this->expectException(\RuntimeException::class);

        $this->engine()->reissuePassFor($user, User::factory()->role(UserRole::Security)->create());
    }

    // ── Guest passes ──

    public function test_a_residents_visitor_is_approved_and_issued_straight_away(): void
    {
        $visitor = $this->visitor();
        $host = $visitor->homeowner;

        $pass = $this->engine()->issueGuestPass($visitor, $host);

        $this->assertSame(PassStatus::Issued, $pass->status);
        $this->assertSame(PassCategory::Visitor, $pass->category);
        $this->assertTrue($pass->single_entry);
        $this->assertSame(
            [null, 'REQUESTED', 'APPROVED'],
            $pass->transitions()->pluck('from_status')->map(fn ($s) => $s?->value)->all(),
        );
    }

    public function test_a_contractor_waits_for_approval(): void
    {
        $visitor = $this->visitor();

        $pass = $this->engine()->issueGuestPass($visitor, $visitor->homeowner, PassCategory::Contractor);

        $this->assertSame(PassStatus::Requested, $pass->status);
        $this->assertSame(QRShape::Pentagon, $pass->category->shape());

        $this->engine()->approve($pass, User::factory()->role(UserRole::Security)->create());

        $this->assertSame(PassStatus::Issued, $pass->fresh()->status);
    }

    public function test_a_one_time_pass_opens_an_hour_early_and_closes_twelve_hours_after_arrival(): void
    {
        $arrival = CarbonImmutable::parse('2026-10-01 14:00:00');
        $visitor = $this->visitor(['expected_at' => $arrival]);

        $pass = $this->engine()->issueGuestPass($visitor, $visitor->homeowner);

        $this->assertEquals($arrival->subHour(), $pass->valid_from);
        $this->assertEquals($arrival->addHours(12), $pass->valid_until);
    }

    public function test_a_recurring_pass_lasts_thirty_days_and_allows_re_entry(): void
    {
        $arrival = CarbonImmutable::parse('2026-10-01 14:00:00');
        $visitor = $this->visitor(['expected_at' => $arrival, 'type' => 'Recurring']);

        $pass = $this->engine()->issueGuestPass($visitor, $visitor->homeowner);

        $this->assertEquals($arrival->addDays(30), $pass->valid_until);
        $this->assertFalse($pass->single_entry);
    }

    // ── Transitions ──

    public function test_illegal_moves_are_refused(): void
    {
        $visitor = $this->visitor();
        $pass = $this->engine()->issueGuestPass($visitor, $visitor->homeowner, PassCategory::Contractor);

        $this->expectException(InvalidPassTransition::class);

        // A request cannot jump straight to checked in.
        $pass->transitionTo(PassStatus::CheckedIn);
    }

    public function test_terminal_states_have_no_way_out(): void
    {
        foreach ([PassStatus::Rejected, PassStatus::Cancelled, PassStatus::Revoked, PassStatus::Expired] as $status) {
            $this->assertTrue($status->isTerminal(), $status->value);
            foreach (PassStatus::cases() as $next) {
                $this->assertFalse($status->canTransitionTo($next), "{$status->value} → {$next->value}");
            }
        }
    }

    public function test_a_single_entry_pass_cannot_be_used_twice(): void
    {
        $visitor = $this->visitor();
        $pass = $this->engine()->issueGuestPass($visitor, $visitor->homeowner);

        $pass->transitionTo(PassStatus::Active);
        $pass->transitionTo(PassStatus::CheckedIn);
        $pass->transitionTo(PassStatus::CheckedOut);

        $this->assertFalse($pass->canTransitionTo(PassStatus::CheckedIn));
    }

    public function test_someone_inside_cannot_expire_only_be_checked_out(): void
    {
        $this->assertFalse(PassStatus::CheckedIn->canTransitionTo(PassStatus::Expired));
        $this->assertTrue(PassStatus::CheckedIn->canTransitionTo(PassStatus::CheckedOut));
    }

    public function test_each_move_is_recorded_with_who_and_where(): void
    {
        $guard = User::factory()->role(UserRole::Security)->create();
        $pass = $this->engine()->issuePassFor(User::factory()->create());

        $pass->transitionTo(PassStatus::CheckedIn, $guard, null, GateId::Gate02);

        $last = $pass->transitions()->get()->last();
        $this->assertSame(PassStatus::Active, $last->from_status);
        $this->assertSame(PassStatus::CheckedIn, $last->to_status);
        $this->assertSame($guard->id, $last->actor_id);
        $this->assertSame('GATE-02', $last->gate);
        $this->assertNotNull($pass->fresh()->checked_in_at);
    }

    // ── Expiry ──

    public function test_lapsed_passes_expire_but_people_inside_do_not(): void
    {
        $waiting = $this->visitor(['expected_at' => now()->subDays(2)]);
        $inside = $this->visitor(['expected_at' => now()->subDays(2)]);

        $waitingPass = $this->engine()->issueGuestPass($waiting, $waiting->homeowner);
        $insidePass = $this->engine()->issueGuestPass($inside, $inside->homeowner);
        $insidePass->transitionTo(PassStatus::Active);
        $insidePass->transitionTo(PassStatus::CheckedIn);

        $this->artisan('gatepass:expire')->assertSuccessful();

        $this->assertSame(PassStatus::Expired, $waitingPass->fresh()->status);
        $this->assertSame(PassStatus::CheckedIn, $insidePass->fresh()->status);
    }

    public function test_the_no_show_sweep_expires_the_pass_with_the_clearance(): void
    {
        $visitor = $this->visitor(['expected_at' => now()->subHours(20)]);
        $pass = $this->engine()->issueGuestPass($visitor, $visitor->homeowner);

        $this->artisan('visitors:expire-no-shows')->assertSuccessful();

        $this->assertNotNull($visitor->fresh()->expired_at);
        $this->assertSame(PassStatus::Expired, $pass->fresh()->status);
    }

    public function test_editing_the_arrival_moves_the_pass_window(): void
    {
        $visitor = $this->visitor();
        $pass = $this->engine()->issueGuestPass($visitor, $visitor->homeowner);
        $later = now()->addDays(3)->startOfHour();

        $visitor->update(['expected_at' => $later]);
        $this->engine()->syncGuestPass($pass, $visitor);

        $this->assertEquals($later->copy()->subHour(), $pass->fresh()->valid_from);
    }

    public function test_the_migration_maps_the_old_status_strings(): void
    {
        // Rows written before the lifecycle existed used Active/Suspended/Revoked.
        $this->assertSame(PassStatus::Active, PassStatus::from('ACTIVE'));
        $this->assertNull(GatePass::where('status', 'Active')->first());
    }
}
