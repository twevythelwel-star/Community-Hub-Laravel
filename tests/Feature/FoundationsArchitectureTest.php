<?php

namespace Tests\Feature;

use App\Http\Resources\GatePassResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\VisitorResource;
use App\Mail\VisitorPassMail;
use App\Models\GatePass;
use App\Models\User;
use App\Models\Visitor;
use App\Policies\GatePassPolicy;
use App\Policies\VisitorPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FoundationsArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_resources_transform_models_accurately(): void
    {
        $user = User::factory()->create([
            'name' => 'Jane Resident',
            'email' => 'jane@example.com',
            'lot' => '42',
            'street' => 'Hibiscus Way',
        ]);

        $userResource = (new UserResource($user))->resolve();
        $this->assertSame('Jane Resident', $userResource['name']);
        $this->assertSame('jane@example.com', $userResource['email']);
        $this->assertSame('42', $userResource['lot']);

        $visitor = Visitor::factory()->create([
            'homeowner_id' => $user->id,
            'name' => 'Courier Driver',
            'type' => 'One-time',
        ]);

        $visitorResource = (new VisitorResource($visitor))->resolve();
        $this->assertSame('Courier Driver', $visitorResource['name']);
        $this->assertSame('One-time', $visitorResource['type']);

        $pass = GatePass::factory()->create([
            'user_id' => $user->id,
            'holder_name' => 'Jane Resident',
        ]);

        $passResource = (new GatePassResource($pass))->resolve();
        $this->assertSame('Jane Resident', $passResource['holderName']);
    }

    public function test_policies_are_registered_and_enforced(): void
    {
        $this->assertInstanceOf(VisitorPolicy::class, Gate::getPolicyFor(Visitor::class));
        $this->assertInstanceOf(GatePassPolicy::class, Gate::getPolicyFor(GatePass::class));

        $user = User::factory()->create();
        $visitor = Visitor::factory()->create(['homeowner_id' => $user->id]);

        $this->assertTrue($user->can('view', $visitor));
        $this->assertTrue($user->can('update', $visitor));
    }

    public function test_visitor_pass_mailable_renders_view(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $visitor = Visitor::factory()->create(['homeowner_id' => $user->id]);

        $mailable = new VisitorPassMail($visitor);
        $mailable->assertSeeInHtml($visitor->name);
        $mailable->assertHasSubject('Community Hub: Your Visitor Pass');
    }

    public function test_localization_domain_strings_resolve(): void
    {
        $this->assertSame('Your visitor pass is ready for access.', __('community.pass_ready'));
        $this->assertSame('This gate pass has expired and is no longer valid for entry.', __('community.pass_expired'));
    }
}
