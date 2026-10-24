<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccessApprovalRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_number',
        'category',
        'applicant_name',
        'applicant_phone',
        'applicant_email',
        'homeowner_id',
        'property_number',
        'gate_pass_id',
        'workflow_type',
        'current_stage',
        'status',
        'approval_chain',
        'requested_from',
        'requested_until',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'approval_chain' => 'array',
            'metadata' => 'array',
            'requested_from' => 'datetime',
            'requested_until' => 'datetime',
        ];
    }

    public function homeowner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'homeowner_id');
    }

    public function gatePass(): BelongsTo
    {
        return $this->belongsTo(GatePass::class, 'gate_pass_id');
    }

    public function pass(): BelongsTo
    {
        return $this->belongsTo(GatePass::class, 'gate_pass_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AuthorizationDocument::class, 'approval_request_id');
    }
}
