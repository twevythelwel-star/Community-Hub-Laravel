<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParkingPass extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'max_duration_minutes' => 'integer',
        'metadata' => 'array',
    ];

    public const CATEGORY_RESIDENT = 'resident';

    public const CATEGORY_VISITOR = 'visitor';

    public const CATEGORY_CONTRACTOR = 'contractor';

    public const CATEGORY_TEMPORARY = 'temporary';

    public const CATEGORY_ACCESSIBLE = 'accessible';

    public const CATEGORY_LOADING_ZONE = 'loading_zone';

    public static function categories(): array
    {
        return [
            self::CATEGORY_RESIDENT => [
                'id' => self::CATEGORY_RESIDENT,
                'name' => 'Resident Parking',
                'prefix' => 'PK-RES',
                'description' => 'Permanent assigned bay privilege for verified estate homeowners & occupants.',
                'zone' => 'Zone R (Resident Private Bays)',
                'default_max_minutes' => null, // unlimited
                'badge' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30',
                'icon' => 'Home',
            ],
            self::CATEGORY_VISITOR => [
                'id' => self::CATEGORY_VISITOR,
                'name' => 'Visitor Parking',
                'prefix' => 'PK-VIS',
                'description' => 'Host-authorized guest vehicle bays with 24-hour renewable clearance.',
                'zone' => 'Zone V (Designated Visitor Bays)',
                'default_max_minutes' => 1440, // 24 hours
                'badge' => 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/30',
                'icon' => 'UserCheck',
            ],
            self::CATEGORY_CONTRACTOR => [
                'id' => self::CATEGORY_CONTRACTOR,
                'name' => 'Contractor Parking',
                'prefix' => 'PK-CON',
                'description' => 'Trade, repair, and construction staging bays permitted Mon–Fri 7am–6pm.',
                'zone' => 'Zone C (Contractor Staging & Trades)',
                'default_max_minutes' => 660, // 11 hours
                'badge' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/30',
                'icon' => 'HardHat',
            ],
            self::CATEGORY_TEMPORARY => [
                'id' => self::CATEGORY_TEMPORARY,
                'name' => 'Temporary Parking',
                'prefix' => 'PK-TMP',
                'description' => 'Short-term rental car, replacement loaner, or multi-day guest permit.',
                'zone' => 'Zone T (Overflow & Temporary Bays)',
                'default_max_minutes' => 4320, // 3 days
                'badge' => 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-500/30',
                'icon' => 'Clock',
            ],
            self::CATEGORY_ACCESSIBLE => [
                'id' => self::CATEGORY_ACCESSIBLE,
                'name' => 'Accessible Parking',
                'prefix' => 'PK-ACC',
                'description' => 'Universal design / disability placard reserved bays closest to clubhouse & ramps.',
                'zone' => 'Zone A (ADA / Universal Access Bays)',
                'default_max_minutes' => null,
                'badge' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400 border-sky-500/30',
                'icon' => 'Accessibility',
            ],
            self::CATEGORY_LOADING_ZONE => [
                'id' => self::CATEGORY_LOADING_ZONE,
                'name' => 'Loading Zones',
                'prefix' => 'PK-LDG',
                'description' => 'Commercial courier delivery, parcel staging, and moving van loading (Strict 30-min window).',
                'zone' => 'Zone L (Active Courier & Moving Bays)',
                'default_max_minutes' => 30, // 30 minutes strict
                'badge' => 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/30',
                'icon' => 'Truck',
            ],
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function categoryMeta(): array
    {
        return self::categories()[$this->category] ?? self::categories()[self::CATEGORY_RESIDENT];
    }

    public function categoryLabel(): string
    {
        return $this->categoryMeta()['name'];
    }

    public function badgeClasses(): string
    {
        return $this->categoryMeta()['badge'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function isPermitValid(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $now = now();
        if ($this->valid_from && $now->lt($this->valid_from)) {
            return false;
        }
        if ($this->valid_until && $now->gt($this->valid_until)) {
            return false;
        }

        return true;
    }
}
