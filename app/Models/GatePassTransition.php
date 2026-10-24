<?php

namespace App\Models;

use App\Enums\PassStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step in a gate pass's life, written by GatePass::transitionTo(). */
class GatePassTransition extends Model
{
    protected $fillable = ['gate_pass_id', 'from_status', 'to_status', 'actor_id', 'gate', 'reason', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'from_status' => PassStatus::class,
            'to_status' => PassStatus::class,
            'occurred_at' => 'datetime',
        ];
    }

    public function gatePass(): BelongsTo
    {
        return $this->belongsTo(GatePass::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
