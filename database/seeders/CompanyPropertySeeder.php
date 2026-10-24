<?php

namespace Database\Seeders;

use App\Enums\AuthorizedPersonRole;
use App\Enums\GateId;
use App\Enums\OwnershipEntityType;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Models\AccessRiskIncident;
use App\Models\AuthorizationDocument;
use App\Models\AuthorizedPerson;
use App\Models\Community;
use App\Models\DelegatedAccess;
use App\Models\GatePass;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Invoice;
use App\Models\OwnershipEntity;
use App\Models\ParkingPass;
use App\Models\Property;
use App\Models\Renter;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Visitor;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CompanyPropertySeeder extends Seeder
{
    public function run(): void
    {
        $community = Community::first();
        $password = Hash::make(config('auth.seed_password'));

        // 1. Create or update Company: ABC Holdings Limited
        $company = OwnershipEntity::updateOrCreate(
            ['legal_name' => 'ABC Holdings Limited'],
            [
                'community_id' => $community?->id,
                'entity_type' => OwnershipEntityType::Company,
                'trade_name' => 'ABC Holdings',
                'registration_number' => 'CORP-ABC-2024',
                'jurisdiction' => 'Jamaica',
                'primary_email' => 'operations@abcholdings.com',
                'primary_phone' => '(876) 555-8800',
                'billing_address' => 'Suite 400, 14 Financial Crescent, New Kingston, Jamaica',
                'status' => 'active',
                'verification_status' => 'verified',
                'verified_at' => Carbon::now()->subMonths(6),
                'verification_source' => 'Companies Office of Jamaica (COJ)',
                'verification_notes' => 'Verified with Companies Office of Jamaica (COJ) corporate registry. Registration #CORP-ABC-2024. Certified Directors, Shareholders, and Certificate of Incorporation validated.',
                'verification_checklist' => [
                    'coj_registration_number_verified' => true,
                    'company_status_verified' => 'Active / Good Standing',
                    'directors_identified' => true,
                    'shareholders_identified' => true,
                    'articles_of_incorporation_verified' => true,
                    'certificate_of_incorporation_verified' => true,
                    'registered_office_verified' => true,
                ],
                'beneficial_ownership' => [
                    'declaration_reference' => 'COJ-BO-2024-88190',
                    'filing_date' => '2024-05-18',
                    'coj_form_reference' => 'COJ Form 38 (Beneficial Ownership Declaration)',
                    'statutory_basis' => 'Companies (Amendment) Act (Jamaica) — Confidential Beneficial Ownership Framework',
                    'beneficial_owners' => [
                        [
                            'full_name' => 'Jane Elizabeth Sterling',
                            'nationality' => 'Jamaican',
                            'id_trn' => 'TRN-109-882-314',
                            'percentage_interest' => 65.0,
                            'control_nature' => 'Direct shareholding (>25% voting rights) & Managing Director',
                            'pep_status' => false,
                            'verified_at' => '2024-05-18',
                        ],
                        [
                            'full_name' => 'Marcus Alexander Sterling',
                            'nationality' => 'Jamaican',
                            'id_trn' => 'TRN-144-902-188',
                            'percentage_interest' => 35.0,
                            'control_nature' => 'Direct shareholding (>25% voting rights)',
                            'pep_status' => false,
                            'verified_at' => '2024-05-18',
                        ],
                    ],
                ],
                'metadata' => [
                    'incorporation_date' => '2018-04-12',
                    'tax_registration_number' => 'TRN-109-882-314',
                    'compliance_status' => 'Good Standing',
                    'coj_verification' => [
                        'registration_number' => 'CORP-ABC-2024',
                        'company_type' => 'Private Company Limited by Shares',
                        'incorporation_date' => '2018-04-12',
                        'directors' => [
                            ['name' => 'Jane Sterling', 'role' => 'Managing Director', 'appointed_at' => '2018-04-12', 'coj_verified' => true],
                            ['name' => 'Marcus Sterling', 'role' => 'Executive Director', 'appointed_at' => '2018-04-12', 'coj_verified' => true],
                        ],
                        'shareholders' => [
                            ['name' => 'Sterling Family Holdings Ltd.', 'shares' => '65,000 Ordinary', 'voting_pct' => '65%'],
                            ['name' => 'Marcus Sterling', 'shares' => '35,000 Ordinary', 'voting_pct' => '35%'],
                        ],
                        'registered_office' => 'Suite 400, 14 Financial Crescent, New Kingston, Jamaica',
                        'annual_return_filed' => true,
                        'last_return_date' => '2025-12-31',
                    ],
                ],
            ]
        );

        // 2. Properties: Unit 14 (Occupied), Unit 18 (Vacant), Unit 22 (Rented)
        $unit14User = User::updateOrCreate(
            ['email' => 'jonathan.brown@abcholdings-resident.net'],
            [
                'name' => 'Jonathan Brown',
                'display_name' => 'Jonathan Brown',
                'role' => UserRole::Homeowner,
                'password' => $password,
                'phone' => '(876) 555-0141',
                'lot' => 'Unit 14',
                'street' => 'Royal Palm Way',
                'status' => 'Active',
            ]
        );

        $unit14 = Property::updateOrCreate(
            ['property_code' => 'UNIT-14'],
            [
                'community_id' => $community?->id,
                'ownership_entity_id' => $company->id,
                'owner_user_id' => null, // Corporate owned!
                'primary_occupant_id' => $unit14User->id,
                'lot_number' => 'Unit 14',
                'street_address' => '14 Royal Palm Way',
                'property_type' => 'Villa',
                'status' => 'occupied',
            ]
        );

        $unit18 = Property::updateOrCreate(
            ['property_code' => 'UNIT-18'],
            [
                'community_id' => $community?->id,
                'ownership_entity_id' => $company->id,
                'owner_user_id' => null, // Corporate owned!
                'primary_occupant_id' => null,
                'lot_number' => 'Unit 18',
                'street_address' => '18 Royal Palm Way',
                'property_type' => 'Townhouse',
                'status' => 'vacant',
            ]
        );

        $unit22 = Property::updateOrCreate(
            ['property_code' => 'UNIT-22'],
            [
                'community_id' => $community?->id,
                'ownership_entity_id' => $company->id,
                'owner_user_id' => null, // Corporate owned!
                'primary_occupant_id' => null,
                'lot_number' => 'Unit 22',
                'street_address' => '22 Royal Palm Way',
                'property_type' => 'Penthouse',
                'status' => 'rented',
            ]
        );

        // 3. Authorized Representatives for ABC Holdings Limited
        // Representative A: Jane Sterling (Director — Full portfolio authority)
        $janeUser = User::updateOrCreate(
            ['email' => 'jane.sterling@abcholdings.com'],
            [
                'name' => 'Jane Sterling',
                'display_name' => 'Jane Sterling',
                'role' => UserRole::Homeowner,
                'password' => $password,
                'phone' => '(876) 555-8810',
                'title' => 'Managing Director, ABC Holdings',
                'status' => 'Active',
            ]
        );

        $janeRep = AuthorizedPerson::updateOrCreate(
            [
                'ownership_entity_id' => $company->id,
                'user_id' => $janeUser->id,
            ],
            [
                'role' => AuthorizedPersonRole::Director,
                'full_name' => 'Jane Sterling',
                'email' => 'jane.sterling@abcholdings.com',
                'phone' => '(876) 555-8810',
                'permissions' => ['*'],
                'is_primary_contact' => true,
                'is_signatory' => true,
                'status' => 'active',
                'verification_status' => 'verified',
                'metadata' => [
                    'corporate_title' => 'Managing Director',
                    // Null or omitted authorized_property_ids means all company properties
                ],
            ]
        );

        // Representative B: Mark Vance (Property Manager — Authorized strictly for Unit 14 and Unit 18)
        $markUser = User::updateOrCreate(
            ['email' => 'mark.vance@abcholdings.com'],
            [
                'name' => 'Mark Vance',
                'display_name' => 'Mark Vance',
                'role' => UserRole::Homeowner,
                'password' => $password,
                'phone' => '(876) 555-8820',
                'title' => 'Senior Property Operations Manager',
                'status' => 'Active',
            ]
        );

        $markRep = AuthorizedPerson::updateOrCreate(
            [
                'ownership_entity_id' => $company->id,
                'user_id' => $markUser->id,
            ],
            [
                'role' => AuthorizedPersonRole::PropertyManager,
                'full_name' => 'Mark Vance',
                'email' => 'mark.vance@abcholdings.com',
                'phone' => '(876) 555-8820',
                'permissions' => [
                    'manage_visitors',
                    'manage_renters_long_term',
                    'manage_renters_short_term',
                    'manage_staff',
                    'manage_contractors',
                    'manage_vehicles',
                    'manage_parking',
                    'receive_notifications',
                ],
                'is_primary_contact' => false,
                'is_signatory' => false,
                'status' => 'active',
                'verification_status' => 'verified',
                'metadata' => [
                    'corporate_title' => 'Property Operations Manager',
                    'authorized_property_ids' => [$unit14->id, $unit18->id], // Authorized ONLY for Unit 14 and Unit 18!
                ],
            ]
        );

        // Representative C: Angela Chen (Financial Officer / Accountant — Full billing authority)
        $angelaUser = User::updateOrCreate(
            ['email' => 'angela.chen@abcholdings.com'],
            [
                'name' => 'Angela Chen',
                'display_name' => 'Angela Chen',
                'role' => UserRole::Homeowner,
                'password' => $password,
                'phone' => '(876) 555-8830',
                'title' => 'Chief Financial Controller',
                'status' => 'Active',
            ]
        );

        AuthorizedPerson::updateOrCreate(
            [
                'ownership_entity_id' => $company->id,
                'user_id' => $angelaUser->id,
            ],
            [
                'role' => AuthorizedPersonRole::Accountant,
                'full_name' => 'Angela Chen',
                'email' => 'angela.chen@abcholdings.com',
                'phone' => '(876) 555-8830',
                'permissions' => [
                    'manage_billing',
                    'receive_notifications',
                ],
                'is_primary_contact' => false,
                'is_signatory' => true,
                'status' => 'active',
                'verification_status' => 'verified',
                'metadata' => [
                    'corporate_title' => 'Chief Financial Controller',
                ],
            ]
        );

        // 4. PEOPLE & ACCESS DATA

        // A. Residents (Unit 14)
        $unit14Household = Household::updateOrCreate(
            ['primary_homeowner_id' => $unit14User->id],
            [
                'property_number' => 'Unit 14',
                'name' => 'Brown Residence',
                'address' => '14 Royal Palm Way',
                'notes' => 'Primary residence for Jonathan Brown & family',
            ]
        );

        HouseholdMember::updateOrCreate(
            ['household_id' => $unit14Household->id, 'name' => 'Brenda Brown'],
            [
                'role_in_household' => 'spouse',
                'relationship_label' => 'Spouse',
                'pass_category' => 'resident',
                'phone' => '(876) 555-0142',
                'email' => 'brenda.brown@abcholdings-resident.net',
                'status' => 'active',
            ]
        );

        // B. Renters (Unit 22: Long-Term Tenant; Unit 14: Short-term Guest)
        $tenantUser = User::updateOrCreate(
            ['email' => 'keith.reynolds@residence.net'],
            [
                'name' => 'Keith Reynolds',
                'display_name' => 'Keith Reynolds',
                'role' => UserRole::TemporaryHomeowner,
                'password' => $password,
                'phone' => '(876) 555-0221',
                'lot' => 'Unit 22',
                'street' => 'Royal Palm Way',
                'status' => 'Active',
            ]
        );

        Renter::updateOrCreate(
            ['property_id' => $unit22->id, 'name' => 'Keith Reynolds'],
            [
                'user_id' => $tenantUser->id,
                'homeowner_id' => $janeUser->id,
                'contact' => 'keith.reynolds@residence.net / (876) 555-0221',
                'lot' => 'Unit 22',
                'street' => 'Royal Palm Way',
                'stay_type' => 'Long-term',
                'status' => 'Active',
                'lease_start' => Carbon::parse('2026-01-01'),
                'lease_end' => Carbon::parse('2026-12-31'),
                'notes' => 'Corporate lease agreement, payment executed via direct bank wire.',
            ]
        );

        Renter::updateOrCreate(
            ['property_id' => $unit14->id, 'name' => 'Marcus Sterling'],
            [
                'homeowner_id' => $unit14User->id,
                'contact' => 'marcus.sterling@airbnb-guest.com / (876) 555-0914',
                'lot' => 'Unit 14',
                'street' => 'Royal Palm Way',
                'stay_type' => 'Short-term',
                'status' => 'Active',
                'lease_start' => Carbon::now()->subDays(2),
                'lease_end' => Carbon::now()->addDays(5),
                'notes' => 'Executive short stay visitor clearance.',
            ]
        );

        // C. Visitors (Unit 14 & Unit 22)
        Visitor::updateOrCreate(
            ['name' => 'David Williams', 'property_id' => $unit14->id],
            [
                'type' => 'One-time',
                'status' => VisitorStatus::CheckedIn,
                'expected_at' => Carbon::now()->setTime(10, 30),
                'checked_in_at' => Carbon::now()->setTime(10, 35),
                'date_range' => Carbon::now()->format('Y-m-d'),
                'homeowner_id' => $unit14User->id,
                'homeowner_name' => 'Jonathan Brown (Unit 14)',
                'is_blocked' => false,
            ]
        );

        Visitor::updateOrCreate(
            ['name' => 'Sarah Jenkins', 'property_id' => $unit22->id],
            [
                'type' => 'Delivery',
                'status' => VisitorStatus::Expected,
                'expected_at' => Carbon::now()->setTime(15, 00),
                'date_range' => Carbon::now()->format('Y-m-d'),
                'homeowner_id' => $tenantUser->id,
                'homeowner_name' => 'Keith Reynolds (Unit 22)',
                'is_blocked' => false,
            ]
        );

        // D. Staff (Unit 14 & Unit 22)
        DelegatedAccess::updateOrCreate(
            ['name' => 'Patricia Myers', 'property_id' => $unit14->id],
            [
                'grantor_user_id' => $unit14User->id,
                'email' => 'patricia.myers@staff.net',
                'phone' => '(876) 555-7140',
                'relationship' => 'Housekeeper',
                'access_level' => 'Domestic Staff',
                'authorization_type' => 'household_staff',
                'duration_type' => 'recurring',
                'status' => 'active',
                'access_rules' => [
                    'days_of_week' => ['Monday', 'Wednesday', 'Friday'],
                    'time_window_start' => '08:00',
                    'time_window_end' => '17:00',
                ],
            ]
        );

        DelegatedAccess::updateOrCreate(
            ['name' => 'Samuel Green', 'property_id' => $unit22->id],
            [
                'grantor_user_id' => $tenantUser->id,
                'email' => 'samuel.green@estatecare.net',
                'phone' => '(876) 555-7220',
                'relationship' => 'Groundskeeper',
                'access_level' => 'Domestic Staff',
                'authorization_type' => 'estate_care',
                'duration_type' => 'recurring',
                'status' => 'active',
                'access_rules' => [
                    'days_of_week' => ['Tuesday', 'Thursday'],
                    'time_window_start' => '07:30',
                    'time_window_end' => '16:00',
                ],
            ]
        );

        // E. Contractors (Unit 14 & Unit 18)
        DelegatedAccess::updateOrCreate(
            ['name' => 'Apex Electrical Solutions', 'property_id' => $unit14->id],
            [
                'grantor_user_id' => $unit14User->id,
                'email' => 'service@apexelectrical.com',
                'phone' => '(876) 555-9140',
                'relationship' => 'Contractor',
                'access_level' => 'Contractor',
                'authorization_type' => 'vendor_work_order',
                'duration_type' => 'custom',
                'status' => 'active',
                'starts_at' => Carbon::now()->subDays(1),
                'expires_at' => Carbon::now()->addDays(7),
                'security_notes' => 'Main panel electrical upgrade & smart breaker installation.',
            ]
        );

        DelegatedAccess::updateOrCreate(
            ['name' => 'Island Plumbing Pro', 'property_id' => $unit18->id],
            [
                'grantor_user_id' => $janeUser->id,
                'email' => 'dispatch@islandplumbing.com',
                'phone' => '(876) 555-9180',
                'relationship' => 'Contractor',
                'access_level' => 'Contractor',
                'authorization_type' => 'preventive_maintenance',
                'duration_type' => 'custom',
                'status' => 'active',
                'starts_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addDays(14),
                'security_notes' => 'Pre-occupancy plumbing audit and water pressure certification.',
            ]
        );

        // F. Legacy Contacts (Unit 14 & Unit 22)
        DelegatedAccess::updateOrCreate(
            ['name' => 'Robert Brown', 'property_id' => $unit14->id],
            [
                'grantor_user_id' => $unit14User->id,
                'email' => 'robert.brown@continuity.org',
                'phone' => '(876) 555-0014',
                'relationship' => 'Family member',
                'access_level' => 'Legacy Delegate',
                'authorization_type' => 'legacy_contact',
                'duration_type' => 'manual_revocation',
                'status' => 'active',
                'security_notes' => 'Designated emergency succession delegate.',
            ]
        );

        // 5. OPERATIONS DATA

        // A. Vehicles
        Vehicle::updateOrCreate(
            ['license_plate' => '8842-JA'],
            [
                'user_id' => $unit14User->id,
                'property' => 'Unit 14',
                'jurisdiction' => 'Jamaica',
                'make' => 'Toyota',
                'model' => 'RAV4 Hybrid',
                'color' => 'Pearl White',
                'year' => 2024,
                'parking_location' => 'Unit 14 — Bay A',
                'is_ev' => false,
                'is_temporary' => false,
                'anpr_enabled' => true,
                'status' => 'active',
            ]
        );

        Vehicle::updateOrCreate(
            ['license_plate' => 'EV-2201'],
            [
                'user_id' => $tenantUser->id,
                'property' => 'Unit 22',
                'jurisdiction' => 'Jamaica',
                'make' => 'Tesla',
                'model' => 'Model Y Long Range',
                'color' => 'Midnight Silver',
                'year' => 2025,
                'parking_location' => 'Unit 22 — Bay A',
                'is_ev' => true,
                'is_temporary' => false,
                'anpr_enabled' => true,
                'status' => 'active',
            ]
        );

        Vehicle::updateOrCreate(
            ['license_plate' => 'PM-1800'],
            [
                'user_id' => $markUser->id,
                'property' => 'Unit 18',
                'jurisdiction' => 'Jamaica',
                'make' => 'Ford',
                'model' => 'Transit Custom',
                'color' => 'Oxford White',
                'year' => 2023,
                'parking_location' => 'Unit 18 — Maintenance Bay',
                'is_ev' => false,
                'is_temporary' => true,
                'anpr_enabled' => true,
                'status' => 'active',
            ]
        );

        // B. Parking Passes
        ParkingPass::updateOrCreate(
            ['pass_id' => 'PK-RES-0014'],
            [
                'user_id' => $unit14User->id,
                'category' => ParkingPass::CATEGORY_RESIDENT,
                'license_plate' => '8842-JA',
                'property' => 'Unit 14',
                'assigned_bay' => 'Bay 14-A',
                'holder_name' => 'Jonathan Brown',
                'valid_from' => Carbon::now()->subMonths(6),
                'valid_until' => Carbon::now()->addYear(),
                'status' => 'active',
            ]
        );

        ParkingPass::updateOrCreate(
            ['pass_id' => 'PK-RES-0022'],
            [
                'user_id' => $tenantUser->id,
                'category' => ParkingPass::CATEGORY_RESIDENT,
                'license_plate' => 'EV-2201',
                'property' => 'Unit 22',
                'assigned_bay' => 'Bay 22-A (EV Dedicated)',
                'holder_name' => 'Keith Reynolds',
                'valid_from' => Carbon::now()->subMonths(3),
                'valid_until' => Carbon::now()->addMonths(9),
                'status' => 'active',
                'metadata' => ['ev_charging_enabled' => true],
            ]
        );

        ParkingPass::updateOrCreate(
            ['pass_id' => 'PK-CON-0018'],
            [
                'user_id' => $markUser->id,
                'category' => ParkingPass::CATEGORY_CONTRACTOR,
                'license_plate' => 'PM-1800',
                'property' => 'Unit 18',
                'assigned_bay' => 'Bay 18-A',
                'holder_name' => 'Mark Vance (Turnover Team)',
                'valid_from' => Carbon::now(),
                'valid_until' => Carbon::now()->addDays(30),
                'status' => 'active',
            ]
        );

        // C. Credentials / Gate Passes
        GatePass::updateOrCreate(
            ['pass_id' => 'GP-RES-0014'],
            [
                'user_id' => $unit14User->id,
                'category' => PassCategory::Homeowner,
                'holder_name' => 'Jonathan Brown',
                'property' => 'Unit 14',
                'access_zone' => 'Full Estate Access',
                'designated_gate' => GateId::Gate01,
                'valid_from' => Carbon::now()->subYear(),
                'valid_until' => Carbon::now()->addYear(),
                'status' => PassStatus::Active,
                'metadata' => ['credential_type' => 'Digital NFC + QR Card'],
            ]
        );

        GatePass::updateOrCreate(
            ['pass_id' => 'GP-TEN-0022'],
            [
                'user_id' => $tenantUser->id,
                'category' => PassCategory::Renter,
                'holder_name' => 'Keith Reynolds',
                'property' => 'Unit 22',
                'access_zone' => 'Residential Quarters & Amenities',
                'designated_gate' => GateId::Gate01,
                'valid_from' => Carbon::now()->subMonths(3),
                'valid_until' => Carbon::now()->addMonths(9),
                'status' => PassStatus::Active,
                'metadata' => ['credential_type' => 'Apple Wallet / Google Wallet NFC'],
            ]
        );

        // D. Incidents
        AccessRiskIncident::updateOrCreate(
            ['title' => 'Unit 14 Gate Sensor Verification'],
            [
                'user_id' => $unit14User->id,
                'pass_id' => 'GP-RES-0014',
                'gate' => 'GATE-01',
                'flag_type' => 'guest_clearance',
                'risk_level' => 'LOW',
                'description' => 'Late night authorized guest arrival cleared following two-way radio confirmation.',
                'status' => 'RESOLVED',
                'resolved_by' => $janeUser->id,
                'resolution_notes' => 'Resident confirmed arrival of David Williams.',
                'occurred_at' => Carbon::now()->subDays(2),
            ]
        );

        AccessRiskIncident::updateOrCreate(
            ['title' => 'Unit 18 Vacant Property Perimeter Inspection'],
            [
                'user_id' => $markUser->id,
                'gate' => 'GATE-02',
                'flag_type' => 'audit_check',
                'risk_level' => 'LOW',
                'description' => 'Routine weekly vacant unit audit conducted by property operations team.',
                'status' => 'RESOLVED',
                'resolved_by' => $markUser->id,
                'resolution_notes' => 'Locks, utility meters, and smart sensors verified fully secure.',
                'occurred_at' => Carbon::now()->subDay(),
            ]
        );

        // E. Payments & Invoices
        Invoice::updateOrCreate(
            ['reference' => 'INV-ABC-2026-1401'],
            [
                'user_id' => $janeUser->id,
                'property_id' => $unit14->id,
                'amount_minor' => 35000, // $350.00
                'currency' => 'USD',
                'period_start' => Carbon::now()->startOfMonth(),
                'period_end' => Carbon::now()->endOfMonth(),
                'due_on' => Carbon::now()->addDays(15),
                'status' => 'paid',
                'paid_at' => Carbon::now()->subDays(3),
            ]
        );

        Invoice::updateOrCreate(
            ['reference' => 'INV-ABC-2026-1801'],
            [
                'user_id' => $janeUser->id,
                'property_id' => $unit18->id,
                'amount_minor' => 15000, // $150.00
                'currency' => 'USD',
                'period_start' => Carbon::now()->startOfMonth(),
                'period_end' => Carbon::now()->endOfMonth(),
                'due_on' => Carbon::now()->addDays(15),
                'status' => 'paid',
                'paid_at' => Carbon::now()->subDays(4),
            ]
        );

        Invoice::updateOrCreate(
            ['reference' => 'INV-ABC-2026-2201'],
            [
                'user_id' => $janeUser->id,
                'property_id' => $unit22->id,
                'amount_minor' => 35000, // $350.00
                'currency' => 'USD',
                'period_start' => Carbon::now()->startOfMonth(),
                'period_end' => Carbon::now()->endOfMonth(),
                'due_on' => Carbon::now()->addDays(5),
                'status' => 'pending',
                'paid_at' => null,
            ]
        );

        // F. Legal & Corporate Documents
        AuthorizationDocument::updateOrCreate(
            ['title' => 'Certificate of Incorporation — ABC Holdings Limited'],
            [
                'ownership_entity_id' => $company->id,
                'document_type' => 'certificate_of_incorporation',
                'holder_name' => 'ABC Holdings Limited',
                'file_name' => 'Certificate_of_Incorporation_ABC_Holdings.pdf',
                'file_path' => 'documents/corporate/cert_inc_abc.pdf',
                'file_size_bytes' => 1420500,
                'mime_type' => 'application/pdf',
                'issued_at' => Carbon::parse('2018-04-12'),
                'expires_at' => Carbon::now()->addYears(20),
                'status' => 'active',
                'verification_status' => 'verified',
                'verified_at' => Carbon::now()->subMonths(12),
            ]
        );

        AuthorizationDocument::updateOrCreate(
            ['title' => 'Articles of Incorporation & Corporate Bylaws'],
            [
                'ownership_entity_id' => $company->id,
                'document_type' => 'articles_of_incorporation',
                'holder_name' => 'ABC Holdings Limited',
                'file_name' => 'Articles_of_Incorporation_ABC.pdf',
                'file_path' => 'documents/corporate/articles_inc_abc.pdf',
                'file_size_bytes' => 2840000,
                'mime_type' => 'application/pdf',
                'issued_at' => Carbon::parse('2018-04-15'),
                'expires_at' => Carbon::now()->addYears(20),
                'status' => 'active',
                'verification_status' => 'verified',
                'verified_at' => Carbon::now()->subMonths(12),
            ]
        );

        AuthorizationDocument::updateOrCreate(
            ['title' => 'Board Resolution: Appointment of Property Officers'],
            [
                'ownership_entity_id' => $company->id,
                'document_type' => 'board_resolution',
                'holder_name' => 'Jane Sterling & Mark Vance',
                'file_name' => 'Board_Resolution_Officers_2026.pdf',
                'file_path' => 'documents/corporate/board_resolution_2026.pdf',
                'file_size_bytes' => 845000,
                'mime_type' => 'application/pdf',
                'issued_at' => Carbon::parse('2026-01-10'),
                'expires_at' => Carbon::now()->addYears(5),
                'status' => 'active',
                'verification_status' => 'verified',
                'verified_at' => Carbon::now()->subMonths(6),
            ]
        );

        AuthorizationDocument::updateOrCreate(
            ['title' => 'Unit 14 Registered Certificate of Title (Volume 1408 / Folio 22)'],
            [
                'ownership_entity_id' => $company->id,
                'document_type' => 'certificate_of_title',
                'holder_name' => 'ABC Holdings Limited',
                'file_name' => 'Title_Deed_Unit_14.pdf',
                'file_path' => 'documents/titles/title_unit_14.pdf',
                'file_size_bytes' => 3120000,
                'mime_type' => 'application/pdf',
                'issued_at' => Carbon::parse('2019-02-18'),
                'expires_at' => Carbon::now()->addYears(50),
                'status' => 'active',
                'verification_status' => 'verified',
                'verified_at' => Carbon::now()->subMonths(10),
            ]
        );

        AuthorizationDocument::updateOrCreate(
            ['title' => 'Unit 18 Registered Certificate of Title (Volume 1408 / Folio 26)'],
            [
                'ownership_entity_id' => $company->id,
                'document_type' => 'certificate_of_title',
                'holder_name' => 'ABC Holdings Limited',
                'file_name' => 'Title_Deed_Unit_18.pdf',
                'file_path' => 'documents/titles/title_unit_18.pdf',
                'file_size_bytes' => 3080000,
                'mime_type' => 'application/pdf',
                'issued_at' => Carbon::parse('2019-02-18'),
                'expires_at' => Carbon::now()->addYears(50),
                'status' => 'active',
                'verification_status' => 'verified',
                'verified_at' => Carbon::now()->subMonths(10),
            ]
        );

        AuthorizationDocument::updateOrCreate(
            ['title' => 'Unit 22 Registered Certificate of Title (Volume 1408 / Folio 30)'],
            [
                'ownership_entity_id' => $company->id,
                'document_type' => 'certificate_of_title',
                'holder_name' => 'ABC Holdings Limited',
                'file_name' => 'Title_Deed_Unit_22.pdf',
                'file_path' => 'documents/titles/title_unit_22.pdf',
                'file_size_bytes' => 3240000,
                'mime_type' => 'application/pdf',
                'issued_at' => Carbon::parse('2019-02-18'),
                'expires_at' => Carbon::now()->addYears(50),
                'status' => 'active',
                'verification_status' => 'verified',
                'verified_at' => Carbon::now()->subMonths(10),
            ]
        );

        AuthorizationDocument::updateOrCreate(
            ['title' => 'Unit 22 Residential Lease Agreement (Keith Reynolds)'],
            [
                'ownership_entity_id' => $company->id,
                'document_type' => 'lease_agreement',
                'holder_name' => 'Keith Reynolds',
                'file_name' => 'Lease_Agreement_Unit_22_Reynolds.pdf',
                'file_path' => 'documents/leases/lease_unit_22.pdf',
                'file_size_bytes' => 1950000,
                'mime_type' => 'application/pdf',
                'issued_at' => Carbon::parse('2026-01-01'),
                'expires_at' => Carbon::parse('2026-12-31'),
                'status' => 'active',
                'verification_status' => 'verified',
                'verified_at' => Carbon::now()->subMonths(9),
            ]
        );

        // 3. Verified Trust Entity: Fairview Estate Family Trust
        $trust = OwnershipEntity::updateOrCreate(
            ['legal_name' => 'The Fairview Estate Family Trust'],
            [
                'community_id' => $community?->id,
                'entity_type' => OwnershipEntityType::Trust,
                'registration_number' => 'TRUST-JA-2021-994',
                'jurisdiction' => 'Jamaica',
                'primary_email' => 'trustees@fairviewtrust.org',
                'primary_phone' => '(876) 555-4420',
                'billing_address' => '12 Hope Road, Kingston 6, Jamaica',
                'status' => 'active',
                'verification_status' => 'verified',
                'verified_at' => Carbon::now()->subMonths(8),
                'verification_source' => 'Trust Deed & Trustee Authorization Registry',
                'verification_notes' => 'Verified with stamped Trust Deed (TR-2021-994) and Trustee Appointment Resolution.',
                'verification_checklist' => [
                    'trust_deed_inspected' => true,
                    'deed_reference_verified' => true,
                    'trustees_identified_and_authorized' => true,
                    'trustee_resolution_verified' => true,
                    'beneficiaries_recorded' => true,
                ],
                'metadata' => [
                    'trust_verification' => [
                        'deed_reference_number' => 'TR-2021-994',
                        'settlement_date' => '2021-03-15',
                        'governing_law' => 'Jamaica',
                        'trustees' => [
                            ['name' => 'Marcus Alexander Sterling', 'role' => 'Managing Trustee'],
                            ['name' => 'National Commercial Trust Limited', 'role' => 'Corporate Trustee'],
                        ],
                        'beneficiaries' => [
                            ['name' => 'Chloe Sterling', 'type' => 'Primary Beneficiary'],
                            ['name' => 'Oliver Sterling', 'type' => 'Primary Beneficiary'],
                        ],
                    ],
                ],
            ]
        );

        AuthorizationDocument::updateOrCreate(
            ['title' => 'Fairview Trust Deed of Settlement (Stamp Duty Stamped)'],
            [
                'ownership_entity_id' => $trust->id,
                'document_type' => 'trust_deed',
                'holder_name' => 'The Fairview Estate Family Trust',
                'file_name' => 'Trust_Deed_Settlement_Fairview.pdf',
                'file_path' => 'documents/trust/trust_deed_fairview.pdf',
                'file_size_bytes' => 4500000,
                'mime_type' => 'application/pdf',
                'issued_at' => Carbon::parse('2021-03-15'),
                'expires_at' => Carbon::now()->addYears(30),
                'status' => 'active',
                'verification_status' => 'verified',
                'verified_at' => Carbon::now()->subMonths(8),
            ]
        );

        // 4. Verified Estate Entity: Estate of Late Robert Sterling
        $estate = OwnershipEntity::updateOrCreate(
            ['legal_name' => 'Estate of Late Robert Sterling'],
            [
                'community_id' => $community?->id,
                'entity_type' => OwnershipEntityType::Estate,
                'registration_number' => 'PROB-2023-0182',
                'jurisdiction' => 'Jamaica',
                'primary_email' => 'probate@sterlingestate.org',
                'primary_phone' => '(876) 555-7731',
                'billing_address' => 'Chambers of Myers Fletcher & Gordon, 21 East Street, Kingston, Jamaica',
                'status' => 'active',
                'verification_status' => 'verified',
                'verified_at' => Carbon::now()->subMonths(5),
                'verification_source' => 'Supreme Court Probate & Administration Division',
                'verification_notes' => 'Verified with Supreme Court Grant of Probate Suit #PROB-2023-0182 and RGD Death Certificate.',
                'verification_checklist' => [
                    'grant_of_probate_or_administration_verified' => true,
                    'death_certificate_verified' => true,
                    'executors_or_administrators_authorized' => true,
                    'court_jurisdiction_verified' => true,
                ],
                'metadata' => [
                    'estate_verification' => [
                        'deceased_name' => 'Robert Alexander Sterling',
                        'date_of_death' => '2022-11-04',
                        'probate_suit_number' => 'PROB-2023-0182',
                        'court_jurisdiction' => 'Supreme Court of Jamaica (Probate Division)',
                        'executors' => [
                            ['name' => 'Jane Elizabeth Sterling', 'role' => 'Sole Executrix under Will'],
                        ],
                    ],
                ],
            ]
        );

        AuthorizationDocument::updateOrCreate(
            ['title' => 'Supreme Court Grant of Probate (Will of Robert Sterling)'],
            [
                'ownership_entity_id' => $estate->id,
                'document_type' => 'grant_of_probate',
                'holder_name' => 'Estate of Late Robert Sterling',
                'file_name' => 'Grant_of_Probate_Robert_Sterling.pdf',
                'file_path' => 'documents/estate/grant_of_probate_sterling.pdf',
                'file_size_bytes' => 2200000,
                'mime_type' => 'application/pdf',
                'issued_at' => Carbon::parse('2023-05-20'),
                'expires_at' => Carbon::now()->addYears(20),
                'status' => 'active',
                'verification_status' => 'verified',
                'verified_at' => Carbon::now()->subMonths(5),
            ]
        );
    }
}
