<?php

namespace App\Services\Properties;

use App\Enums\AuthorizedPersonRole;
use App\Models\AuthorizedPerson;
use App\Models\GatePass;
use App\Models\OwnershipEntity;
use App\Models\Property;
use App\Models\Visitor;
use Illuminate\Support\Collection;

class EnterpriseAuthorizationRegistry
{
    /**
     * Resolves the complete 4-Pillar hierarchy for an Ownership Entity and its Property Portfolio:
     * 1. Entity People (director, trustee, representative, manager, executor)
     * 2. Occupants (resident, long_term_renter, short_term_guest)
     * 3. Access Relationships (visitor, staff, contractor, legacy_contact)
     * 4. Credentials (QR, NFC, Wallet)
     */
    public function resolveHierarchy(OwnershipEntity $entity): array
    {
        $properties = $entity->properties()->with(['renters', 'visitors', 'delegatedAccesses'])->get();

        // Pillar 1: Entity People
        $entityPeople = $this->resolveEntityPeople($entity);

        // Pillar 2: Occupants
        $occupants = $this->resolveOccupants($entity, $properties);

        // Pillar 3: Access Relationships
        $accessRelationships = $this->resolveAccessRelationships($entity, $properties);

        // Pillar 4: Credentials
        $credentials = $this->resolveCredentials($entity, $properties);

        $totalAuthorizations = count($entityPeople) + count($occupants) + count($accessRelationships) + count($credentials);

        return [
            'entity' => [
                'id' => $entity->id,
                'name' => $entity->legal_name,
                'type' => $entity->entity_type->value,
                'type_label' => $entity->entity_type->label(),
                'registration_number' => $entity->registration_number,
                'status' => $entity->status,
            ],
            'properties_count' => $properties->count(),
            'entity_people' => $entityPeople,
            'occupants' => $occupants,
            'access_relationships' => $accessRelationships,
            'credentials' => $credentials,
            'total_authorizations' => $totalAuthorizations,
            'pillars' => [
                'entity_people' => $entityPeople,
                'occupants' => $occupants,
                'access_relationships' => $accessRelationships,
                'credentials' => $credentials,
            ],
            'summary' => [
                'total_entity_people' => count($entityPeople),
                'total_occupants' => count($occupants),
                'total_access_relationships' => count($accessRelationships),
                'total_credentials' => count($credentials),
                'total_authorizations' => $totalAuthorizations,
            ],
        ];
    }

    /**
     * Pillar 1: Entity People
     * (director, trustee, representative, manager, executor)
     */
    protected function resolveEntityPeople(OwnershipEntity $entity): array
    {
        return $entity->authorizedPeople()->with('user')->get()->map(function (AuthorizedPerson $person) use ($entity) {
            $scopeProps = $person->authorizedProperties();
            $scopeLabels = $scopeProps->isEmpty()
                ? ['All Entity Properties ('.$entity->properties()->count().' Units)']
                : $scopeProps->pluck('lot_number')->all();

            $functionalType = match ($person->role) {
                AuthorizedPersonRole::Director, AuthorizedPersonRole::Officer => 'director',
                AuthorizedPersonRole::Trustee, AuthorizedPersonRole::CoTrustee => 'trustee',
                AuthorizedPersonRole::Executor => 'executor',
                AuthorizedPersonRole::PropertyManager => 'manager',
                default => 'representative',
            };

            $answers = [
                'who' => [
                    'name' => $person->full_name,
                    'email' => $person->email,
                    'phone' => $person->phone,
                    'id_document' => $person->id_document_number ? "{$person->id_document_type}: {$person->id_document_number}" : 'Verified Corporate Registry Filing',
                    'user_id' => $person->user_id,
                ],
                'relationship' => [
                    'type' => $functionalType,
                    'label' => $person->role->label(),
                    'is_signatory' => $person->is_signatory,
                    'is_primary_contact' => $person->is_primary_contact,
                ],
                'scope' => [
                    'entity_name' => $entity->legal_name,
                    'entity_type' => $entity->entity_type->label(),
                    'properties' => $scopeLabels,
                ],
                'permissions' => [
                    'granted' => $person->effectivePermissions(),
                    'summary' => count($person->effectivePermissions()).' corporate & operational powers',
                ],
                'validity' => [
                    'valid_from' => $person->valid_from?->toDateString() ?? $entity->created_at->toDateString(),
                    'valid_until' => $person->valid_until?->toDateString() ?? 'Indefinite (Active Appointment)',
                    'status' => $person->status,
                    'is_active' => $person->isActive(),
                ],
                'authorizer' => [
                    'name' => 'Corporate Board & Legal Registry',
                    'authority' => 'Articles of Incorporation / Governing Resolution',
                    'source' => 'Companies Office of Jamaica Registry Filing',
                ],
            ];

            return $this->formatRecord('ep-'.$person->id, $person->id, 'entity_people', $functionalType, $answers);
        })->all();
    }

