<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessLogEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'user_name', 'user_role', 'method', 'gate', 'pass_id',
        'result', 'deny_reason', 'validation_report', 'scanned_by', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'validation_report' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scanner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }

    public function scopeDenied($query)
    {
        return $query->where('result', 'DENY');
    }
}
