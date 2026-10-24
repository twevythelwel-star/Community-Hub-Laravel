<?php

namespace App\Models;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Models\Contracts\PropertyPerson;
use App\Services\GatePassEngine;
use App\Traits\SummarizesPropertyPerson;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DelegatedAccess extends Model implements PropertyPerson
{
    use HasFactory;
    use SummarizesPropertyPerson;

    protected $table = 'delegated_accesses';

    public const ACCESS_LEVELS = [
        'Pre-Cleared Visitor' => 'Short-term or invited guest pre-cleared for property ingress',
        'Visitor' => 'Invited guest with digital gate access credential',
        'Emergency Contact' => 'Receive emergency notifications and contact homeowner/security',
        'Long-Term Occupant' => 'Extended stay non-resident with designated property and amenity access',
        'Legacy Delegate' => 'Access remains available according to a predefined succession/legacy arrangement',
        'Family Member' => 'Non-resident family member with recurrent property clearance',
        'Caregiver' => 'Care and property assistance access with scheduled operational windows',
        'Property Delegate' => 'Manage approved property-related functions and vendors',
        'Domestic Staff' => 'Private household staff (housekeeper, gardener, driver, private guard)',
        'Contractor' => 'Contracted vendor or maintenance technician with task-specific entry window',
        'Limited Delegate' => 'Perform specifically authorized homeowner actions',
        'Emergency Delegate' => 'Temporary broader access activated during an emergency',
        'Full Authorized Representative' => 'Highest delegated authority, subject to verification',
    ];

    public const RELATIONSHIPS = [
        'Family member',
        'Relative',
        'Family/Friend',
        'Domestic Worker',
        'Cleaner',
        'Caregiver',
        'Gardener',
        'Pool Maintenance',
        'Driver',
        'Contractor',
        'Service Provider',
        'Long-Term Service Provider',
        'Babysitter',
        'Delivery Personnel',
        'Domestic Staff',
        'Property manager',
        'Attorney',
        'Executor',
        'Trusted friend',
        'Emergency representative',
        'Other',
    ];

    public const PERMISSIONS = [
        'gate_access' => 'Digital Gate Pass & Scanner Clearance',
        'visitor_authorization' => 'Authorize Visitors & Pre-Clear Guests',
        'emergency_communications' => 'Receive & Respond to Emergency SOS Notices',
        'property_maintenance' => 'Submit & Approve Maintenance Requests',
        'incident_reporting' => 'Submit Incident Reports & View Logs',
        'document_access' => 'Access Bylaws & Community Documents',
    ];

    public const DURATION_TYPES = [
        '2_hours' => '2 Hours (Cleaners, deliveries, short visits)',
        '1_day' => '1 Day (Day workers, babysitters, party guests)',
        '1_week' => '1 Week (Weekly babysitters, short-stay relatives, painting crews)',
        'custom' => 'Custom Date Range (Specific start and expiration date)',
        'recurring' => 'Recurring Days (Weekly recurring shift schedule e.g. Tue/Thu)',
        'manual_revocation' => 'Until Manually Revoked (Active indefinitely until revoked)',
    ];

    protected $fillable = [
        'grantor_user_id',
        'delegate_user_id',
        'property_id',
        'name',
        'email',
        'phone',
        'relationship',
        'authorization_type',
        'access_level',
        'duration_type',
        'permissions',
        'access_rules',
        'status',
        'starts_at',
        'expires_at',
        'activation_method',
        'approval_status',
        'approved_by',
        'approved_at',
        'is_emergency_active',
        'emergency_activated_at',
        'emergency_expires_at',
        'emergency_reason',
        'emergency_activated_by',
        'revoked_at',
        'revocation_reason',
        'invite_token',
        'security_notes',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'access_rules' => 'array',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'is_emergency_active' => 'boolean',
            'emergency_activated_at' => 'datetime',
            'emergency_expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantor_user_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_user_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function emergencyActivator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emergency_activated_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DelegatedAccessEvent::class)->orderByDesc('occurred_at');
    }

    public function gatePasses(): HasMany
    {
        return $this->hasMany(GatePass::class);
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->access_level === 'Emergency Contact') {
            return $permission === 'emergency_communications';
        }

        if ($this->access_level === 'Full Authorized Representative') {
            return true;
        }

        $perms = $this->permissions ?? [];

        return in_array($permission, $perms, true);
    }

    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->approval_status === 'pending' || $this->approval_status === 'rejected') {
            return false;
        }

        if ($this->starts_at && now()->isBefore($this->starts_at)) {
            return false;
        }

        if ($this->expires_at && now()->isAfter($this->expires_at)) {
            return false;
        }

        if ($this->activation_method === 'emergency_trigger' && ! $this->is_emergency_active) {
            return false;
        }

        return true;
    }

    public function personType(): string
    {
        return 'delegate';
    }

    public function personName(): string
    {
        return (string) ($this->name ?: $this->delegate?->name);
    }

    public function personRole(): string
    {
        return (string) ($this->relationship ?: $this->access_level);
    }

    public function hasCurrentAccess(): bool
    {
        return $this->isActive();
    }

    public function accessEndsAt(): ?CarbonInterface
    {
        return $this->duration_type === 'manual_revocation' ? null : $this->expires_at;
    }

    public function isExpired(): bool
    {
        if ($this->duration_type === 'manual_revocation' || $this->expires_at === null) {
            return false;
        }

        return now()->isAfter($this->expires_at);
    }

    public function durationLabel(): string
    {
        if ($this->duration_type === 'recurring') {
            $days = $this->access_rules['allowed_days'] ?? null;
            if (is_array($days) && count($days) > 0) {
                if ($days === ['Mon', 'Tue', 'Wed', 'Thu', 'Fri']) {
                    return 'Mon–Fri Recurring';
                }
                if (count($days) <= 3) {
                    return implode('/', $days).' Recurring';
                }

                return count($days).' Days/Wk Recurring';
            }

            return 'Recurring Days';
        }

        return match ($this->duration_type) {
            '2_hours' => '2 Hours',
            '1_day' => '1 Day',
            '1_week' => '1 Week',
            'manual_revocation' => 'Until Revoked',
            default => 'Custom Date Range',
        };
    }

    public function isRevoked(): bool
    {
        return $this->status === 'revoked' || $this->revoked_at !== null;
    }

    public function logEvent(string $type, string $description, array $metadata = [], ?User $actor = null): DelegatedAccessEvent
    {
        return $this->events()->create([
            'actor_user_id' => $actor?->id,
            'event_type' => $type,
            'description' => $description,
            'metadata' => $metadata,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'occurred_at' => now(),
        ]);
    }

    public function activateEmergency(string $reason, ?User $actor = null, ?\DateTimeInterface $expiresAt = null, ?string $notes = null): self
    {
        $this->update([
            'is_emergency_active' => true,
            'emergency_activated_at' => now(),
            'emergency_expires_at' => $expiresAt ?? now()->addDays(7),
            'emergency_reason' => $reason,
            'emergency_activated_by' => $actor?->id,
            'security_notes' => $notes ?? $this->security_notes,
        ]);

        $this->logEvent('emergency_activated', "Emergency access activated: {$reason}", [
            'reason' => $reason,
            'expires_at' => $this->emergency_expires_at?->toIso8601String(),
            'activated_by' => $actor?->name ?? 'System',
        ], $actor);

        return $this;
    }

    public function deactivateEmergency(?User $actor = null, ?string $reason = null): self
    {
        $this->update([
            'is_emergency_active' => false,
            'emergency_expires_at' => now(),
        ]);

        $this->logEvent('emergency_deactivated', 'Emergency access deactivated'.($reason ? ": {$reason}" : ''), [
            'deactivated_by' => $actor?->name ?? 'System',
            'reason' => $reason,
        ], $actor);

        return $this;
    }

    public function revoke(?User $actor = null, ?string $reason = null): self
    {
        $this->update([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revocation_reason' => $reason ?? 'Revoked by homeowner or administrator',
            'is_emergency_active' => false,
        ]);

        $this->logEvent('revoked', "Access delegation revoked: {$this->revocation_reason}", [
            'revoked_by' => $actor?->name ?? 'System',
            'reason' => $this->revocation_reason,
        ], $actor);

        // This delegation's passes only. It also revoked every delegate pass the
        // same person held from other homeowners' delegations.
        $this->revokePasses($actor, 'Delegated access revoked');

        return $this;
    }

    /**
     * Revoke all active passes issued under this delegation.
     */
    public function revokePasses(?User $actor = null, string $reason = 'Pass rotated or revoked'): self
    {
        $this->gatePasses()
            ->whereIn('status', [PassStatus::Active, PassStatus::Issued, PassStatus::CheckedIn])
            ->get()
            ->each(function (GatePass $pass) use ($actor, $reason) {
                $pass->transitionTo(PassStatus::Revoked, $actor, $reason);
            });

        return $this;
    }

    /**
     * Issues or rotates an authorized delegate or extended occupant gate pass.
     */
    /**
     * When a pass issued now should stop working: the delegation's own end, or
     * its emergency end, but never beyond config('delegation.max_pass_days').
     */
    public function passValidUntil(): Carbon
    {
        $from = $this->starts_at && $this->starts_at->isFuture() ? $this->starts_at->clone() : now();

        $until = match (true) {
            $this->is_emergency_active => $this->emergency_expires_at ?? now()->addDays(7),
            $this->duration_type === '2_hours' => $this->expires_at ?? $from->clone()->addHours(2),
            $this->duration_type === '1_day' => $this->expires_at ?? $from->clone()->endOfDay(),
            $this->duration_type === '1_week' => $this->expires_at ?? $from->clone()->addDays(7)->endOfDay(),
            $this->duration_type === 'recurring' => $this->expires_at ?? now()->endOfYear(),
            // Until revoked: the delegation runs on, each pass is renewed.
            $this->duration_type === 'manual_revocation' => now()->addDays((int) config('delegation.max_pass_days', 365)),
            default => $this->expires_at ?? now()->addMonths(6),
        };

        $cap = now()->addDays((int) config('delegation.max_pass_days', 365));

        return Carbon::parse($until)->min($cap);
    }

    public function issueDelegateGatePass(GatePassEngine $engine, ?User $actor = null): GatePass
    {
        $grantor = $this->grantor;
        $propertyLabel = $grantor?->propertyLabel() ?? ($this->property ? "Lot {$this->property->lot_number}" : 'Residence');

        $category = match (true) {
            $this->access_level === 'Long-Term Occupant' || $this->authorization_type === 'long_term_occupant' => PassCategory::LongTermOccupant,
            $this->access_level === 'Domestic Staff' || $this->authorization_type === 'domestic_staff' => PassCategory::HomeownerStaff,
            default => PassCategory::Delegate,
        };
        $prefix = $category->passIdPrefix();

        $passId = sprintf('%s-%d-%s', $prefix, $this->id, strtoupper(bin2hex(random_bytes(2))));

        $pass = GatePass::create([
            'pass_id' => $passId,
            'user_id' => $this->delegate_user_id ?? $this->grantor_user_id,
            'delegated_access_id' => $this->id,
            'category' => $category,
            'holder_name' => $this->name,
            'property' => $propertyLabel,
            'access_zone' => $category->defaultZone(),
            'designated_gate' => GateId::Any,
            'rotation_seq' => 1,
            'status' => PassStatus::Active,
            'valid_from' => $this->starts_at ?? now(),
            'valid_until' => $this->passValidUntil(),
            'max_uses' => 500,
            'uses_count' => 0,
            'single_entry' => false,
            'metadata' => [
                'delegated_access_id' => $this->id,
                'relationship' => $this->relationship,
                'grantor_name' => $grantor?->name,
                'access_level' => $this->access_level,
                'duration_type' => $this->duration_type ?? 'custom',
                'authorization_type' => $this->authorization_type ?? ($category === PassCategory::LongTermOccupant ? 'long_term_occupant' : 'legacy_contact'),
                'is_emergency' => $this->is_emergency_active,
                'access_rules' => $this->access_rules ?? [],
            ],
        ]);

        $this->logEvent('gate_pass_issued', "Gate Pass {$passId} ({$category->label()}) issued to {$this->name}", [
            'pass_id' => $passId,
            'category' => $category->value,
            'valid_until' => $pass->valid_until->toIso8601String(),
        ], $actor);

        return $pass;
    }
}
