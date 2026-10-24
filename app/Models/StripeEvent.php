<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per Stripe webhook event already applied. See the
 * create_stripe_events_table migration for why this is the idempotency lock.
 */
class StripeEvent extends Model
{
    protected $fillable = ['event_id', 'type', 'status', 'payload', 'error_message', 'processed_at'];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
