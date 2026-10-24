<?php

namespace App\Models;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Exceptions\InvalidPassTransition;
use App\Traits\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Searchable;

/**
 * Pass registry. Every pass belongs either to an account (residents, staff,
 * security, administrators) or to a visitor record (visitors, contractors).
 *
 * Status changes go through transitionTo(), which enforces the PassStatus
 * transition table and records each step in gate_pass_transitions.
 */
class GatePass extends Model
{
    use Auditable, HasFactory, Searchable;

    protected $fillable = [
        'pass_id', 'user_id', 'person_id', 'access_authorization_id', 'property_id', 'visitor_id', 'delegated_access_id', 'category', 'holder_name', 'property',
        'access_zone', 'designated_gate', 'valid_from', 'valid_until', 'single_entry',
        'color_variant', 'rotation_seq', 'status', 'checked_in_at', 'checked_out_at',
        'status_changed_at', 'revoked_at', 'revoked_by', 'revocation_reason', 'last_rotated_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'category' => PassCategory::class,
            'designated_gate' => GateId::class,
            'status' => PassStatus::class,
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'single_entry' => 'boolean',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_rotated_at' => 'datetime',
            'rotation_seq' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function accessAuthorization(): BelongsTo
    {
        return $this->belongsTo(AccessAuthorization::class);
    }

    public function targetProperty(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    public function delegatedAccess(): BelongsTo
    {
        return $this->belongsTo(DelegatedAccess::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(GatePassTransition::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function isRevoked(): bool
    {
        return $this->status === PassStatus::Revoked || $this->revoked_at !== null;
    }

    /** Usable at the gate now or later: issued, active, inside or between visits. */
    public function isActive(): bool
    {
        return $this->status->isLive() && ! $this->isRevoked();
    }

    /** @param  Builder<GatePass>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', array_map(
            fn (PassStatus $s) => $s->value,
            array_filter(PassStatus::cases(), fn (PassStatus $s) => $s->isLive()),
        ))->whereNull('revoked_at');
    }

    /** Whether the pass's own validity window (not the token's) covers a moment. */
    public function isWithinValidity(CarbonInterface $at): bool
    {
        return ($this->valid_from === null || $at->greaterThanOrEqualTo($this->valid_from))
            && ($this->valid_until === null || $at->lessThanOrEqualTo($this->valid_until));
    }

    public function canTransitionTo(PassStatus $next): bool
    {
        if (! $this->status->canTransitionTo($next)) {
            return false;
        }

        // Re-entry is what a multi-entry pass is for, and what a single-entry
        // pass must never allow.
        return ! ($this->status === PassStatus::CheckedOut && $next === PassStatus::CheckedIn && $this->single_entry);
    }

    /** Deterministic, cryptographically secure 6-digit keyed offline backup gate PIN */
    public function getOfflinePinAttribute(): string
    {
        $key = config('app.key') ?: 'community-hub-secret-gate-key';
        $binaryHash = hash_hmac('sha256', "gate-offline:{$this->pass_id}:".($this->created_at?->timestamp ?? 'pin'), $key, true);
        $unpacked = unpack('N', substr($binaryHash, 0, 4))[1] & 0x7FFFFFFF;

        return sprintf('%06d', $unpacked % 1000000);
    }

    /**
     * Moves the pass to a new state, or throws if the state table forbids it.
     *
     * The row is re-read under a lock first, so two guards confirming at the
     * same moment cannot both check the same person in.
     *
     * @throws InvalidPassTransition
     */
    public function transitionTo(PassStatus $next, ?User $actor = null, ?string $reason = null, ?GateId $gate = null): self
    {
        return DB::transaction(function () use ($next, $actor, $reason, $gate) {
            /** @var self $locked */
            $locked = self::query()->lockForUpdate()->findOrFail($this->getKey());

            if (! $locked->canTransitionTo($next)) {
                throw InvalidPassTransition::between($locked, $next);
            }

            $from = $locked->status;
            $now = now();

            $changes = ['status' => $next, 'status_changed_at' => $now];

            match ($next) {
                PassStatus::CheckedIn => $changes['checked_in_at'] = $now,
                PassStatus::CheckedOut => $changes['checked_out_at'] = $now,
                PassStatus::Revoked => $changes += [
                    'revoked_at' => $now,
                    'revoked_by' => $actor?->id,
                    'revocation_reason' => $reason,
                ],
                default => null,
            };

            $locked->update($changes);

            $locked->transitions()->create([
                'from_status' => $from,
                'to_status' => $next,
                'actor_id' => $actor?->id,
                'gate' => $gate?->value,
                'reason' => $reason,
                'occurred_at' => $now,
            ]);

            $this->setRawAttributes($locked->getAttributes(), true);

            return $this;
        });
    }

    /** Records the state a pass was created in, so its history starts somewhere. */
    public function recordCreation(?User $actor = null, ?string $reason = null): void
    {
        $this->transitions()->create([
            'from_status' => null,
            'to_status' => $this->status,
            'actor_id' => $actor?->id,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }

    public function revoke(?User $by = null, ?string $reason = null): void
    {
        $this->transitionTo(PassStatus::Revoked, $by, $reason);
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (int) $this->id,
            'pass_id' => $this->pass_id,
            'holder_name' => $this->holder_name,
            'property' => $this->property,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : (string) $this->status,
            'category' => $this->category instanceof \BackedEnum ? $this->category->value : (string) $this->category,
        ];
    }
}
