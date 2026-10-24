<?php

namespace App\Enums;

enum QRShape: string
{
    case Star8 = 'STAR_8';
    case Octagon = 'OCTAGON';
    case Hexagon = 'HEXAGON';
    case RoundedSquare = 'ROUNDED_SQUARE';
    case Circle = 'CIRCLE';
    case Diamond = 'DIAMOND';
    case Shield = 'SHIELD';
    case HouseHex = 'HOUSE_HEX';
    case Pentagon = 'PENTAGON';
    case Ticket = 'TICKET';
    case Arch = 'ARCH';

    public function label(): string
    {
        return match ($this) {
            self::Star8 => '8-Point Star Frame',
            self::Octagon => 'Octagonal Frame',
            self::Hexagon => 'Hexagonal House Frame',
            self::RoundedSquare => 'Rounded Square Frame',
            self::Circle => 'Visitor Circle Frame',
            self::Diamond => 'Diamond Frame',
            self::Shield => 'Heraldic Shield Frame',
            self::HouseHex => 'Custom Hex/House Frame',
            self::Pentagon => 'Contractor Pentagon Frame',
            self::Ticket => 'Delegate Ticket Frame',
            self::Arch => 'Long-Term Occupant Arch Frame',
        };
    }
}
