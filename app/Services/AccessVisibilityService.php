<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\AccessApprovalRequest;
use App\Models\DelegatedAccess;
use App\Models\GatePass;
use App\Models\Property;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Occupancy\OccupancyCategoryResolver;
use Illuminate\Database\Eloquent\Builder;

class AccessVisibilityService
{
    /**
     * Core Rule:
     * A person can only see people and access information that their role is authorized to see,
     * and the default scope is the property/address they are associated with.
     * "Can see" and "can act" are strictly separate permissions.
     */
    public function getVisibilityMatrix(): array
    {
        return [
            [
                'role' => 'Homeowner',
                'own_property' => 'Full authorized occupants/access people',
                'other_properties' => false,
                'entire_community' => false,
                'can_see' => [
                    'Authorized household members',
                    'Long-term occupants on their property',
                    'Invited visitors & temporary guests',
                    'Assigned household staff & contractors',
                    'Gate passes & emergency continuity plan for own property',
                ],
                'can_act' => [
                    'Invite & register visitors',
                    'Add household members & staff',
                    'Sponsor long-term occupants & contractors',
                    'Configure emergency continuity plan',
                    'Report credential lost & request rotation',
                ],
                'cannot_act' => [
                    'Cannot view or act on other properties',
                    'Cannot alter community boundary or estate infrastructure',
                    'Cannot override physical gate scanner security',
                ],
            ],
            [
                'role' => 'Long-Term Renter',
                'own_property' => 'Their authorized people',
                'other_properties' => false,
                'entire_community' => false,
                'can_see' => [
                    'Their authorized household members under lease',
                    'Visitors they registered for their leased residence',
                    'Their own gate pass and lease validity dates',
                ],
                'can_act' => [
                    'Register temporary visitors within lease allocation',
                    'View lease and compliance documents attached to tenancy',
                ],
                'cannot_act' => [
                    'Cannot add permanent occupants or subtenants without homeowner/admin approval',
                    'Cannot alter property title, boundaries, or ownership profile',
                    'Cannot view other properties in community',
                ],
            ],
            [
                'role' => 'Short-Term Rental / Airbnb Host/Guest',
                'own_property' => 'Their authorized visitors',
                'other_properties' => false,
                'entire_community' => false,
                'can_see' => [
                    'Their reservation-authorized visitors & party members',
                    'Active temporary gate clearance and stay duration',
                ],
                'can_act' => [
                    'Pre-register guests for their booking stay window',
                ],
                'cannot_act' => [
                    'Cannot view permanent residents, HOA documents, or estate financial records',
                    'Cannot register contractors or staff',
                    'Cannot see or access any other unit/property',
                ],
            ],
            [
                'role' => 'Visitor',
                'own_property' => 'Only their own credential/status',
                'other_properties' => false,
                'entire_community' => false,
                'can_see' => [
                    'Only their own digital gate pass (QR/NFC)',
                    'Pass validity window and arrival status',
                ],
                'can_act' => [
                    'Present credential at gate scanner',
                ],
                'cannot_act' => [
                    'Cannot see any other persons or property occupants',
                    'Cannot issue passes or invite guests',
                    'Zero administrative or creation privileges',
                ],
            ],
            [
                'role' => 'Staff',
                'own_property' => 'Based on assigned permissions',
                'other_properties' => false,
                'entire_community' => false,
                'can_see' => [
                    'Assigned maintenance tasks & work orders',
                    'Service gate clearance window and assigned property address',
                ],
                'can_act' => [
                    'Update status on assigned maintenance tasks',
                    'Check in at designated service gate',
                ],
                'cannot_act' => [
                    'Cannot view unassigned properties or private resident profiles',
                    'Cannot view billing or financial ledger',
                    'Cannot invite visitors',
                ],
            ],
            [
                'role' => 'Contractor',
                'own_property' => 'Assigned properties only',
                'other_properties' => false,
                'entire_community' => false,
                'can_see' => [
                    'Their own assigned work authorization request',
                    'Active contractor pass for assigned job site/property',
                ],
                'can_act' => [
                    'Attach required liability insurance & contractor license',
                    'Present contractor badge at gate',
                ],
                'cannot_act' => [
                    'Cannot see other properties or non-assigned lots',
                    'Cannot invite guests or authorize third-party entries',
                ],
            ],
            [
                'role' => 'Legacy Contact',
                'own_property' => "Properties they've been delegated to",
                'other_properties' => false,
                'entire_community' => false,
                'can_see' => [
                    'Continuity plan & authorized contacts for delegated property only',
                    'Access emergency instructions and health advisory notes',
                ],
                'can_act' => [
                    'Trigger emergency access protocol when authorized',
                    'Act as designated family delegate during emergency mode',
                ],
                'cannot_act' => [
                    'Cannot see un-delegated properties',
                    'Cannot sell, transfer deed, or modify property ownership without legal probate clearance',
                ],
            ],
            [
                'role' => 'Security',
                'own_property' => true,
                'other_properties' => true,
                'entire_community' => 'All',
                'can_see' => [
                    'All gate queues, passes, and presentation telemetry',
                    'ANPR vehicle recognition feeds across all gates',
                    'Live occupancy ("Who\'s On Property?") for all units',
                    'Suspicious risk engine anomaly alerts & incident logs',
                ],
                'can_act' => [
                    'Scan & admit credentials at gates',
                    'Check out departing visitors and vehicles',
                    'Override anti-passback with logged reason',
                    'Initiate 1-click emergency lockdown on compromised passes',
                ],
                'cannot_act' => [
                    'Cannot modify resident leases, deeds, or billing schedules',
                    'Cannot delete system audit logs',
                ],
            ],
            [
                'role' => 'Community Admin',
                'own_property' => true,
                'other_properties' => true,
                'entire_community' => 'All',
                'can_see' => [
                    'All community properties, lots, and resident directories',
                    'All multi-tier approval requests and compliance vaults',
                    'Estate financial billing summaries and dues records',
                    'Forensic audit timelines and compliance reports',
                ],
                'can_act' => [
                    'Approve multi-tier access requests (occupants, contractors, leases)',
                    'Audit compliance documents & enforce automatic expiration policies',
                    'Publish community guidelines and broadcast emergency alerts',
                ],
                'cannot_act' => [
                    'Cannot bypass platform-wide cryptographic security logs',
                ],
            ],
            [
                'role' => 'System Admin',
                'own_property' => true,
                'other_properties' => true,
                'entire_community' => 'All',
                'can_see' => [
                    'Entire platform multi-tenant data',
                    'Cryptographic signing keys & audit trails',
                    'Full API logs & system health telemetry',
                ],
                'can_act' => [
                    'Full administrative configuration across all communities',
                    'Global policy management and system maintenance',
                ],
                'cannot_act' => [],
            ],
        ];
    }

