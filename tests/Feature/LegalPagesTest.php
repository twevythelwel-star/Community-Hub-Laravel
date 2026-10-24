<?php

namespace Tests\Feature;

use App\Models\PaymentLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the policy pages and the claims that must not reappear.
 *
 * The assertions about absent text are the point of this file. Each one marks a
 * statement the application made that was not true, and each would be easy to
 * reintroduce by copying an older component.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, array{0: string}> */
    public static function policyRoutes(): array
    {
        return [
            ['privacy'],
            ['terms'],
            ['refunds'],
            ['cookies'],
        ];
    }

    #[DataProvider('policyRoutes')]
    public function test_a_policy_page_is_public(string $name): void
    {
        $this->get(route($name))->assertOk();
    }

    #[DataProvider('policyRoutes')]
    public function test_a_policy_page_warns_while_the_business_details_are_placeholders(string $name): void
    {
        config(['legal.complete' => false]);

        $this->get(route($name))
            ->assertOk()
            ->assertSee('This policy is a draft and is not in force.');
    }

    #[DataProvider('policyRoutes')]
    public function test_the_draft_warning_clears_once_the_details_are_complete(string $name): void
    {
        config(['legal.complete' => true]);

        $this->get(route($name))
            ->assertOk()
            ->assertDontSee('This policy is a draft and is not in force.');
    }

    public function test_the_cookie_policy_lists_the_session_cookie_by_its_real_name(): void
    {
        // A cookie policy naming cookies the application does not set is no
        // better than no policy.
        $this->get(route('cookies'))
            ->assertOk()
            ->assertSee(config('session.cookie'))
            ->assertSee('XSRF-TOKEN');
    }

    public function test_no_public_page_claims_a_compliance_certification(): void
    {
        /*
         | "🔒 PCI-DSS Compliant" and "🔒 256-bit Encrypted" both sat above card
         | number and CVC inputs that submitted nothing. Neither claim was
         | backed by anything.
         */
        foreach (['landing', 'privacy', 'terms', 'refunds', 'cookies'] as $name) {
            $response = $this->get(route($name));

            $response->assertDontSee('PCI-DSS');
            $response->assertDontSee('PCI DSS');
            $response->assertDontSee('256-bit');
        }
    }

    public function test_public_payment_links_are_disabled_by_default(): void
    {
        /*
         | No payment driver takes money, and the controller records a
         | `completed` Transaction regardless. While this route is reachable,
         | anyone with a link can fabricate a settled payment without signing
         | in. See config/payments.php.
         */
        $link = PaymentLink::create([
            'token' => 'default-disabled-token',
            'title' => 'Perimeter Lighting Fund',
            'amount_minor' => 5000,
            'currency' => 'JMD',
            'active' => true,
        ]);

        $this->get("/pay/{$link->token}")->assertNotFound();
        $this->get("/pay/{$link->token}/poster")->assertNotFound();

        $this->post("/pay/{$link->token}/process", [
            'channel' => 'cash_office',
            'payer_name' => 'Somebody Else',
            'lot' => '42',
        ])->assertNotFound();

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_public_payment_cannot_be_attributed_to_a_guessed_resident(): void
    {
        /*
         | `User::where('name', 'like', "%{$payer_name}%")->first()` attached the
         | transaction to whichever resident's name happened to match, so a
         | stranger could post a payment against someone else's account — or a
         | common surname could do it by accident.
         */
        config(['payments.public_links_enabled' => true]);

        $resident = User::factory()->create(['name' => 'Marcus Vance']);

        $link = PaymentLink::create([
            'token' => 'attribution-token',
            'title' => 'Security Upgrade',
            'amount_minor' => 15000,
            'currency' => 'JMD',
            'active' => true,
        ]);

        $this->post("/pay/{$link->token}/process", [
            'channel' => 'cash_office',
            'payer_name' => 'Marcus',
            'lot' => '42',
        ])->assertRedirect();

        $this->assertDatabaseMissing('payments', ['user_id' => $resident->id]);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['state' => 'awaiting_transfer', 'user_id' => null]);
        // Nothing is on the ledger until the office has verified the money.
        $this->assertDatabaseCount('transactions', 0);
    }
}