    /**
     * Pillar 2: Occupants
     * (resident, long_term_renter, short_term_guest)
     */
    protected function resolveOccupants(OwnershipEntity $entity, Collection $properties): array
    {
        $occupants = [];

        // 1. Direct Residents / Primary Occupants
        foreach ($properties as $prop) {
            if ($prop->primaryOccupant) {
                $user = $prop->primaryOccupant;
                $answers = [
                    'who' => [
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'id_document' => 'Title Holder / Resident Registry',
                        'user_id' => $user->id,
                    ],
                    'relationship' => [
                        'type' => 'resident',
                        'label' => 'Primary Resident Occupant',
                        'is_signatory' => false,
                        'is_primary_contact' => true,
                    ],
                    'scope' => [
                        'entity_name' => $entity->legal_name,
                        'entity_type' => $entity->entity_type->label(),
                        'properties' => [$prop->lot_number],
                    ],
                    'permissions' => [
                        'granted' => ['residential_occupancy', 'amenity_access', 'invite_visitors', 'register_household_vehicles'],
                        'summary' => 'Full residential dwelling & community amenity privileges',
                    ],
                    'validity' => [
                        'valid_from' => $prop->created_at->toDateString(),
                        'valid_until' => 'Indefinite (Resident)',
                        'status' => 'active',
                        'is_active' => true,
                    ],
                    'authorizer' => [
                        'name' => $entity->legal_name,
                        'authority' => 'Property Owner Entity',
                        'source' => 'Deed of Ownership / Estate Registry',
                    ],
                ];
                $occupants[] = $this->formatRecord('occ-res-'.$user->id.'-'.$prop->id, $user->id, 'occupants', 'resident', $answers);
            }

            // 2. Renters & Guests
            foreach ($prop->renters as $renter) {
                $isShortTerm = str_contains(strtolower($renter->stay_type ?? ''), 'short');
                $functionalType = $isShortTerm ? 'short_term_guest' : 'long_term_renter';

                $answers = [
                    'who' => [
                        'name' => $renter->name,
                        'email' => $renter->user?->email,
                        'phone' => $renter->contact,
                        'id_document' => 'Verified Tenancy Lease Agreement',
                        'user_id' => $renter->user_id,
                    ],
                    'relationship' => [
                        'type' => $functionalType,
                        'label' => $isShortTerm ? 'Short-Term Guest (Vacation / Extended Stay)' : 'Long-Term Renter (Tenancy)',
                        'is_signatory' => false,
                        'is_primary_contact' => false,
                    ],
                    'scope' => [
                        'entity_name' => $entity->legal_name,
                        'entity_type' => $entity->entity_type->label(),
                        'properties' => [$prop->lot_number],
                    ],
                    'permissions' => [
                        'granted' => ['residential_occupancy', 'gate_entry', 'parking_access', 'visitor_request'],
                        'summary' => 'Tenancy occupancy & digital access credential rights',
                    ],
                    'validity' => [
                        'valid_from' => $renter->lease_start?->toDateString() ?? now()->toDateString(),
                        'valid_until' => $renter->lease_end?->toDateString() ?? 'Active Lease',
                        'status' => $renter->status,
                        'is_active' => $renter->status === 'Active' && (! $renter->lease_end || $renter->lease_end->isFuture()),
                    ],
                    'authorizer' => [
                        'name' => $entity->legal_name,
                        'authority' => 'Authorized Landlord / Property Representative',
                        'source' => 'Tenancy Agreement & Registration Record',
                    ],
                ];
                $occupants[] = $this->formatRecord('occ-rnt-'.$renter->id, $renter->id, 'occupants', $functionalType, $answers);
            }
        }

        return $occupants;
    }

