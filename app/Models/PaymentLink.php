<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'token',
        'title',
        'description',
        'amount_minor',
        'currency',
        'category',
        'user_id',
        'invoice_id',
        'expires_at',
        'max_uses',
        'uses_count',
        'active',
        'created_by',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'expires_at' => 'datetime',
        'max_uses' => 'integer',
        'uses_count' => 'integer',
        'active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $link) {
            $link->token ??= bin2hex(random_bytes(16));
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function publicUrl(): string
    {
        return url('/pay/'.$this->token);
    }

    public function formattedAmount(): string
    {
        if ($this->amount_minor === null) {
            return 'Custom Amount';
        }

        return '$'.number_format($this->amount_minor / 100, 2).' '.$this->currency;
    }
}
