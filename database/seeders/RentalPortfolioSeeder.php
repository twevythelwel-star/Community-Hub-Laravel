<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Community;
use App\Models\Property;
use App\Models\Renter;
use App\Models\User;
use App\Services\Properties\OwnershipEntityService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RentalPortfolioSeeder extends Seeder
{
    public function run(): void
    {
        $community = Community::first() ?? Community::create([
            'name' => 'Cypress Bay',
            'code' => 'CID-CYPRESS-BAY',
            'jurisdiction' => 'Saint James, Jamaica',
            'datum' => 'WGS84',
            'reference_lat' => 18.4766,
            'reference_lng' => -77.9257,
        ]);

        $defaultPassword = Hash::make(config('auth.seed_password'));
        $entityService = app(OwnershipEntityService::class);

        // =========================================================================
        // 1. HOMEOWNER 1 WITH EXACTLY 2 PROPERTIES (Damian Clarke)
        // =========================================================================
        $homeowner1 = User::updateOrCreate(
            ['email' => 'damian.clarke@residence.net'],
            [
                'name' => 'Damian Clarke',
                'display_name' => 'Damian Clarke',
                'role' => UserRole::Homeowner,
                'phone' => '(876) 555-0210',
                'title' => 'Verified Homeowner (Dual Property Portfolio)',
                'lot' => 'Lot 101',
                'street' => 'Royal Palms Boulevard',
                'password' => $defaultPassword,
                'status' => 'Active',
                'email_verified_at' => now(),
            ]
        );

        $entity1 = $entityService->ensureIndividualEntityForUser($homeowner1);
        $entity1->update([
            'legal_name' => 'Damian Clarke (Individual)',
            'verification_status' => 'verified',
            'verified_at' => now()->subMonths(3),
            'verification_source' => 'National Land Agency (NLA) Title Verification',
            'verification_notes' => 'Registered proprietor of Lot 101 and Lot 102 under Volume 1420 Folio 881.',
        ]);

        // Damian Clarke Property 1
        $h1Prop1 = Property::updateOrCreate(
            ['property_code' => 'PROP-CB-101'],
            [
                'community_id' => $community->id,
                'ownership_entity_id' => $entity1->id,
                'owner_user_id' => $homeowner1->id,
                'primary_occupant_id' => $homeowner1->id,
                'lot_number' => 'Lot 101',
                'street_address' => '101 Royal Palms Boulevard',
                'property_type' => 'Single Family Residence',
                'status' => 'occupied',
            ]
        );

        // Damian Clarke Property 2
        $h1Prop2 = Property::updateOrCreate(
            ['property_code' => 'PROP-CB-102'],
            [
                'community_id' => $community->id,
                'ownership_entity_id' => $entity1->id,
                'owner_user_id' => $homeowner1->id,
                'primary_occupant_id' => null,
                'lot_number' => 'Lot 102',
                'street_address' => '102 Royal Palms Boulevard',
                'property_type' => 'Luxury Vacation Villa',
                'status' => 'rented',
            ]
        );

        // =========================================================================
        // 2. HOMEOWNER 2 WITH EXACTLY 3 PROPERTIES (Victoria Campbell)
        // =========================================================================
        $homeowner2 = User::updateOrCreate(
            ['email' => 'victoria.campbell@residence.net'],
            [
                'name' => 'Victoria Campbell',
                'display_name' => 'Victoria Campbell',
                'role' => UserRole::Homeowner,
                'phone' => '(876) 555-0310',
                'title' => 'Verified Homeowner (Triple Property Investor)',
                'lot' => 'Lot 201',
                'street' => 'Ocean View Heights',
                'password' => $defaultPassword,
                'status' => 'Active',
                'email_verified_at' => now(),
            ]
        );

        $entity2 = $entityService->ensureIndividualEntityForUser($homeowner2);
        $entity2->update([
            'legal_name' => 'Victoria Campbell (Individual)',
            'verification_status' => 'verified',
            'verified_at' => now()->subMonths(5),
            'verification_source' => 'National Land Agency (NLA) Title Verification',
            'verification_notes' => 'Registered proprietor of Lots 201, 202, and 203 under Volume 1588 Folios 102-104.',
        ]);

        // Victoria Campbell Property 1
        $h2Prop1 = Property::updateOrCreate(
            ['property_code' => 'PROP-CB-201'],
            [
                'community_id' => $community->id,
                'ownership_entity_id' => $entity2->id,
                'owner_user_id' => $homeowner2->id,
                'primary_occupant_id' => $homeowner2->id,
                'lot_number' => 'Lot 201',
                'street_address' => '201 Ocean View Heights',
                'property_type' => 'Executive Coastal Estate',
                'status' => 'occupied',
            ]
        );

        // Victoria Campbell Property 2
        $h2Prop2 = Property::updateOrCreate(
            ['property_code' => 'PROP-CB-202'],
            [
                'community_id' => $community->id,
                'ownership_entity_id' => $entity2->id,
                'owner_user_id' => $homeowner2->id,
                'primary_occupant_id' => null,
                'lot_number' => 'Lot 202',
                'street_address' => '202 Ocean View Heights',
                'property_type' => 'Oceanfront Villa',
                'status' => 'rented',
            ]
        );

        // Victoria Campbell Property 3
        $h2Prop3 = Property::updateOrCreate(
            ['property_code' => 'PROP-CB-203'],
            [
                'community_id' => $community->id,
                'ownership_entity_id' => $entity2->id,
                'owner_user_id' => $homeowner2->id,
                'primary_occupant_id' => null,
                'lot_number' => 'Lot 203',
                'street_address' => '203 Ocean View Heights',
                'property_type' => 'Garden Guest Cottage',
                'status' => 'rented',
            ]
        );

        // =========================================================================
        // 3. COMMUNITY ASSET LESSOR (Hosts general community rental units)
        // =========================================================================
        $communityLessor = User::updateOrCreate(
            ['email' => 'estates.portfolio@communityhub.org'],
            [
                'name' => 'Cypress Bay Estates Portfolio',
                'display_name' => 'Estate Asset Management',
                'role' => UserRole::Homeowner,
                'phone' => '(876) 555-0999',
                'title' => 'Community Asset Holding',
                'lot' => 'HQ-02',
                'street' => 'Central Clubhouse Way',
                'password' => $defaultPassword,
                'status' => 'Active',
                'email_verified_at' => now(),
            ]
        );

        $streets = [
            'Hibiscus Crescent',
            'Royal Palm Way',
            'Ocean View Ridge',
            'Orchid Valley Drive',
            'Coconut Grove Terrace',
            'Mahogany Ridge',
            'Pelican Point Lane',
            'Bougainvillea Circle',
        ];

        // 50 dedicated community portfolio rental properties
        $portfolioProperties = [];
        for ($i = 1; $i <= 50; $i++) {
            $lotNum = 'Lot '.(300 + $i);
            $streetName = $streets[($i - 1) % count($streets)];
            $code = sprintf('PROP-CB-%03d', 300 + $i);

            $prop = Property::updateOrCreate(
                ['property_code' => $code],
                [
                    'community_id' => $community->id,
                    'owner_user_id' => $communityLessor->id,
                    'lot_number' => $lotNum,
                    'street_address' => (300 + $i).' '.$streetName,
                    'property_type' => ($i % 3 === 0) ? 'Townhouse' : (($i % 3 === 1) ? 'Villa' : 'Condo Suite'),
                    'status' => 'rented',
                ]
            );
            $portfolioProperties[] = $prop;
        }

        // Target properties pool: Homeowner 1 rental, Homeowner 2 rentals, and community portfolio properties
        $rentalHostingProperties = array_merge([
            $h1Prop2,
            $h2Prop2,
            $h2Prop3,
        ], $portfolioProperties);
        $totalHostingProps = count($rentalHostingProperties);

        // =========================================================================
        // 4. SEED EXACTLY 100 TEMPORARY (AIRBNB) RENTALS
        // =========================================================================
        $airbnbFirstNames = [
            'Liam', 'Emma', 'Noah', 'Olivia', 'Ethan', 'Ava', 'Lucas', 'Sophia', 'Mason', 'Isabella',
            'Oliver', 'Mia', 'Aiden', 'Charlotte', 'Elijah', 'Amelia', 'James', 'Harper', 'Benjamin', 'Evelyn',
            'Sebastian', 'Abigail', 'Mateo', 'Emily', 'Logan', 'Elizabeth', 'Jackson', 'Mila', 'Levi', 'Ella',
            'Alexander', 'Avery', 'Daniel', 'Sofia', 'Henry', 'Camila', 'Michael', 'Aria', 'Jack', 'Scarlett',
            'Julian', 'Victoria', 'David', 'Madison', 'Wyatt', 'Luna', 'Matthew', 'Grace', 'Luke', 'Chloe',
        ];

        $airbnbLastNames = [
            'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez',
            'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin',
            'Lee', 'Perez', 'Thompson', 'White', 'Harris', 'Sanchez', 'Clark', 'Ramirez', 'Lewis', 'Robinson',
            'Walker', 'Young', 'Allen', 'King', 'Wright', 'Scott', 'Torres', 'Nguyen', 'Hill', 'Flores',
            'Green', 'Adams', 'Nelson', 'Baker', 'Hall', 'Rivera', 'Campbell', 'Mitchell', 'Carter', 'Roberts',
        ];

        for ($i = 1; $i <= 100; $i++) {
            $firstName = $airbnbFirstNames[($i - 1) % count($airbnbFirstNames)];
            $lastName = $airbnbLastNames[(($i * 3) + 7) % count($airbnbLastNames)];
            $middleInitial = chr(65 + ($i % 26));
            $fullName = "{$firstName} {$middleInitial}. {$lastName}";

            $prop = $rentalHostingProperties[$i % $totalHostingProps];
            $owner = $prop->owner ?? $communityLessor;

            // Short-term stay duration: 2 to 14 days
            $startOffset = ($i % 20) - 3;
            $stayDuration = 3 + ($i % 9);
            $leaseStart = Carbon::today()->addDays($startOffset);
            $leaseEnd = (clone $leaseStart)->addDays($stayDuration);

            $bookingCode = sprintf('HM-%04d-%02d', 1000 + $i, $i % 99);
            $phone = sprintf('+1 (876) 555-%04d', 4000 + $i);

            Renter::updateOrCreate(
                ['contact' => $phone],
                [
                    'name' => $fullName,
                    'homeowner_id' => $owner->id,
                    'property_id' => $prop->id,
                    'stay_type' => 'Short-term (Airbnb)',
                    'contact' => $phone,
                    'status' => 'Active',
                    'lease_start' => $leaseStart->toDateString(),
                    'lease_end' => $leaseEnd->toDateString(),
                    'lot' => $prop->lot_number,
                    'street' => $prop->street_address,
                    'notes' => "Airbnb Verified Booking #{$bookingCode}. Guests: ".(1 + ($i % 4)).' adults. Superhost instant reservation.',
                ]
            );
        }

        // =========================================================================
        // 5. SEED EXACTLY 250 LONG-TERM RENTALS
        // =========================================================================
        $longTermFirstNames = [
            'Andre', 'Kadian', 'Damion', 'Shanique', 'Tariq', 'Romaine', 'Althea', 'Javell', 'Dionne', 'Oshane',
            'Keisha', 'Tyrone', 'Stacy-Ann', 'Devon', 'Nadine', 'Marlon', 'Rochelle', 'Garth', 'Tamara', 'Kenroy',
            'Latoya', 'Sheldon', 'Marsha', 'Dwight', 'Natasha', 'Rohan', 'Simone', 'Trevor', 'Camille', 'Jermaine',
            'Alicia', 'Ricardo', 'Tanika', 'Carl', 'Sherene', 'Delroy', 'Beverly', 'Winston', 'Patrice', 'Leroy',
            'Monique', 'Garfield', 'Charmaine', 'Patrick', 'Donna', 'Wayne', 'Sasha', 'Barrington', 'Gillian', 'Everton',
        ];

        $longTermLastNames = [
            'Campbell', 'Clarke', 'Sterling', 'Beckford', 'Simpson', 'Powell', 'McIntosh', 'Dixon', 'Reid', 'Watson',
            'Hutchinson', 'Buchanan', 'Cunningham', 'Patterson', 'Daley', 'Forbes', 'Sinclair', 'Morrison', 'Chambers', 'Barrett',
            'Grant', 'Dawkins', 'Samuels', 'Facey', 'Henriques', 'Mullings', 'Chin', 'Wong', 'Chang', 'Chung',
            'Barrow', 'Gyles', 'Manning', 'Blake', 'Samuda', 'Bowen', 'Golding', 'Patterson', 'Peart', 'Burke',
            'Sangster', 'Manley', 'Bustamante', 'Seaga', 'Glasspole', 'Hall', 'Robinson', 'Fowler', 'Blythe', 'Hylton',
        ];

        for ($i = 1; $i <= 250; $i++) {
            $firstName = $longTermFirstNames[($i - 1) % count($longTermFirstNames)];
            $lastName = $longTermLastNames[(($i * 5) + 11) % count($longTermLastNames)];
            $middleInitial = chr(65 + (($i * 3) % 26));
            $fullName = "{$firstName} {$middleInitial}. {$lastName}";

            $prop = $rentalHostingProperties[($i * 3) % $totalHostingProps];
            $owner = $prop->owner ?? $communityLessor;

            // Long-term lease: start 1 to 9 months ago, expires 6 to 18 months in future
            $startMonthsAgo = ($i % 9) + 1;
            $leaseDurationMonths = 12 + (($i % 3) * 6);
            $leaseStart = Carbon::today()->subMonths($startMonthsAgo)->day(1);
            $leaseEnd = (clone $leaseStart)->addMonths($leaseDurationMonths);

            $leaseAgreementNo = sprintf('LSE-CB-%04d', 2000 + $i);
            $phone = sprintf('(876) 555-%04d', 6000 + $i);

            Renter::updateOrCreate(
                ['contact' => $phone],
                [
                    'name' => $fullName,
                    'homeowner_id' => $owner->id,
                    'property_id' => $prop->id,
                    'stay_type' => 'Long-term (Renter)',
                    'contact' => $phone,
                    'status' => 'Active',
                    'lease_start' => $leaseStart->toDateString(),
                    'lease_end' => $leaseEnd->toDateString(),
                    'lot' => $prop->lot_number,
                    'street' => $prop->street_address,
                    'notes' => "Annual Residential Tenancy Lease #{$leaseAgreementNo}. Background & credit check cleared. Monthly recurring rental.",
                ]
            );
        }
    }
}
