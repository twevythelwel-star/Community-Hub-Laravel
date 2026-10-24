<?php

namespace App\Models;

use App\Models\Contracts\PropertyPerson;
use App\Traits\SummarizesPropertyPerson;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person who lives or works at a property: a renter, a household member or
 * household staff. They share the `occupants` table, told apart by `type`.
 *
 * Renter, HouseholdMember and Staff extend this class. Each is scoped to its
 * own type and stamps that type on create, so Renter::query() only ever sees
 * renters. Querying Occupant itself sees everyone, and hydrates each row as
 * its own subclass.
 */
abstract class Occupant extends Model implements PropertyPerson
{
    use SummarizesPropertyPerson;

    /** The `type` value this subclass owns. */
    public const TYPE = '';

    /** @var array<string, class-string<Occupant>> */
    public const SUBCLASSES = [
        Renter::TYPE => Renter::class,
        HouseholdMember::TYPE => HouseholdMember::class,
        Staff::TYPE => Staff::class,
    ];

    protected $table = 'occupants';

    protected static function booted(): void
    {
        if (static::TYPE === '') {
            return;
        }

        static::addGlobalScope('occupant_type', function (Builder $query): void {
            $query->where($query->qualifyColumn('type'), static::TYPE);
        });

        static::creating(function (Occupant $occupant): void {
            $occupant->type = static::TYPE;
        });
    }

    /**
     * Every occupant of any type, each hydrated as its own subclass.
     *
     * @return Builder<Occupant>
     */
    public static function everyone(): Builder
    {
        return AnyOccupant::query();
    }

    /**
     * The homeowner's property a free-text label names ("Unit Lot 42",
     * "Lot 42, Royal Palm Drive"), or their only property when there is no
     * label. Null when it names none of theirs, e.g. "Community Grounds".
     */
    protected static function propertyOwnedAt(?int $ownerId, ?string $label): ?int
    {
        if ($ownerId === null) {
            return null;
        }

        $bare = static function (?string $text): string {
            $text = strtolower(trim(explode(',', (string) $text)[0]));

            while (preg_match('/^(unit|lot|#)\s*/', $text)) {
                $text = preg_replace('/^(unit|lot|#)\s*/', '', $text);
            }

            return $text;
        };

        $owned = Property::query()->where('owner_user_id', $ownerId)->orderBy('id')->get(['id', 'lot_number']);

        if ($bare($label) === '') {
            return $owned->count() === 1 ? $owned->first()->id : null;
        }

        return $owned->first(fn (Property $property) => $bare($property->lot_number) === $bare($label))?->id;
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function personType(): string
    {
        return static::TYPE;
    }

    public function personName(): string
    {
        return (string) $this->name;
    }
}
