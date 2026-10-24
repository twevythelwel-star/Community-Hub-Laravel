<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only forensic event attached to a discreet distress incident. */
class DistressAlertEvent extends Model
{
    public const ACTIVATED = 'activated';

    public const DISPATCH_QUEUED = 'dispatch_queued';

    public const DISPATCH_SENT = 'dispatch_sent';

    public const DISPATCH_DELIVERED = 'dispatch_delivered';

    public const SECURITY_NOTIFIED = 'security_notified';

    public const ACKNOWLEDGED = 'security_acknowledged';

    public const ACKNOWLEDGMENT_TIMEOUT_ESCALATED = 'acknowledgment_timeout_escalated';

    public const RESPONSE_INITIATED = 'response_initiated';

    public const GATE_CONTEXT_CAPTURED = 'gate_context_captured';

    public const EMERGENCY_SERVICES_ESCALATED = 'emergency_services_escalated';

    public const CLOSED = 'incident_closed';

    protected $fillable = [
        'incident_id',
        'distress_alert_id',
        'user_id',
        'safety_profile_id',
        'visitor_id',
        'actor_id',
        'event_type',
        'occurred_at',
        'snapshot',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Distress audit events are immutable.'));
        static::deleting(fn () => throw new LogicException('Distress audit events are immutable.'));
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(DistressAlert::class, 'distress_alert_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function safetyProfile(): BelongsTo
    {
        return $this->belongsTo(SafetyProfile::class, 'safety_profile_id');
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class, 'visitor_id');
    }
}
