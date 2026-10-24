<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versioned community boundary. Mirrors BoundaryConfig from
 * src/lib/boundary-manager/types.ts, but persisted and versioned server-side so
 * a published boundary is authoritative for every user and every device.
 */
class BoundaryConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'community_id', 'version', 'status', 'published_coordinates',
        'last_published_at', 'last_published_by',
    ];

    protected function casts(): array
    {
        return [
            'published_coordinates' => 'array',
            'last_published_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function points(): HasMany
    {
        return $this->hasMany(BoundaryPoint::class)->orderBy('point_index');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(BoundaryAuditLog::class)->latest('occurred_at');
    }

    public function isPublished(): bool
    {
        return $this->status === 'PUBLISHED';
    }

    /** Point pairs as [[lat, lng], ...], ready for Leaflet. */
    public function coordinatePairs(): array
    {
        return $this->points->map(fn (BoundaryPoint $p) => [$p->lat, $p->lng])->all();
    }
}
