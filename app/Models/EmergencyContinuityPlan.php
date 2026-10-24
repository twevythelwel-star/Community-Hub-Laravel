<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyContinuityPlan extends Model
{
    use HasFactory;

    protected $table = 'emergency_continuity_plans';

    public const CONDITIONS = [
        'medical_emergency' => 'Medical Emergency / Hospitalization',
        'incapacity' => 'Temporary or Permanent Incapacity',
        'extended_absence' => 'Extended Absence / Unreachable (>72 hours)',
        'legal_probate' => 'Estate Administration / Legal Succession',
    ];

    public const VERIFICATIONS = [
        'admin_verification' => 'Community Administrator Verification',
        'two_officer_signoff' => 'Two Security Officers Signoff',
        'legal_affidavit' => 'Written Legal / Medical Affidavit',
        'homeowner_unreachable_72h' => 'Homeowner Unreachable for 72 Hours',
    ];

    public const ACTIONS = [
        'gate_access' => 'Full Gate Access & Visitor Authorization',
        'property_maintenance' => 'Property Maintenance & Emergency Repairs',
        'emergency_dispatch' => 'Emergency SOS Dispatch & Incident Reports',
        'financial_oversight' => 'View Dues & Statements (No Direct Card Access)',
        'community_communications' => 'Receive Official Community Life Updates',
    ];

    protected $fillable = [
        'user_id',
        'primary_delegate_id',
        'secondary_delegate_id',
        'activation_conditions',
        'required_verification',
        'authorized_actions',
        'max_duration_days',
        'requires_admin_approval',
        'notify_homeowner_on_trigger',
        'notify_community_security',
        'notification_recipients',
        'special_instructions',
    ];

    protected function casts(): array
    {
        return [
            'activation_conditions' => 'array',
            'authorized_actions' => 'array',
            'notification_recipients' => 'array',
            'max_duration_days' => 'integer',
            'requires_admin_approval' => 'boolean',
            'notify_homeowner_on_trigger' => 'boolean',
            'notify_community_security' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function primaryDelegate(): BelongsTo
    {
        return $this->belongsTo(DelegatedAccess::class, 'primary_delegate_id');
    }

    public function secondaryDelegate(): BelongsTo
    {
        return $this->belongsTo(DelegatedAccess::class, 'secondary_delegate_id');
    }
}
