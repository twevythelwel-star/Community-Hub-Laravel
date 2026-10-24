<?php

namespace App\Services\Safety;

use App\Enums\PassCategory;
use App\Enums\UserRole;
use App\Models\GatePass;
use App\Models\SafetyProfile;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use Illuminate\Support\Facades\Log;

class DistressAccessContextResolver
{
    public function profileFor(User $user): SafetyProfile
    {
        $holderType = $this->holderTypeForUser($user);
        $options = $this->configuredOptions($holderType);

        return SafetyProfile::firstOrCreate(
            ['user_id' => $user->id],
            [
                'silent_alerts_enabled' => true,
                'preferred_assistance' => $options[0] ?? 'security',
                'assistance_preferences' => $options,
                'allowed_assistance' => $options,
            ]
        );
    }

    /** @return list<string> */
    public function configuredOptions(string $holderType): array
    {
        $all = (array) config('safety.community_options.'.$holderType, config('safety.assistance_by_holder.'.$holderType, ['security']));
        $allowed = array_merge(SafetyProfile::ASSISTANCE_TYPES, ['emergency_services']);

        if (array_diff($all, $allowed)) {
            Log::channel('security')->error('Invalid configured assistance option', ['holder_type' => $holderType]);
            $all = array_values(array_intersect($all, $allowed));
        }

        return array_values(array_filter($all, fn ($type) => (in_array($type, SafetyProfile::ASSISTANCE_TYPES, true)
                || ($type === 'emergency_services' && config('safety.emergency_services_enabled', false)))
            && ($type !== 'security' || config('safety.guest_assistance_options.security', true))));
    }

    public function holderTypeForUser(User $user): string
    {
        if ($user->activeAuthorizedAppointments()->exists()) {
            return 'authorized_representative';
        }

        $pass = $user->gatePasses()
            ->whereNull('visitor_id')
            ->whereNull('delegated_access_id')
            ->active()
            ->latest('id')
            ->first();
        $passType = match ($pass?->category) {
            PassCategory::Homeowner => 'homeowner',
            PassCategory::Renter => 'renter',
            PassCategory::LongTermOccupant => 'long_term_occupant',
            PassCategory::HomeownerStaff => 'domestic_staff',
            PassCategory::Staff => 'staff',
            PassCategory::Security => 'security',
            default => null,
        };

        return $passType ?? match ($user->role) {
            UserRole::Homeowner, UserRole::Admin, UserRole::SystemAdmin => 'homeowner',
            UserRole::TemporaryHomeowner => 'renter',
            UserRole::Staff => 'staff',
            UserRole::Security => 'security',
        };
    }

    /** @return array{holderType:string,label:string,holderName:string,property:?string,assistanceOptions:list<string>,accountHolder:bool,isEntityRepresentative?:bool,capacity?:string,representativeRole?:?string,ownerEntity?:?string,ownerEntityType?:?string} */
    public function accessContextFor(User $user): array
    {
        $profile = $this->profileFor($user);
        $holderType = $this->holderTypeForUser($user);
        $options = $this->optionsForAccount($user, $profile, $holderType);
        if ($user->role->isOperational()) {
            $options = array_values(array_diff($options, ['police', 'medical', 'fire', 'contact']));
        }

        $repAppointment = $user->activeAuthorizedAppointments()->with('ownershipEntity')->first();
        $ownerEntity = $repAppointment?->ownershipEntity;

        return [
            'holderType' => $holderType,
            'label' => $user->display_name,
            'holderName' => $user->display_name,
            'property' => $user->propertyLabel(),
            'assistanceOptions' => $options,
            'accountHolder' => true,
            'isEntityRepresentative' => $repAppointment !== null,
            'capacity' => $repAppointment ? 'Authorized Representative' : $user->role->label(),
            'representativeRole' => $repAppointment?->role->label(),
            'ownerEntity' => $ownerEntity?->legal_name,
            'ownerEntityType' => $ownerEntity?->entity_type?->label(),
        ];
    }

    public function accessContextForPass(GatePass $pass): ?array
    {
        $context = $this->guestContextForPass($pass);
        if (! $context) {
            return null;
        }

        if (($context['user'] ?? null) instanceof User) {
            $profile = $this->profileFor($context['user']);
            $context['assistanceOptions'] = $this->optionsForAccount($context['user'], $profile, $context['holderType']);
        }

        return $context;
    }

    /** Validate requested help against both the credential class and its own profile choices. */
    public function allowsAssistance(User $user, string $assistance): bool
    {
        return in_array($assistance, $this->optionsForUser($user), true);
    }

    /** @return list<string> */
    public function optionsForUser(User $user): array
    {
        return $this->accessContextFor($user)['assistanceOptions'];
    }

    public function optionsForAccount(User $user, SafetyProfile $profile, ?string $holderType = null): array
    {
        $holderType ??= $this->holderTypeForUser($user);
        $options = $this->configuredOptions($holderType);
        $preferences = $profile->allowed_assistance ?: $profile->assistance_preferences ?: $options;

        return array_values(array_intersect($options, $preferences));
    }

