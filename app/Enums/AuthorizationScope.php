<?php

namespace App\Enums;

enum AuthorizationScope: string
{
    case Property = 'property';
    case Community = 'community';
    case Zones = 'zones';

    public function label(): string
    {
        return match ($this) {
            self::Property => 'Single Property',
            self::Community => 'Community-Wide',
            self::Zones => 'Designated Zones',
        };
    }
}
