<?php

namespace App\Models;

use App\Services\Properties\OwnershipEntityService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Property extends Model
{
    use HasFactory;

    protected $fillable = [
        'community_id',
        'ownership_entity_id',
        'owner_user_id',
        'primary_occupant_id',
        'property_code',
        'lot_number',
        'street_address',
        'property_type',
        'status',
    ];

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function ownershipEntity(): BelongsTo
    {
        return $this->belongsTo(OwnershipEntity::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function primaryOccupant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_occupant_id');
    }

    public function propertyRelationships(): HasMany
    {
        return $this->hasMany(PropertyRelationship::class);
    }

    public function accessAuthorizations(): HasMany
    {
        return $this->hasMany(AccessAuthorization::class);
    }

    public function renters(): HasMany
    {
        return $this->hasMany(Renter::class);
    }

    /**
     * Renters, household members and staff linked to this property, each as
     * its own model.
     */
    public function occupants(): HasMany
    {
        return $this->hasMany(AnyOccupant::class);
    }

    public function visitors(): HasMany
    {
        return $this->hasMany(Visitor::class);
    }

    public function delegatedAccesses(): HasMany
    {
        return $this->hasMany(DelegatedAccess::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Display name of the legal owner entity or individual account holder.
     */
    public function ownerName(): string
    {
        return $this->ownershipEntity?->legal_name ?? $this->owner?->name ?? $this->owner?->display_name ?? 'Unassigned';
    }

    /**
     * Resolves the effective OwnershipEntity, creating or wrapping the individual user if needed.
     */
    public function effectiveOwnershipEntity(): ?OwnershipEntity
    {
        if ($this->ownershipEntity) {
            return $this->ownershipEntity;
        }

        if ($this->owner) {
            return app(OwnershipEntityService::class)->ensureIndividualEntityForUser($this->owner);
        }

        return null;
    }

    /**
     * Human-readable label for the ownership type (e.g. "Company", "Trust", "Individual").
     */
    public function ownershipType(): string
    {
        if ($this->ownershipEntity?->entity_type) {
            return $this->ownershipEntity->entity_type->label();
        }

        return $this->owner_user_id ? 'Individual' : 'Unassigned';
    }

    /**
     * Representatives authorized by the owning entity.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, AuthorizedPerson>
     */
    public function authorizedRepresentatives(): \Illuminate\Database\Eloquent\Collection
    {
        if (! $this->ownershipEntity) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return $this->ownershipEntity->activeAuthorizedPeople()->with('user')->get();
    }

    /**
     * Name of the primary resident / occupant living in the property.
     */
    public function occupantName(): ?string
    {
        return $this->primaryOccupant?->display_name ?? $this->primaryOccupant?->name;
    }

    /**
     * Primary resident / occupancy layer.
     * Note: Owner != Resident!
     */
    public function occupancyLayer(): array
    {
        $occupant = $this->primaryOccupant;

        return [
            'primary_occupant' => $occupant ? [
                'id' => $occupant->id,
                'name' => $occupant->display_name ?? $occupant->name,
                'email' => $occupant->email,
                'phone' => $occupant->phone,
                'role' => 'Resident',
            ] : null,
            'occupant_name' => $this->occupantName() ?? 'Vacant / Unspecified',
            'is_owner_occupied' => $this->isOwnerOccupied(),
        ];
    }

    /**
     * Determine if property is owner-occupied (owner is the primary resident).
     */
    public function isOwnerOccupied(): bool
    {
        return $this->owner_user_id !== null && $this->primary_occupant_id === $this->owner_user_id;
    }

    public function getIsOwnerOccupiedAttribute(): bool
    {
        return $this->isOwnerOccupied();
    }

    /**
     * Long-term rental layer (tenants, leaseholders).
     */
    public function longTermRentalLayer(): Collection
    {
        $renters = $this->renters()
            ->where(function ($q) {
                $q->where('stay_type', 'Long-term')
                    ->orWhereNull('stay_type');
            })
            ->get();

        $delegates = $this->delegatedAccesses()
            ->where('access_level', 'Long-Term Occupant')
            ->get();

        return $renters->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'type' => 'Tenant (Long-Term)',
            'lease_start' => $r->lease_start?->toDateString(),
            'lease_end' => $r->lease_end?->toDateString(),
            'status' => $r->status,
        ])->concat($delegates->map(fn ($d) => [
            'id' => $d->id,
            'name' => $d->name,
            'type' => 'Long-Term Occupant',
            'status' => $d->status ?? 'active',
        ]))->values();
    }

    /**
     * Short-term rental layer (Airbnb, VRBO, short-stay guests).
     */
    public function shortTermRentalLayer(): Collection
    {
        $renters = $this->renters()
            ->where(function ($q) {
                $q->where('stay_type', 'Short-term')
                    ->orWhere('stay_type', 'Airbnb')
                    ->orWhere('stay_type', 'VRBO');
            })
            ->get();

        $visitors = Visitor::where(function ($q) {
            $q->where('property_id', $this->id);
            if ($this->primary_occupant_id) {
                $q->orWhere('homeowner_id', $this->primary_occupant_id);
            }
            if ($this->owner_user_id) {
                $q->orWhere('homeowner_id', $this->owner_user_id);
            }
        })
            ->where(function ($q) {
                $q->where('type', 'Short-term')
                    ->orWhere('type', 'Airbnb')
                    ->orWhere('type', 'Rental Guest');
            })
            ->get();

        $delegates = $this->delegatedAccesses()
            ->where(function ($q) {
                $q->where('access_level', 'like', '%Short-Term%')
                    ->orWhere('relationship', 'like', '%Airbnb%');
            })
            ->get();

        return $renters->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'type' => 'Short-Term Rental Guest',
            'status' => $r->status,
        ])->concat($visitors->map(fn ($v) => [
            'id' => $v->id,
            'name' => $v->name,
            'type' => 'Short-Term Guest',
            'status' => $v->status?->value ?? $v->status,
        ]))->concat($delegates->map(fn ($d) => [
            'id' => $d->id,
            'name' => $d->name,
            'type' => 'Short-Term Rental Guest',
            'status' => $d->status ?? 'active',
        ]))->values();
    }

    /**
     * Staff layer (domestic staff, cleaners, gardeners, caregivers).
     */
    public function staffLayer(): Collection
    {
        $registered = Staff::query()->where('property_id', $this->id)->orderBy('name')->get();

        $delegated = $this->delegatedAccesses()
            ->whereIn('access_level', ['Domestic Staff', 'Caregiver', 'Staff', 'Housekeeper'])
            ->get();

        return $registered->map(fn (Staff $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'role' => $s->job ?: 'Household Staff',
            'access_level' => 'Household Staff',
            'status' => $s->effectiveStatus(),
        ])->concat($delegated->map(fn ($d) => [
            'id' => $d->id,
            'name' => $d->name,
            'role' => $d->relationship ?: 'Domestic Staff',
            'access_level' => $d->access_level,
            'status' => $d->status ?? 'active',
        ]))->values();
    }

    /**
     * Contractor layer (maintenance, plumbing, electrical, vendors).
     */
    public function contractorLayer(): Collection
    {
        return $this->delegatedAccesses()
            ->where(function ($q) {
                $q->where('access_level', 'Contractor')
                    ->orWhere('relationship', 'like', '%Contractor%')
                    ->orWhere('relationship', 'like', '%Service Provider%');
            })
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'company' => $d->name,
                'trade' => $d->relationship ?: 'Contractor',
                'status' => $d->status ?? 'active',
            ]);
    }

    /**
     * Legacy contact layer (legacy delegates, emergency contacts, family).
     */
    public function legacyContactLayer(): Collection
    {
        return $this->delegatedAccesses()
            ->where(function ($q) {
                $q->whereIn('access_level', ['Legacy Delegate', 'Emergency Contact', 'Family Member'])
                    ->orWhere('authorization_type', 'legacy_contact')
                    ->orWhere('authorization_type', 'emergency_delegate');
            })
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'relationship' => $d->relationship ?: 'Legacy Contact',
                'status' => $d->status ?? 'active',
            ]);
    }

    /**
     * Complete Property Architecture Tree:
     * Explicitly separates Ownership from Occupancy, Long-Term Rental, Short-Term Rental, Staff, Contractors, and Legacy Contacts.
     */
    public function propertyEcosystemTree(): array
    {
        return [
            'property' => [
                'code' => $this->property_code,
                'label' => $this->label(),
                'lot_number' => $this->lot_number,
                'street_address' => $this->street_address,
                'status' => $this->status,
            ],
            'ownership' => [
                'owner' => $this->ownerName(),
                'ownership_type' => $this->ownershipType(),
                'is_corporate' => $this->ownershipEntity?->entity_type?->value === 'company',
                'is_trust' => $this->ownershipEntity?->entity_type?->value === 'trust',
                'is_estate' => $this->ownershipEntity?->entity_type?->value === 'estate',
                'entity' => $this->ownershipEntity ? [
                    'id' => $this->ownershipEntity->id,
                    'name' => $this->ownershipEntity->legal_name,
                    'type' => $this->ownershipEntity->entity_type->label(),
                ] : null,
                'representatives' => $this->authorizedRepresentatives()->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->full_name ?: $r->user?->name,
                    'role' => $r->role->label(),
                ])->values()->all(),
            ],
            'occupancy' => $this->occupancyLayer(),
            'long_term_rental' => $this->longTermRentalLayer()->all(),
            'short_term_rental' => $this->shortTermRentalLayer()->all(),
            'staff' => $this->staffLayer()->all(),
            'contractors' => $this->contractorLayer()->all(),
            'legacy_contacts' => $this->legacyContactLayer()->all(),
        ];
    }

    /**
     * Structured property ownership summary with authorized representatives.
     *
     * @return array<string, mixed>
     */
    public function ownershipDetails(): array
    {
        $entity = $this->ownershipEntity ?? $this->effectiveOwnershipEntity();

        return [
            'property' => $this->lot_number ?: "Unit {$this->id}",
            'owner' => $this->ownerName(),
            'ownershipType' => $this->ownershipType(),
            'entityTypeKey' => $entity?->entity_type?->value ?? 'individual',
            'isCorporate' => $entity?->entity_type?->value === 'company',
            'primaryOccupant' => $this->occupantName(),
            'isOwnerOccupied' => $this->isOwnerOccupied(),
            'representatives' => $entity ? $entity->activeAuthorizedPeople()->with('user')->get()->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->full_name ?: $p->user?->name,
                'role' => $p->role->label(),
                'role_key' => $p->role->value,
                'is_primary' => $p->is_primary_contact,
                'permissions' => $p->permissions,
            ])->values()->all() : [],
            'ecosystem' => $this->propertyEcosystemTree(),
        ];
    }

    /** The label, with the owning company's name where a company owns it: for choosing a visit's host property. */
    public function hostLabel(): string
    {
        $owner = $this->ownershipEntity?->legal_name;

        return $owner ? "{$this->label()} ({$owner})" : $this->label();
    }

    /** "Lot 14, Hibiscus Way": how the property reads on an invoice or a pass. */
    public function label(): string
    {
        return collect([$this->lot_number, $this->street_address])->filter()->implode(', ');
    }
}
