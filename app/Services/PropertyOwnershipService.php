<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Who owns which property. HOA dues are charged per property, so this, not
 * the single lot on a user's account, is the record of what an owner holds.
 */
class PropertyOwnershipService
{
    /**
     * A homeowner whose only record of their property is the lot on their
     * account gets it recorded. Nothing changes for anyone who already has a
     * property on record: after that, properties are managed explicitly.
     */
    public function recordAccountProperty(User $owner): ?Property
    {
        if ($owner->role !== UserRole::Homeowner || blank($owner->lot) || $owner->properties()->exists()) {
            return null;
        }

        return $this->assign($owner, $owner->lot, $owner->street);
    }

    /** The lot on their account is on record as someone else's. */
    public function lotOwnedByAnother(User $owner): bool
    {
        if (blank($owner->lot)) {
            return false;
        }

        return $this->findLot($owner->lot, $owner->street)
            ->whereNotNull('owner_user_id')
            ->where('owner_user_id', '!=', $owner->id)
            ->exists();
    }

    /**
     * Gives a lot to an owner. An unowned record of the same lot becomes
     * theirs rather than a duplicate; a lot someone else owns is refused.
     *
     * @throws \DomainException when another owner already holds the lot
     */
    public function assign(User $owner, string $lotNumber, ?string $streetAddress = null): Property
    {
        $lotNumber = trim($lotNumber);

        $existing = $this->findLot($lotNumber, $streetAddress)->with('owner')->first();

        if ($existing?->owner && ! $existing->owner->is($owner)) {
            throw new \DomainException("{$lotNumber} is already owned by {$existing->owner->display_name}.");
        }

        if ($existing) {
            $existing->update([
                'owner_user_id' => $owner->id,
                'street_address' => $existing->street_address ?? $streetAddress,
            ]);

            return $existing;
        }

        return Property::create([
            'owner_user_id' => $owner->id,
            'property_code' => $this->newPropertyCode(),
            'lot_number' => $lotNumber,
            'street_address' => $streetAddress,
        ]);
    }

    /**
     * The owner gives the property up. The lot stays on record, unowned, and
     * is no longer billed to anyone.
     */
    public function release(Property $property): void
    {
        $property->update(['owner_user_id' => null]);
    }

    /**
     * The same lot: same number, and the same street unless one of the two
     * leaves the street out.
     *
     * @return Builder<Property>
     */
    private function findLot(string $lotNumber, ?string $streetAddress): Builder
    {
        return Property::query()
            ->where('lot_number', trim($lotNumber))
            ->when(filled($streetAddress), fn ($q) => $q->where(fn ($s) => $s
                ->where('street_address', $streetAddress)
                ->orWhereNull('street_address')));
    }

    private function newPropertyCode(): string
    {
        do {
            $code = 'PROP-'.strtoupper(Str::random(8));
        } while (Property::where('property_code', $code)->exists());

        return $code;
    }
}
