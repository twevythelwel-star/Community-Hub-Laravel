<?php

namespace App\Models;

use App\Enums\PassCategory;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone on a household's roster: spouse, child, long-term occupant or
 * caregiver, with their own gate pass and permissions.
 */
class HouseholdMember extends Occupant
{
    use HasFactory;

    public const TYPE = 'household_member';

    protected $guarded = ['id', 'type'];

    protected $attributes = [
        'status' => 'active',
    ];

    protected $casts = [
        'permissions' => 'array',
        'access_schedule' => 'array',
        'valid_until' => 'datetime',
    ];

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (HouseholdMember $member): void {
            if ($member->property_id === null && $household = $member->household) {
                $member->property_id = static::propertyOwnedAt($household->primary_homeowner_id, $household->property_number);
            }
        });
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function gatePass(): BelongsTo
    {
        return $this->belongsTo(GatePass::class);
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? [], true);
    }

    public function roleBadgeColor(): string
    {
        return match ($this->role_in_household) {
            'homeowner' => 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/30',
            'spouse' => 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border-indigo-500/30',
            'child' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30',
            'long_term_occupant' => 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-500/30',
            'caregiver' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/30',
            default => 'bg-muted text-muted-foreground border-border',
        };
    }

    public function credentialTypeLabel(): string
    {
        return match ($this->pass_category) {
            PassCategory::Homeowner->value => 'Resident Primary Hexagon Credential',
            PassCategory::Renter->value => 'Resident Dependent Squircle Pass',
            PassCategory::LongTermOccupant->value => 'Long-Term Occupant House-Hex Pass',
            PassCategory::Delegate->value => 'Authorized Caregiver Octagon Pass',
            PassCategory::HomeownerStaff->value => 'Private Household Staff Bezel',
            default => 'Standard Digital Pass',
        };
    }

    public function personRole(): string
    {
        return (string) ($this->relationship_label ?: $this->role_in_household);
    }

    public function hasCurrentAccess(): bool
    {
        return $this->status === 'active' && ($this->valid_until === null || $this->valid_until->isFuture());
    }

    public function accessEndsAt(): ?CarbonInterface
    {
        return $this->valid_until;
    }
}