    /**
     * Get list of properties/addresses an actor is associated with.
     */
    public function getUserAssociatedProperties(User $actor): array
    {
        $properties = [];

        // 1. Direct lot / street on User record
        if (filled($actor->lot)) {
            $properties[] = trim($actor->lot);
        }

        // 2. Owned properties
        foreach ($actor->properties as $prop) {
            if (filled($prop->lot_number)) {
                $properties[] = trim($prop->lot_number);
            }
            if (filled($prop->property_code)) {
                $properties[] = trim($prop->property_code);
            }
        }

        // 3. Leased properties (Renter)
        if ($actor->renter && filled($actor->renter->property)) {
            $properties[] = trim($actor->renter->property);
        }

        // 4. Delegated properties: delegations this account accepted (linked)
        // and that are in force now. A matching email used to be enough, before
        // acceptance, and pending, expired or emergency-only ones counted too.
        $delegations = DelegatedAccess::with(['property', 'grantor'])
            ->where('delegate_user_id', $actor->id)
            ->where('status', 'active')
            ->get()
            ->filter(fn (DelegatedAccess $del) => $del->isActive());

        foreach ($delegations as $del) {
            if ($del->property && filled($del->property->lot_number)) {
                $properties[] = trim($del->property->lot_number);
            }
            if ($del->property && filled($del->property->property_code)) {
                $properties[] = trim($del->property->property_code);
            }
            if ($del->grantor && filled($del->grantor->lot)) {
                $properties[] = trim($del->grantor->lot);
            }
        }

        // 5. Contractor / Staff assignments
        $contractorRequests = AccessApprovalRequest::where('applicant_email', $actor->email)
            ->where('status', 'approved')
            ->get();

        foreach ($contractorRequests as $req) {
            if (filled($req->property_number)) {
                $properties[] = trim($req->property_number);
            }
        }

        return array_values(array_unique(array_filter($properties)));
    }

