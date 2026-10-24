<?php

namespace App\Services;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Collection;

class VehicleManagementService
{
    /**
     * Get vehicles for a user / property.
     *
     * @return Collection<int, Vehicle>
     */
    public function getVehiclesForUser(User $user): Collection
    {
        $property = $user->propertyLabel();

        return Vehicle::with(['user', 'householdMember'])
            ->where('user_id', $user->id)
            ->orWhere('property', $property)
            ->orderBy('id', 'asc')
            ->get();
    }

    /**
     * Seed example vehicles for John Smith and family as requested:
     * - John Smith: Toyota Land Cruiser (Jamaica Plate: XXX-1234)
     * - Mary Smith: Lexus RX 450h+ EV (Jamaica Plate: 9821-JA)
     * - Alex Smith: Honda Civic Sport (Jamaica Plate: 4412-JA)
     */
    public function seedSmithHouseholdVehicles(User $user): array
    {
        $property = $user->propertyLabel();
        $household = Household::where('primary_homeowner_id', $user->id)->first();

        $johnMember = $household ? HouseholdMember::where('household_id', $household->id)->where('name', 'John Smith')->first() : null;
        $maryMember = $household ? HouseholdMember::where('household_id', $household->id)->where('name', 'Mary Smith')->first() : null;
        $alexMember = $household ? HouseholdMember::where('household_id', $household->id)->where('name', 'Alex Smith')->first() : null;

        $vehicles = [];

        // 1. John Smith - Toyota Land Cruiser (Primary Homeowner Vehicle)
        $vehicles[] = Vehicle::firstOrCreate(
            ['license_plate' => 'XXX-1234'],
            [
                'user_id' => $user->id,
                'household_member_id' => $johnMember?->id,
                'property' => $property,
                'jurisdiction' => 'Jamaica',
                'make' => 'Toyota',
                'model' => 'Land Cruiser',
                'color' => 'Pearl White',
                'year' => 2024,
                'parking_location' => 'Unit 14 — Driveway Bay A',
                'is_ev' => false,
                'is_temporary' => false,
                'anpr_enabled' => true,
                'status' => 'active',
                'notes' => 'Primary registered resident vehicle. Automatic gate arm clearance enabled.',
            ]
        );

        // 2. Mary Smith - Lexus RX 450h+ EV (Spouse Vehicle)
        $vehicles[] = Vehicle::firstOrCreate(
            ['license_plate' => '9821-JA'],
            [
                'user_id' => $user->id,
                'household_member_id' => $maryMember?->id,
                'property' => $property,
                'jurisdiction' => 'Jamaica',
                'make' => 'Lexus',
                'model' => 'RX 450h+ EV',
                'color' => 'Caviar Black',
                'year' => 2023,
                'parking_location' => 'Unit 14 — EV Charging Bay B',
                'is_ev' => true,
                'is_temporary' => false,
                'anpr_enabled' => true,
                'status' => 'active',
                'notes' => 'Plug-in Hybrid EV. Authorized for Clubhouse Level 2 EV charging station.',
            ]
        );

        // 3. Alex Smith - Honda Civic Sport (Child / Dependent Vehicle)
        $vehicles[] = Vehicle::firstOrCreate(
            ['license_plate' => '4412-JA'],
            [
                'user_id' => $user->id,
                'household_member_id' => $alexMember?->id,
                'property' => $property,
                'jurisdiction' => 'Jamaica',
                'make' => 'Honda',
                'model' => 'Civic Sport',
                'color' => 'Aegean Blue',
                'year' => 2022,
                'parking_location' => 'Unit 14 — Assigned Bay C',
                'is_ev' => false,
                'is_temporary' => false,
                'anpr_enabled' => true,
                'status' => 'active',
                'notes' => 'Subject to dependent 9:00 PM curfew gate access rules.',
            ]
        );

        return $vehicles;
    }

    /**
     * Register a new vehicle.
     *
     * @param  array<string, mixed>  $data
     */
    public function registerVehicle(User|array $first, User|array $second = []): Vehicle
    {
        if ($first instanceof User) {
            $user = $first;
            $data = (array) $second;
        } else {
            $user = $second;
            $data = (array) $first;
        }

        return Vehicle::create([
            'user_id' => $user->id,
            'household_member_id' => $data['household_member_id'] ?? null,
            'property' => $data['property'] ?? $user->propertyLabel(),
            'license_plate' => strtoupper(trim((string) $data['license_plate'])),
            'jurisdiction' => $data['jurisdiction'] ?? 'Jamaica',
            'make' => trim((string) $data['make']),
            'model' => trim((string) $data['model']),
            'color' => trim((string) $data['color']),
            'year' => ! empty($data['year']) ? (int) $data['year'] : null,
            'parking_location' => $data['parking_location'] ?? null,
            'is_ev' => (bool) ($data['is_ev'] ?? false),
            'is_temporary' => (bool) ($data['is_temporary'] ?? false),
            'valid_until' => ! empty($data['valid_until']) ? $data['valid_until'] : null,
            'anpr_enabled' => (bool) ($data['anpr_enabled'] ?? true),
            'status' => $data['status'] ?? 'active',
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Fast ANPR license plate recognition lookup.
     *
     * @return array<string, mixed>|null
     */
    public function lookupPlate(string $plate): ?array
    {
        $clean = strtoupper(trim(str_replace([' ', '-'], '', $plate)));
        if (empty($clean)) {
            return null;
        }

        $vehicle = Vehicle::with(['user', 'householdMember'])
            ->whereRaw("REPLACE(REPLACE(UPPER(license_plate), ' ', ''), '-', '') = ?", [$clean])
            ->first();

        if (! $vehicle) {
            return [
                'found' => false,
                'authorized' => false,
                'message' => 'Unrecognized license plate.',
            ];
        }

        $ownerName = $vehicle->householdMember?->name ?? $vehicle->user?->name ?? 'Unknown Owner';

        return [
            'found' => true,
            'authorized' => $vehicle->status === 'active' && $vehicle->anpr_enabled,
            'owner' => ['name' => $ownerName],
            'vehicle' => [
                'id' => $vehicle->id,
                'make' => $vehicle->make,
                'model' => $vehicle->model,
                'color' => $vehicle->color,
                'year' => $vehicle->year,
                'parking_location' => $vehicle->parking_location,
            ],
            'id' => $vehicle->id,
            'licensePlate' => $vehicle->license_plate,
            'jurisdiction' => $vehicle->jurisdiction,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'color' => $vehicle->color,
            'year' => $vehicle->year,
            'fullDescription' => $vehicle->fullDescription(),
            'ownerName' => $ownerName,
            'property' => $vehicle->property,
            'parkingLocation' => $vehicle->parking_location,
            'isEv' => $vehicle->is_ev,
            'isTemporary' => $vehicle->is_temporary,
            'anprAuthorized' => $vehicle->status === 'active' && $vehicle->anpr_enabled,
            'status' => $vehicle->status,
            'notes' => $vehicle->notes,
        ];
    }

    /**
     * Get all registered vehicles for estate overview.
     *
     * @return Collection<int, Vehicle>
     */
    public function getAllVehicles(): Collection
    {
        return Vehicle::with(['user', 'householdMember'])
            ->orderBy('property', 'asc')
            ->orderBy('id', 'asc')
            ->get();
    }
}
