<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Points 1 to 4 are mandatory; 5 to 8 are optional, matching the original BoundaryPoint contract. */
class BoundaryPoint extends Model
{
    use HasFactory;

    protected $fillable = ['boundary_config_id', 'point_index', 'label', 'lat', 'lng', 'is_optional'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'is_optional' => 'boolean', 'point_index' => 'integer'];
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(BoundaryConfig::class, 'boundary_config_id');
    }
}
