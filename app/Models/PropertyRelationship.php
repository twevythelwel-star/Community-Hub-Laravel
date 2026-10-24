<?php

namespace App\Models;

use App\Enums\PropertyRelationshipType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Property Relationship.
 *
 * Defines the specific relationship between a Person and a Property:
 * Resident · Renter · Household · Staff · Guest
 *
 * Keeps ownership separate from occupancy.
 */
class PropertyRelationship extends Model
{
    use HasFactory;

    protected $table = 'property_relationships';

    protected $fillable = [
        'person_id',
        'property_id',
        'type',
        'role_label',
        'status',
        'sponsor_person_id',
        'sponsor_user_id',
        'start_date',
        'end_date',
        'source_type',
        'source_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'type' => PropertyRelationshipType::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'sponsor_person_id');
    }

    public function sponsorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sponsor_user_id');
    }

    public function authorizations(): HasMany
    {
        return $this->hasMany(AccessAuthorization::class);
    }

    public function isExpired(): bool
    {
        if ($this->end_date === null) {
            return false;
        }

        return Carbon::today()->gt($this->end_date);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && ! $this->isExpired();
    }
}
