<?php

namespace App\Services;

use App\Models\ParkingPass;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ParkingPassService
{
    /**
     * Get parking passes for user or property.
     *
     * @return Collection<int, ParkingPass>
     */
    public function getPassesForUser(User $user): Collection
    {
        $property = $user->propertyLabel();

        return ParkingPass::with(['user', 'vehicle'])
            ->where('user_id', $user->id)
            ->orWhere('property', $property)
            ->orderBy('id', 'asc')
            ->get();
    }

    /**
     * Seed all 6 distinct parking QR credentials requested by the user:
     * 1. Resident parking
     * 2. Visitor parking
     * 3. Contractor parking
     * 4. Temporary parking
     * 5. Accessible parking
     * 6. Loading zones
     */
    public function seedExampleParkingPasses(User $user): array
    {
        $property = $user->propertyLabel();
        $now = CarbonImmutable::now();
        $passes = [];

        $landCruiser = Vehicle::where('license_plate', 'XXX-1234')->first();

        // 1. Resident Parking
        $passes[] = ParkingPass::firstOrCreate(
            ['pass_id' => 'PK-RES-XXX1234'],
            [
                'user_id' => $user->id,
                'vehicle_id' => $landCruiser?->id,
                'category' => ParkingPass::CATEGORY_RESIDENT,
                'license_plate' => 'XXX-1234',
                'property' => $property,
                'assigned_bay' => 'Driveway Bay 14A',
                'holder_name' => 'John Smith',
                'valid_from' => $now->subMonths(1),
                'valid_until' => $now->addYears(1),
                'max_duration_minutes' => null, // unlimited
                'status' => 'active',
                'qr_payload' => $this->generateQrPayload('PK-RES-XXX1234', 'XXX-1234', ParkingPass::CATEGORY_RESIDENT),
                'metadata' => [
                    'vehicle' => 'Toyota Land Cruiser (Pearl White)',
                    'bay_type' => 'Private Driveway',
                    'ev_charging' => false,
                ],
            ]
        );

        // 2. Visitor Parking
        $passes[] = ParkingPass::firstOrCreate(
            ['pass_id' => 'PK-VIS-0014'],
            [
                'user_id' => $user->id,
                'vehicle_id' => null,
                'category' => ParkingPass::CATEGORY_VISITOR,
                'license_plate' => '7731-JA',
                'property' => $property,
                'assigned_bay' => 'Visitor Bay V-04',
                'holder_name' => 'Marcus Vance (Guest)',
                'valid_from' => $now->subHours(2),
                'valid_until' => $now->addHours(22),
                'max_duration_minutes' => 1440, // 24 hours
                'status' => 'active',
                'qr_payload' => $this->generateQrPayload('PK-VIS-0014', '7731-JA', ParkingPass::CATEGORY_VISITOR),
                'metadata' => [
                    'host_resident' => 'John Smith',
                    'vehicle' => 'Nissan X-Trail (Silver)',
                    'bay_type' => 'Designated Visitor Parking',
                ],
            ]
        );

        // 3. Contractor Parking
        $passes[] = ParkingPass::firstOrCreate(
            ['pass_id' => 'PK-CON-7702'],
            [
                'user_id' => $user->id,
                'vehicle_id' => null,
                'category' => ParkingPass::CATEGORY_CONTRACTOR,
                'license_plate' => 'TRUCK-88',
                'property' => $property,
                'assigned_bay' => 'Staging Bay C-02',
                'holder_name' => 'Apex Solar & Electrical Contractors',
                'valid_from' => $now->setTime(7, 0),
                'valid_until' => $now->setTime(18, 0),
                'max_duration_minutes' => 660, // 11 hours
                'status' => 'active',
                'qr_payload' => $this->generateQrPayload('PK-CON-7702', 'TRUCK-88', ParkingPass::CATEGORY_CONTRACTOR),
                'metadata' => [
                    'company' => 'Apex Electrical Ltd.',
                    'vehicle' => 'Isuzu D-Max Work Truck',
                    'work_order' => 'WO-2026-991',
                    'bay_type' => 'Trades & Contractor Staging',
                ],
            ]
        );

        // 4. Temporary Parking
        $passes[] = ParkingPass::firstOrCreate(
            ['pass_id' => 'PK-TMP-3140'],
            [
                'user_id' => $user->id,
                'vehicle_id' => null,
                'category' => ParkingPass::CATEGORY_TEMPORARY,
                'license_plate' => 'RENT-552',
                'property' => $property,
                'assigned_bay' => 'Overflow Bay T-08',
                'holder_name' => 'James Smith (Rental Loaner)',
                'valid_from' => $now,
                'valid_until' => $now->addDays(3),
                'max_duration_minutes' => 4320, // 3 days
                'status' => 'active',
                'qr_payload' => $this->generateQrPayload('PK-TMP-3140', 'RENT-552', ParkingPass::CATEGORY_TEMPORARY),
                'metadata' => [
                    'rental_agency' => 'Island Car Rentals Kingston',
                    'vehicle' => 'Kia Sportage (Graphite Grey)',
                    'bay_type' => 'Overflow Parking Area',
                ],
            ]
        );

        // 5. Accessible Parking
        $passes[] = ParkingPass::firstOrCreate(
            ['pass_id' => 'PK-ACC-0014'],
            [
                'user_id' => $user->id,
                'vehicle_id' => null,
                'category' => ParkingPass::CATEGORY_ACCESSIBLE,
                'license_plate' => '9821-JA',
                'property' => $property,
                'assigned_bay' => 'Accessible Bay A-01 (Clubhouse & Ramp)',
                'holder_name' => 'Dr. James Smith (Medical Mobility)',
                'valid_from' => $now->subMonths(1),
                'valid_until' => $now->addYears(1),
                'max_duration_minutes' => null,
                'status' => 'active',
                'qr_payload' => $this->generateQrPayload('PK-ACC-0014', '9821-JA', ParkingPass::CATEGORY_ACCESSIBLE),
                'metadata' => [
                    'disability_placard' => 'ADA-JM-99218',
                    'ramp_proximity' => 'Direct step-free access to Pavilion 1',
                    'vehicle' => 'Lexus RX 450h+ EV',
                    'bay_type' => 'Universal Accessibility Reserved',
                ],
            ]
        );

        // 6. Loading Zones
        $passes[] = ParkingPass::firstOrCreate(
            ['pass_id' => 'PK-LDG-1029'],
            [
                'user_id' => $user->id,
                'vehicle_id' => null,
                'category' => ParkingPass::CATEGORY_LOADING_ZONE,
                'license_plate' => 'DHL-440',
                'property' => $property,
                'assigned_bay' => 'Loading Bay L-1 (Strict 30 Min Limit)',
                'holder_name' => 'Caribbean Express Couriers',
                'valid_from' => $now->subMinutes(10),
                'valid_until' => $now->addMinutes(20),
                'max_duration_minutes' => 30, // 30 minutes strict
                'status' => 'active',
                'qr_payload' => $this->generateQrPayload('PK-LDG-1029', 'DHL-440', ParkingPass::CATEGORY_LOADING_ZONE),
                'metadata' => [
                    'courier' => 'DHL Express Caribbean',
                    'vehicle' => 'Ford Transit High-Roof Van',
                    'purpose' => 'Heavy parcel staging and delivery',
                    'bay_type' => 'Active Loading Zone',
                    'time_limit' => '30 Minutes Strict',
                ],
            ]
        );

        return $passes;
    }

    /**
     * Issue a new parking pass (convenience alias).
     */
    public function issuePass(array $data, User $user): ParkingPass
    {
        return $this->issueParkingPass($user, $data);
    }

    /**
     * Issue a new parking pass.
     *
     * @param  array<string, mixed>  $data
     */
    public function issueParkingPass(User $user, array $data): ParkingPass
    {
        $category = $data['category'] ?? ParkingPass::CATEGORY_RESIDENT;
        $categoryMeta = ParkingPass::categories()[$category] ?? ParkingPass::categories()[ParkingPass::CATEGORY_RESIDENT];
        $prefix = $categoryMeta['prefix'];
        $cleanPlate = strtoupper(trim(str_replace([' ', '-'], '', (string) $data['license_plate'])));

        $passId = sprintf('%s-%s-%s', $prefix, $cleanPlate, strtoupper(Str::random(4)));

        $validFrom = ! empty($data['valid_from']) ? CarbonImmutable::parse($data['valid_from']) : CarbonImmutable::now();
        $maxMinutes = isset($data['max_duration_minutes']) ? (int) $data['max_duration_minutes'] : $categoryMeta['default_max_minutes'];
        $validUntil = ! empty($data['valid_until'])
            ? CarbonImmutable::parse($data['valid_until'])
            : ($maxMinutes ? $validFrom->addMinutes($maxMinutes) : $validFrom->addMonths(12));

        $qrPayload = $this->generateQrPayload($passId, (string) $data['license_plate'], $category);

        return ParkingPass::create([
            'pass_id' => $passId,
            'user_id' => $user->id,
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'category' => $category,
            'license_plate' => strtoupper(trim((string) $data['license_plate'])),
            'property' => $data['property'] ?? $user->propertyLabel(),
            'assigned_bay' => $data['assigned_bay'] ?? null,
            'holder_name' => trim((string) ($data['holder_name'] ?? $user->name)),
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'max_duration_minutes' => $maxMinutes,
            'status' => 'active',
            'qr_payload' => $qrPayload,
            'metadata' => $data['metadata'] ?? [],
        ]);
    }

    /**
     * Generate HMAC signed parking QR payload.
     */
    public function generateQrPayload(string $passId, string $licensePlate, string $category): string
    {
        $secret = config('gatepass.secret', 'community_hub_parking_secret');
        $signature = hash_hmac('sha256', "{$passId}:{$licensePlate}:{$category}", $secret);

        return sprintf('CHUB-PARK|%s|%s|%s|%s', $passId, strtoupper($licensePlate), $category, substr($signature, 0, 16));
    }

    /**
     * Verify a parking pass QR code or Pass ID.
     *
     * @return array<string, mixed>
     */
    public function verifyParkingPass(string $rawToken): array
    {
        $clean = trim($rawToken);
        $passId = $clean;

        if (str_starts_with($clean, 'CHUB-PARK|')) {
            $parts = explode('|', $clean);
            $passId = $parts[1] ?? '';
        }

        $pass = ParkingPass::with(['user', 'vehicle'])->where('pass_id', $passId)->first();

        if (! $pass) {
            return [
                'valid' => false,
                'isValid' => false,
                'message' => 'Invalid or unrecognized parking credential.',
                'reason' => 'Invalid or unrecognized parking credential.',
                'pass' => null,
            ];
        }

        if (! $pass->isPermitValid()) {
            return [
                'valid' => false,
                'isValid' => false,
                'message' => "Parking permit is {$pass->status} or expired.",
                'reason' => "Parking permit is {$pass->status} or expired.",
                'pass' => $pass,
            ];
        }

        return [
            'valid' => true,
            'isValid' => true,
            'message' => "Authorized for {$pass->categoryLabel()}.",
            'reason' => "Authorized for {$pass->categoryLabel()}.",
            'pass' => $pass,
            'category' => $pass->categoryMeta(),
        ];
    }
}
