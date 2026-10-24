<?php

namespace App\Enums;

enum AuthorizedPersonRole: string
{
    case IndividualOwner = 'individual_owner';
    case CoOwner = 'co_owner';
    case Director = 'director';
    case Officer = 'officer';
    case Trustee = 'trustee';
    case CoTrustee = 'co_trustee';
    case Beneficiary = 'beneficiary';
    case Executor = 'executor';
    case Partner = 'partner';
    case AuthorizedRepresentative = 'authorized_representative';
    case PropertyManager = 'property_manager';
    case Accountant = 'accountant';
    case SecurityRepresentative = 'security_representative';
    case PowerOfAttorney = 'power_of_attorney';
    case Attorney = 'attorney';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::IndividualOwner => 'Individual Owner',
            self::CoOwner => 'Co-Owner',
            self::Director => 'Director',
            self::Officer => 'Corporate Officer',
            self::Trustee => 'Trustee',
            self::CoTrustee => 'Co-Trustee',
            self::Beneficiary => 'Beneficiary',
            self::Executor => 'Executor / Administrator',
            self::Partner => 'Partner',
            self::AuthorizedRepresentative => 'Authorized Representative',
            self::PropertyManager => 'Property Manager',
            self::Accountant => 'Accountant / Financial Officer',
            self::SecurityRepresentative => 'Security Representative',
            self::PowerOfAttorney => 'Power of Attorney Holder',
            self::Attorney => 'Attorney / Legal Counsel',
            self::Other => 'Other Authorized Person',
        };
    }

    /**
     * Complete granular permission matrix for the enterprise ownership system.
     *
     * @return array<string>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::IndividualOwner, self::CoOwner,
            self::Director, self::Officer,
            self::Trustee, self::CoTrustee,
            self::Executor, self::Partner => [
                'manage_visitors',
                'manage_renters_long_term',
                'manage_renters_short_term',
                'manage_staff',
                'manage_contractors',
                'manage_delegated_access',
                'manage_vehicles',
                'manage_parking',
                'receive_notifications',
                'manage_emergency_contacts',
                'manage_legacy_contacts',
                'receive_security_alerts',
                'manage_billing',
                'manage_ownership',
                'transfer_ownership',
                'vote',
                'sign_legal',
            ],

            // Attorney: legal affairs, estate succession, ownership governance, and notifications
            self::Attorney => [
                'manage_ownership',
                'transfer_ownership',
                'sign_legal',
                'manage_legacy_contacts',
                'manage_delegated_access',
                'manage_billing',
                'receive_notifications',
            ],

            // Property Manager: operational property authority without financial or ownership ownership rights
            self::PropertyManager => [
                'manage_visitors',
                'manage_renters_long_term',
                'manage_renters_short_term',
                'manage_staff',
                'manage_contractors',
                'manage_delegated_access',
                'manage_vehicles',
                'manage_parking',
                'receive_notifications',
                'manage_emergency_contacts',
                'receive_security_alerts',
            ],

            // General Authorized Representative: scoped operations + communications
            self::AuthorizedRepresentative, self::PowerOfAttorney => [
                'manage_visitors',
                'manage_renters_long_term',
                'manage_renters_short_term',
                'manage_staff',
                'manage_contractors',
                'manage_delegated_access',
                'manage_vehicles',
                'manage_parking',
                'receive_notifications',
                'manage_emergency_contacts',
                'receive_security_alerts',
                'manage_billing',
            ],

            // Accountant: pure financial visibility and settlement management
            self::Accountant => [
                'manage_billing',
                'receive_notifications',
            ],

            // Security Representative: ingress, egress, parking, and safety
            self::SecurityRepresentative => [
                'manage_visitors',
                'manage_vehicles',
                'manage_parking',
                'receive_security_alerts',
                'receive_notifications',
            ],

            self::Beneficiary => [
                'receive_notifications',
                'view_property_status',
            ],

            self::Other => [
                'manage_visitors',
                'receive_notifications',
            ],
        };
    }

    public function canExecuteLegalDocuments(): bool
    {
        return in_array($this, [
            self::IndividualOwner,
            self::Director,
            self::Trustee,
            self::CoTrustee,
            self::Executor,
            self::Partner,
            self::PowerOfAttorney,
            self::Attorney,
        ], true);
    }
}
