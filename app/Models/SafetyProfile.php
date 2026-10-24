<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A credential holder's safety profile: what help they can request if they
 * raise a silent assistance request. Reached through an opaque Safety
 * Credential ID, and shown only to security staff once an alert is raised.
 */
class SafetyProfile extends Model
{
    /** police | medical | fire | security | contact */
    public const ASSISTANCE_TYPES = ['police', 'medical', 'fire', 'security', 'contact', 'emergency_services'];

    protected $fillable = [
        'user_id',
        'gate_pass_id',
        'visitor_id',
        'safety_credential_id',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
        'preferred_assistance',
        'assistance_preferences',
        'emergency_contacts',
        'preferred_gate',
        'preferred_vehicle_id',
        'medical_notes',
        'silent_alerts_enabled',
        'allowed_assistance',
        'allow_contact_sharing',
    ];

    /** Never serialised: neither the hash nor whether an arm is pending. */
    protected $hidden = ['duress_pin_hash', 'armed_until'];

    protected function casts(): array
    {
        return [
            'silent_alerts_enabled' => 'boolean',
            'allow_contact_sharing' => 'boolean',
            'armed_until' => 'datetime',
            'medical_notes' => 'encrypted',
            'assistance_preferences' => 'array',
            'allowed_assistance' => 'array',
            'emergency_contacts' => 'encrypted:array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SafetyProfile $profile) {
            if ($profile->user_id !== null) {
                $profile->safety_credential_id ??= self::newCredentialId();
            }
        });
    }

    public static function newCredentialId(): string
    {
        return 'SCID-'.Str::upper(Str::random(32));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function distressAlerts(): HasMany
    {
        return $this->hasMany(DistressAlert::class, 'safety_profile_id');
    }

    public function gatePass(): BelongsTo
    {
        return $this->belongsTo(GatePass::class);
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function hasDuressPin(): bool
    {
        return $this->duress_pin_hash !== null;
    }

    public function isArmed(): bool
    {
        return $this->armed_until?->isFuture() ?? false;
    }
}
