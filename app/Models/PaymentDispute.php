<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentDispute extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'provider_dispute_id',
        'amount_minor',
        'currency',
        'reason',
        'status',
        'evidence_due_by',
        'dispute_notes',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'evidence_due_by' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