    /** Resolve an active, signed pass to a narrowly scoped guest credential. */
    public function guestContextForPass(GatePass $pass): ?array
    {
        if (! $pass->isActive() || ! $pass->isWithinValidity(now())) {
            return null;
        }

        $category = $pass->category instanceof PassCategory ? $pass->category->value : (string) $pass->category;
        $metadata = $pass->metadata ?? [];
        $holderType = match (true) {
            $category === PassCategory::Renter->value => 'renter',
            $category === PassCategory::LongTermOccupant->value => 'long_term_occupant',
            $category === PassCategory::Homeowner->value => 'homeowner',
            $category === PassCategory::Contractor->value => 'contractor',
            $category === PassCategory::HomeownerStaff->value => 'domestic_staff',
            $category === PassCategory::Staff->value => 'staff',
            $category === PassCategory::Security->value => 'security',
            $category === PassCategory::Delegate->value
                && in_array($metadata['authorization_type'] ?? null, ['legacy_contact', 'authorized_representative', 'caregiver', 'property_manager', 'family_member'], true) => 'legacy_contact',
            $category === PassCategory::Delegate->value => 'delegate',
            $category === PassCategory::Visitor->value => 'short_term_guest',
            default => null,
        };

        if ($holderType === null) {
            return null;
        }

        $visitor = $pass->visitor;
        $visitor?->loadMissing(['property', 'homeowner']);
        $accountHolder = $pass->visitor_id === null ? $pass->user : null;
        if ($pass->visitor_id !== null && (! $visitor || $visitor->is_blocked || $visitor->status->value === 'Checked Out' || $visitor->expired_at?->isPast())) {
            return null;
        }
        if ($pass->visitor_id === null && ! $accountHolder) {
            return null;
        }
        $options = $this->configuredOptions($holderType);
        if ($accountHolder) {
            $profile = $this->profileFor($accountHolder);
            $options = $this->optionsForAccount($accountHolder, $profile, $holderType);
        }
        if (! $options) {
            return null;
        }

        return [
            'holderType' => $holderType,
            'label' => match ($holderType) {
                'short_term_guest' => 'Short-term guest',
                'renter' => 'Renter',
                'legacy_contact' => 'Legacy contact',
                'domestic_staff' => 'Domestic staff',
                default => ucfirst(str_replace('_', ' ', $holderType)),
            },
            'holderName' => $pass->holder_name ?: ($visitor?->name ?: $accountHolder?->display_name),
            'property' => $pass->property ?: ($visitor?->property?->label() ?: ($visitor?->homeowner?->propertyLabel() ?: $accountHolder?->propertyLabel())),
            'assistanceOptions' => $options,
            'accountHolder' => $accountHolder !== null,
            'pass' => $pass,
            'visitor' => $visitor,
            'user' => $accountHolder,
        ];
    }

    /** Resolve a private guest-pass link to its currently valid visitor pass. */
    public function passForVisitorToken(string $token): ?GatePass
    {
        $visitor = Visitor::with(['gatePass', 'property', 'homeowner'])
            ->where('share_token', $token)
            ->first();
        $pass = $visitor?->gatePass;

        if (! $visitor || ! $pass || $visitor->is_blocked || $visitor->status->value === 'Checked Out'
            || $visitor->expired_at?->isPast() || ! $pass->isActive() || ! $pass->isWithinValidity(now())) {
            return null;
        }

        return $this->accessContextForPass($pass) ? $pass : null;
    }

    /** Resolve an authorized non-resident pass by its short-lived signed QR token. */
    public function passForCredentialToken(string $rawToken, GatePassEngine $engine): ?GatePass
    {
        $report = $engine->validate($rawToken, bypassReplayCheck: true);
        foreach (['cryptographicSignature', 'passRegistered', 'statusEligible', 'validityPeriod', 'notRevoked', 'personVerified'] as $check) {
            if (($report['checks'][$check] ?? false) !== true) {
                return null;
            }
        }

        $pass = GatePass::with(['user', 'visitor.property', 'visitor.homeowner'])
            ->where('pass_id', $report['passId'] ?? null)
            ->first();

        if (! $pass || $pass->user?->role->isResident() || $pass->user?->role->isAdministrative()) {
            return null;
        }

        if (! $this->accessContextForPass($pass)) {
            return null;
        }

        return $pass;
    }

    /** Resolve a currently usable guest or contractor pass without requiring a resident account. */
    public function guestProfile(GatePass $pass, array $context): SafetyProfile
    {
        if (! $context['assistanceOptions']) {
            throw new \DomainException('Safety assistance is not enabled for this credential type.');
        }

        $identity = $pass->visitor_id
            ? ['visitor_id' => $pass->visitor_id]
            : ($pass->user_id ? ['user_id' => $pass->user_id] : ['gate_pass_id' => $pass->id]);
        $defaults = [
            'user_id' => null,
            'emergency_contact_name' => null,
            'emergency_contact_phone' => null,
            'preferred_assistance' => $context['assistanceOptions'][0] ?? 'security',
            'allowed_assistance' => $context['assistanceOptions'],
            'assistance_preferences' => $context['assistanceOptions'],
            'silent_alerts_enabled' => true,
        ];
        if ($pass->visitor_id === null && $pass->user_id !== null) {
            $defaults['user_id'] = $pass->user_id;
        }

        return SafetyProfile::firstOrCreate($identity, $defaults);
    }
}
