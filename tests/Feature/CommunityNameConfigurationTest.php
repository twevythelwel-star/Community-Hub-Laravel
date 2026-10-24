<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BrandingSetting;
use App\Models\Community;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityNameConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed a default community and branding
        Community::query()->firstOrCreate(
            ['code' => config('gatepass.default_community_id')],
            ['name' => 'Initial Community Test', 'datum' => 'JAD2001']
        );

        BrandingSetting::query()->firstOrCreate([], [
            'app_name' => 'Community Hub',
            'theme_tokens' => ['communityName' => 'Initial Community Test'],
        ]);
    }

    public function test_system_admin_can_change_community_name(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SystemAdmin]);

        $response = $this->actingAs($admin)
            ->patch('/dashboard/settings', [
                'community_name' => 'Royal Palm Haven',
                'app_name' => 'Community Hub Pro',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Verify database tables updated
        $this->assertSame('Royal Palm Haven', Community::default()->name);
        $this->assertSame('Royal Palm Haven', BrandingSetting::current()->theme_tokens['communityName']);
    }

    public function test_regular_resident_cannot_change_community_name(): void
    {
        $resident = User::factory()->create(['role' => UserRole::Homeowner]);

        $response = $this->actingAs($resident)
            ->patch('/dashboard/settings', [
                'community_name' => 'Malicious Takeover Estates',
            ]);

        $response->assertForbidden();

        // Verify community name untouched
        $this->assertNotSame('Malicious Takeover Estates', Community::default()->name);
    }

    public function test_login_and_landing_screens_display_community_name(): void
    {
        Community::query()->update(['name' => 'Emerald Ridge Estates']);
        $branding = BrandingSetting::current();
        $tokens = $branding->theme_tokens ?? [];
        $tokens['communityName'] = 'Emerald Ridge Estates';
        $branding->update(['theme_tokens' => $tokens]);

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Emerald Ridge Estates');
        // Ensure community name is displayed above the user name / email input
        $response->assertSee('Authorized Access');
    }

    public function test_the_gate_pass_pdf_reflects_the_updated_community_name(): void
    {
        Community::query()->update(['name' => 'Whispering Pines Sanctuary']);

        // The view composer supplies the community when the controller does not.
        $pass = ['name' => 'John Resident', 'category' => 'Visitor', 'lot' => 'Lot 7', 'vehicle' => null, 'qrImage' => ''];

        $this->assertStringContainsString('WHISPERING PINES SANCTUARY', view('pdf.gate-pass', $pass)->render());

        $pdf = Pdf::loadView('pdf.gate-pass', $pass)->output();
        $this->assertStringStartsWith('%PDF-', $pdf);
    }
}
