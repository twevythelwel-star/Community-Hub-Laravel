<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Payout extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_name',
        'category',
        'amount_minor',
        'currency',
        'status',
        'scheduled_for',
        'processed_at',
        'approved_by',
        'reference',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'scheduled_for' => 'date',
        'processed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $payout) {
            $payout->reference ??= 'PAYOUT-'.strtoupper(Str::random(8));
        });
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function formattedAmount(): string
    {
        return '$'.number_format($this->amount_minor / 100, 2).' '.$this->currency;
    }
}
