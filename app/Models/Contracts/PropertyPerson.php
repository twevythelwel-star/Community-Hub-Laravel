<?php

namespace App\Models\Contracts;

use Carbon\CarbonInterface;

/**
 * Anyone allowed on a property: a renter, a household member, household
 * staff, a delegate or a company's authorized representative. Each keeps its
 * own workflow; this is the shape every screen can list them by.
 */
interface PropertyPerson
{
    public const TYPES = ['renter', 'household_member', 'staff', 'delegate', 'authorized_person'];

    /** One of self::TYPES. */
    public function personType(): string;

    public function personName(): string;

    /** What the person is to the property, e.g. "Long-term (Renter)", "Spouse" or "Gardener". */
    public function personRole(): string;

    /** Whether the person may be on the property right now. */
    public function hasCurrentAccess(): bool;

    /** When access lapses; null when it does not lapse on a date. */
    public function accessEndsAt(): ?CarbonInterface;

    /**
     * @return array{type: string, id: int, name: string, role: string, active: bool, endsAt: string|null}
     */
    public function toPersonSummary(): array;
}
