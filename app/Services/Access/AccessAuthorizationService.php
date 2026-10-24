<?php

namespace App\Services\Access;

use App\Enums\ApprovalStatus;
use App\Enums\AuthorizationScope;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\PropertyRelationshipType;
use App\Models\AccessAuthorization;
use App\Models\GatePass;
use App\Models\Person;
use App\Models\Property;
use App\Models\PropertyRelationship;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Access Authorization Service.
 *
 * Scope · Permissions · Approval · Expiry · Revocation
 *
 * Guarantees cross-property access is denied unless explicitly authorized.
 * Guarantees no conflicting property-access rules.
 */
class AccessAuthorizationService
{
    /**
     * Establish a property relationship and return it.
     * Prevents conflicting duplicate active relationships of the same type on the property.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function establishRelationship(
        Person $person,
        Property $property,
        PropertyRelationshipType $type,
        array $attributes = []
    ): PropertyRelationship {
        // Check for existing active relationship of the same type on this property
        $existing = PropertyRelationship::where('person_id', $person->id)
            ->where('property_id', $property->id)
            ->where('type', $type->value)
            ->where('status', 'active')
            ->first();

        if ($existing && ! $existing->isExpired()) {
            // Update details without creating duplicate conflicting relationship
            $existing->update([
                'role_label' => $attributes['role_label'] ?? $existing->role_label,
                'end_date' => $attributes['end_date'] ?? $existing->end_date,
                'metadata' => array_merge($existing->metadata ?? [], $attributes['metadata'] ?? []),
            ]);

            return $existing;
        }

        return PropertyRelationship::create([
            'person_id' => $person->id,
            'property_id' => $property->id,
            'type' => $type,
            'role_label' => $attributes['role_label'] ?? $type->label(),
            'status' => $attributes['status'] ?? 'active',
            'sponsor_person_id' => $attributes['sponsor_person_id'] ?? null,
            'sponsor_user_id' => $attributes['sponsor_user_id'] ?? null,
            'start_date' => $attributes['start_date'] ?? Carbon::today(),
            'end_date' => $attributes['end_date'] ?? null,
            'source_type' => $attributes['source_type'] ?? null,
            'source_id' => $attributes['source_id'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
        ]);
    }

    /**
     * Issue an access authorization.
     *
     * @param  array<string, mixed>  $options
     */
    public function issueAuthorization(
        Person $person,
        ?Property $property,
        ?PropertyRelationship $relationship = null,
        array $options = []
    ): AccessAuthorization {
        $scope = $options['scope'] ?? ($property ? AuthorizationScope::Property : AuthorizationScope::Community);
        if (is_string($scope)) {
            $scope = AuthorizationScope::from($scope);
        }

        $approvalStatus = $options['approval_status'] ?? ApprovalStatus::Approved;
        if (is_string($approvalStatus)) {
            $approvalStatus = ApprovalStatus::from($approvalStatus);
        }

        $validFrom = isset($options['valid_from']) ? Carbon::parse($options['valid_from']) : Carbon::now();
        $validUntil = isset($options['valid_until']) ? Carbon::parse($options['valid_until']) : null;

        return AccessAuthorization::create([
            'person_id' => $person->id,
            'property_id' => $property?->id,
            'property_relationship_id' => $relationship?->id,
            'scope' => $scope,
            'allowed_zones' => $options['allowed_zones'] ?? ['Main Gate'],
            'permissions' => $options['permissions'] ?? ['gate_access'],
            'access_rules' => $options['access_rules'] ?? [],
            'approval_status' => $approvalStatus,
            'approved_by' => $options['approved_by'] ?? null,
            'approved_at' => $approvalStatus === ApprovalStatus::Approved ? ($options['approved_at'] ?? now()) : null,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'status' => $options['status'] ?? ($approvalStatus === ApprovalStatus::Approved ? 'active' : 'pending'),
            'is_revoked' => false,
            'source_type' => $options['source_type'] ?? null,
            'source_id' => $options['source_id'] ?? null,
            'metadata' => $options['metadata'] ?? [],
        ]);
    }