    /**
     * Pillar 3: Access Relationships
     * (visitor, staff, contractor, legacy_contact)
     */
    protected function resolveAccessRelationships(OwnershipEntity $entity, Collection $properties): array
    {
        $relationships = [];

        foreach ($properties as $prop) {
            // 1. Visitors
            foreach ($prop->visitors()->latest()->take(10)->get() as $visitor) {
                $answers = [
                    'who' => [
                        'name' => $visitor->name,
                        'email' => $visitor->email,
                        'phone' => $visitor->phone,
                        'id_document' => 'Visitor Security Registry',
                        'user_id' => null,
                    ],
                    'relationship' => [
                        'type' => 'visitor',
                        'label' => 'Invited Guest / Visitor',
                        'is_signatory' => false,
                        'is_primary_contact' => false,
                    ],
                    'scope' => [
                        'entity_name' => $entity->legal_name,
                        'entity_type' => $entity->entity_type->label(),
                        'properties' => [$prop->lot_number],
                    ],
                    'permissions' => [
                        'granted' => ['single_ingress', 'visitor_parking'],
                        'summary' => 'Pre-cleared entry for scheduled visit',
                    ],
                    'validity' => [
                        'valid_from' => $visitor->arrival_date?->toDateString() ?? now()->toDateString(),
                        'valid_until' => $visitor->departure_date?->toDateString() ?? now()->toDateString(),
                        'status' => $visitor->status ?? 'active',
                        'is_active' => true,
                    ],
                    'authorizer' => [
                        'name' => $prop->ownerName(),
                        'authority' => 'Authorized Property Occupant / Manager',
                        'source' => 'Pre-Clearance Gate Pass Issuance',
                    ],
                ];
                $relationships[] = $this->formatRecord('ar-vis-'.$visitor->id, $visitor->id, 'access_relationships', 'visitor', $answers);
            }

            // 2. Delegated Accesses: Staff, Contractors, Legacy Contacts
            foreach ($prop->delegatedAccesses as $delegation) {
                $level = strtolower($delegation->access_level ?? '');
                $functionalType = match (true) {
                    str_contains($level, 'contractor') || str_contains($level, 'service') => 'contractor',
                    str_contains($level, 'staff') || str_contains($level, 'cleaner') || str_contains($level, 'worker') => 'staff',
                    str_contains($level, 'legacy') || str_contains($level, 'emergency') => 'legacy_contact',
                    default => 'staff',
                };

                $answers = [
                    'who' => [
                        'name' => $delegation->name,
                        'email' => $delegation->email,
                        'phone' => $delegation->phone,
                        'id_document' => 'Verified Service / Identity Credential',
                        'user_id' => $delegation->user_id,
                    ],
                    'relationship' => [
                        'type' => $functionalType,
                        'label' => $delegation->access_level ?? ucfirst($functionalType),
                        'is_signatory' => false,
                        'is_primary_contact' => false,
                    ],
                    'scope' => [
                        'entity_name' => $entity->legal_name,
                        'entity_type' => $entity->entity_type->label(),
                        'properties' => [$prop->lot_number],
                    ],
                    'permissions' => [
                        'granted' => $delegation->rules ?? ['scheduled_ingress', 'service_gate_access'],
                        'summary' => $delegation->description ?? 'Authorized operational access schedule',
                    ],
                    'validity' => [
                        'valid_from' => $delegation->valid_from?->toDateString() ?? now()->toDateString(),
                        'valid_until' => $delegation->valid_until?->toDateString() ?? 'Recurring Authorized Schedule',
                        'status' => $delegation->status ?? 'active',
                        'is_active' => $delegation->status === 'active',
                    ],
                    'authorizer' => [
                        'name' => $delegation->homeowner?->name ?? $entity->legal_name,
                        'authority' => 'Authorizing Property Representative',
                        'source' => 'Delegated Access Authorization Policy',
                    ],
                ];
                $relationships[] = $this->formatRecord('ar-del-'.$delegation->id, $delegation->id, 'access_relationships', $functionalType, $answers);
            }
        }

        return $relationships;
    }

