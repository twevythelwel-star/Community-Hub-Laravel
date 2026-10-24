<?php

namespace App\Services;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Models\AccessApprovalRequest;
use App\Models\AccessAuditTimelineEvent;
use App\Models\GatePass;
use App\Models\User;
use Carbon\Carbon;

class AccessApprovalWorkflowService
{
    public function __construct(
        private readonly GatePassEngine $engine,
        private readonly AccessExpirationPolicyService $expirationPolicy,
    ) {}

    /**
     * Submit a new access authorization request with workflow routing.
     */
    public function submitRequest(array $data, User $homeowner): AccessApprovalRequest
    {
        $category = $data['category'] ?? 'visitor';
        $requestNum = sprintf('APR-%s-%04d', strtoupper(substr(md5(uniqid()), 0, 4)), rand(1000, 9999));

        $workflowType = match ($category) {
            'visitor' => 'visitor_instant',
            'contractor' => 'contractor_property_admin',
            'long_term_occupant', 'renter' => 'occupant_community_admin',
            'legacy_contact', 'delegate' => 'legacy_id_verification',
            default => 'contractor_property_admin',
        };

        // Determine initial stage and auto-approval
        $currentStage = 'homeowner_approval';
        $status = 'pending';
        $passId = null;

        $validUntil = $this->expirationPolicy->computeMandatoryExpiration(
            $category === 'contractor' ? PassCategory::Contractor : (
                $category === 'long_term_occupant' ? PassCategory::LongTermOccupant : (
                    $category === 'caregiver' ? PassCategory::HomeownerStaff : PassCategory::Visitor
                )
            ),
            $data
        );

        $chain = [
            [
                'stage' => 'submission',
                'action' => 'SUBMITTED',
                'actor_id' => $homeowner->id,
                'actor_name' => $homeowner->name,
                'timestamp' => now()->toIso8601String(),
                'note' => 'Access authorization request initiated by homeowner',
            ],
        ];

        // Visitor: Instant Homeowner approval
        if ($workflowType === 'visitor_instant') {
            $currentStage = 'completed';
            $status = 'approved';
            $chain[] = [
                'stage' => 'homeowner_approval',
                'action' => 'AUTO_APPROVED',
                'actor_id' => $homeowner->id,
                'actor_name' => $homeowner->name,
                'timestamp' => now()->toIso8601String(),
                'note' => 'Homeowner direct approval granted',
            ];

            // Auto-issue gate pass
            $pass = $this->issueApprovedPass($category, $data['applicant_name'], $homeowner, $validUntil, $data['property_number'] ?? $homeowner->propertyLabel());
            $passId = $pass->id;
        } else {
            // Advanced multi-tier: homeowner initiates $\rightarrow$ moves to required tier
            $nextStage = match ($workflowType) {
                'contractor_property_admin' => 'property_admin_approval',
                'occupant_community_admin' => 'community_admin_approval',
                'legacy_id_verification' => 'id_verification_approval',
                default => 'admin_approval',
            };
            $currentStage = $nextStage;
            $chain[] = [
                'stage' => 'homeowner_approval',
                'action' => 'ENDORSED_BY_HOMEOWNER',
                'actor_id' => $homeowner->id,
                'actor_name' => $homeowner->name,
                'timestamp' => now()->toIso8601String(),
                'note' => 'Homeowner sponsored applicant. Routed to administrative tier.',
            ];
        }

        $request = AccessApprovalRequest::create([
            'request_number' => $requestNum,
            'category' => $category,
            'applicant_name' => $data['applicant_name'],
            'applicant_phone' => $data['applicant_phone'] ?? null,
            'applicant_email' => $data['applicant_email'] ?? null,
            'homeowner_id' => $homeowner->id,
            'property_number' => $data['property_number'] ?? $homeowner->propertyLabel(),
            'gate_pass_id' => $passId,
            'workflow_type' => $workflowType,
            'current_stage' => $currentStage,
            'status' => $status,
            'approval_chain' => $chain,
            'requested_from' => now(),
            'requested_until' => $validUntil,
            'metadata' => [
                'company' => $data['company'] ?? null,
                'trade' => $data['trade'] ?? null,
                'notes' => $data['notes'] ?? null,
            ],
        ]);

        return $request;
    }

