<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MusterRollCall extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'marked_at' => 'datetime',
    ];

    public function musterSession(): BelongsTo
    {
        return $this->belongsTo(MusterSession::class);
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'safe' => 'Safe',
            'missing' => 'Missing',
            'evacuated' => 'Evacuated',
            'needs_assistance' => 'Needs Assistance',
            'checked_out' => 'Checked Out',
            // A stale check-in: probably left without checking out, but not ruled out.
            'unverified' => 'Unverified (last seen at gate)',
            default => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }
}
