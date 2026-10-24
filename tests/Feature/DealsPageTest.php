<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Business;
use App\Models\FoodApp;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_residents_see_real_businesses_vouchers_and_food_apps(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();
        $business = Business::create(['name' => 'Island Pharmacy', 'active' => true]);
        Voucher::create([
            'business_id' => $business->id,
            'title' => '15% Off Prescriptions',
            'description' => 'Valid on all wellness items.',
            'expires_at' => now()->addDays(30),
        ]);
        FoodApp::create([
            'name' => '7Krave',
            'website_url' => 'https://7krave.com',
            'coupon_percentage' => 10,
        ]);

        $this->actingAs($resident)->get('/dashboard/deals')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Deals')
                ->has('businesses', 1)
                ->where('businesses.0.name', 'Island Pharmacy')
                ->where('businesses.0.vouchers.0.title', '15% Off Prescriptions')
                ->has('foodApps', 1)
                ->where('foodApps.0.name', '7Krave')
                ->where('canManage', false)
            );
    }

    public function test_admin_can_publish_and_delete_vouchers(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();
        $business = Business::create(['name' => 'Hardware Store', 'active' => true]);

        $this->actingAs($admin)->post('/dashboard/deals/vouchers', [
            'business_id' => $business->id,
            'title' => '10% Off Paint',
            'description' => 'Discounts on exterior emulsions.',
        ])->assertRedirect()->assertSessionHas('success', 'Voucher published.');

        $voucher = Voucher::sole();
        $this->assertSame('10% Off Paint', $voucher->title);

        $this->actingAs($admin)->delete("/dashboard/deals/vouchers/{$voucher->id}")
            ->assertRedirect()->assertSessionHas('success', 'Voucher deleted.');

        $this->assertDatabaseMissing('vouchers', ['id' => $voucher->id]);
    }

    public function test_admin_can_add_and_delete_food_delivery_apps(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->actingAs($admin)->post('/dashboard/deals/food-apps', [
            'name' => 'QuickPlate',
            'website_url' => 'https://quickplate.com',
            'coupon_percentage' => 20,
        ])->assertRedirect()->assertSessionHas('success', 'Food delivery option added.');

        $app = FoodApp::where('name', 'QuickPlate')->firstOrFail();

        $this->actingAs($admin)->delete("/dashboard/deals/food-apps/{$app->id}")
            ->assertRedirect()->assertSessionHas('success', 'Food delivery option removed.');

        $this->assertDatabaseMissing('food_apps', ['id' => $app->id]);
    }

    public function test_homeowner_cannot_publish_vouchers_or_food_apps(): void
    {
        $homeowner = User::factory()->role(UserRole::Homeowner)->create();
        $business = Business::create(['name' => 'Bakery', 'active' => true]);

        $this->actingAs($homeowner)->post('/dashboard/deals/vouchers', [
            'business_id' => $business->id,
            'title' => 'Free Pastry',
            'description' => 'With coffee purchase',
        ])->assertForbidden();

        $this->assertDatabaseCount('vouchers', 0);
    }
}
