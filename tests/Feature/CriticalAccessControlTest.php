<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AccessApprovalRequest;
use App\Models\AccessAuditTimelineEvent;
use App\Models\AccessRiskIncident;
use App\Models\AuthorizationDocument;
use App\Models\GatePass;
use App\Models\User;
use App\Services\AccessApprovalWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Access governance and the gate sensor log had no permission checks:
 * any signed-in account could approve access requests (each minting a gate pass),
 * lock down any credential, sweep passes, or dismiss a tailgating violation.
 */
class CriticalAccessControlTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function guardedRoutes(): array
    {
        return [
            'governance page' => ['get', '/dashboard/access-governance'],
            'approve a request' => ['post', '/dashboard/access-governance/approvals/1/approve'],
            'reject a request' => ['post', '/dashboard/access-governance/approvals/1/reject'],
            'lock down a credential' => ['post', '/dashboard/access-governance/incidents/1/lockdown'],
            'resolve an incident' => ['post', '/dashboard/access-governance/incidents/1/resolve'],
            'a pass timeline' => ['get', '/dashboard/access-governance/timeline/GP-1'],
            'sweep expired passes' => ['post', '/dashboard/access-governance/passes/expire-sweep'],
            'audit documents' => ['post', '/dashboard/access-governance/documents/audit'],
            'attach a document' => ['post', '/dashboard/access-governance/documents/attach'],
            'resolve a sensor event' => ['post', '/dashboard/gate-sensor/resolve'],
            'read sensor events' => ['get', '/dashboard/gate-sensor/recent'],
        ];
    }

    #[DataProvider('guardedRoutes')]
    public function test_a_resident_is_refused(string $method, string $uri): void
    {
        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->json($method, $uri)
            ->assertForbidden();
    }

    public function test_security_runs_incidents_but_cannot_grant_access(): void
    {
        $officer = User::factory()->role(UserRole::Security)->create();

        $this->actingAs($officer)->getJson('/dashboard/gate-sensor/recent')->assertOk();
        $this->postJson('/dashboard/access-governance/approvals/1/approve')->assertForbidden();
        $this->postJson('/dashboard/access-governance/passes/expire-sweep')->assertForbidden();
    }

    public function test_opening_governance_invents_nothing(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/dashboard/access-governance')
            ->assertOk();

        // It used to fill empty tables with demo requests (one issuing a live
        // gate pass), documents, critical incidents and a sample timeline.
        $this->assertSame(0, AccessApprovalRequest::count());
        $this->assertSame(0, GatePass::count());
        $this->assertSame(0, AuthorizationDocument::count());
        $this->assertSame(0, AccessRiskIncident::count());
        $this->assertSame(0, AccessAuditTimelineEvent::count());
    }

    private function pendingRequest(User $homeowner, ?string $property = null): AccessApprovalRequest
    {
        return AccessApprovalRequest::create([
            'request_number' => 'APR-TEST-0001',
            'category' => 'contractor',
            'applicant_name' => 'ABC Plumbing',
            'homeowner_id' => $homeowner->id,
            'property_number' => $property,
            'workflow_type' => 'contractor_property_admin',
            'current_stage' => 'property_admin_approval',
            'status' => 'pending',
            'requested_until' => now()->addDays(3),
        ]);
    }

    public function test_an_administrator_approves_once_and_only_once(): void
    {
        $homeowner = User::factory()->role(UserRole::Homeowner)->create(['lot' => '9', 'street' => 'Palm Vista Drive']);
        $request = $this->pendingRequest($homeowner, 'Lot 9');
        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->actingAs($admin)->post("/dashboard/access-governance/approvals/{$request->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame(1, GatePass::where('holder_name', 'ABC Plumbing')->count());

        // Approving again used to mint another pass.
        $this->post("/dashboard/access-governance/approvals/{$request->id}/approve")->assertSessionHasErrors('request');
        $this->post("/dashboard/access-governance/approvals/{$request->id}/reject", ['reason' => 'late'])->assertSessionHasErrors('request');
        $this->assertSame(1, GatePass::where('holder_name', 'ABC Plumbing')->count());
    }

    public function test_a_request_without_a_property_takes_the_homeowners_not_an_invented_one(): void
    {
        $homeowner = User::factory()->role(UserRole::Homeowner)->create(['lot' => '9', 'street' => 'Palm Vista Drive']);

        $request = app(AccessApprovalWorkflowService::class)->submitRequest([
            'category' => 'visitor',
            'applicant_name' => 'Guest Without Property',
        ], $homeowner);

        // It fell back to "Unit 14", on the request and on the pass it issued.
        $this->assertSame($homeowner->propertyLabel(), $request->property_number);
        $this->assertSame($homeowner->propertyLabel(), GatePass::where('holder_name', 'Guest Without Property')->value('property'));
    }
}