    /**
     * Advance approval request through the administrative tier.
     */
    public function approveRequest(AccessApprovalRequest|int $request, User $actor, ?string $note = null): AccessApprovalRequest
    {
        $request = is_int($request) ? AccessApprovalRequest::findOrFail($request) : $request;
        // Once decided, a request stays decided: approving it again minted
        // another gate pass each time.
        $this->ensurePending($request);
        $roleLabel = is_object($actor->role) ? ($actor->role->value ?? 'Admin') : ($actor->role ?? 'Admin');
        $chain = $request->approval_chain ?? [];
        $chain[] = [
            'stage' => $request->current_stage,
            'action' => 'APPROVED',
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'actor_role' => $roleLabel,
            'timestamp' => now()->toIso8601String(),
            'note' => $note ?: "Approved at tier {$request->current_stage}",
        ];

        $homeowner = $request->homeowner ?? User::find($request->homeowner_id) ?? $actor;
        $validUntil = $request->requested_until ? Carbon::parse($request->requested_until) : now()->addDays(7);

        // Issue gate pass upon completion
        $pass = $this->issueApprovedPass(
            $request->category,
            $request->applicant_name,
            $homeowner,
            $validUntil,
            // The homeowner's property, not an invented "Unit 14".
            $request->property_number ?? $homeowner->propertyLabel()
        );

        $request->update([
            'current_stage' => 'completed',
            'status' => 'approved',
            'gate_pass_id' => $pass->id,
            'approval_chain' => $chain,
        ]);

        AccessAuditTimelineEvent::create([
            'pass_id' => $pass->pass_id,
            'user_id' => $homeowner->id,
            'holder_name' => $request->applicant_name,
            'gate' => 'GOVERNANCE_PORTAL',
            'event_type' => 'GUARD_APPROVED',
            'severity' => 'NOTICE',
            'headline' => 'Multi-Tier Authorization Approved',
            'description' => "Request #{$request->request_number} approved by {$actor->name} ({$roleLabel}). Credential #{$pass->pass_id} minted.",
            'actor_type' => 'GUARD',
            'actor_id' => $actor->id,
            'occurred_at' => now(),
        ]);

        return $request->fresh(['gatePass', 'documents']);
    }

    public function approveStage(AccessApprovalRequest|int $request, User $actor, ?string $note = null): AccessApprovalRequest
    {
        return $this->approveRequest($request, $actor, $note);
    }

    /**
     * Reject an access approval request.
     */
    public function rejectRequest(AccessApprovalRequest|int $request, User $actor, string $reason): AccessApprovalRequest
    {
        $request = is_int($request) ? AccessApprovalRequest::findOrFail($request) : $request;
        $this->ensurePending($request);
        $chain = $request->approval_chain ?? [];
        $chain[] = [
            'stage' => $request->current_stage,
            'action' => 'REJECTED',
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'timestamp' => now()->toIso8601String(),
            'note' => $reason,
        ];

        $request->update([
            'current_stage' => 'rejected',
            'status' => 'rejected',
            'approval_chain' => $chain,
        ]);

        return $request;
    }

    private function issueApprovedPass(string $category, string $holderName, User $homeowner, Carbon $validUntil, string $property): GatePass
    {
        $passCategory = match ($category) {
            'contractor' => PassCategory::Contractor,
            'long_term_occupant', 'renter' => PassCategory::LongTermOccupant,
            'caregiver' => PassCategory::HomeownerStaff,
            default => PassCategory::Visitor,
        };

        $passId = sprintf('GP-%s-%s-%04d',
            substr($passCategory->value, 0, 3),
            strtoupper(substr(md5($holderName.uniqid()), 0, 4)),
            rand(1000, 9999)
        );

        return GatePass::create([
            'pass_id' => $passId,
            'user_id' => $homeowner->id,
            'category' => $passCategory,
            'holder_name' => $holderName,
            'property' => $property,
            'access_zone' => $passCategory->defaultZone(),
            'designated_gate' => GateId::Any,
            'status' => PassStatus::Active,
            'rotation_seq' => 1,
            'valid_from' => now(),
            'valid_until' => $validUntil,
            'color_variant' => $this->engine->randomApprovedColor($passCategory)['id'] ?? null,
            'status_changed_at' => now(),
        ]);
    }

    private function ensurePending(AccessApprovalRequest $request): void
    {
        if ($request->status !== 'pending') {
            throw new \DomainException("Request {$request->request_number} has already been {$request->status}.");
        }
    }
}
