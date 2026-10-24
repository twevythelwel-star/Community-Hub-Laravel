<?php

namespace App\Models;

use App\Enums\PaymentState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step in a payment's history. Written by Payment::transitionTo(), never edited. */
class PaymentStateTransition extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'payment_id',
        'from_state',
        'to_state',
        'actor_id',
        'source',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'from_state' => PaymentState::class,
            'to_state' => PaymentState::class,
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
