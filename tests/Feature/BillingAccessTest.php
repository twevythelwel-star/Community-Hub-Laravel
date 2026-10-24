<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who may reach the payments area.
 *
 * Administrators, Homeowners and Temporary Homeowners. Security and Staff are
 * not billed by the estate, and the whole area used to be ungated — the sidebar
 * hid the link from them without closing the door.
 */
class BillingAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: UserRole}> */
    public static function allowedRoles(): array
    {
        return [
            'system admin' => [UserRole::SystemAdmin],
            'admin' => [UserRole::Admin],
            'homeowner' => [UserRole::Homeowner],
            'temporary homeowner' => [UserRole::TemporaryHomeowner],
        ];
    }

    /** @return array<string, array{0: UserRole}> */
    public static function refusedRoles(): array
    {
        return [
            'security' => [UserRole::Security],
            'staff' => [UserRole::Staff],
        ];
    }

    #[DataProvider('allowedRoles')]
    public function test_an_allowed_role_can_open_the_payments_page(UserRole $role): void
    {
        $this->actingAs(User::factory()->role($role)->create())
            ->get('/dashboard/billing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Billing'));
    }

    #[DataProvider('refusedRoles')]
    public function test_a_refused_role_cannot_open_the_payments_page(UserRole $role): void
    {
        $this->actingAs(User::factory()->role($role)->create())
            ->get('/dashboard/billing')
            ->assertForbidden();
    }

    #[DataProvider('refusedRoles')]
    public function test_a_refused_role_gets_no_wallet_created_for_them(UserRole $role): void
    {
        /*
         | BillingController::index() calls Wallet::firstOrCreate(), so while the
         | page was ungated a GET from Security or Staff created a wallet row
         | against an account the estate never bills.
         */
        $user = User::factory()->role($role)->create();

        $this->actingAs($user)->get('/dashboard/billing')->assertForbidden();

        $this->assertDatabaseMissing('wallets', ['user_id' => $user->id]);
    }

    #[DataProvider('refusedRoles')]
    public function test_a_refused_role_cannot_post_a_payment(UserRole $role): void
    {
        $this->actingAs(User::factory()->role($role)->create())
            ->post('/dashboard/billing/pay', [
                'channel' => 'cash_office',
                'amount' => 5000,
                'currency' => 'JMD',
            ])
            ->assertForbidden();
    }

    public function test_a_resident_still_cannot_change_the_estate_rates(): void
    {
        // accessBilling opens the page; manageBilling is the narrower right to
        // change what everybody pays.
        foreach ([UserRole::Homeowner, UserRole::TemporaryHomeowner] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->patch('/dashboard/billing/settings', [
                    'monthly_fee' => 1,
                    'currency' => 'JMD',
                    'due_day_of_month' => 1,
                ])
                ->assertForbidden();
        }
    }

    public function test_a_temporary_homeowner_sees_the_resident_view_not_the_estate_view(): void
    {
        $this->actingAs(User::factory()->role(UserRole::TemporaryHomeowner)->create())
            ->get('/dashboard/billing')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canManage', false)
                ->where('adminSummary', null)
                ->has('myInvoices')
            );
    }
}