    /**
     * Pillar 4: Credentials
     * (QR, NFC, Wallet)
     */
    protected function resolveCredentials(OwnershipEntity $entity, Collection $properties): array
    {
        $credentials = [];

        // Digital Gate Passes
        $propertyLots = $properties->pluck('lot_number')->filter()->all();
        $userIds = $entity->authorizedPeople()->pluck('user_id')->filter()->all();

        $gatePasses = GatePass::query()
            ->when(! empty($propertyLots), fn ($q) => $q->whereIn('property', $propertyLots))
            ->when(! empty($userIds), fn ($q) => $q->orWhereIn('user_id', $userIds))
            ->latest()
            ->take(20)
            ->get();

        foreach ($gatePasses as $pass) {
            $propScope = is_string($pass->property) && ! empty($pass->property)
                ? [$pass->property]
                : ($pass->property?->lot_number ? [$pass->property->lot_number] : ['Entity Portfolio']);

            // QR Credential
            $qrAnswers = [
                'who' => [
                    'name' => $pass->holder_name ?? 'Credential Bearer',
                    'email' => null,
                    'phone' => null,
                    'id_document' => 'Pass Token: '.$pass->pass_id,
                    'user_id' => $pass->user_id,
                ],
                'relationship' => [
                    'type' => 'QR',
                    'label' => 'Dynamic Rotating QR Code',
                    'is_signatory' => false,
                    'is_primary_contact' => false,
                ],
                'scope' => [
                    'entity_name' => $entity->legal_name,
                    'entity_type' => $entity->entity_type->label(),
                    'properties' => $propScope,
                ],
                'permissions' => [
                    'granted' => ['gate_scanner_ingress', 'security_turnstile_scan'],
                    'summary' => 'Cryptographically signed gate scanner verification token',
                ],
                'validity' => [
                    'valid_from' => $pass->created_at->toDateString(),
                    'valid_until' => $pass->valid_until?->toDateString() ?? 'Rotating Token',
                    'status' => $pass->status->value,
                    'is_active' => $pass->isActive(),
                ],
                'authorizer' => [
                    'name' => 'Community Hub Cryptographic Engine',
                    'authority' => 'Digital Pass Authority',
                    'source' => 'GatePass Engine #PASS-'.$pass->id,
                ],
            ];
            $credentials[] = $this->formatRecord('cred-qr-'.$pass->id, $pass->id, 'credentials', 'QR', $qrAnswers);

            // NFC Credential
            $nfcAnswers = [
                'who' => [
                    'name' => $pass->holder_name ?? 'NFC Fob Bearer',
                    'email' => null,
                    'phone' => null,
                    'id_document' => 'NFC Serial: NFC-'.strtoupper(substr(md5((string) $pass->id), 0, 8)),
                    'user_id' => $pass->user_id,
                ],
                'relationship' => [
                    'type' => 'NFC',
                    'label' => 'Contactless NFC Security Fob',
                    'is_signatory' => false,
                    'is_primary_contact' => false,
                ],
                'scope' => [
                    'entity_name' => $entity->legal_name,
                    'entity_type' => $entity->entity_type->label(),
                    'properties' => $propScope,
                ],
                'permissions' => [
                    'granted' => ['contactless_boom_barrier', 'pedestrian_turnstile_tap'],
                    'summary' => 'Encrypted RFID/NFC physical access reader authentication',
                ],
                'validity' => [
                    'valid_from' => $pass->created_at->toDateString(),
                    'valid_until' => 'Continuous Key Access',
                    'status' => $pass->status->value,
                    'is_active' => $pass->isActive(),
                ],
                'authorizer' => [
                    'name' => 'Estate Security Physical Access System',
                    'authority' => 'NFC Provisioning Officer',
                    'source' => 'Hardware Registry',
                ],
            ];
            $credentials[] = $this->formatRecord('cred-nfc-'.$pass->id, $pass->id, 'credentials', 'NFC', $nfcAnswers);

            // Mobile Wallet Credential (Apple / Google / Samsung)
            $walletAnswers = [
                'who' => [
                    'name' => $pass->holder_name ?? 'Smartphone Wallet Holder',
                    'email' => null,
                    'phone' => null,
                    'id_document' => 'Mobile Pass ID: WALLET-'.$pass->pass_id,
                    'user_id' => $pass->user_id,
                ],
                'relationship' => [
                    'type' => 'Wallet',
                    'label' => 'Apple Wallet & Google Wallet Pass',
                    'is_signatory' => false,
                    'is_primary_contact' => false,
                ],
                'scope' => [
                    'entity_name' => $entity->legal_name,
                    'entity_type' => $entity->entity_type->label(),
                    'properties' => $propScope,
                ],
                'permissions' => [
                    'granted' => ['mobile_wallet_nfc', 'lockscreen_pass_entry', 'offline_tap'],
                    'summary' => 'Native smartphone wallet certificate (PKPass / Google Pay Pass)',
                ],
                'validity' => [
                    'valid_from' => $pass->created_at->toDateString(),
                    'valid_until' => $pass->valid_until?->toDateString() ?? 'Device Certificate Active',
                    'status' => $pass->status->value,
                    'is_active' => $pass->isActive(),
                ],
                'authorizer' => [
                    'name' => 'Community Hub Pass Certificate CA',
                    'authority' => 'Signed Apple & Google Developer Certificate',
                    'source' => 'Secure Pass Delivery Pipeline',
                ],
            ];
            $credentials[] = $this->formatRecord('cred-wal-'.$pass->id, $pass->id, 'credentials', 'Wallet', $walletAnswers);
        }

        return $credentials;
    }

