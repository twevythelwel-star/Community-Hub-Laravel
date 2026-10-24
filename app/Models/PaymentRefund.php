<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRefund extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'refund_reference',
        'provider_refund_id',
        'amount_minor',
        'currency',
        'reason',
        'status',
        'created_by',
        'refunded_at',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'refunded_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
