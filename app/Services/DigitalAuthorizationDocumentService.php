<?php

namespace App\Services;

use App\Enums\PassStatus;
use App\Models\AccessAuditTimelineEvent;
use App\Models\AuthorizationDocument;
use App\Models\GatePass;
use App\Models\User;
use Carbon\Carbon;

class DigitalAuthorizationDocumentService
{
    /**
     * Attach a digital authorization compliance document.
     */
    public function attachDocument(array $data, ?User $actor = null): AuthorizationDocument
    {
        $expiresAt = Carbon::parse($data['expires_at']);
        $now = now();

        $status = 'valid';
        if ($expiresAt->isPast()) {
            $status = 'expired';
        } elseif ($expiresAt->diffInDays($now) <= 14) {
            $status = 'expiring_soon';
        }

        $doc = AuthorizationDocument::create([
            'title' => $data['title'],
            'document_type' => $data['document_type'], // lease, authorization_letter, id_verification, insurance, contractor_certificate, other
            'household_id' => $data['household_id'] ?? null,
            'user_id' => $data['user_id'] ?? $actor?->id,
            'gate_pass_id' => $data['gate_pass_id'] ?? null,
            'approval_request_id' => $data['approval_request_id'] ?? null,
            'holder_name' => $data['holder_name'],
            'file_path' => $data['file_path'] ?? '/storage/documents/'.uniqid().'.pdf',
            'file_name' => $data['file_name'] ?? ($data['title'].'.pdf'),
            'file_size_bytes' => $data['file_size_bytes'] ?? rand(500000, 3500000),
            'mime_type' => $data['mime_type'] ?? 'application/pdf',
            'issued_at' => isset($data['issued_at']) ? Carbon::parse($data['issued_at']) : $now,
            'expires_at' => $expiresAt,
            'status' => $status,
            'verification_status' => $data['verification_status'] ?? 'verified',
            'verified_by' => $actor?->id,
            'verified_at' => $now,
        ]);

        if (! empty($data['gate_pass_id'])) {
            $pass = GatePass::find($data['gate_pass_id']);
            if ($pass) {
                AccessAuditTimelineEvent::create([
                    'pass_id' => $pass->pass_id,
                    'user_id' => $pass->user_id,
                    'holder_name' => $doc->holder_name,
                    'gate' => 'COMPLIANCE_VAULT',
                    'event_type' => 'IDENTITY_VERIFIED',
                    'severity' => 'INFO',
                    'headline' => "Compliance Document Verified: {$doc->title}",
                    'description' => "Uploaded {$doc->document_type} (Valid until {$doc->expires_at->toFormattedDateString()})",
                    'actor_type' => $actor ? 'GUARD' : 'SYSTEM',
                    'actor_id' => $actor?->id,
                    'occurred_at' => $now,
                ]);
            }
        }

        return $doc;
    }

    /**
     * Audit all documents and identify expired or expiring items.
     * Automatically suspends passes tied to expired mandatory compliance documents.
     */
    public function auditDocumentExpirations(): array
    {
        $now = now();
        $expiredDocs = AuthorizationDocument::where('expires_at', '<', $now)->get();

        $suspendedPasses = 0;
        foreach ($expiredDocs as $doc) {
            if ($doc->status !== 'expired') {
                $doc->update(['status' => 'expired']);
            }

            // If attached to a live pass, suspend the credential due to compliance expiration!
            if ($doc->gate_pass_id) {
                $pass = GatePass::find($doc->gate_pass_id);
                if ($pass && $pass->isActive()) {
                    $pass->update([
                        'status' => PassStatus::Suspended,
                        'status_changed_at' => $now,
                        'metadata' => array_merge($pass->metadata ?? [], [
                            'suspended_reason' => "Required compliance document expired: {$doc->title}",
                            'document_expired_id' => $doc->id,
                        ]),
                    ]);

                    AccessAuditTimelineEvent::create([
                        'pass_id' => $pass->pass_id,
                        'user_id' => $pass->user_id,
                        'holder_name' => $doc->holder_name,
                        'gate' => 'COMPLIANCE_VAULT',
                        'event_type' => 'REVOCATION_ENFORCED',
                        'severity' => 'CRITICAL',
                        'headline' => 'Credential Suspended: Compliance Document Expired',
                        'description' => "Access revoked because {$doc->title} expired on {$doc->expires_at->toFormattedDateString()}.",
                        'actor_type' => 'SYSTEM',
                        'occurred_at' => $now,
                    ]);

                    $suspendedPasses++;
                }
            }
        }

        // Mark expiring soon (< 14 days)
        $expiringSoonDocs = AuthorizationDocument::where('expires_at', '>=', $now)
            ->where('expires_at', '<=', $now->copy()->addDays(14))
            ->where('status', '!=', 'expiring_soon')
            ->update(['status' => 'expiring_soon']);

        return [
            'expired_count' => $expiredDocs->count(),
            'expired_documents_found' => $expiredDocs->count(),
            'suspended_passes' => $suspendedPasses,
            'suspended_passes_count' => $suspendedPasses,
            'expiring_soon_count' => $expiringSoonDocs,
        ];
    }
}
