<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoundaryAuditLog extends Model
{
    use HasFactory;

    protected $fillable = ['boundary_config_id', 'community', 'action', 'changed_by', 'role', 'previous_version', 'new_version', 'points_count', 'published', 'notes', 'area_acres', 'perimeter_meters', 'occurred_at'];

    protected function casts(): array
    {
        return ['published' => 'boolean', 'occurred_at' => 'datetime', 'perimeter_meters' => 'float'];
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(BoundaryConfig::class, 'boundary_config_id');
    }
}
