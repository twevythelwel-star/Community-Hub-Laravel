<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BlocklistEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'photo_url', 'reason', 'date_added', 'expiry_date', 'added_by_id', 'added_by',
    ];

    protected function casts(): array
    {
        return [
            'date_added' => 'datetime',
            'expiry_date' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_id');
    }

    public function removalRequests(): HasMany
    {
        return $this->hasMany(BlocklistRemovalRequest::class);
    }

    /** A null expiry_date means a permanent block. */
    public function isPermanent(): bool
    {
        return $this->expiry_date === null;
    }

    public function isCurrentlyBlocking(): bool
    {
        return $this->isPermanent() || $this->expiry_date->isFuture();
    }

    /** Whether an entry in force names this person (case- and space-insensitive). */
    public static function blocks(string $name): bool
    {
        return static::inForce()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->exists();
    }

    public function scopeInForce($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expiry_date')->orWhere('expiry_date', '>', now());
        });
    }
}
