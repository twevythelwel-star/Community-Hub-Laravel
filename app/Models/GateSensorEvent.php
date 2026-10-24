<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GateSensorEvent extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'authorized_occupants' => 'integer',
        'detected_occupants' => 'integer',
        'is_tailgating' => 'boolean',
        'sensor_metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function gatePass(): BelongsTo
    {
        return $this->belongsTo(GatePass::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function getResolvedByUserIdAttribute(): ?int
    {
        return $this->resolved_by;
    }

    public function scopeTailgating(Builder $query): void
    {
        $query->where('is_tailgating', true);
    }

    public function scopeUnresolved(Builder $query): void
    {
        $query->whereIn('resolution_status', ['UNRESOLVED', 'INVESTIGATING']);
    }
}