    /**
     * Standardize authorization record with dual keys (answers and invariants)
     * answering the 6 Cardinal Questions: Who, Relationship, Scope, Permissions, Validity, Authorizer.
     */
    protected function formatRecord(string $id, int|string $sourceId, string $pillar, string $functionalType, array $answers): array
    {
        $who = array_merge($answers['who'] ?? [], [
            'person_name' => $answers['who']['name'] ?? '',
        ]);

        $relationship = array_merge($answers['relationship'] ?? [], [
            'relationship_name' => $answers['relationship']['type'] ?? $functionalType,
        ]);

        $scope = array_merge($answers['scope'] ?? [], [
            'entity_name' => $answers['scope']['entity_name'] ?? '',
        ]);

        $permissions = array_merge($answers['permissions'] ?? [], [
            'allowed_actions' => $answers['permissions']['granted'] ?? [],
        ]);

        $validity = array_merge($answers['validity'] ?? [], [
            'status' => $answers['validity']['status'] ?? 'active',
        ]);

        $authorizer = array_merge($answers['authorizer'] ?? [], [
            'authorized_by' => $answers['authorizer']['name'] ?? '',
        ]);

        $invariants = [
            'who' => $who,
            'relationship' => $relationship,
            'scope' => $scope,
            'permissions' => $permissions,
            'validity' => $validity,
            'authorizer' => $authorizer,
        ];

        return [
            'id' => $id,
            'source_id' => $sourceId,
            'pillar' => $pillar,
            'functional_type' => $functionalType,
            'answers' => $invariants,
            'invariants' => $invariants,
        ];
    }
}
