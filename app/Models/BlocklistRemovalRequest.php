<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A resident's request that someone be taken off the community blocklist. */
class BlocklistRemovalRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'blocklist_entry_id', 'requested_by', 'reason',
        'status', 'reviewer_note', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(BlocklistEntry::class, 'blocklist_entry_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'Pending';
    }

    public function scopePending($query)
    {
        return $query->where('status', 'Pending');
    }
}
