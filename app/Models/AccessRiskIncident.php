<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessRiskIncident extends Model
{
    use HasFactory;

    protected $fillable = [
        'pass_id',
        'user_id',
        'gate',
        'flag_type',
        'risk_level',
        'title',
        'description',
        'evidence',
        'status',
        'resolved_by',
        'resolution_notes',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function gatePass(): BelongsTo
    {
        return $this->belongsTo(GatePass::class, 'pass_id', 'pass_id');
    }
}
