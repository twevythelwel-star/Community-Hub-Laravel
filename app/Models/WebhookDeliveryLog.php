<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDeliveryLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'webhook_subscription_id',
        'event',
        'url',
        'status_code',
        'is_success',
        'request_payload',
        'response_body',
        'duration_ms',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'status_code' => 'integer',
            'is_success' => 'boolean',
            'duration_ms' => 'integer',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'webhook_subscription_id');
    }
}
