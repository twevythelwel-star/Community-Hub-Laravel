<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Persistent anti-replay ledger. Replaces USED_NONCE_CACHE (an in-memory Map in
 * src/lib/gate-pass-engine/engine.ts) which emptied on every page reload and was
 * invisible to other guards and devices.
 */
class GatePassNonce extends Model
{
    protected $fillable = ['nonce', 'pass_id', 'first_seen_at', 'expires_at'];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', now());
    }
}
