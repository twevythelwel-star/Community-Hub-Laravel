<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessAuditTimelineEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'pass_id',
        'user_id',
        'holder_name',
        'gate',
        'event_type',
        'severity',
        'headline',
        'description',
        'actor_type',
        'actor_id',
        'telemetry',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'telemetry' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
