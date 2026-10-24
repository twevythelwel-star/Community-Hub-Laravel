<?php

namespace Tests\Feature;

use App\Enums\VisitorStatus;
use App\Models\Community;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestPassTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitor_automatically_receives_share_token(): void
    {
        $homeowner = User::factory()->create();

        $visitor = Visitor::create([
            'name' => 'Michael Chang',
            'type' => 'One-time',
            'homeowner_id' => $homeowner->id,
            'homeowner_name' => $homeowner->name,
            'status' => VisitorStatus::Expected,
            'expected_at' => now()->addHours(2),
        ]);

        $this->assertNotNull($visitor->share_token);
        $this->assertEquals(32, strlen($visitor->share_token));
    }

    public function test_guest_pass_view_is_publicly_accessible(): void
    {
        $community = Community::create([
            'name' => 'Cypress Bay',
            'code' => 'CYPRESS-01',
            'slug' => 'cypress-bay',
            'domain' => 'cypressbay.communityhub.org',
            'center_latitude' => 18.0,
            'center_longitude' => -76.8,
        ]);

        $homeowner = User::factory()->create(['name' => 'Marcus Vance', 'lot' => 'Lot 42']);

        $visitor = Visitor::create([
            'name' => 'Michael Chang',
            'type' => 'One-time',
            'homeowner_id' => $homeowner->id,
            'homeowner_name' => $homeowner->name,
            'status' => VisitorStatus::Expected,
            'expected_at' => now()->addHours(3),
        ]);

        $response = $this->get(route('guest-pass.show', ['token' => $visitor->share_token]));

        $response->assertOk();
        $response->assertSee('Michael Chang');
        $response->assertSee('Marcus Vance');
        $response->assertSee('Digital Guest Pass');
    }

    public function test_invalid_token_returns_404(): void
    {
        $response = $this->get(route('guest-pass.show', ['token' => 'invalid-token-123']));
        $response->assertNotFound();
    }

    public function test_visitor_pdf_download_streams_pdf(): void
    {
        $homeowner = User::factory()->create();
        $visitor = Visitor::create([
            'name' => 'Michael Chang',
            'type' => 'One-time',
            'homeowner_id' => $homeowner->id,
            'homeowner_name' => $homeowner->name,
            'status' => VisitorStatus::Expected,
            'expected_at' => now()->addHours(2),
        ]);

        $response = $this->get(route('pdf.visitor-pass', ['token' => $visitor->share_token]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    /**
     * The permit PDF is public, so whatever identifies the visitor in that URL
     * is the credential. Bound to `{visitor}` it was the row id, which meant
     * walking 1..n returned every guest's name, host lot and vehicle to an
     * unauthenticated caller. It is keyed on the share token now.
     */
    public function test_visitor_pdf_is_not_reachable_by_row_id(): void
    {
        $homeowner = User::factory()->create();
        $visitor = Visitor::create([
            'name' => 'Michael Chang',
            'type' => 'One-time',
            'homeowner_id' => $homeowner->id,
            'homeowner_name' => $homeowner->name,
            'status' => VisitorStatus::Expected,
            'expected_at' => now()->addHours(2),
        ]);

        $this->get("/guest/pass/{$visitor->id}/pdf")->assertNotFound();
    }

    public function test_visitor_pdf_rejects_an_unknown_token(): void
    {
        $this->get('/guest/pass/not-a-real-token/pdf')->assertNotFound();
    }
}
