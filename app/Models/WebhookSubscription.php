<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WebhookSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'estate_id',
        'name',
        'url',
        'secret',
        'events',
        'is_active',
        'failure_count',
        'last_delivered_at',
    ];

    public static function generateSecret(): string
    {
        return 'whsec_'.Str::random(32);
    }

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            'failure_count' => 'integer',
            'last_delivered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WebhookSubscription $subscription) {
            if (empty($subscription->secret)) {
                $subscription->secret = static::generateSecret();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deliveryLogs(): HasMany
    {
        return $this->hasMany(WebhookDeliveryLog::class);
    }

    public function subscribesTo(string $event): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $events = $this->events ?? [];

        return in_array('*', $events, true) || in_array($event, $events, true);
    }
}
