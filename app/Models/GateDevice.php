<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GateDevice extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => 'array',
        'last_heartbeat_at' => 'datetime',
        'last_sync_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public static function hashKey(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    public static function findByPlainKey(string $plainKey): ?self
    {
        $hash = self::hashKey($plainKey);

        return self::where('device_key_hash', $hash)->first();
    }
}
