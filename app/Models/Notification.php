<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Community notices. target_roles carries the audience chosen by the AI targeting flow. */
class Notification extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'content', 'author_id', 'author_name', 'target_roles', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'target_roles' => 'array'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** Null or empty target_roles means the notice goes to everyone. */
    public function targetsRole(string $role): bool
    {
        return blank($this->target_roles) || in_array($role, $this->target_roles, true);
    }

    public function scopeForRole($query, string $role)
    {
        return $query->where(function ($q) use ($role) {
            $q->whereNull('target_roles')
                ->orWhereJsonContains('target_roles', $role);
        });
    }
}
