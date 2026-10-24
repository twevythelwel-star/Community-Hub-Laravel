<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'user_id',
        'total_installments',
        'frequency',
        'installment_amount_minor',
        'remaining_installments',
        'status',
    ];

    protected $casts = [
        'total_installments' => 'integer',
        'installment_amount_minor' => 'integer',
        'remaining_installments' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
