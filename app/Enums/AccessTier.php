<?php

namespace App\Enums;

enum AccessTier: string
{
    case Resident = 'RESIDENT_ACCESS';
    case Delegated = 'DELEGATED_ACCESS';
    case Temporary = 'TEMPORARY_ACCESS';

    public function label(): string
    {
        return match ($this) {
            self::Resident => 'Resident Access',
            self::Delegated => 'Delegated Access',
            self::Temporary => 'Temporary Access',
        };
    }
}
