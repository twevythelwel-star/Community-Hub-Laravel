<?php

namespace Tests\Feature;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Models\AccessAuditTimelineEvent;
use App\Models\AccessLogEntry;
use App\Models\AccessRiskIncident;
use App\Models\User;
use App\Services\AccessApprovalWorkflowService;
use App\Services\AccessAuditTimelineService;
use App\Services\AccessExpirationPolicyService;
use App\Services\AccessRiskEngine;
use App\Services\DigitalAuthorizationDocumentService;
use App\Services\GatePassEngine;
use App\Services\GateScanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessGovernanceAndRiskTest extends TestCase
{
    use RefreshDatabase;

    private GatePassEngine $engine;

    private GateScanner $scanner;

    private AccessRiskEngine $riskEngine;

    private AccessExpirationPolicyService $expirationPolicy;

    private AccessApprovalWorkflowService $approvalService;

    private DigitalAuthorizationDocumentService $documentService;

    private AccessAuditTimelineService $timelineService;

    private User $guard;

    private User $homeowner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00')); // Wednesday 10:00 AM

        $this->engine = app(GatePassEngine::class);
        $this->scanner = app(GateScanner::class);
        $this->riskEngine = app(AccessRiskEngine::class);
        $this->expirationPolicy = app(AccessExpirationPolicyService::class);
        $this->approvalService = app(AccessApprovalWorkflowService::class);
        $this->documentService = app(DigitalAuthorizationDocumentService::class);
        $this->timelineService = app(AccessAuditTimelineService::class);

        $this->guard = User::factory()->role(UserRole::Security)->create([
            'name' => 'Officer Miller',
            'display_name' => 'Officer Miller',
        ]);

        $this->homeowner = User::factory()->role(UserRole::Homeowner)->create([
            'name' => 'David Sterling',
            'display_name' => 'David Sterling',
        ]);

        $this->admin = User::factory()->role(UserRole::Admin)->create([
            'name' => 'Estate Admin Sarah',
            'display_name' => 'Estate Admin Sarah',
        ]);
    }

    public function test_suspicious_access_engine_flags_revoked_pass_attempt(): void
    {
        $pass = $this->engine->issuePassFor($this->homeowner);
        $token = $this->engine->issueToken($pass, GateId::Gate01)['token'];

        // Revoke the pass
        $pass->update([
            'status' => PassStatus::Revoked,
            'revoked_at' => now(),
            'revocation_reason' => 'Reported lost by homeowner',
        ]);

        // Attempt scan at Gate 01
        $res = $this->scanner->scan($token, GateId::Gate01, $this->guard);
        $this->assertEquals('REJECT', $res['decision']);

        // Assert AccessRiskIncident was triggered
        $incident = AccessRiskIncident::where('pass_id', $pass->pass_id)->first();
        $this->assertNotNull($incident);
        $this->assertEquals('REVOKED_ATTEMPT', $incident->flag_type);
        $this->assertEquals('CRITICAL', $incident->risk_level);
        $this->assertStringContainsString('Revoked QR Presented', $incident->title);
    }

    public function test_suspicious_access_engine_flags_simultaneous_multi_gate_presentation(): void
    {
        $pass = $this->engine->issuePassFor($this->homeowner);

        // First scan at Gate 01 (Main Gate)
        AccessLogEntry::create([
            'user_id' => $this->homeowner->id,
            'user_name' => $this->homeowner->name,
            'user_role' => 'Homeowner',
            'method' => 'Digital Pass',
            'gate' => 'Main Gate',
            'pass_id' => $pass->pass_id,
            'result' => 'ALLOW',
            'scanned_by' => $this->guard->id,
            'occurred_at' => now()->subSeconds(25),
        ]);

        // Second scan 25 seconds later at Gate 02 (impossible transit / cloning)
        $token = $this->engine->issueToken($pass, GateId::Gate02)['token'];
        $this->scanner->scan($token, GateId::Gate02, $this->guard);

        $incident = AccessRiskIncident::where('pass_id', $pass->pass_id)
            ->where('flag_type', 'SIMULTANEOUS_MULTI_GATE')
            ->first();

        $this->assertNotNull($incident);
        $this->assertEquals('CRITICAL', $incident->risk_level);
        $this->assertStringContainsString('Cloning Detected', $incident->title);
    }

    public function test_emergency_credential_lockdown_suspends_pass(): void
    {
        $pass = $this->engine->issuePassFor($this->homeowner);

        $incident = AccessRiskIncident::create([
            'pass_id' => $pass->pass_id,
            'user_id' => $this->homeowner->id,
            'gate' => 'GATE-01',
            'flag_type' => 'SIMULTANEOUS_MULTI_GATE',
            'risk_level' => 'CRITICAL',
            'title' => '⚠️ Suspicious Credential Activity: Clone Alert',
            'description' => 'Impossible travel between gates',
            'status' => 'NEW',
            'occurred_at' => now(),
        ]);

        $this->actingAs($this->guard)
            ->post(route('dashboard.access-governance.lockdown', $incident->id), [
                'reason' => 'Suspected cloned screenshot',
            ])
            ->assertRedirect();

        $pass->refresh();
        $this->assertEquals(PassStatus::Suspended, $pass->status);

        $incident->refresh();
        $this->assertEquals('LOCKED_DOWN', $incident->status);
    }

    public function test_mandatory_automatic_expiration_policy_computation(): void
    {
        // 1. Visitor expires tonight (23:59:59)
        $visitorExpiry = $this->expirationPolicy->computeMandatoryExpiration(PassCategory::Visitor);
        $this->assertEquals('2026-10-07 23:59:59', $visitorExpiry->toDateTimeString());

        // 2. Contractor expires upcoming Friday at 18:00
        $contractorExpiry = $this->expirationPolicy->computeMandatoryExpiration(PassCategory::Contractor);
        $this->assertEquals('2026-10-09 18:00:00', $contractorExpiry->toDateTimeString());

        // 3. Caregiver/HomeownerStaff expires Dec 31
        $caregiverExpiry = $this->expirationPolicy->computeMandatoryExpiration(PassCategory::HomeownerStaff);
        $this->assertEquals('2026-12-31 23:59:59', $caregiverExpiry->toDateTimeString());

        // 4. Long-Term Occupant expires per verified lease date
        $leaseExpiry = $this->expirationPolicy->computeMandatoryExpiration(PassCategory::LongTermOccupant, [
            'lease_expires_at' => '2027-08-31',
        ]);
        $this->assertEquals('2027-08-31 23:59:59', $leaseExpiry->toDateTimeString());
    }

    public function test_mandatory_expiration_sweep_expires_stale_passes(): void
    {
        // Create an active pass that expired 1 hour ago
        $pass = $this->engine->issuePassFor($this->homeowner);
        $pass->update([
            'valid_until' => now()->subHour(),
        ]);

        $count = $this->expirationPolicy->sweepExpiredPasses();
        $this->assertGreaterThanOrEqual(1, $count);

        $pass->refresh();
        $this->assertEquals(PassStatus::Expired, $pass->status);

        // Verify timeline event recorded
        $timelineEvent = AccessAuditTimelineEvent::where('pass_id', $pass->pass_id)
            ->where('headline', 'Policy Window Expired')
            ->first();
        $this->assertNotNull($timelineEvent);
    }

    public function test_approval_workflows_routing_and_auto_issuing(): void
    {
        // 1. Visitor instant approval
        $visitorReq = $this->approvalService->submitRequest([
            'category' => 'visitor',
            'applicant_name' => 'Alice Walker',
            'property_number' => 'Lot 10',
        ], $this->homeowner);

        $this->assertEquals('approved', $visitorReq->status);
        $this->assertEquals('completed', $visitorReq->current_stage);
        $this->assertNotNull($visitorReq->gate_pass_id);

        // 2. Contractor multi-tier approval
        $contractorReq = $this->approvalService->submitRequest([
            'category' => 'contractor',
            'applicant_name' => 'QuickFix Plumbing',
            'property_number' => 'Lot 10',
        ], $this->homeowner);

        $this->assertEquals('pending', $contractorReq->status);
        $this->assertEquals('property_admin_approval', $contractorReq->current_stage);

        // Approve contractor stage
        $approvedReq = $this->approvalService->approveStage($contractorReq->id, $this->admin, 'License verified');
        $this->assertEquals('approved', $approvedReq->status);
        $this->assertEquals('completed', $approvedReq->current_stage);
        $this->assertNotNull($approvedReq->gate_pass_id);
    }

    public function test_digital_lease_expiration_suspends_linked_pass(): void
    {
        $pass = $this->engine->issuePassFor($this->homeowner);

        // Attach a lease expiring 1 day in the past
        $doc = $this->documentService->attachDocument([
            'title' => 'Tenancy Agreement 2026',
            'document_type' => 'lease',
            'holder_name' => $this->homeowner->name,
            'expires_at' => now()->subDay()->toIso8601String(),
            'gate_pass_id' => $pass->id,
        ], $this->guard);

        $result = $this->documentService->auditDocumentExpirations();
        $this->assertGreaterThanOrEqual(1, $result['expired_documents_found']);
        $this->assertGreaterThanOrEqual(1, $result['suspended_passes_count']);

        $pass->refresh();
        $this->assertEquals(PassStatus::Suspended, $pass->status);
    }

    public function test_complete_audit_timeline_reproduces_mary_smith_investigation(): void
    {
        $events = $this->timelineService->seedExampleMarySmithTimeline();
        $this->assertCount(5, $events);

        // Check Mary Smith timeline events
        $scanned = $events[0];
        $this->assertEquals('CREDENTIAL_SCANNED', $scanned->event_type);
        $this->assertStringContainsString("Mary Smith's Legacy QR scanned at Main Gate", $scanned->headline);
        $this->assertEquals('2026-10-04 20:43:00', $scanned->occurred_at->format('Y-m-d H:i:s'));

        $verified = $events[1];
        $this->assertEquals('IDENTITY_VERIFIED', $verified->event_type);
        $this->assertEquals('Identity verified', $verified->headline);

        $approved = $events[2];
        $this->assertEquals('SECURITY_APPROVED', $approved->event_type);
        $this->assertEquals('Security approved entry', $approved->headline);

        $opened = $events[3];
        $this->assertEquals('GATE_OPENED', $opened->event_type);
        $this->assertEquals('Gate opened', $opened->headline);

        $checkedOut = $events[4];
        $this->assertEquals('CHECKED_OUT', $checkedOut->event_type);
        $this->assertEquals('Mary checked out', $checkedOut->headline);
        $this->assertEquals('2026-10-04 23:17:00', $checkedOut->occurred_at->format('Y-m-d H:i:s'));
    }

    public function test_access_governance_controller_renders_and_responds(): void
    {
        $this->actingAs($this->admin)
            ->get(route('dashboard.access-governance'))
            ->assertOk();

        $this->actingAs($this->admin)
            ->post(route('dashboard.access-governance.sweep'))
            ->assertRedirect();
    }
}
