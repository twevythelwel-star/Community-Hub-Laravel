<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'event_type',
        'provider_event_id',
        'status',
        'payload_reference',
        'occurred_at',
        'processed_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public static function log(
        Transaction $transaction,
        string $eventType,
        string $status,
        ?string $providerEventId = null,
        ?string $payloadReference = null
    ): self {
        return static::create([
            'transaction_id' => $transaction->id,
            'event_type' => $eventType,
            'provider_event_id' => $providerEventId,
            'status' => $status,
            'payload_reference' => $payloadReference,
            'occurred_at' => now(),
            'processed_at' => now(),
        ]);
    }
}
