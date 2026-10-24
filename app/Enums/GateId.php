<?php

namespace App\Enums;

enum GateId: string
{
    case Gate01 = 'GATE-01';
    case Gate02 = 'GATE-02';
    case SouthGate = 'GATE-SOUTH';
    case Any = 'GATE-ANY';

    public function label(): string
    {
        return match ($this) {
            self::Gate01 => 'Gate 01 (Main)',
            self::Gate02 => 'Gate 02 (Service)',
            self::SouthGate => 'South Gate',
            self::Any => 'Any Gate',
        };
    }
}
