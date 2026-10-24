<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MusterSession extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function rollCalls(): HasMany
    {
        return $this->hasMany(MusterRollCall::class);
    }

    public function incidentLabel(): string
    {
        return match ($this->incident_type) {
            'fire' => 'Fire Alarm & Structural Fire',
            'hurricane' => 'Hurricane / Tropical Cyclone',
            'earthquake' => 'Earthquake & Seismic Event',
            'flood' => 'Coastal / Flash Flood',
            'security_incident' => 'Perimeter Security Incident',
            'drill' => 'Preparedness Drill',
            default => 'Emergency Incident',
        };
    }

    public function incidentIcon(): string
    {
        return match ($this->incident_type) {
            'fire' => 'Flame',
            'hurricane' => 'Wind',
            'earthquake' => 'Activity',
            'flood' => 'Waves',
            'security_incident' => 'ShieldAlert',
            'drill' => 'Clock',
            default => 'AlertTriangle',
        };
    }

    public function getCounts(): array
    {
        $records = $this->rollCalls;

        return [
            'total' => $records->count(),
            'safe' => $records->where('status', 'safe')->count(),
            'missing' => $records->where('status', 'missing')->count(),
            'evacuated' => $records->where('status', 'evacuated')->count(),
            'needs_assistance' => $records->where('status', 'needs_assistance')->count(),
            'checked_out' => $records->where('status', 'checked_out')->count(),
            'unverified' => $records->where('status', 'unverified')->count(),
        ];
    }
}
