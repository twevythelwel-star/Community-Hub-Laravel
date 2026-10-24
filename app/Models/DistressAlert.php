<?php

namespace App\Models;

use App\Enums\GateId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A silent assistance request. Seen only by security and administration;
 * never shown in the resident's own notifications.
 */
class DistressAlert extends Model
{
    /** How it was raised: in the app, from a guest credential, by QR gesture, at the gate, or by duress PIN. */
    public const CHANNELS = ['app', 'credential', 'qr_gesture', 'qr_scan', 'duress_pin'];

    /** Escalation levels for safety safeguards */
    public const ESCALATION_SILENT_ALERT = 'silent_alert';

    public const ESCALATION_SECURITY = 'security_escalation';

    public const ESCALATION_EMERGENCY_SERVICES = 'emergency_services';

    public const SEVERITY_ASSISTANCE = 'assistance';

    public const SEVERITY_URGENT_ASSISTANCE = 'urgent_assistance';

    public const SEVERITY_SILENT_EMERGENCY = 'silent_emergency';

    public const SEVERITY_EMERGENCY_SERVICES = 'emergency_services';

    public const SEVERITY_LEVELS = [
        self::SEVERITY_ASSISTANCE,
        self::SEVERITY_URGENT_ASSISTANCE,
        self::SEVERITY_SILENT_EMERGENCY,
        self::SEVERITY_EMERGENCY_SERVICES,
    ];

    protected $fillable = [
        'user_id',
        'safety_profile_id',
        'visitor_id',
        'incident_id',
        'gate_pass_id',
        'channel',
        'assistance_type',
        'severity_level',
        'status',
        'gate',
        'event_type',
        'event_at',
        'credential_reference',
        'vehicle_context',
        'latitude',
        'longitude',
        'notes',
        'triggered_at',
        'acknowledgment_deadline_at',
        'escalation_level',
        'escalated_at',
        'escalated_by',
    ];

    protected function casts(): array
    {
        return [
            'triggered_at' => 'datetime',
            'acknowledgment_deadline_at' => 'datetime',
            'event_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'escalated_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'vehicle_context' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function safetyProfile(): BelongsTo
    {
        return $this->belongsTo(SafetyProfile::class, 'safety_profile_id');
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class, 'visitor_id');
    }

    public function gatePass(): BelongsTo
    {
        return $this->belongsTo(GatePass::class);
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function escalator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(DistressAlertEvent::class, 'distress_alert_id')->orderBy('occurred_at')->orderBy('id');
    }

    public static function newIncidentId(): string
    {
        do {
            $id = 'DP-'.now()->format('Y').'-'.Str::upper(Str::random(4));
        } while (self::where('incident_id', $id)->exists());

        return $id;
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', ['open', 'acknowledged']);
    }

    public function representativeAppointment(): ?AuthorizedPerson
    {
        if ($this->user) {
            return $this->user->activeAuthorizedAppointments()->with('ownershipEntity')->first();
        }

        if ($this->gatePass?->user) {
            return $this->gatePass->user->activeAuthorizedAppointments()->with('ownershipEntity')->first();
        }

        return null;
    }

    public function isEntityRepresentative(): bool
    {
        return $this->representativeAppointment() !== null;
    }

    public function ownerEntity(): ?OwnershipEntity
    {
        return $this->representativeAppointment()?->ownershipEntity;
    }

    public function ownerEntityName(): ?string
    {
        return $this->ownerEntity()?->legal_name;
    }

    public function ownerEntityType(): ?string
    {
        return $this->ownerEntity()?->entity_type?->label();
    }

    public function representativeRole(): ?string
    {
        return $this->representativeAppointment()?->role?->label();
    }

    public function personName(): string
    {
        return $this->user?->display_name ?? $this->requesterName() ?? 'Unknown';
    }

    public function capacityLabel(): string
    {
        $appointment = $this->representativeAppointment();
        if ($appointment) {
            return 'Authorized Representative';
        }

        if ($this->gatePass) {
            return $this->gatePass->category->label();
        }

        return $this->user ? $this->user->role->label() : 'Visitor';
    }

    public function requesterName(): ?string
    {
        return $this->user?->display_name ?? $this->visitor?->name ?? $this->gatePass?->holder_name;
    }

    public function propertyLabel(): ?string
    {
        return $this->user?->propertyLabel()
            ?? $this->gatePass?->property
            ?? $this->ownerEntity()?->properties()->first()?->label()
            ?? $this->visitor?->property?->label()
            ?? $this->visitor?->homeowner?->propertyLabel();
    }

    public function gateLabel(): string
    {
        if (! $this->gate) {
            return 'Unknown';
        }

        return config('gatepass.gates.'.$this->gate, GateId::tryFrom($this->gate)?->label() ?? $this->gate);
    }

    public function assistanceLabel(): string
    {
        return ucfirst(str_replace('_', ' ', $this->assistance_type));
    }

    public function activeStatusLabel(): string
    {
        return in_array($this->status, ['open', 'acknowledged'], true) ? 'ACTIVE' : strtoupper(str_replace('_', ' ', $this->status));
    }

    public function channelLabel(): string
    {
        return match ($this->channel) {
            'app' => 'App request',
            'credential' => 'Credential assistance request',
            'qr_gesture' => 'Discreet QR gesture',
            'qr_scan' => 'Gate scan of an armed credential',
            'duress_pin' => 'Duress PIN at the gate',
            default => $this->channel,
        };
    }

    public function escalationLabel(): string
    {
        return match ($this->escalation_level) {
            self::ESCALATION_SILENT_ALERT => 'Level 1 - Silent Security Alert',
            self::ESCALATION_SECURITY => 'Level 2 - Security Escalation',
            self::ESCALATION_EMERGENCY_SERVICES => 'Level 3 - Emergency Services Escalation',
            default => 'Not Escalated',
        };
    }

    public function severityLabel(): string
    {
        return match ($this->severity_level) {
            self::SEVERITY_ASSISTANCE => 'Assistance',
            self::SEVERITY_URGENT_ASSISTANCE => 'Urgent Assistance',
            self::SEVERITY_SILENT_EMERGENCY => 'Silent Emergency',
            self::SEVERITY_EMERGENCY_SERVICES => 'Emergency Services',
            default => 'Assistance',
        };
    }

    public function canEscalateToSecurity(): bool
    {
        return $this->escalation_level === self::ESCALATION_SILENT_ALERT;
    }

    public function canEscalateToEmergencyServices(): bool
    {
        return $this->escalation_level === self::ESCALATION_SECURITY;
    }

    public function isEmergencyServicesEligible(): bool
    {
        return in_array($this->assistance_type, ['police', 'medical', 'fire', 'emergency_services'], true);
    }
}
