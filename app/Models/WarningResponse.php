<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One confirm/deny vote per user per warning, enforced by a unique index. */
class WarningResponse extends Model
{
    use HasFactory;

    protected $fillable = ['warning_id', 'user_id', 'response'];

    public function warning(): BelongsTo
    {
        return $this->belongsTo(Warning::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
