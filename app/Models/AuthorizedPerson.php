<?php

namespace App\Models;

use App\Enums\AuthorizedPersonRole;
use App\Enums\RepresentativePermission;
use App\Models\Contracts\PropertyPerson;
use App\Traits\SummarizesPropertyPerson;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuthorizedPerson extends Model implements PropertyPerson
{
    use HasFactory;
    use SummarizesPropertyPerson;

    protected $table = 'authorized_people';

    protected $fillable = [
        'ownership_entity_id',
        'user_id',
        'role',
        'full_name',
        'email',
        'phone',
        'id_document_type',
        'id_document_number',
        'permissions',
        'is_primary_contact',
        'is_signatory',
        'valid_from',
        'valid_until',
        'status',
        'verification_status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'role' => AuthorizedPersonRole::class,
            'permissions' => 'array',
            'metadata' => 'array',
            'is_primary_contact' => 'boolean',
            'is_signatory' => 'boolean',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function ownershipEntity(): BelongsTo
    {
        return $this->belongsTo(OwnershipEntity::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AuthorizationDocument::class);
    }

    /**
     * Appointments in force today: active, started, and not past their end
     * date. The query form of isActive().
     */
    public function scopeCurrent(Builder $query): void
    {
        $today = now()->toDateString();

        $query->where('status', 'active')
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $today))
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $today));
    }

    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $now = now()->startOfDay();

        if ($this->valid_from && $this->valid_from->gt($now)) {
            return false;
        }

        if ($this->valid_until && $this->valid_until->lt($now)) {
            return false;
        }

        return true;
    }

    public function personType(): string
    {
        return 'authorized_person';
    }

    public function personName(): string
    {
        return (string) ($this->full_name ?: $this->user?->name);
    }

    public function personRole(): string
    {
        return (string) $this->role?->label();
    }

    public function hasCurrentAccess(): bool
    {
        return $this->isActive();
    }

    public function accessEndsAt(): ?CarbonInterface
    {
        return $this->valid_until?->copy()->endOfDay();
    }

    /**
     * Resolves currently active permissions, falling back to role defaults if not customized.
     *
     * @return array<string>
     */
    public function effectivePermissions(): array
    {
        return $this->permissions ?? $this->role?->defaultPermissions() ?? [];
    }

    public function hasPermission(string $permission): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $perms = $this->effectivePermissions();

        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    public function grantPermission(string $permission): void
    {
        $perms = $this->effectivePermissions();
        if (! in_array($permission, $perms, true)) {
            $perms[] = $permission;
            $this->update(['permissions' => array_values($perms)]);
        }
    }

    public function revokePermission(string $permission): void
    {
        $perms = array_values(array_filter($this->effectivePermissions(), fn ($p) => $p !== $permission));
        $this->update(['permissions' => $perms]);
    }

    public function syncPermissions(array $permissions): void
    {
        $this->update(['permissions' => array_values(array_unique($permissions))]);
    }

    // ── Granular Capability Checks ──

    public function canManageVisitors(): bool
    {
        return $this->hasPermission(RepresentativePermission::ManageVisitors->value)
            || $this->hasPermission('manage_passes');
    }

    public function canManageRentersLongTerm(): bool
    {
        return $this->hasPermission(RepresentativePermission::ManageRentersLongTerm->value);
    }

    public function canManageRentersShortTerm(): bool
    {
        return $this->hasPermission(RepresentativePermission::ManageRentersShortTerm->value);
    }

    public function canManageRenters(): bool
    {
        return $this->canManageRentersLongTerm() || $this->canManageRentersShortTerm();
    }

    public function canManageStaff(): bool
    {
        return $this->hasPermission(RepresentativePermission::ManageStaff->value);
    }

    public function canManageContractors(): bool
    {
        return $this->hasPermission(RepresentativePermission::ManageContractors->value);
    }

    public function canManageDelegatedAccess(): bool
    {
        return $this->hasPermission(RepresentativePermission::ManageDelegatedAccess->value);
    }

    public function canManageVehicles(): bool
    {
        return $this->hasPermission(RepresentativePermission::ManageVehicles->value);
    }

    public function canManageParking(): bool
    {
        return $this->hasPermission(RepresentativePermission::ManageParking->value);
    }

    public function canReceiveNotifications(): bool
    {
        return $this->hasPermission(RepresentativePermission::ReceiveNotifications->value);
    }

    public function canManageEmergencyContacts(): bool
    {
        return $this->hasPermission(RepresentativePermission::ManageEmergencyContacts->value);
    }

    public function canManageLegacyContacts(): bool
    {
        return $this->hasPermission(RepresentativePermission::ManageLegacyContacts->value);
    }

    public function canReceiveSecurityAlerts(): bool
    {
        return $this->hasPermission(RepresentativePermission::ReceiveSecurityAlerts->value);
    }

    public function canManagePasses(): bool
    {
        return $this->canManageVisitors();
    }

    public function canManageBilling(): bool
    {
        return $this->hasPermission(RepresentativePermission::ManageBilling->value);
    }

    public function requiresLegalVerification(): bool
    {
        return in_array($this->role, [
            AuthorizedPersonRole::Trustee,
            AuthorizedPersonRole::CoTrustee,
            AuthorizedPersonRole::Executor,
            AuthorizedPersonRole::PowerOfAttorney,
        ], true);
    }

    public function canManageOwnership(): bool
    {
        if ($this->requiresLegalVerification() && $this->verification_status !== 'verified') {
            return false;
        }

        return $this->hasPermission(RepresentativePermission::ManageOwnership->value);
    }

    public function canTransferOwnership(): bool
    {
        if ($this->requiresLegalVerification() && $this->verification_status !== 'verified') {
            return false;
        }

        return $this->hasPermission(RepresentativePermission::TransferOwnership->value);
    }

    public function canVote(): bool
    {
        if ($this->requiresLegalVerification() && $this->verification_status !== 'verified') {
            return false;
        }

        return $this->hasPermission(RepresentativePermission::Vote->value);
    }

    public function canSignLegal(): bool
    {
        if ($this->requiresLegalVerification() && $this->verification_status !== 'verified') {
            return false;
        }

        return $this->is_signatory && $this->hasPermission(RepresentativePermission::SignLegal->value);
    }

    /**
     * Revoke this person's authorization immediately (e.g. employee offboarding).
     * Invariant: Does not alter underlying property ownership.
     */
    public function revoke(?string $reason = null): void
    {
        $this->update([
            'status' => 'revoked',
        ]);
    }

    /**
     * Properties this representative is authorized to manage for the entity.
     * If metadata['authorized_property_ids'] is set and non-empty, scoped strictly to those properties.
     * Otherwise, authorized for all properties belonging to the entity.
     *
     * @return Collection<int, Property>
     */
    public function authorizedProperties(): Collection
    {
        $allProperties = $this->ownershipEntity?->properties ?? Property::where('ownership_entity_id', $this->ownership_entity_id)->get();
        $allowedIds = $this->metadata['authorized_property_ids'] ?? null;

        if (is_array($allowedIds) && count($allowedIds) > 0) {
            return $allProperties->whereIn('id', $allowedIds)->values();
        }

        return $allProperties;
    }

    /**
     * Check if this representative is authorized for a specific property.
     */
    public function isAuthorizedForProperty(Property|int $property): bool
    {
        $propertyId = $property instanceof Property ? $property->id : $property;
        $allowedIds = $this->metadata['authorized_property_ids'] ?? null;

        if (is_array($allowedIds) && count($allowedIds) > 0) {
            return in_array($propertyId, $allowedIds, false);
        }

        if ($this->ownershipEntity) {
            return $this->ownershipEntity->properties()->where('id', $propertyId)->exists();
        }

        return Property::where('ownership_entity_id', $this->ownership_entity_id)->where('id', $propertyId)->exists();
    }

    /**
     * Set specific authorized property IDs for this representative.
     *
     * @param  array<int>|null  $propertyIds
     */
    public function setAuthorizedPropertyIds(?array $propertyIds): void
    {
        $meta = $this->metadata ?? [];
        if ($propertyIds === null || empty($propertyIds)) {
            unset($meta['authorized_property_ids']);
        } else {
            $meta['authorized_property_ids'] = array_values(array_unique(array_map('intval', $propertyIds)));
        }

        $this->update(['metadata' => $meta]);
    }
}
