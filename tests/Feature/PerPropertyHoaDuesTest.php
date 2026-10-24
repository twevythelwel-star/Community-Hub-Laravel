<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BillingSetting;
use App\Models\Community;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Property;
use App\Models\Renter;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Billing\AssessmentBillingService;
use App\Services\Billing\AssessmentGenerationResult;
use App\Services\PropertyOwnershipService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HOA dues are charged per property. Billing was per person: an owner of three
 * lots paid once, a renter was billed on top of the owner, and a homeowner's
 * only property was the single lot on their account.
 */
class PerPropertyHoaDuesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        BillingSetting::create(['monthly_fee_minor' => 500000, 'currency' => 'JMD', 'due_day_of_month' => 5]);
        $this->confirmPassword();
    }

    private function homeowner(array $attributes = []): User
    {
        return User::factory()->role(UserRole::Homeowner)->create($attributes + ['lot' => 'Lot 14', 'street' => 'Hibiscus Way']);
    }

    private function bill(bool $dryRun = false): AssessmentGenerationResult
    {
        return app(AssessmentBillingService::class)->generateMonthlyAssessments('2026-11', dryRun: $dryRun);
    }

    // ── Billing ──────────────────────────────────────────────────────────

    public function test_an_owner_of_two_properties_is_billed_for_each_once(): void
    {
        $owner = $this->homeowner();
        $ownership = app(PropertyOwnershipService::class);
        $first = $ownership->recordAccountProperty($owner);
        $second = $ownership->assign($owner, 'Lot 22', 'Royal Palm Drive');

        $result = $this->bill();

        $this->assertSame(2, $result->invoicesGenerated);
        $this->assertSame(1000000, $result->totalBilledMinor);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], Invoice::pluck('property_id')->all());
        $this->assertSame(2, Invoice::where('user_id', $owner->id)->count());
        $this->assertStringContainsString('Lot 22, Royal Palm Drive', InvoiceItem::whereHas('invoice', fn ($q) => $q->where('property_id', $second->id))->value('title'));

        // A second run the same month bills neither again.
        $rerun = $this->bill();
        $this->assertSame(0, $rerun->invoicesGenerated);
        $this->assertSame(2, $rerun->invoicesSkipped);
        $this->assertSame(2, Invoice::count());
    }

    public function test_the_lot_on_a_homeowners_account_is_recorded_and_billed(): void
    {
        $owner = $this->homeowner();

        // A dry run counts it without writing anything.
        $simulated = $this->bill(dryRun: true);
        $this->assertSame(1, $simulated->totalEligible);
        $this->assertSame(0, Property::count());

        $this->bill();

        $property = Property::sole();
        $this->assertTrue($property->owner->is($owner));
        $this->assertSame('Lot 14, Hibiscus Way', $property->label());
        $this->assertSame($property->id, Invoice::sole()->property_id);
    }

    public function test_renters_are_not_billed_for_a_property_its_owner_pays_for(): void
    {
        $owner = $this->homeowner();
        $renter = User::factory()->role(UserRole::TemporaryHomeowner)->create(['lot' => 'Lot 14', 'street' => 'Hibiscus Way']);

        $this->bill();

        $this->assertSame(1, Invoice::count());
        $this->assertSame(0, Invoice::where('user_id', $renter->id)->count());
        $this->assertSame($owner->id, Invoice::sole()->user_id);
    }

    public function test_two_accounts_at_one_address_are_billed_for_it_once(): void
    {
        $owner = $this->homeowner();
        app(PropertyOwnershipService::class)->recordAccountProperty($owner);
        $coOwner = $this->homeowner();

        $result = $this->bill();

        // It used to crash the run: recording the co-owner's lot was refused.
        $this->assertSame(1, $result->invoicesGenerated);
        $this->assertSame([$coOwner->id], $result->ownersWithoutProperty);
        $this->assertSame(1, Property::count());
    }

    public function test_a_homeowner_with_no_property_is_reported_not_billed(): void
    {
        $noLot = $this->homeowner(['lot' => null, 'street' => null]);

        $result = $this->bill();

        $this->assertSame(0, Invoice::count());
        $this->assertSame([$noLot->id], $result->ownersWithoutProperty);

        // A warning, not a failed run.
        $this->artisan('billing:generate-assessments', ['--month' => '2026-12'])
            ->expectsOutputToContain('no property on record')
            ->assertSuccessful();
    }

    public function test_a_released_property_is_no_longer_billed(): void
    {
        $owner = $this->homeowner();
        $ownership = app(PropertyOwnershipService::class);
        $ownership->recordAccountProperty($owner);
        $sold = $ownership->assign($owner, 'Lot 22');

        $ownership->release($sold);
        $this->bill();

        $this->assertSame(1, Invoice::count());
        $this->assertNotSame($sold->id, Invoice::sole()->property_id);
        $this->assertModelExists($sold);
    }

    public function test_an_invoice_from_before_per_property_dues_is_not_billed_again(): void
    {
        $owner = $this->homeowner();
        $legacy = Invoice::create([
            'user_id' => $owner->id,
            'reference' => 'INV-LEGACY',
            'amount_minor' => 500000,
            'currency' => 'JMD',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'due_on' => '2026-11-05',
            'status' => 'Unpaid',
        ]);
        InvoiceItem::create(['invoice_id' => $legacy->id, 'category' => 'hoa_dues', 'title' => 'Monthly HOA Assessment', 'amount_minor' => 500000, 'status' => 'Unpaid']);

        $result = $this->bill();

        $this->assertSame(0, $result->invoicesGenerated);
        $this->assertSame(1, Invoice::count());
    }

    public function test_the_community_filter_bills_that_communitys_properties(): void
    {
        $community = Community::create(['name' => 'Orchid Valley', 'code' => 'CID-ORCHID']);
        $owner = $this->homeowner();
        $ownership = app(PropertyOwnershipService::class);
        $inside = $ownership->assign($owner, 'Lot 1');
        $inside->update(['community_id' => $community->id]);
        $ownership->assign($owner, 'Lot 2');

        // It filtered on users.community_id, a column that does not exist.
        $result = app(AssessmentBillingService::class)->generateMonthlyAssessments('2026-11', community: 'CID-ORCHID');

        $this->assertSame(1, $result->invoicesGenerated);
        $this->assertSame($inside->id, Invoice::sole()->property_id);
    }

    public function test_the_billing_page_says_which_property_each_invoice_is_for(): void
    {
        $owner = $this->homeowner();
        $ownership = app(PropertyOwnershipService::class);
        $ownership->recordAccountProperty($owner);
        $ownership->assign($owner, 'Lot 22', 'Royal Palm Drive');
        $this->bill();

        $this->actingAs($owner)->get('/dashboard/billing')->assertInertia(fn ($page) => $page
            ->where('myInvoices.data', fn ($rows) => collect($rows)->pluck('property')->sort()->values()->all()
                === ['Lot 14, Hibiscus Way', 'Lot 22, Royal Palm Drive']));

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())->get('/dashboard/billing')->assertInertia(fn ($page) => $page
            ->where('invoices.data', fn ($rows) => collect($rows)->pluck('property')->filter()->count() === 2));
    }

    // ── Visitors: which property they are coming to ──────────────────────

    private function visitorFor(array $extra = []): array
    {
        return $extra + [
            'name' => 'Weekend Guest',
            'type' => 'One-time',
            'expected_at' => now()->addDay()->toIso8601String(),
        ];
    }

    public function test_an_owner_of_two_properties_registers_a_visitor_for_the_second(): void
    {
        $owner = $this->homeowner();
        $ownership = app(PropertyOwnershipService::class);
        $ownership->recordAccountProperty($owner);
        $second = $ownership->assign($owner, 'Lot 22', 'Royal Palm Drive');

        $this->actingAs($owner)->get('/dashboard/visitors')
            ->assertInertia(fn ($page) => $page->has('hostProperties', 2));

        $this->post('/dashboard/visitors', $this->visitorFor(['property_id' => $second->id]))->assertSessionHasNoErrors();

        $visitor = Visitor::sole();
        $this->assertSame($second->id, $visitor->property_id);
        // The pass names the property visited, not the host's account address.
        $this->assertSame('Lot 22, Royal Palm Drive', $visitor->gatePass->property);
    }

    public function test_a_visitor_cannot_be_registered_to_someone_elses_property(): void
    {
        $neighbour = $this->homeowner(['lot' => 'Lot 30']);
        $theirs = app(PropertyOwnershipService::class)->recordAccountProperty($neighbour);

        $this->actingAs($this->homeowner())
            ->post('/dashboard/visitors', $this->visitorFor(['property_id' => $theirs->id]))
            ->assertSessionHasErrors('property_id');

        $this->assertSame(0, Visitor::count());
    }

    public function test_without_a_choice_the_pass_takes_the_hosts_address(): void
    {
        $this->actingAs($this->homeowner())->post('/dashboard/visitors', $this->visitorFor())->assertSessionHasNoErrors();

        $this->assertSame('Lot 14, Hibiscus Way', Visitor::sole()->gatePass->property);
    }

    // ── Directory: recording what each owner holds ───────────────────────

    public function test_an_administrator_records_a_second_property(): void
    {
        $owner = $this->homeowner();
        app(PropertyOwnershipService::class)->recordAccountProperty($owner);

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->post("/dashboard/directory/users/{$owner->id}/properties", ['lot_number' => 'Lot 22', 'street_address' => 'Royal Palm Drive'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['Lot 14, Hibiscus Way', 'Lot 22, Royal Palm Drive'], $owner->properties()->orderBy('id')->get()->map->label()->all());

        $this->get('/dashboard/directory')->assertInertia(fn ($page) => $page
            ->where('residents', fn ($residents) => count(collect($residents)->firstWhere('id', $owner->id)['properties']) === 2));
    }

    public function test_a_lot_someone_else_owns_cannot_be_given_to_another(): void
    {
        $owner = $this->homeowner();
        app(PropertyOwnershipService::class)->recordAccountProperty($owner);
        $other = $this->homeowner(['lot' => 'Lot 30']);

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->post("/dashboard/directory/users/{$other->id}/properties", ['lot_number' => 'Lot 14', 'street_address' => 'Hibiscus Way'])
            ->assertSessionHasErrors('lot_number');

        $this->assertSame(1, Property::where('lot_number', 'Lot 14')->count());
    }

    public function test_only_homeowners_own_and_only_administrators_record_properties(): void
    {
        $owner = $this->homeowner();
        $security = User::factory()->role(UserRole::Security)->create();

        $this->actingAs($owner)
            ->post("/dashboard/directory/users/{$owner->id}/properties", ['lot_number' => 'Lot 99'])
            ->assertForbidden();

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->post("/dashboard/directory/users/{$security->id}/properties", ['lot_number' => 'Lot 99'])
            ->assertSessionHasErrors('lot_number');

        $this->assertSame(0, Property::where('lot_number', 'Lot 99')->count());
    }

    public function test_removing_a_property_keeps_the_lot_and_its_invoices(): void
    {
        $owner = $this->homeowner();
        $property = app(PropertyOwnershipService::class)->recordAccountProperty($owner);
        $this->bill();

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->delete("/dashboard/directory/properties/{$property->id}")
            ->assertSessionHasNoErrors();

        $this->assertNull($property->fresh()->owner_user_id);
        $this->assertSame($property->id, Invoice::sole()->property_id);
    }

    public function test_creating_a_homeowner_records_their_lot(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->post('/dashboard/directory/users', [
                'name' => 'New Owner',
                'email' => 'new.owner@example.com',
                'role' => UserRole::Homeowner->value,
                'lot' => 'Lot 7',
                'street' => 'Coral Way',
                'password' => 'Str0ng-Passw0rd!x',
                'password_confirmation' => 'Str0ng-Passw0rd!x',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Lot 7, Coral Way', User::where('email', 'new.owner@example.com')->sole()->properties()->sole()->label());
    }

    // ── Access & People ──────────────────────────────────────────────────

    public function test_a_renter_whose_homeowner_has_no_property_on_record_can_open_access_and_people(): void
    {
        Carbon::setTestNow('2026-10-07 10:00');
        $owner = $this->homeowner(['lot' => null, 'street' => null]);
        $renterUser = User::factory()->role(UserRole::TemporaryHomeowner)->create(['lot' => null]);
        Renter::create([
            'user_id' => $renterUser->id,
            'homeowner_id' => $owner->id,
            'name' => $renterUser->name,
            'status' => 'Active',
            'lease_start' => '2026-10-01',
            'lease_end' => '2027-03-31',
        ]);

        // It looked properties up by a homeowner_id column that does not exist.
        $this->actingAs($renterUser)->getJson('/dashboard/delegation')->assertOk();
    }
}
