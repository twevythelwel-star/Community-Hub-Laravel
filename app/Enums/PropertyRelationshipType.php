<?php

namespace App\Enums;

/**
 * The 5 core property relationships:
 * Resident · Renter · Household · Staff · Guest
 *
 * Keeps ownership separate from occupancy.
 */
enum PropertyRelationshipType: string
{
    case Resident = 'resident';
    case Renter = 'renter';
    case Household = 'household';
    case Staff = 'staff';
    case Guest = 'guest';

    public function label(): string
    {
        return match ($this) {
            self::Resident => 'Resident',
            self::Renter => 'Renter',
            self::Household => 'Household Member',
            self::Staff => 'Property Staff',
            self::Guest => 'Guest / Visitor',
        };
    }

    public function isOccupant(): bool
    {
        return in_array($this, [self::Resident, self::Renter, self::Household], true);
    }
}
