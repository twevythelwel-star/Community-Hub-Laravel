<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Person / User Identity.
 *
 * Represents an individual physical human being with a unified, consistent identity.
 * Avoids duplicate identity records across multiple properties, roles, or relationships.
 * An authenticated User account optionally maps to this Person.
 */
class Person extends Model
{
    use HasFactory;

    protected $table = 'people';

    protected $fillable = [
        'user_id',
        'name',
        'first_name',
        'last_name',
        'email',
        'phone',
        'id_type',
        'id_number',
        'id_expiry',
        'id_image_url',
        'photo_url',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'id_expiry' => 'date',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function propertyRelationships(): HasMany
    {
        return $this->hasMany(PropertyRelationship::class);
    }

    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'property_relationships')
            ->withPivot(['type', 'role_label', 'status', 'start_date', 'end_date'])
            ->withTimestamps();
    }

    public function accessAuthorizations(): HasMany
    {
        return $this->hasMany(AccessAuthorization::class);
    }

    public function gatePasses(): HasMany
    {
        return $this->hasMany(GatePass::class);
    }

    /**
     * Active relationships across all properties.
     *
     * @return Collection<int, PropertyRelationship>
     */
    public function activeRelationships(): Collection
    {
        return $this->propertyRelationships()
            ->where('status', 'active')
            ->get()
            ->filter(fn (PropertyRelationship $r) => ! $r->isExpired())
            ->values();
    }

    /**
     * Relationships specific to a given property.
     *
     * @return Collection<int, PropertyRelationship>
     */
    public function relationshipsForProperty(Property|int $property): Collection
    {
        $propertyId = $property instanceof Property ? $property->id : $property;

        return $this->propertyRelationships()
            ->where('property_id', $propertyId)
            ->get();
    }

    /**
     * Check if this person has valid, active access to a property.
     */
    public function hasAccessTo(Property|int $property): bool
    {
        $propertyId = $property instanceof Property ? $property->id : $property;

        return $this->accessAuthorizations()
            ->get()
            ->contains(fn (AccessAuthorization $auth) => $auth->isCurrentlyActive() && $auth->isPermittedForProperty($propertyId));
    }

    public function displayName(): string
    {
        return $this->name ?: trim(($this->first_name ?? '').' '.($this->last_name ?? '')) ?: 'Unknown Person';
    }
}
