<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BrandingSetting;
use App\Models\Community;
use App\Models\Landmark;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers the map and settings pages once their localStorage stores became
 * server state: landmarks, the boundary editor's config, per-user preferences
 * and estate-wide branding.
 */
class MapAndSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, array{0: float, 1: float}> */
    private function validPolygon(): array
    {
        return [
            [18.4742, -77.9284],
            [18.4748, -77.9241],
            [18.4771, -77.9232],
            [18.4790, -77.9246],
        ];
    }

    private function pointsPayload(array $polygon): array
    {
        return array_map(
            fn (array $p, int $i) => ['label' => 'Point '.($i + 1), 'lat' => $p[0], 'lng' => $p[1]],
            $polygon,
            array_keys($polygon),
        );
    }

    // ── Map page ─────────────────────────────────────────────────────

    public function test_landmarks_come_from_the_database_not_local_storage(): void
    {
        $community = Community::default();

        $community->landmarks()->create([
            'name' => 'Main Security Gate', 'category' => 'Security Gate',
            'description' => 'Primary vehicular entry.', 'lat' => 18.4750, 'lng' => -77.9257,
        ]);

        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->get('/dashboard/map')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Map')
                ->has('landmarks', 1)
                ->where('landmarks.0.name', 'Main Security Gate')
                ->where('landmarks.0.color', '#10B981')
                ->where('landmarks.0.iconType', 'shield')
                ->has('landmarks.0.elevation')
            );
    }

    public function test_a_resident_cannot_add_a_landmark(): void
    {
        // Adding a pin changes the map for the whole estate.
        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->post('/dashboard/map/landmarks', [
                'name' => 'My Pin', 'category' => 'Park',
                'lat' => 18.4766, 'lng' => -77.9257,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('landmarks', 0);
    }

    public function test_an_admin_can_add_and_remove_a_landmark(): void
    {
        Community::default();
        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->actingAs($admin)
            ->post('/dashboard/map/landmarks', [
                'name' => 'New Playground', 'category' => 'Park',
                'description' => 'Shaded play area.',
                'lat' => 18.4775, 'lng' => -77.9268,
            ])
            ->assertSessionHasNoErrors();

        $landmark = Landmark::firstWhere('name', 'New Playground');
        $this->assertNotNull($landmark);

        $this->actingAs($admin)
            ->delete("/dashboard/map/landmarks/{$landmark->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('landmarks', 0);
    }

    public function test_a_landmark_category_must_be_one_of_the_three_pin_types(): void
    {
        Community::default();

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->post('/dashboard/map/landmarks', [
                'name' => 'Odd One', 'category' => 'Helipad',
                'lat' => 18.4766, 'lng' => -77.9257,
            ])
            ->assertSessionHasErrors('category');
    }

    public function test_the_boundary_editor_receives_the_server_config(): void
    {
        $community = Community::default();

        $config = $community->boundaryConfigs()->create([
            'version' => 3,
            'status' => 'PUBLISHED',
            'published_coordinates' => $this->validPolygon(),
            'last_published_at' => now(),
            'last_published_by' => 'Elena Rostova',
        ]);

        foreach ($this->validPolygon() as $index => [$lat, $lng]) {
            $config->points()->create([
                'point_index' => $index + 1,
                'label' => 'Point '.($index + 1),
                'lat' => $lat,
                'lng' => $lng,
                'is_optional' => $index + 1 > 4,
            ]);
        }

        $this->actingAs(User::factory()->role(UserRole::SystemAdmin)->create())
            ->get('/dashboard/map')
            ->assertInertia(fn (Assert $page) => $page
                ->where('boundaryConfig.version', 3)
                ->where('boundaryConfig.status', 'PUBLISHED')
                ->has('boundaryConfig.points', 4)
                ->where('boundaryConfig.points.0.label', 'Point 1')
                ->where('boundaryConfig.lastPublishedBy', 'Elena Rostova')
                ->where('can.manageBoundary', true)
            );
    }

    public function test_the_map_reports_no_boundary_before_one_is_published(): void
    {
        Community::default();

        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->get('/dashboard/map')
            ->assertInertia(fn (Assert $page) => $page
                ->where('boundaryConfig.version', 0)
                ->has('boundaryConfig.points', 0)
                ->has('boundary.coordinates', 0)
                ->where('can.manageBoundary', false)
            );
    }

    public function test_publishing_supersedes_the_previous_version(): void
    {
        $community = Community::default();
        $sysAdmin = User::factory()->role(UserRole::SystemAdmin)->create();

        $this->actingAs($sysAdmin)
            ->post('/dashboard/map/boundary/publish', [
                'points' => $this->pointsPayload($this->validPolygon()),
            ])
            ->assertSessionHasNoErrors();

        $first = $community->fresh()->publishedBoundary();
        $this->assertNotNull($first);

        // A second publish moves the estate to v2 and retires v1.
        $shifted = array_map(fn ($p) => [$p[0] + 0.001, $p[1]], $this->validPolygon());

        $this->actingAs($sysAdmin)
            ->post('/dashboard/map/boundary/publish', ['points' => $this->pointsPayload($shifted)])
            ->assertSessionHasNoErrors();

        $this->assertSame($first->version + 1, $community->fresh()->publishedBoundary()->version);
        $this->assertSame('SUPERSEDED', $first->fresh()->status);
    }

    public function test_a_self_intersecting_boundary_is_refused(): void
    {
        Community::default();

        // A bow-tie: the 1-2 edge crosses the 3-4 edge. The client editor already
        // refused this; the server had not, so it could be posted directly.
        $bowTie = [
            [18.4742, -77.9284],
            [18.4790, -77.9241],
            [18.4742, -77.9241],
            [18.4790, -77.9284],
        ];

        $this->actingAs(User::factory()->role(UserRole::SystemAdmin)->create())
            ->post('/dashboard/map/boundary/publish', ['points' => $this->pointsPayload($bowTie)])
            ->assertSessionHasErrors('points');

        $this->assertNull(Community::default()->publishedBoundary());
    }

    public function test_a_draft_does_not_change_what_the_gates_see(): void
    {
        $community = Community::default();
        $sysAdmin = User::factory()->role(UserRole::SystemAdmin)->create();

        $this->actingAs($sysAdmin)
            ->post('/dashboard/map/boundary/draft', [
                'points' => $this->pointsPayload($this->validPolygon()),
            ])
            ->assertSessionHasNoErrors();

        // Saved, but not in force.
        $this->assertNotNull($community->fresh()->draftBoundary());
        $this->assertNull($community->fresh()->publishedBoundary());
    }

    public function test_an_admin_cannot_publish_a_boundary(): void
    {
        Community::default();

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->post('/dashboard/map/boundary/publish', [
                'points' => $this->pointsPayload($this->validPolygon()),
            ])
            ->assertForbidden();
    }

    public function test_a_resident_cannot_publish_a_boundary(): void
    {
        Community::default();

        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->post('/dashboard/map/boundary/publish', [
                'points' => $this->pointsPayload($this->validPolygon()),
            ])
            ->assertForbidden();
    }

    public function test_the_locate_endpoint_answers_from_the_published_boundary(): void
    {
        $community = Community::default();

        $community->boundaryConfigs()->create([
            'version' => 1,
            'status' => 'PUBLISHED',
            'published_coordinates' => [
                [18.470, -77.930],
                [18.480, -77.930],
                [18.480, -77.920],
                [18.470, -77.920],
            ],
            'last_published_at' => now(),
        ]);

        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($resident)
            ->postJson('/dashboard/map/locate', ['lat' => 18.475, 'lng' => -77.925])
            ->assertOk()
            ->assertJsonPath('perimeter.isInside', true);

        $this->actingAs($resident)
            ->postJson('/dashboard/map/locate', ['lat' => 18.500, 'lng' => -77.925])
            ->assertOk()
            ->assertJsonPath('perimeter.isInside', false);
    }

    // ── Settings page ────────────────────────────────────────────────

    public function test_notification_preferences_persist(): void
    {
        $user = User::factory()->role(UserRole::Homeowner)->create();

        // Previously three uncontrolled switches and a Save button with no handler.
        $this->actingAs($user)
            ->patch('/dashboard/settings', [
                'notify_email' => false,
                'notify_push' => true,
                'notify_sms' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $user->id,
            'notify_email' => false,
            'notify_push' => true,
            'notify_sms' => true,
        ]);
    }

    public function test_settings_returns_the_stored_preferences(): void
    {
        $user = User::factory()->role(UserRole::Homeowner)->create();

        $user->preferences()->create([
            'theme' => 'dark',
            'notify_email' => false,
            'notify_push' => true,
            'notify_sms' => false,
        ]);

        $this->actingAs($user)
            ->get('/dashboard/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Settings')
                ->where('preferences.theme', 'dark')
                ->where('preferences.notifyEmail', false)
                ->where('preferences.notifyPush', true)
                ->where('can.brand', false)
            );
    }

    public function test_a_resident_cannot_change_community_branding(): void
    {
        // Branding is estate-wide; personal preferences are not.
        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->patch('/dashboard/settings', ['app_name' => 'Hijacked Estate'])
            ->assertForbidden();

        $this->assertDatabaseMissing('branding_settings', ['app_name' => 'Hijacked Estate']);
    }

    public function test_an_admin_can_change_community_branding(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->patch('/dashboard/settings', [
                'app_name' => 'Cypress Bay Living',
                'theme_tokens' => ['themePreset' => 'ocean', 'iconSize' => 32],
            ])
            ->assertSessionHasNoErrors();

        $branding = BrandingSetting::first();

        $this->assertSame('Cypress Bay Living', $branding->app_name);
        $this->assertSame('ocean', $branding->theme_tokens['themePreset']);
    }

    public function test_branding_is_shared_with_every_page(): void
    {
        BrandingSetting::create([
            'app_name' => 'Cypress Bay Living',
            'theme_tokens' => ['themePreset' => 'sunset', 'communityName' => 'CYPRESS BAY'],
        ]);

        // Shared globally, so the logo and palette are identical for every user
        // instead of coming from each browser's localStorage.
        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('branding.appName', 'Cypress Bay Living')
                ->where('branding.themeTokens.themePreset', 'sunset')
            );
    }

    public function test_a_theme_change_does_not_clobber_the_branding_preset(): void
    {
        BrandingSetting::create([
            'app_name' => 'Community Hub',
            'theme_tokens' => ['themePreset' => 'ocean', 'iconSize' => 40],
        ]);

        // useTheme() merges into the existing token blob rather than replacing it.
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->patch('/dashboard/settings', [
                'theme_tokens' => [
                    'themePreset' => 'ocean',
                    'iconSize' => 40,
                    'primary' => '#ff0000',
                    'font' => 'Roboto',
                ],
            ])
            ->assertSessionHasNoErrors();

        $tokens = BrandingSetting::first()->theme_tokens;

        $this->assertSame('#ff0000', $tokens['primary']);
        $this->assertSame('ocean', $tokens['themePreset']);
        $this->assertSame(40, $tokens['iconSize']);
    }

    public function test_changing_a_password_requires_the_current_one(): void
    {
        $user = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($user)
            ->patch('/dashboard/settings', [
                'current_password' => 'wrong-password',
                'password' => 'Str0ng-New-Password!23',
                'password_confirmation' => 'Str0ng-New-Password!23',
            ])
            ->assertSessionHasErrors('current_password');
    }

    public function test_export_geojson_returns_valid_features(): void
    {
        $community = Community::default();
        $sysAdmin = User::factory()->role(UserRole::SystemAdmin)->create();

        $config = $community->boundaryConfigs()->create([
            'version' => 1,
            'status' => 'PUBLISHED',
            'published_coordinates' => $this->validPolygon(),
            'last_published_at' => now(),
            'last_published_by' => $sysAdmin->display_name,
        ]);

        foreach ($this->validPolygon() as $index => $pair) {
            $config->points()->create([
                'point_index' => $index + 1,
                'label' => 'Point '.($index + 1),
                'lat' => $pair[0],
                'lng' => $pair[1],
                'is_optional' => $index >= 4,
            ]);
        }

        $response = $this->actingAs($sysAdmin)
            ->get('/dashboard/map/boundary/export')
            ->assertOk();

        $this->assertStringContainsString('attachment;', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('.geojson', $response->headers->get('content-disposition'));
    }

    public function test_import_geojson_parses_polygon(): void
    {
        $sysAdmin = User::factory()->role(UserRole::SystemAdmin)->create();

        $geojson = [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [
                            [
                                [-77.930, 18.470],
                                [-77.930, 18.480],
                                [-77.920, 18.480],
                                [-77.920, 18.470],
                                [-77.930, 18.470],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($sysAdmin)
            ->postJson('/dashboard/map/boundary/import', [
                'geojson' => $geojson,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $points = $response->json('points');
        $this->assertCount(4, $points);
        $this->assertSame(18.470, $points[0]['lat']);
        $this->assertSame(-77.930, $points[0]['lng']);
    }
}
