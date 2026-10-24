<?php

namespace App\Enums;

enum OwnershipEntityType: string
{
    case Individual = 'individual';
    case Company = 'company';
    case Trust = 'trust';
    case Partnership = 'partnership';
    case Estate = 'estate';
    case Nonprofit = 'nonprofit';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Individual',
            self::Company => 'Company',
            self::Trust => 'Trust',
            self::Partnership => 'Partnership',
            self::Estate => 'Estate',
            self::Nonprofit => 'Nonprofit / Organization',
            self::Other => 'Other Legal Entity',
        };
    }

    /**
     * Permissible authorized roles for this ownership structure.
     *
     * @return array<AuthorizedPersonRole>
     */
    public function allowedRoles(): array
    {
        return match ($this) {
            self::Individual => [
                AuthorizedPersonRole::IndividualOwner,
                AuthorizedPersonRole::CoOwner,
                AuthorizedPersonRole::AuthorizedRepresentative,
                AuthorizedPersonRole::PropertyManager,
                AuthorizedPersonRole::PowerOfAttorney,
                AuthorizedPersonRole::Accountant,
            ],
            self::Company => [
                AuthorizedPersonRole::Director,
                AuthorizedPersonRole::Officer,
                AuthorizedPersonRole::AuthorizedRepresentative,
                AuthorizedPersonRole::PropertyManager,
                AuthorizedPersonRole::Accountant,
                AuthorizedPersonRole::SecurityRepresentative,
                AuthorizedPersonRole::Attorney,
            ],
            self::Trust => [
                AuthorizedPersonRole::Trustee,
                AuthorizedPersonRole::CoTrustee,
                AuthorizedPersonRole::AuthorizedRepresentative,
                AuthorizedPersonRole::Beneficiary,
                AuthorizedPersonRole::PropertyManager,
                AuthorizedPersonRole::Accountant,
                AuthorizedPersonRole::Attorney,
            ],
            self::Estate => [
                AuthorizedPersonRole::Executor,
                AuthorizedPersonRole::AuthorizedRepresentative,
                AuthorizedPersonRole::Beneficiary,
                AuthorizedPersonRole::PropertyManager,
                AuthorizedPersonRole::Accountant,
                AuthorizedPersonRole::Attorney,
            ],
            self::Partnership => [
                AuthorizedPersonRole::Partner,
                AuthorizedPersonRole::AuthorizedRepresentative,
                AuthorizedPersonRole::PropertyManager,
                AuthorizedPersonRole::Accountant,
                AuthorizedPersonRole::Attorney,
            ],
            self::Nonprofit => [
                AuthorizedPersonRole::Director,
                AuthorizedPersonRole::Officer,
                AuthorizedPersonRole::AuthorizedRepresentative,
                AuthorizedPersonRole::PropertyManager,
                AuthorizedPersonRole::Accountant,
            ],
            self::Other => [
                AuthorizedPersonRole::AuthorizedRepresentative,
                AuthorizedPersonRole::PropertyManager,
                AuthorizedPersonRole::Officer,
                AuthorizedPersonRole::Accountant,
                AuthorizedPersonRole::SecurityRepresentative,
            ],
        };
    }

    public function isCorporateOrInstitutional(): bool
    {
        return $this !== self::Individual;
    }
}
