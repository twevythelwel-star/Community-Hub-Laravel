<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BrandingSetting;
use App\Models\Community;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginThemePresetColorTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders_active_theme_preset_colors(): void
    {
        $community = Community::query()->firstOrCreate(
            ['code' => config('gatepass.default_community_id')],
            ['name' => 'Sunset Ridge Village', 'datum' => 'JAD2001']
        );
        $community->update(['name' => 'Sunset Ridge Village']);

        $branding = BrandingSetting::current();
        $branding->update([
            'community_id' => $community->id,
            'theme_tokens' => [
                'themePreset' => 'sunset',
                'communityName' => 'Sunset Ridge Village',
            ],
        ]);

        $response = $this->get('/login');
        $response->assertOk();

        // Sunset preset primary is '16 90% 50%' and accent is '38 92% 50%'
        $response->assertSee('--primary: 16 90% 50%;', false);
        $response->assertSee('--accent: 38 92% 50%;', false);
        $response->assertSee('bg-primary');
        $response->assertSee('from-primary/25');
        $response->assertSee('Sunset Ridge Village');
    }

    public function test_all_fifteen_theme_presets_resolve_valid_colors(): void
    {
        $presets = [
            'triovo', 'classic', 'ocean', 'sunset', 'brutalist',
            'emerald', 'amethyst', 'crimson', 'dunes', 'nordic',
            'sage', 'copper', 'rose', 'slate', 'espresso',
        ];
        $branding = BrandingSetting::current();

        foreach ($presets as $presetKey) {
            $branding->update([
                'theme_tokens' => ['themePreset' => $presetKey],
            ]);

            $colors = $branding->fresh()->getThemePresetColors();
            $this->assertNotEmpty($colors['primary']);
            $this->assertNotEmpty($colors['primary_foreground']);
            $this->assertNotEmpty($colors['accent']);
            $this->assertNotEmpty($colors['accent_foreground']);

            $response = $this->get('/login');
            $response->assertOk();
            $response->assertSee("--primary: {$colors['primary']};", false);
            $response->assertSee("--accent: {$colors['accent']};", false);
        }
    }

    public function test_without_a_chosen_preset_the_login_page_keeps_the_app_css_colors(): void
    {
        // app.css carries its own, brighter, dark-mode primary; an injected
        // default would replace it on every community that never picked one.
        BrandingSetting::current()->update(['theme_tokens' => ['communityName' => 'Cypress Bay']]);

        $this->get('/login')
            ->assertOk()
            ->assertDontSee('--primary:', false);
    }

    public function test_the_php_and_typescript_palettes_match(): void
    {
        // The dashboard reads branding-context.tsx; the Blade pages read
        // BrandingSetting. A preset edited in one must be edited in the other.
        $source = file_get_contents(resource_path('js/context/branding-context.tsx'));

        foreach (BrandingSetting::THEME_PRESETS as $key => $colors) {
            $this->assertMatchesRegularExpression(
                '/\b'.$key.":\s*\{\s*primary:\s*'".preg_quote($colors['primary'], '/')."',\s*\/\/[^\n]*\n\s*primaryForeground:\s*'".preg_quote($colors['primary_foreground'], '/')."',[^\n]*\n\s*accent:\s*'".preg_quote($colors['accent'], '/')."',[^\n]*\n\s*accentForeground:\s*'".preg_quote($colors['accent_foreground'], '/')."'/",
                $source,
                "The {$key} preset differs between BrandingSetting and branding-context.tsx",
            );
        }

        // \r?: a Windows checkout (core.autocrlf) has CRLF line endings.
        preg_match_all('/^    ([a-z]+): \{\r?$/m', $source, $tsKeys);
        $this->assertEqualsCanonicalizing(array_keys(BrandingSetting::THEME_PRESETS), $tsKeys[1]);
    }

    public function test_admin_updating_preset_in_settings_updates_login_page_colors(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::SystemAdmin,
        ]);

        $this->actingAs($admin)->patch('/dashboard/settings', [
            'theme_tokens' => [
                'themePreset' => 'ocean',
            ],
        ])->assertSessionHasNoErrors();

        // Check unauthenticated login page
        auth()->logout();
        $response = $this->get('/login');
        $response->assertOk();

        // Ocean primary is '199 89% 48%' and accent is '173 80% 40%'
        $response->assertSee('--primary: 199 89% 48%;', false);
        $response->assertSee('--accent: 173 80% 40%;', false);
    }
}
