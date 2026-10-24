<?php

namespace App\Models;

use App\Enums\AuthorizedPersonRole;
use App\Enums\OwnershipEntityType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OwnershipEntity extends Model
{
    use HasFactory;

    protected $fillable = [
        'community_id',
        'entity_type',
        'legal_name',
        'trade_name',
        'registration_number',
        'jurisdiction',
        'primary_email',
        'primary_phone',
        'billing_address',
        'status',
        'verification_status',
        'verified_at',
        'verified_by_user_id',
        'verification_source',
        'verification_notes',
        'verification_checklist',
        'beneficial_ownership',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'entity_type' => OwnershipEntityType::class,
            'metadata' => 'array',
            'verification_checklist' => 'array',
            'beneficial_ownership' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    public function authorizedPeople(): HasMany
    {
        return $this->hasMany(AuthorizedPerson::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AuthorizationDocument::class);
    }

    public function verifiedLegalDocuments(): HasMany
    {
        return $this->documents()->where('verification_status', 'verified');
    }

    public function hasVerifiedLegalDocument(string $docType): bool
    {
        return $this->documents()
            ->where('document_type', $docType)
            ->where('verification_status', 'verified')
            ->exists();
    }

    public function hasVerifiedTrustDeed(): bool
    {
        return $this->hasVerifiedLegalDocument('trust_deed');
    }

    public function activeAuthorizedPeople(): HasMany
    {
        return $this->hasMany(AuthorizedPerson::class)->current();
    }

    /**
     * Primary contact representative for the entity.
     */
    public function primaryContact(): ?AuthorizedPerson
    {
        return $this->activeAuthorizedPeople()->where('is_primary_contact', true)->first()
            ?? $this->activeAuthorizedPeople()->first();
    }

    /**
     * Directors of a corporate/company entity.
     *
     * @return Collection<int, AuthorizedPerson>
     */
    public function directors(): Collection
    {
        return $this->authorizedPeople()->where('role', AuthorizedPersonRole::Director)->get();
    }

    /**
     * Trustees of a trust entity.
     *
     * @return Collection<int, AuthorizedPerson>
     */
    public function trustees(): Collection
    {
        return $this->authorizedPeople()
            ->whereIn('role', [AuthorizedPersonRole::Trustee, AuthorizedPersonRole::CoTrustee])
            ->get();
    }

    /**
     * Co-Trustees of a trust entity.
     *
     * @return Collection<int, AuthorizedPerson>
     */
    public function coTrustees(): Collection
    {
        return $this->authorizedPeople()->where('role', AuthorizedPersonRole::CoTrustee)->get();
    }

    /**
     * Executors / administrators of an estate entity.
     *
     * @return Collection<int, AuthorizedPerson>
     */
    public function executors(): Collection
    {
        return $this->authorizedPeople()->where('role', AuthorizedPersonRole::Executor)->get();
    }

    /**
     * Authorized representatives for the entity.
     *
     * @return Collection<int, AuthorizedPerson>
     */
    public function representatives(): Collection
    {
        return $this->authorizedPeople()->where('role', AuthorizedPersonRole::AuthorizedRepresentative)->get();
    }

    /**
     * Property managers appointed by the entity.
     *
     * @return Collection<int, AuthorizedPerson>
     */
    public function propertyManagers(): Collection
    {
        return $this->authorizedPeople()->where('role', AuthorizedPersonRole::PropertyManager)->get();
    }

    /**
     * Financial accountants / officers appointed by the entity.
     *
     * @return Collection<int, AuthorizedPerson>
     */
    public function accountants(): Collection
    {
        return $this->authorizedPeople()->where('role', AuthorizedPersonRole::Accountant)->get();
    }

    /**
     * Security representatives appointed by the entity.
     *
     * @return Collection<int, AuthorizedPerson>
     */
    public function securityRepresentatives(): Collection
    {
        return $this->authorizedPeople()->where('role', AuthorizedPersonRole::SecurityRepresentative)->get();
    }

    /**
     * Beneficiaries associated with a trust or estate.
     *
     * @return Collection<int, AuthorizedPerson>
     */
    public function beneficiaries(): Collection
    {
        return $this->authorizedPeople()->where('role', AuthorizedPersonRole::Beneficiary)->get();
    }

    /**
     * Check if a specific user holds authority with a given permission.
     */
    public function hasAuthority(User $user, ?string $permission = null): bool
    {
        $person = $this->activeAuthorizedPeople()->where('user_id', $user->id)->first();
        if (! $person) {
            return false;
        }

        if ($permission === null) {
            return true;
        }

        return $person->hasPermission($permission);
    }

    /**
     * Revoke an authorized person (e.g. employee offboarding).
     * Invariant: The entity's property ownership remains completely unchanged.
     */
    public function revokeAuthorization(AuthorizedPerson $person, ?string $reason = null): void
    {
        $person->revoke($reason);
    }

    public function verifiedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    /**
     * Check if user is a director or legal signatory of this entity.
     */
    public function isUserSignatoryOrDirector(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->activeAuthorizedPeople()
            ->where('user_id', $user->id)
            ->where(function ($q) {
                $q->where('role', AuthorizedPersonRole::Director)
                    ->orWhere('is_signatory', true);
            })
            ->exists();
    }

    /**
     * Beneficial Ownership Access Policy:
     * Under the Companies (Amendment) Act and Companies Office of Jamaica (COJ) regulations,
     * beneficial ownership records are private and confidential.
     *
     * ONLY:
     * 1. System administrators / compliance officers, OR
     * 2. Active Directors / Signatories of this corporate entity
     * are authorized to access beneficial ownership data.
     *
     * Ordinary residents, renters, household members, and security personnel / gate guards
     * MUST NEVER have access to or be exposed to beneficial ownership information.
     */
    public function canUserViewBeneficialOwnership(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        // Administrative & compliance roles have statutory oversight
        if ($user->role->isAdministrative() || $user->can('manageUsers')) {
            return true;
        }

        // Directors or legal signatories for this specific entity
        return $this->isUserSignatoryOrDirector($user);
    }

    /**
     * Return beneficial ownership payload with strict confidentiality protection.
     */
    public function getBeneficialOwnershipProfile(?User $user): array
    {
        if (! $this->canUserViewBeneficialOwnership($user)) {
            return [
                'is_restricted' => true,
                'statutory_notice' => 'Beneficial ownership records are private and confidential pursuant to the Companies Office of Jamaica (COJ) regulatory framework. Access is strictly restricted from ordinary residents and security personnel.',
                'confidentiality_standard' => 'Companies (Amendment) Act (Jamaica) — Beneficial Ownership Confidentiality Rules',
                'declaration_reference' => null,
                'filing_date' => null,
                'beneficial_owners' => [],
            ];
        }

        $data = $this->beneficial_ownership ?? [];

        return [
            'is_restricted' => false,
            'statutory_notice' => 'CONFIDENTIAL: Beneficial ownership records verified pursuant to Companies Office of Jamaica (COJ) statutory compliance.',
            'confidentiality_standard' => 'Companies (Amendment) Act (Jamaica) — Statutory Confidentiality Framework',
            'declaration_reference' => $data['declaration_reference'] ?? ($this->registration_number ? "COJ-BO-{$this->registration_number}" : null),
            'filing_date' => $data['filing_date'] ?? null,
            'coj_form_reference' => $data['coj_form_reference'] ?? 'COJ Form 38 (Beneficial Ownership Declaration)',
            'beneficial_owners' => $data['beneficial_owners'] ?? [],
        ];
    }

    /**
     * Verify Company ownership entity using Companies Office of Jamaica (COJ) statutory profile.
     */
    public function verifyCompany(array $cojData, User $verifier, ?array $beneficialOwnershipData = null): void
    {
        $checklist = [
            'coj_registration_number_verified' => true,
            'company_status_verified' => $cojData['company_status'] ?? 'Active / Good Standing',
            'directors_identified' => ! empty($cojData['directors']),
            'shareholders_identified' => ! empty($cojData['shareholders']),
            'articles_of_incorporation_verified' => (bool) ($cojData['articles_verified'] ?? true),
            'certificate_of_incorporation_verified' => (bool) ($cojData['cert_of_incorporation_verified'] ?? true),
            'registered_office_verified' => (bool) ($cojData['registered_office_verified'] ?? true),
        ];

        $notes = $cojData['notes'] ?? "Verified via Companies Office of Jamaica (COJ) registry records. Registration #{$this->registration_number}.";

        $meta = $this->metadata ?? [];
        $meta['coj_verification'] = [
            'registration_number' => $cojData['registration_number'] ?? $this->registration_number,
            'company_type' => $cojData['company_type'] ?? 'Private Company Limited by Shares',
            'incorporation_date' => $cojData['incorporation_date'] ?? ($meta['incorporation_date'] ?? null),
            'directors' => $cojData['directors'] ?? [],
            'shareholders' => $cojData['shareholders'] ?? [],
            'registered_office' => $cojData['registered_office'] ?? $this->billing_address,
        ];

        $bo = $beneficialOwnershipData ?? $this->beneficial_ownership;

        $this->update([
            'verification_status' => 'verified',
            'verified_at' => now(),
            'verified_by_user_id' => $verifier->id,
            'verification_source' => 'Companies Office of Jamaica (COJ)',
            'verification_notes' => $notes,
            'verification_checklist' => $checklist,
            'beneficial_ownership' => $bo,
            'metadata' => $meta,
        ]);
    }

    /**
     * Verify Trust ownership entity with Trust Deed & Trustee Authorization.
     */
    public function verifyTrust(array $trustData, User $verifier): void
    {
        $checklist = [
            'trust_deed_inspected' => true,
            'deed_reference_verified' => ! empty($trustData['deed_reference_number']),
            'trustees_identified_and_authorized' => ! empty($trustData['trustees']),
            'trustee_resolution_verified' => (bool) ($trustData['trustee_resolution_verified'] ?? true),
            'beneficiaries_recorded' => ! empty($trustData['beneficiaries']),
        ];

        $notes = $trustData['notes'] ?? "Verified with official Trust Deed (#{$trustData['deed_reference_number']}) and authorized trustee powers.";

        $meta = $this->metadata ?? [];
        $meta['trust_verification'] = [
            'deed_reference_number' => $trustData['deed_reference_number'] ?? null,
            'settlement_date' => $trustData['settlement_date'] ?? null,
            'governing_law' => $trustData['governing_law'] ?? 'Jamaica',
            'trustees' => $trustData['trustees'] ?? [],
            'beneficiaries' => $trustData['beneficiaries'] ?? [],
        ];

        $this->update([
            'verification_status' => 'verified',
            'verified_at' => now(),
            'verified_by_user_id' => $verifier->id,
            'verification_source' => 'Trust Deed & Trustee Authorization Registry',
            'verification_notes' => $notes,
            'verification_checklist' => $checklist,
            'metadata' => $meta,
        ]);
    }

    /**
     * Verify Estate ownership entity with Supreme Court Grant of Probate / Letters of Administration.
     */
    public function verifyEstate(array $estateData, User $verifier): void
    {
        $checklist = [
            'grant_of_probate_or_administration_verified' => true,
            'death_certificate_verified' => (bool) ($estateData['death_cert_verified'] ?? true),
            'executors_or_administrators_authorized' => ! empty($estateData['executors']),
            'court_jurisdiction_verified' => (bool) ($estateData['court_jurisdiction_verified'] ?? true),
        ];

        $notes = $estateData['notes'] ?? "Verified with Supreme Court Grant of Probate / Administration (#{$estateData['probate_suit_number']}) for Estate of {$estateData['deceased_name']}.";

        $meta = $this->metadata ?? [];
        $meta['estate_verification'] = [
            'deceased_name' => $estateData['deceased_name'] ?? null,
            'date_of_death' => $estateData['date_of_death'] ?? null,
            'probate_suit_number' => $estateData['probate_suit_number'] ?? null,
            'court_jurisdiction' => $estateData['court_jurisdiction'] ?? 'Supreme Court of Jamaica (Probate Division)',
            'executors' => $estateData['executors'] ?? [],
        ];

        $this->update([
            'verification_status' => 'verified',
            'verified_at' => now(),
            'verified_by_user_id' => $verifier->id,
            'verification_source' => 'Supreme Court Probate & Administration Division',
            'verification_notes' => $notes,
            'verification_checklist' => $checklist,
            'metadata' => $meta,
        ]);
    }

    /**
     * Verify Individual ownership entity with Identity Verification & Registered Property Title.
     */
    public function verifyIndividual(array $identityData, User $verifier): void
    {
        $checklist = [
            'government_photo_id_verified' => true,
            'trn_verified' => ! empty($identityData['trn']),
            'duplicate_certificate_of_title_verified' => (bool) ($identityData['title_verified'] ?? true),
            'property_authorization_confirmed' => (bool) ($identityData['ownership_confirmed'] ?? true),
        ];

        $notes = $identityData['notes'] ?? "Verified identity (#{$identityData['id_number']}) and Certificate of Title (Vol: {$identityData['volume']}, Fol: {$identityData['folio']}).";

        $meta = $this->metadata ?? [];
        $meta['individual_verification'] = [
            'id_type' => $identityData['id_type'] ?? 'National ID',
            'id_number' => $identityData['id_number'] ?? null,
            'trn' => $identityData['trn'] ?? null,
            'title_volume' => $identityData['volume'] ?? null,
            'title_folio' => $identityData['folio'] ?? null,
            'registered_owner' => $identityData['registered_owner'] ?? $this->legal_name,
        ];

        $this->update([
            'verification_status' => 'verified',
            'verified_at' => now(),
            'verified_by_user_id' => $verifier->id,
            'verification_source' => 'National Land Agency (NLA) & Registrar General',
            'verification_notes' => $notes,
            'verification_checklist' => $checklist,
            'metadata' => $meta,
        ]);
    }

    /**
     * Comprehensive verification summary for the ownership entity.
     */
    public function getVerificationSummary(): array
    {
        $meta = $this->metadata ?? [];

        return [
            'status' => $this->verification_status ?: 'unverified',
            'is_verified' => $this->isVerified(),
            'verified_at' => $this->verified_at?->format('M d, Y H:i'),
            'verifier_name' => $this->verifiedByUser?->name ?? 'Compliance Auditor',
            'source' => $this->verification_source ?: match ($this->entity_type) {
                OwnershipEntityType::Company => 'Companies Office of Jamaica (COJ)',
                OwnershipEntityType::Trust => 'Trust Deed & Settlement Registry',
                OwnershipEntityType::Estate => 'Supreme Court Probate Division',
                default => 'National Land Agency (NLA) & Identity Verification',
            },
            'notes' => $this->verification_notes,
            'checklist' => $this->verification_checklist ?? [],
            'entity_type' => $this->entity_type->value,
            'entity_type_label' => $this->entity_type->label(),
            'coj_details' => $meta['coj_verification'] ?? null,
            'trust_details' => $meta['trust_verification'] ?? null,
            'estate_details' => $meta['estate_verification'] ?? null,
            'individual_details' => $meta['individual_verification'] ?? null,
        ];
    }
}
