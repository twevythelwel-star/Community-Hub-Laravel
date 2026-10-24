<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReceipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'receipt_number',
        'receipt_type',
        'amount_minor',
        'currency',
        'payer_name',
        'payer_lot',
        'issued_by_name',
        'issued_at',
        'pdf_storage_path',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'issued_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
