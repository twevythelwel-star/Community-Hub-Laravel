<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'year' => 'integer',
        'is_ev' => 'boolean',
        'is_temporary' => 'boolean',
        'anpr_enabled' => 'boolean',
        'valid_until' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function householdMember(): BelongsTo
    {
        return $this->belongsTo(HouseholdMember::class);
    }

    public function sensorEvents(): HasMany
    {
        return $this->hasMany(GateSensorEvent::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function scopeAnprAuthorized(Builder $query): void
    {
        $query->where('status', 'active')->where('anpr_enabled', true);
    }

    public function fullDescription(): string
    {
        $yearStr = $this->year ? "{$this->year} " : '';

        return "{$yearStr}{$this->make} {$this->model} ({$this->color})";
    }

    public function formattedPlate(): string
    {
        return strtoupper(trim($this->license_plate));
    }
}
