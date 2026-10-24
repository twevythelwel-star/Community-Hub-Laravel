<?php

namespace App\Repositories;

use App\Enums\PassCategory;
use App\Models\GatePass;
use App\Models\Staff;
use App\Services\GatePassEngine;
use Illuminate\Support\Collection;

class GatePassRepository
{
    public function __construct(
        protected GatePassEngine $engine
    ) {}

    /**
     * Return formatted visual configurations for all pass categories.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getCategoryConfigs(): array
    {
        $configs = [];

        foreach (PassCategory::cases() as $case) {
            $raw = $this->engine->categoryConfig($case);

            $configs[$case->value] = [
                'displayName' => $raw['display_name'],
                'shape' => $raw['shape'],
                'shapeLabel' => $raw['shape_label'],
                'themeColor' => $raw['theme_color'],
                'contrastBg' => $raw['contrast_bg'],
                'accentColor' => $raw['accent_color'],
                'badgeBorder' => $raw['badge_border'],
                'gradient' => $raw['gradient'],
                'iconName' => $raw['icon_name'],
                'description' => $raw['description'],
            ];
        }

        return $configs;
    }

    /**
     * Get identity directory for security operators.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDirectory(): array
    {
        return GatePass::with('user:id,role,display_name')
            ->orderBy('category')
            ->orderBy('holder_name')
            ->get()
            ->map(fn (GatePass $pass) => [
                'id' => $pass->id,
                'passId' => $pass->pass_id,
                'category' => $pass->category->value,
                'userName' => $pass->holder_name,
                'role' => $pass->user?->role->value ?? $pass->category->value,
                'property' => $pass->property,
                'gate' => $pass->designated_gate->value,
                'status' => $pass->status->value,
                'validFrom' => $pass->valid_from?->toIso8601String(),
                'validUntil' => $pass->valid_until?->toIso8601String(),
                'colorVariant' => $this->engine->variantFor($pass),
            ])
            ->all();
    }

    /**
     * Get staff listing with effective status and category.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getStaffList(): Collection
    {
        return Staff::with('addedBy:id,role')->orderBy('name')->get()->map(fn (Staff $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'job' => $s->job,
            'idType' => $s->id_type,
            'idExpiry' => $s->id_expiry->toIso8601String(),
            'property' => $s->property_label,
            'status' => $s->effectiveStatus(),
            'photoUrl' => $s->photo_url,
            'category' => $s->addedBy?->role?->isResident()
                ? PassCategory::HomeownerStaff->value
                : PassCategory::Staff->value,
        ]);
    }

    /**
     * Converts a snake_case policy array into the camelCase shape React expects.
     *
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    public function camelPolicy(array $policy): array
    {
        $hours = $policy['operational_hours'];

        return [
            'title' => $policy['title'],
            'description' => $policy['description'],
            'authorizedZones' => $policy['authorized_zones'],
            'allowedGates' => $policy['allowed_gates'],
            'operationalHours' => [
                'is24Hours' => $hours['is_24_hours'] ?? false,
                'startHour' => $hours['start_hour'] ?? null,
                'endHour' => $hours['end_hour'] ?? null,
                'daysOfWeek' => $hours['days_of_week'] ?? null,
            ],
            'privileges' => [
                'canManageGuests' => $policy['privileges']['can_manage_guests'],
                'canAssociateVehicles' => $policy['privileges']['can_associate_vehicles'],
                'hasEmergencyOverride' => $policy['privileges']['has_emergency_override'],
                'hasGateOperationOverride' => $policy['privileges']['has_gate_operation_override'],
                'restrictedFromHomeownerFunctions' => $policy['privileges']['restricted_from_homeowner_functions'],
            ],
        ];
    }
}
