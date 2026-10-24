<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Mirrors the CommunityInfo contract from src/lib/boundary-manager/types.ts. */
class Community extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'code', 'jurisdiction', 'datum',
        'reference_lat', 'reference_lng', 'reference_dms',
        'reference_description', 'cadastral_zone',
    ];

    protected function casts(): array
    {
        return [
            'reference_lat' => 'float',
            'reference_lng' => 'float',
        ];
    }

    public function boundaryConfigs(): HasMany
    {
        return $this->hasMany(BoundaryConfig::class);
    }

    /** The live, published boundary — or null while only a draft exists. */
    public function publishedBoundary(): ?BoundaryConfig
    {
        return $this->boundaryConfigs()
            ->where('status', 'PUBLISHED')
            ->orderByDesc('version')
            ->first();
    }

    public function draftBoundary(): ?BoundaryConfig
    {
        return $this->boundaryConfigs()
            ->where('status', 'DRAFT')
            ->orderByDesc('version')
            ->first();
    }

    public function landmarks(): HasMany
    {
        return $this->hasMany(Landmark::class);
    }

    public function branding(): HasOne
    {
        return $this->hasOne(BrandingSetting::class);
    }

    public static function default(): self
    {
        return static::query()->firstOrCreate(
            ['code' => config('gatepass.default_community_id')],
            ['name' => 'Cypress Bay', 'datum' => 'WGS84']
        );
    }
}
