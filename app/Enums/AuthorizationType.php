<?php

namespace App\Enums;

enum AuthorizationType: string
{
    case Homeowner = 'homeowner';
    case Renter = 'renter';
    case LongTermOccupant = 'long_term_occupant';
    case LegacyContact = 'legacy_contact';
    case AuthorizedRepresentative = 'authorized_representative';
    case Caregiver = 'caregiver';
    case PropertyManager = 'property_manager';
    case DomesticStaff = 'domestic_staff';
    case FamilyMember = 'family_member';
    case Staff = 'staff';
    case Visitor = 'visitor';
    case Contractor = 'contractor';
    case EventGuest = 'event_guest';

    public function label(): string
    {
        return match ($this) {
            self::Homeowner => 'Homeowner',
            self::Renter => 'Renter',
            self::LongTermOccupant => 'Long-Term Occupant',
            self::LegacyContact => 'Legacy Contact',
            self::AuthorizedRepresentative => 'Authorized Representative',
            self::Caregiver => 'Caregiver',
            self::PropertyManager => 'Property Manager',
            self::DomesticStaff => 'Domestic Staff',
            self::FamilyMember => 'Family Member',
            self::Staff => 'Staff',
            self::Visitor => 'Visitor',
            self::Contractor => 'Contractor',
            self::EventGuest => 'Event Guest',
        };
    }

    public function tier(): AccessTier
    {
        return match ($this) {
            self::Homeowner, self::Renter, self::LongTermOccupant => AccessTier::Resident,
            self::LegacyContact, self::AuthorizedRepresentative, self::Caregiver, self::PropertyManager, self::DomesticStaff, self::FamilyMember => AccessTier::Delegated,
            self::Staff, self::Visitor, self::Contractor, self::EventGuest => AccessTier::Temporary,
        };
    }

    public function isResident(): bool
    {
        return match ($this) {
            self::Homeowner, self::Renter => true,
            default => false,
        };
    }

    public function passCategory(): PassCategory
    {
        return match ($this) {
            self::Homeowner => PassCategory::Homeowner,
            self::Renter => PassCategory::Renter,
            self::LongTermOccupant => PassCategory::LongTermOccupant,
            self::LegacyContact, self::AuthorizedRepresentative, self::Caregiver, self::PropertyManager, self::FamilyMember => PassCategory::Delegate,
            self::DomesticStaff => PassCategory::HomeownerStaff,
            self::Staff => PassCategory::Staff,
            self::Visitor, self::EventGuest => PassCategory::Visitor,
            self::Contractor => PassCategory::Contractor,
        };
    }

