<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DelegatedAccessEvent extends Model
{
    use HasFactory;

    protected $table = 'delegated_access_events';

    protected $fillable = [
        'delegated_access_id',
        'actor_user_id',
        'event_type',
        'description',
        'metadata',
        'ip_address',
        'user_agent',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function delegatedAccess(): BelongsTo
    {
        return $this->belongsTo(DelegatedAccess::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
