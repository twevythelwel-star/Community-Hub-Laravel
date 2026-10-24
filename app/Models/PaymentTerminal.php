<?php

namespace App\Models;

use Database\Factories\PaymentTerminalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A card reader registered with a processor for in-person (tap/insert)
 * payments. Only a reader the processor has confirmed — verified_at set, and
 * still active — can take a payment; see TakesInPersonPayments.
 */
class PaymentTerminal extends Model
{
    /** @use HasFactory<PaymentTerminalFactory> */
    use HasFactory;

    protected $fillable = [
        'provider',
        'terminal_id',
        'device_id',
        'device_type',
        'location_id',
        'country',
        'label',
        'status',
        'verified_at',
        'registered_by',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->status === 'active' && $this->verified_at !== null;
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('status', 'active')->whereNotNull('verified_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
