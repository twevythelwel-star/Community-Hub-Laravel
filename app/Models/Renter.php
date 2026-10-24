<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stay at a homeowner's property: long-term renter or short-term guest.
 * The person is a `users` row; this is the tenancy.
 */
class Renter extends Occupant
{
    use HasFactory;

    public const TYPE = 'renter';

    protected $fillable = [
        'homeowner_id',
        'property_id',
        'user_id',
        'name',
        'stay_type',
        'contact',
        'notes',
        'status',
        'lease_start',
        'lease_end',
        'lot',
        'street',
    ];

    protected $attributes = [
        'status' => 'Active',
        'stay_type' => 'Long-term (Renter)',
    ];

    protected function casts(): array
    {
        return [
            'lease_start' => 'date',
            'lease_end' => 'date',
        ];
    }

    public function homeowner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'homeowner_id');
    }

    public function leaseHasExpired(): bool
    {
        return $this->lease_end->isPast();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'Active')->whereDate('lease_end', '>=', now()->toDateString());
    }

    public function personRole(): string
    {
        return (string) $this->stay_type;
    }

    public function hasCurrentAccess(): bool
    {
        return $this->status === 'Active' && $this->lease_end !== null && ! $this->lease_end->endOfDay()->isPast();
    }

    public function accessEndsAt(): ?CarbonInterface
    {
        return $this->lease_end?->copy()->endOfDay();
    }
}
