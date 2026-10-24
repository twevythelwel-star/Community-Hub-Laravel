<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserProfileThemePresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_homeowner_can_set_custom_personal_theme_preset(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
        ]);

        $response = $this->actingAs($homeowner)->post('/dashboard/profile/theme-preset', [
            'theme_preset' => 'sage',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $prefs = UserPreference::where('user_id', $homeowner->id)->first();
        $this->assertNotNull($prefs);
        $this->assertSame('sage', $prefs->theme_preset);

        // Verify shared branding and theme in Inertia page render
        $pageResponse = $this->actingAs($homeowner)->get('/dashboard/profile');
        $pageResponse->assertOk();
        $pageResponse->assertInertia(fn ($page) => $page->component('Dashboard/Profile')
            ->where('branding.userThemePreset', 'sage')
            ->where('theme.preset', 'sage')
            ->where('profile.userThemePreset', 'sage')
        );
    }

    public function test_security_officer_can_adjust_personal_theme_preset(): void
    {
        $officer = User::factory()->create([
            'role' => UserRole::Security,
        ]);

        $response = $this->actingAs($officer)->post('/dashboard/profile/theme-preset', [
            'theme_preset' => 'copper',
        ]);

        $response->assertSessionHasNoErrors();

        $prefs = UserPreference::where('user_id', $officer->id)->first();
        $this->assertNotNull($prefs);
        $this->assertSame('copper', $prefs->theme_preset);
    }

    public function test_user_can_reset_theme_to_community_default(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Staff,
        ]);

        $prefs = UserPreference::create([
            'user_id' => $user->id,
            'theme_preset' => 'amethyst',
        ]);

        $response = $this->actingAs($user)->post('/dashboard/profile/theme-preset', [
            'theme_preset' => 'default',
        ]);

        $response->assertSessionHasNoErrors();

        $prefs->refresh();
        $this->assertNull($prefs->theme_preset);

        $pageResponse = $this->actingAs($user)->get('/dashboard/profile');
        $pageResponse->assertOk();
        $pageResponse->assertInertia(fn ($page) => $page->component('Dashboard/Profile')
            ->where('branding.userThemePreset', null)
            ->where('theme.preset', null)
        );
    }

    public function test_invalid_theme_preset_is_rejected(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Homeowner,
        ]);

        $response = $this->actingAs($user)->post('/dashboard/profile/theme-preset', [
            'theme_preset' => 'nonexistent_rainbow_theme',
        ]);

        $response->assertSessionHasErrors(['theme_preset']);
    }

    public function test_all_fifteen_presets_accepted_for_user_profile(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Homeowner,
        ]);

        $all15 = [
            'triovo', 'classic', 'ocean', 'sunset', 'brutalist',
            'emerald', 'amethyst', 'crimson', 'dunes', 'nordic',
            'sage', 'copper', 'rose', 'slate', 'espresso',
        ];

        foreach ($all15 as $preset) {
            $response = $this->actingAs($user)->post('/dashboard/profile/theme-preset', [
                'theme_preset' => $preset,
            ]);
            $response->assertSessionHasNoErrors();
            $this->assertSame($preset, $user->fresh()->preferences->theme_preset);
        }
    }
}
