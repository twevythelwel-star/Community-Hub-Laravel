<?php

namespace App\Traits;

/**
 * The summary half of App\Models\Contracts\PropertyPerson, built from the
 * other methods so each model only says what is particular to it.
 */
trait SummarizesPropertyPerson
{
    /**
     * @return array{type: string, id: int, name: string, role: string, active: bool, endsAt: string|null}
     */
    public function toPersonSummary(): array
    {
        return [
            'type' => $this->personType(),
            'id' => (int) $this->getKey(),
            'name' => $this->personName(),
            'role' => $this->personRole(),
            'active' => $this->hasCurrentAccess(),
            'endsAt' => $this->accessEndsAt()?->toIso8601String(),
        ];
    }
}
