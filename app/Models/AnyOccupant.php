<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * The unscoped view of the occupants table, behind Occupant::everyone().
 * Each row comes back as a Renter, HouseholdMember or Staff, never as this
 * class, and it cannot be saved: create occupants through their own model.
 */
final class AnyOccupant extends Occupant
{
    /**
     * @param  array<string, mixed>|object  $attributes
     */
    public function newFromBuilder($attributes = [], $connection = null): Occupant
    {
        $attributes = (array) $attributes;
        $class = self::SUBCLASSES[$attributes['type'] ?? ''] ?? null;

        if ($class === null) {
            throw new LogicException('Occupant row has an unknown type: '.($attributes['type'] ?? 'none'));
        }

        return (new $class)->newFromBuilder($attributes, $connection);
    }

    public function personRole(): string
    {
        throw new LogicException('AnyOccupant is never hydrated.');
    }

    public function hasCurrentAccess(): bool
    {
        throw new LogicException('AnyOccupant is never hydrated.');
    }

    public function accessEndsAt(): ?CarbonInterface
    {
        throw new LogicException('AnyOccupant is never hydrated.');
    }

    protected function performInsert(Builder $query): bool
    {
        throw new LogicException('Create occupants through Renter, HouseholdMember or Staff.');
    }
}