    /**
     * Issue a credential (GatePass) linked to an authorization.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function issueCredential(
        AccessAuthorization $authorization,
        PassCategory $category = PassCategory::Visitor,
        array $attributes = []
    ): GatePass {
        $person = $authorization->person;
        $property = $authorization->property;
        $propertyLabel = $property ? "Unit {$property->lot_number}" : 'Community';

        $passId = $attributes['pass_id'] ?? ('GP-'.strtoupper(Str::random(10)));

        return GatePass::create([
            'pass_id' => $passId,
            'person_id' => $person->id,
            'access_authorization_id' => $authorization->id,
            'property_id' => $property?->id,
            'user_id' => $person->user_id,
            'category' => $category,
            'holder_name' => $person->displayName(),
            'property' => $propertyLabel,
            'access_zone' => $attributes['access_zone'] ?? $category->defaultZone(),
            'valid_from' => $authorization->valid_from,
            'valid_until' => $authorization->valid_until,
            'single_entry' => $attributes['single_entry'] ?? false,
            'rotation_seq' => $attributes['rotation_seq'] ?? 1,
            'status' => PassStatus::Active,
            'status_changed_at' => now(),
            'metadata' => array_merge($attributes['metadata'] ?? [], [
                'authorization_id' => $authorization->id,
                'person_id' => $person->id,
                'access_rules' => $authorization->access_rules,
            ]),
        ]);
    }

    /**
     * Evaluate if a person is allowed entry to a given target property.
     * Enforces: Cross-property access is denied unless explicitly authorized.
     *
     * @return array{allowed: bool, reason: string|null, authorization: AccessAuthorization|null}
     */
    public function evaluatePropertyAccess(
        Person $person,
        Property|int $targetProperty,
        ?string $permission = 'gate_access',
        ?Carbon $at = null
    ): array {
        $targetPropertyId = $targetProperty instanceof Property ? $targetProperty->id : (int) $targetProperty;
        $time = $at ?? Carbon::now();

        // Retrieve all authorizations for this person
        $authorizations = $person->accessAuthorizations()
            ->where('is_revoked', false)
            ->where('approval_status', ApprovalStatus::Approved->value)
            ->where('status', 'active')
            ->get();

        if ($authorizations->isEmpty()) {
            return [
                'allowed' => false,
                'reason' => 'No active access authorization found for this person',
                'authorization' => null,
            ];
        }

        // Check if any authorization explicitly permits the target property
        $permittedAuth = $authorizations->first(function (AccessAuthorization $auth) use ($targetPropertyId, $permission, $time) {
            // 1. Time / Expiry check
            if (! $auth->isCurrentlyActive($time)) {
                return false;
            }

            // 2. Permission check
            if ($permission && ! $auth->hasPermission($permission)) {
                return false;
            }

            // 3. Property boundary check
            if (! $auth->isPermittedForProperty($targetPropertyId)) {
                return false;
            }

            // 4. Access rules (days, hours) check
            if (! $this->satisfiesAccessRules($auth, $time)) {
                return false;
            }

            return true;
        });

        if (! $permittedAuth) {
            // Check if person is authorized for other properties (Cross-property denial)
            $otherPropertyAuth = $authorizations->first(fn (AccessAuthorization $a) => $a->property_id && $a->property_id !== $targetPropertyId);
            if ($otherPropertyAuth) {
                $otherPropName = $otherPropertyAuth->property?->property_code ?: "Unit {$otherPropertyAuth->property?->lot_number}";

                return [
                    'allowed' => false,
                    'reason' => "Cross-property access denied: Pass is authorized only for {$otherPropName}",
                    'authorization' => null,
                ];
            }

            return [
                'allowed' => false,
                'reason' => 'Access denied: Authorization conditions (schedule, rules, or validity window) not met',
                'authorization' => null,
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'authorization' => $permittedAuth,
        ];
    }

    /**
     * Verify whether an authorization's access rules (allowed days, entry times) are satisfied.
     */
    public function satisfiesAccessRules(AccessAuthorization $authorization, Carbon $time): bool
    {
        $rules = $authorization->access_rules ?? [];
        if (empty($rules)) {
            return true;
        }

        // Days check
        $allowedDays = $rules['allowed_days'] ?? $rules['days_permitted'] ?? null;
        if (! empty($allowedDays) && ! in_array('all', array_map('strtolower', $allowedDays))) {
            $today = strtolower($time->format('D'));
            $normalized = array_map(fn ($d) => strtolower(substr($d, 0, 3)), $allowedDays);
            if (! in_array(substr($today, 0, 3), $normalized, true)) {
                return false;
            }
        }

        // Time window check
        $startTime = $rules['entry_start_time'] ?? $rules['time_window']['start'] ?? null;
        $endTime = $rules['entry_end_time'] ?? $rules['time_window']['end'] ?? null;

        if (! empty($startTime) && ! empty($endTime) && ($startTime !== '00:00' || $endTime !== '23:59')) {
            $currentTime = $time->format('H:i');
            if ($currentTime < $startTime || $currentTime > $endTime) {
                return false;
            }
        }

        return true;
    }
}
