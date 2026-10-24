<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\AuthorizationScope;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Access Authorization.
 *
 * Scope · Permissions · Approval · Expiry · Revocation
 *
 * Enforces cross-property access restrictions:
 * Access to a property is denied unless explicitly authorized.
 */
class AccessAuthorization extends Model
{
    use HasFactory;

    protected $table = 'access_authorizations';

    protected $fillable = [
        'person_id',
        'property_id',
        'property_relationship_id',
        'scope',
        'allowed_zones',
        'permissions',
        'access_rules',
        'approval_status',
        'approved_by',
        'approved_at',
        'valid_from',
        'valid_until',
        'status',
        'is_revoked',
        'revoked_at',
        'revoked_by',
        'revocation_reason',
        'source_type',
        'source_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'scope' => AuthorizationScope::class,
            'approval_status' => ApprovalStatus::class,
            'allowed_zones' => 'array',
            'permissions' => 'array',
            'access_rules' => 'array',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'approved_at' => 'datetime',
            'is_revoked' => 'boolean',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function propertyRelationship(): BelongsTo
    {
        return $this->belongsTo(PropertyRelationship::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function gatePasses(): HasMany
    {
        return $this->hasMany(GatePass::class);
    }

    /**
     * Scope query for live, unrevoked, approved authorizations.
     *
     * @param  Builder<AccessAuthorization>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active')
            ->where('is_revoked', false)
            ->where('approval_status', ApprovalStatus::Approved->value)
            ->where('valid_from', '<=', now())
            ->where(function ($q) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', now());
            });
    }

    /**
     * Determine whether the authorization is active and in good standing.
     */
    public function isCurrentlyActive(?CarbonInterface $at = null): bool
    {
        $time = $at ?? Carbon::now();

        if ($this->is_revoked || $this->status !== 'active') {
            return false;
        }

        if ($this->approval_status !== ApprovalStatus::Approved) {
            return false;
        }

        if ($this->valid_from && $time->lt($this->valid_from)) {
            return false;
        }

        if ($this->valid_until && $time->gt($this->valid_until)) {
            return false;
        }

        return true;
    }

    /**
     * Enforce Cross-Property Isolation:
     * Access to any property is denied unless explicitly authorized.
     */
    public function isPermittedForProperty(Property|int $targetProperty): bool
    {
        $targetPropertyId = $targetProperty instanceof Property ? $targetProperty->id : $targetProperty;

        // Community-wide authorizations (e.g. estate maintenance, emergency) permit all properties
        if ($this->scope === AuthorizationScope::Community) {
            return true;
        }

        // Single-property scope strictly enforces exact property match
        if ($this->scope === AuthorizationScope::Property) {
            return (int) $this->property_id === (int) $targetPropertyId;
        }

        // Zone-based authorization: check explicit allowed properties if declared
        if ($this->scope === AuthorizationScope::Zones) {
            $allowedProps = $this->metadata['allowed_property_ids'] ?? [];
            if (! empty($allowedProps) && in_array((int) $targetPropertyId, array_map('intval', $allowedProps), true)) {
                return true;
            }

            return (int) $this->property_id === (int) $targetPropertyId;
        }

        return false;
    }

    /**
     * Check if a specific permission is granted.
     */
    public function hasPermission(string $permission): bool
    {
        if (empty($this->permissions)) {
            return false;
        }

        return in_array($permission, $this->permissions, true) || in_array('*', $this->permissions, true);
    }

    /**
     * Approve authorization.
     */
    public function approve(User $approver): void
    {
        $this->update([
            'approval_status' => ApprovalStatus::Approved,
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'status' => 'active',
        ]);
    }

    /**
     * Reject authorization.
     */
    public function reject(User $reviewer, ?string $reason = null): void
    {
        $this->update([
            'approval_status' => ApprovalStatus::Rejected,
            'status' => 'rejected',
            'metadata' => array_merge($this->metadata ?? [], ['rejection_reason' => $reason]),
        ]);
    }

    /**
     * Revoke authorization immediately.
     */
    public function revoke(User $revoker, string $reason): void
    {
        $this->update([
            'is_revoked' => true,
            'status' => 'revoked',
            'revoked_at' => now(),
            'revoked_by' => $revoker->id,
            'revocation_reason' => $reason,
        ]);

        // Revoke linked gate passes as well
        $this->gatePasses()->each(function (GatePass $pass) use ($revoker, $reason) {
            if ($pass->isActive()) {
                $pass->revoke($revoker, $reason);
            }
        });
    }
}