    /**
     * Default baseline access rules for this authorization type.
     *
     * @return array<string, mixed>
     */
    public function defaultAccessRules(): array
    {
        return match ($this) {
            self::Homeowner => [
                'days_permitted' => ['all'],
                'time_window' => null, // 24/7
                'gate_access' => true,
                'property_access' => true,
                'amenity_access' => ['pool' => true, 'gym' => true, 'clubhouse' => true, 'tennis' => true],
                'parking_access' => true,
                'can_create_visitors' => true,
                'emergency_access' => true,
                'payment_privileges' => true,
                'verify_id' => false,
                'auto_expires' => false,
            ],
            self::Renter => [
                'days_permitted' => ['all'],
                'time_window' => null,
                'gate_access' => true,
                'property_access' => true,
                'amenity_access' => ['pool' => true, 'gym' => true, 'clubhouse' => true, 'tennis' => true],
                'parking_access' => true,
                'can_create_visitors' => true,
                'emergency_access' => false,
                'payment_privileges' => false,
                'verify_id' => false,
                'auto_expires' => true,
            ],
            self::LongTermOccupant => [
                'days_permitted' => ['all'],
                'time_window' => null,
                'gate_access' => true,
                'property_access' => true,
                'amenity_access' => ['pool' => true, 'gym' => false, 'clubhouse' => true, 'tennis' => false],
                'parking_access' => true,
                'can_create_visitors' => false,
                'emergency_access' => false,
                'payment_privileges' => false,
                'verify_id' => true,
                'auto_expires' => true,
            ],
            self::LegacyContact => [
                'days_permitted' => ['all'],
                'time_window' => null,
                'gate_access' => true,
                'property_access' => true,
                'amenity_access' => ['pool' => false, 'gym' => false, 'clubhouse' => false, 'tennis' => false],
                'parking_access' => true,
                'can_create_visitors' => true,
                'emergency_access' => true,
                'payment_privileges' => false,
                'verify_id' => true,
                'auto_expires' => false,
            ],
            self::AuthorizedRepresentative => [
                'days_permitted' => ['all'],
                'time_window' => ['start' => '06:00', 'end' => '22:00'],
                'gate_access' => true,
                'property_access' => true,
                'amenity_access' => ['pool' => false, 'gym' => false, 'clubhouse' => true, 'tennis' => false],
                'parking_access' => true,
                'can_create_visitors' => true,
                'emergency_access' => true,
                'payment_privileges' => false,
                'verify_id' => true,
                'auto_expires' => true,
            ],
            self::Caregiver => [
                'days_permitted' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
                'time_window' => ['start' => '06:00', 'end' => '21:00'],
                'gate_access' => true,
                'property_access' => true,
                'amenity_access' => ['pool' => false, 'gym' => false, 'clubhouse' => false, 'tennis' => false],
                'parking_access' => true,
                'can_create_visitors' => false,
                'emergency_access' => true,
                'payment_privileges' => false,
                'verify_id' => true,
                'auto_expires' => true,
            ],
            self::PropertyManager => [
                'days_permitted' => ['all'],
                'time_window' => ['start' => '07:00', 'end' => '20:00'],
                'gate_access' => true,
                'property_access' => true,
                'amenity_access' => ['pool' => false, 'gym' => false, 'clubhouse' => true, 'tennis' => false],
                'parking_access' => true,
                'can_create_visitors' => true,
                'emergency_access' => false,
                'payment_privileges' => false,
                'verify_id' => true,
                'auto_expires' => true,
            ],
            self::DomesticStaff => [
                'days_permitted' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
                'time_window' => ['start' => '06:30', 'end' => '19:00'],
                'gate_access' => true,
                'property_access' => true,
                'amenity_access' => ['pool' => false, 'gym' => false, 'clubhouse' => false, 'tennis' => false],
                'parking_access' => true,
                'can_create_visitors' => false,
                'emergency_access' => false,
                'payment_privileges' => false,
                'verify_id' => true,
                'auto_expires' => true,
            ],
            self::FamilyMember => [
                'days_permitted' => ['all'],
                'time_window' => null,
                'gate_access' => true,
                'property_access' => true,
                'amenity_access' => ['pool' => true, 'gym' => true, 'clubhouse' => true, 'tennis' => true],
                'parking_access' => true,
                'can_create_visitors' => true,
                'emergency_access' => true,
                'payment_privileges' => false,
                'verify_id' => true,
                'auto_expires' => false,
            ],
            self::Visitor => [
                'days_permitted' => ['all'],
                'time_window' => null,
                'gate_access' => true,
                'property_access' => true,
                'amenity_access' => ['pool' => false, 'gym' => false, 'clubhouse' => false, 'tennis' => false],
                'parking_access' => false,
                'can_create_visitors' => false,
                'emergency_access' => false,
                'payment_privileges' => false,
                'verify_id' => true,
                'auto_expires' => true,
            ],
            self::Contractor => [
                'days_permitted' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
                'time_window' => ['start' => '07:00', 'end' => '18:00'],
                'gate_access' => true,
                'property_access' => true,
                'amenity_access' => ['pool' => false, 'gym' => false, 'clubhouse' => false, 'tennis' => false],
                'parking_access' => true,
                'can_create_visitors' => false,
                'emergency_access' => false,
                'payment_privileges' => false,
                'verify_id' => true,
                'auto_expires' => true,
            ],
            default => [
                'days_permitted' => ['all'],
                'time_window' => null,
                'gate_access' => true,
                'property_access' => false,
                'amenity_access' => ['pool' => false, 'gym' => false, 'clubhouse' => false, 'tennis' => false],
                'parking_access' => false,
                'can_create_visitors' => false,
                'emergency_access' => false,
                'payment_privileges' => false,
                'verify_id' => true,
                'auto_expires' => true,
            ],
        };
    }
}
