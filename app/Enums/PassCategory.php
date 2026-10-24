<?php

namespace App\Enums;

/**
 * The profile a pass is issued under. Each profile has one fixed frame shape,
 * so a guard can tell a visitor from a resident at a glance. Shape and colour
 * are visual identity only: authorization comes from the signed token and the
 * pass registry, never from what the badge looks like.
 *
 * Mirrored in resources/js/lib/gate-pass-engine/types.ts.
 */
enum PassCategory: string
{
    case SysAdmin = 'SYSADMIN';
    case Admin = 'ADMIN';
    case Homeowner = 'HOMEOWNER';
    case Renter = 'RENTER';
    case Staff = 'STAFF';
    case Security = 'SECURITY';
    case HomeownerStaff = 'HOMEOWNER_STAFF';
    case Visitor = 'VISITOR';
    case Contractor = 'CONTRACTOR';
    case Delegate = 'DELEGATE';
    case LongTermOccupant = 'LONG_TERM_OCCUPANT';

    public function shape(): QRShape
    {
        return match ($this) {
            self::SysAdmin => QRShape::Star8,
            self::Admin => QRShape::Octagon,
            self::Homeowner => QRShape::Hexagon,
            self::Renter => QRShape::RoundedSquare,
            self::Staff => QRShape::Diamond,
            self::Security => QRShape::Shield,
            self::HomeownerStaff => QRShape::HouseHex,
            self::Visitor => QRShape::Circle,
            self::Contractor => QRShape::Pentagon,
            // Their own frames: Octagon and RoundedSquare are Admin's and Renter's,
            // and a guard tells a pass apart by its shape.
            self::Delegate => QRShape::Ticket,
            self::LongTermOccupant => QRShape::Arch,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SysAdmin => 'System Admin',
            self::Admin => 'Admin',
            self::Homeowner => 'Homeowner',
            self::Renter => 'Renter',
            self::Staff => 'Staff',
            self::Security => 'Security',
            self::HomeownerStaff => 'Homeowner Staff',
            self::Visitor => 'Visitor',
            self::Contractor => 'Contractor',
            self::Delegate => 'Authorized Delegate',
            self::LongTermOccupant => 'Long-Term Occupant',
        };
    }

    /**
     * Visitors and contractors are passes for a person without an account,
     * issued for a stay with a start and an end.
     */
    public function isGuest(): bool
    {
        return $this === self::Visitor || $this === self::Contractor;
    }

    /** Contractors are let in to work, so security or an admin approves them first. */
    public function requiresApproval(): bool
    {
        return $this === self::Contractor;
    }

    /** Pass ID prefix, per formatPassId() in the original engine. */
    public function passIdPrefix(): string
    {
        return match ($this) {
            self::SysAdmin => 'GP-SYS',
            self::Admin => 'GP-ADM',
            self::Homeowner => 'GP-HO',
            self::Renter => 'GP-RNT',
            self::Staff => 'GP-STF',
            self::Security => 'GP-SEC',
            self::HomeownerStaff => 'GP-HST',
            self::Visitor => 'GP-VIS',
            self::Contractor => 'GP-CON',
            self::Delegate => 'GP-DEL',
            self::LongTermOccupant => 'GP-LTO',
        };
    }

    /** Default access zone assigned when none is supplied. */
    public function defaultZone(): string
    {
        return match ($this) {
            self::SysAdmin => 'ZONE-ROOT-CORE',
            self::Security => 'ZONE-ALL-PERIMETER',
            self::Admin => 'ZONE-ADMIN-COMMON',
            self::Staff, self::Contractor => 'ZONE-FACILITIES-WORKSHOP',
            self::Visitor, self::Delegate => 'ZONE-HOST-RESIDENCE',
            default => 'ZONE-RESIDENTIAL-AMENITIES',
        };
    }
}