    /**
     * Determine whether an actor CAN SEE a specific resource or property.
     */
    public function canSee(
        User $actor,
        string $resourceType,
        mixed $resource = null,
        ?string $targetProperty = null
    ): bool {
        $roleStr = $this->resolveUserRole($actor);

        // Community-wide roles have global visibility
        if (in_array($roleStr, ['System Admin', 'Community Admin', 'Admin', 'Security'], true)) {
            return true;
        }

        // Visitor can ONLY see their own pass/credential
        if ($roleStr === 'Visitor') {
            if ($resourceType === 'credential' || $resourceType === 'gate_pass') {
                if ($resource instanceof GatePass) {
                    return $resource->user_id === $actor->id;
                }

                return true;
            }

            return false;
        }

        // Target property resolution
        $resolvedProperty = $targetProperty;
        if (! $resolvedProperty && $resource) {
            $resolvedProperty = $this->extractPropertyFromResource($resource);
        }

        // If checking a specific property, must be in user's associated properties
        if ($resolvedProperty) {
            $userProperties = $this->getUserAssociatedProperties($actor);

            return $this->matchesAnyProperty($resolvedProperty, $userProperties);
        }

        // If resource is user-owned (e.g. created by or for this actor)
        if ($resource && isset($resource->user_id) && $resource->user_id === $actor->id) {
            return true;
        }
        if ($resource && isset($resource->homeowner_id) && $resource->homeowner_id === $actor->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether an actor CAN ACT on a specific resource or property.
     * Crucial: Can See DOES NOT equal Can Act!
     */
    public function canAct(
        User $actor,
        string $action,
        string $resourceType,
        mixed $resource = null,
        ?string $targetProperty = null
    ): bool {
        $roleStr = $this->resolveUserRole($actor);

        // System Admin can act everywhere
        if ($roleStr === 'System Admin') {
            return true;
        }

        // Security can act on gate scanning, lockdowns, anti-passback overrides, but NOT property management
        if ($roleStr === 'Security') {
            $securityActions = [
                'scan_pass', 'gate_checkin', 'gate_checkout', 'emergency_lockdown',
                'anti_passback_override', 'resolve_alert', 'log_telemetry',
            ];

            return in_array($action, $securityActions, true);
        }

        // Community Admin can approve multi-tier requests, manage guidelines, audit docs
        if (in_array($roleStr, ['Community Admin', 'Admin'], true)) {
            $adminDisallowed = ['platform_config', 'modify_system_audit_logs'];

            return ! in_array($action, $adminDisallowed, true);
        }

        // Visitors CANNOT perform any creation or management actions
        if ($roleStr === 'Visitor') {
            return $action === 'present_pass';
        }

        // For property-scoped roles: Must have "canSee" permission first
        if (! $this->canSee($actor, $resourceType, $resource, $targetProperty)) {
            return false;
        }

        // Role-specific action permissions
        return match ($roleStr) {
            'Homeowner' => in_array($action, [
                'invite_visitor', 'add_household_member', 'remove_household_member',
                'add_staff', 'sponsor_occupant', 'sponsor_contractor', 'report_lost_credential',
                'configure_continuity_plan', 'update_pass_schedule', 'revoke_pass',
            ], true),

            'Long-Term Renter', 'Temporary Homeowner' => in_array($action, [
                'invite_visitor', 'report_lost_credential',
            ], true),

            'Short-Term Rental Host/Guest', 'Airbnb Guest' => in_array($action, [
                'register_stay_guest', 'report_lost_credential',
            ], true),

            'Contractor' => in_array($action, [
                'attach_compliance_document', 'submit_work_permit',
            ], true),

            'Staff' => in_array($action, [
                'update_maintenance_task', 'log_service_entry',
            ], true),

            'Legacy Contact', 'Delegate' => in_array($action, [
                'emergency_activation', 'sponsor_emergency_visitor',
            ], true),

            default => false,
        };
    }

    /**
     * Scope an Eloquent query to only include records within the actor's authorized visibility.
     */
    public function scopeAccessQuery(
        Builder $query,
        User $actor,
        string $propertyColumn = 'property',
        string $userColumn = 'user_id'
    ): Builder {
        $roleStr = $this->resolveUserRole($actor);

        // Entire Community Scope: System Admin, Community Admin, Admin, Security
        if (in_array($roleStr, ['System Admin', 'Community Admin', 'Admin', 'Security'], true)) {
            return $query;
        }

        // Visitor: only their exact user ID or pass
        if ($roleStr === 'Visitor') {
            return $query->where($userColumn, $actor->id);
        }

        // Property-scoped roles: scope by associated properties or user ID
        $userProperties = $this->getUserAssociatedProperties($actor);

        return $query->where(function (Builder $sub) use ($actor, $userProperties, $propertyColumn, $userColumn) {
            $sub->where($userColumn, $actor->id);

            // Exact values only. A LIKE '%{prop}%' per property let lot "1"
            // reach every record whose property merely contained a 1.
            if (! empty($userProperties)) {
                $sub->orWhereIn($propertyColumn, $this->unitSpellings($userProperties));
            }
        });
    }

    public function resolveUserRole(User $actor): string
    {
        // 1. Explicit title / persona
        if (filled($actor->title)) {
            $normalizedTitle = ucwords(strtolower(trim($actor->title)));
            if (in_array($normalizedTitle, [
                'Visitor', 'Contractor', 'Legacy Contact', 'Long-Term Renter',
                'Short-Term Rental / Airbnb Host/Guest', 'Short-Term Renter',
            ], true)) {
                return $normalizedTitle;
            }
        }

        // 2. Renter stay check
        if ($actor->role === UserRole::TemporaryHomeowner || $actor->renter()->exists()) {
            return 'Long-Term Renter';
        }

        // 3. UserRole standard mapping
        if ($actor->role instanceof UserRole) {
            return match ($actor->role) {
                UserRole::SystemAdmin => 'System Admin',
                UserRole::Admin => 'Community Admin',
                UserRole::Security => 'Security',
                UserRole::Staff => 'Staff',
                UserRole::Homeowner => 'Homeowner',
                UserRole::TemporaryHomeowner => 'Long-Term Renter',
            };
        }

        return (string) ($actor->role ?? 'Visitor');
    }

    private function normalizeRoleString(UserRole|string|null $role): string
    {
        if ($role instanceof UserRole) {
            return match ($role) {
                UserRole::SystemAdmin => 'System Admin',
                UserRole::Admin => 'Community Admin',
                UserRole::Security => 'Security',
                UserRole::Staff => 'Staff',
                UserRole::Homeowner => 'Homeowner',
                UserRole::TemporaryHomeowner => 'Long-Term Renter',
            };
        }

        return (string) ($role ?? 'Visitor');
    }

    private function extractPropertyFromResource(mixed $resource): ?string
    {
        if (is_object($resource)) {
            return $resource->property
                ?? $resource->property_number
                ?? $resource->lot_number
                ?? $resource->lot
                ?? null;
        }
        if (is_array($resource)) {
            return $resource['property']
                ?? $resource['property_number']
                ?? $resource['lot_number']
                ?? $resource['lot']
                ?? null;
        }

        return null;
    }

    /**
     * The ways one unit is written in stored records — "14", "Unit 14",
     * "Lot 14", "#14" — so an exact match still finds them all.
     *
     * @param  list<string>  $properties
     * @return list<string>
     */
    private function unitSpellings(array $properties): array
    {
        $occupancy = app(OccupancyCategoryResolver::class);
        $spellings = [];

        foreach ($properties as $prop) {
            $spellings[] = $prop;
            $normalized = (string) $occupancy->normalizeUnit($prop);
            if (preg_match('/^Unit (.+)$/', $normalized, $m)) {
                array_push($spellings, $m[1], "Unit {$m[1]}", "Lot {$m[1]}", "#{$m[1]}");
            }
        }

        return array_values(array_unique($spellings));
    }

    /**
     * Whether $target is one of the user's properties. Exact, after the same
     * normalisation the occupancy service uses ("Lot 14", "#14", "14" are
     * "Unit 14"). It matched when either string contained the other, so lot
     * "1" was associated with Units 10-19, 21, 100...
     */
    private function matchesAnyProperty(string $target, array $userProperties): bool
    {
        $occupancy = app(OccupancyCategoryResolver::class);
        $wanted = strtolower((string) $occupancy->normalizeUnit($target));

        foreach ($userProperties as $prop) {
            if ($wanted !== '' && $wanted === strtolower((string) $occupancy->normalizeUnit($prop))) {
                return true;
            }
        }

        return false;
    }
}
