<?php

namespace App\Enums;

enum RepresentativePermission: string
{
    case ManageVisitors = 'manage_visitors';
    case ManageRentersLongTerm = 'manage_renters_long_term';
    case ManageRentersShortTerm = 'manage_renters_short_term';
    case ManageStaff = 'manage_staff';
    case ManageContractors = 'manage_contractors';
    case ManageDelegatedAccess = 'manage_delegated_access';
    case ManageVehicles = 'manage_vehicles';
    case ManageParking = 'manage_parking';
    case ReceiveNotifications = 'receive_notifications';
    case ManageEmergencyContacts = 'manage_emergency_contacts';
    case ManageLegacyContacts = 'manage_legacy_contacts';
    case ReceiveSecurityAlerts = 'receive_security_alerts';
    case ManageBilling = 'manage_billing';
    case ManageOwnership = 'manage_ownership';
    case TransferOwnership = 'transfer_ownership';
    case Vote = 'vote';
    case SignLegal = 'sign_legal';

    public function label(): string
    {
        return match ($this) {
            self::ManageVisitors => 'Manage Visitors',
            self::ManageRentersLongTerm => 'Manage Long-Term Renters',
            self::ManageRentersShortTerm => 'Manage Short-Term Rentals',
            self::ManageStaff => 'Manage Staff',
            self::ManageContractors => 'Manage Contractors',
            self::ManageDelegatedAccess => 'Create / Revoke Delegated Access',
            self::ManageVehicles => 'Manage Vehicles',
            self::ManageParking => 'Manage Parking Passes',
            self::ReceiveNotifications => 'Receive Property Notifications',
            self::ManageEmergencyContacts => 'Manage Emergency Contacts',
            self::ManageLegacyContacts => 'Manage Legacy Contacts',
            self::ReceiveSecurityAlerts => 'Receive Security Alerts',
            self::ManageBilling => 'Manage Billing & Pay Invoices',
            self::ManageOwnership => 'Manage Ownership Structure',
            self::TransferOwnership => 'Transfer Property Ownership',
            self::Vote => 'Vote at AGM / Community Resolutions',
            self::SignLegal => 'Sign Statutory Legal Documents',
        };
    }

    /**
     * Standard operational permissions commonly delegable to property managers and agents.
     *
     * @return array<string>
     */
    public static function operational(): array
    {
        return [
            self::ManageVisitors->value,
            self::ManageRentersLongTerm->value,
            self::ManageRentersShortTerm->value,
            self::ManageStaff->value,
            self::ManageContractors->value,
            self::ManageDelegatedAccess->value,
            self::ManageVehicles->value,
            self::ManageParking->value,
            self::ReceiveNotifications->value,
            self::ManageEmergencyContacts->value,
            self::ReceiveSecurityAlerts->value,
        ];
    }

    /**
     * Strict fiduciary/governance rights reserved for deeded owners, trustees, and directors.
     *
     * @return array<string>
     */
    public static function governance(): array
    {
        return [
            self::ManageBilling->value,
            self::ManageOwnership->value,
            self::TransferOwnership->value,
            self::Vote->value,
            self::SignLegal->value,
            self::ManageLegacyContacts->value,
        ];
    }
}
