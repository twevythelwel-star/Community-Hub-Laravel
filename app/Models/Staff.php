<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Household or community staff, identified by an ID document. `property_label`
 * is where they work in the homeowner's words; `property_id` is set when that
 * names one of the homeowner's properties.
 */
class Staff extends Occupant
{
    use HasFactory;

    public const TYPE = 'staff';

    protected $fillable = [
        'name', 'job', 'id_type', 'id_number', 'id_expiry', 'id_image_url',
        'property_label', 'property_id', 'added_by', 'status', 'photo_url',
    ];

    protected $attributes = [
        'status' => 'Active',
    ];

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (Staff $staff): void {
            $staff->property_id ??= static::propertyOwnedAt($staff->added_by, $staff->property_label);
        });
    }

    protected function casts(): array
    {
        return ['id_expiry' => 'date'];
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function hasExpiredId(): bool
    {
        return $this->id_expiry !== null && $this->id_expiry->isPast();
    }

    public function effectiveStatus(): string
    {
        return $this->hasExpiredId() ? 'Expired ID' : $this->status;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'Active');
    }

    public function personRole(): string
    {
        return (string) $this->job;
    }

    public function hasCurrentAccess(): bool
    {
        return $this->status === 'Active' && ! $this->hasExpiredId();
    }

    public function accessEndsAt(): ?CarbonInterface
    {
        return $this->id_expiry;
    }
}
