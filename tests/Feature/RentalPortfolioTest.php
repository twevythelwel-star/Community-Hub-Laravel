<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Renter;
use App\Models\User;
use Database\Seeders\RentalPortfolioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RentalPortfolioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RentalPortfolioSeeder::class);
    }

    public function test_homeowner_one_has_two_properties(): void
    {
        $homeowner1 = User::where('email', 'damian.clarke@residence.net')->first();
        $this->assertNotNull($homeowner1);
        $this->assertEquals(UserRole::Homeowner, $homeowner1->role);

        $properties = $homeowner1->properties;
        $this->assertCount(2, $properties);

        $lotNumbers = $properties->pluck('lot_number')->all();
        $this->assertContains('Lot 101', $lotNumbers);
        $this->assertContains('Lot 102', $lotNumbers);
    }

    public function test_homeowner_two_has_three_properties(): void
    {
        $homeowner2 = User::where('email', 'victoria.campbell@residence.net')->first();
        $this->assertNotNull($homeowner2);
        $this->assertEquals(UserRole::Homeowner, $homeowner2->role);

        $properties = $homeowner2->properties;
        $this->assertCount(3, $properties);

        $lotNumbers = $properties->pluck('lot_number')->all();
        $this->assertContains('Lot 201', $lotNumbers);
        $this->assertContains('Lot 202', $lotNumbers);
        $this->assertContains('Lot 203', $lotNumbers);
    }

    public function test_rental_portfolio_has_one_hundred_airbnb_and_two_hundred_fifty_long_term_rentals(): void
    {
        $airbnbCount = Renter::where('stay_type', 'Short-term (Airbnb)')->count();
        $longTermCount = Renter::where('stay_type', 'Long-term (Renter)')->count();

        $this->assertEquals(100, $airbnbCount, 'Must have exactly 100 Short-term (Airbnb) rental records');
        $this->assertEquals(250, $longTermCount, 'Must have exactly 250 Long-term rental records');
        $this->assertEquals(350, $airbnbCount + $longTermCount, 'Must have exactly 350 total rentals');

        // Verify all Airbnb rentals have valid required attributes
        $sampleAirbnb = Renter::where('stay_type', 'Short-term (Airbnb)')->first();
        $this->assertNotNull($sampleAirbnb->name);
        $this->assertNotNull($sampleAirbnb->property_id);
        $this->assertNotNull($sampleAirbnb->homeowner_id);
        $this->assertNotNull($sampleAirbnb->lease_start);
        $this->assertNotNull($sampleAirbnb->lease_end);
        $this->assertEquals('Active', $sampleAirbnb->status);
        $this->assertStringContainsString('Airbnb', $sampleAirbnb->notes);

        // Verify all Long-term rentals have valid required attributes
        $sampleLongTerm = Renter::where('stay_type', 'Long-term (Renter)')->first();
        $this->assertNotNull($sampleLongTerm->name);
        $this->assertNotNull($sampleLongTerm->property_id);
        $this->assertNotNull($sampleLongTerm->homeowner_id);
        $this->assertNotNull($sampleLongTerm->lease_start);
        $this->assertNotNull($sampleLongTerm->lease_end);
        $this->assertEquals('Active', $sampleLongTerm->status);
    }

    public function test_renters_dashboard_renders_cleanly(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard.renters'));
        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Renters')
            ->has('renters.data')
        );
    }
}
