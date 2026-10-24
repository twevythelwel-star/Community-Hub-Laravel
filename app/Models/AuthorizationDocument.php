<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthorizationDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'document_type',
        'household_id',
        'ownership_entity_id',
        'authorized_person_id',
        'user_id',
        'gate_pass_id',
        'approval_request_id',
        'holder_name',
        'file_path',
        'file_name',
        'file_size_bytes',
        'mime_type',
        'issued_at',
        'expires_at',
        'status',
        'verification_status',
        'verified_by',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'expires_at' => 'date',
            'verified_at' => 'datetime',
            'file_size_bytes' => 'integer',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function ownershipEntity(): BelongsTo
    {
        return $this->belongsTo(OwnershipEntity::class);
    }

    public function authorizedPerson(): BelongsTo
    {
        return $this->belongsTo(AuthorizedPerson::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function gatePass(): BelongsTo
    {
        return $this->belongsTo(GatePass::class);
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(AccessApprovalRequest::class, 'approval_request_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isExpiringSoon(int $days = 14): bool
    {
        return $this->expires_at && ! $this->isExpired() && $this->expires_at->diffInDays(now()) <= $days;
    }
}
