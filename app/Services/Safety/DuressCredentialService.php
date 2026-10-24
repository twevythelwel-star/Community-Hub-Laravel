<?php

namespace App\Services\Safety;

use App\Enums\GateId;
use App\Models\DistressAlert;
use App\Models\GatePass;
use App\Models\SafetyProfile;
use App\Models\User;

class DuressCredentialService
{
    public function __construct(
        private readonly DistressAccessContextResolver $contextResolver,
    ) {}

    public function hashPin(string $pin): string
    {
        return hash_hmac('sha256', 'duress-pin:'.$pin, (string) config('safety.duress_pin_key'));
    }

    /**
     * Sets the duress PIN. It must differ from the resident's own gate PIN,
     * and from every other resident's duress PIN, since the gate tells them
     * apart by the PIN alone.
     *
     * @throws \DomainException
     */
    public function setDuressPin(User $user, string $pin): void
    {
        if (! preg_match('/^\d{6}$/', $pin)) {
            throw new \DomainException('A duress PIN is six digits.');
        }

        $ownPins = $user->gatePasses()->whereNull('visitor_id')->get()->map->offline_pin;
        if ($ownPins->contains($pin)) {
            throw new \DomainException('Your duress PIN must be different from your normal gate PIN.');
        }

        $hash = $this->hashPin($pin);
        $profile = $this->contextResolver->profileFor($user);

        if (SafetyProfile::where('duress_pin_hash', $hash)->where('id', '!=', $profile->id)->exists()) {
            // Said plainly, without revealing whose it is.
            throw new \DomainException('That PIN is not available. Choose another.');
        }

        $profile->forceFill(['duress_pin_hash' => $hash])->save();
    }

    public function clearDuressPin(User $user): void
    {
        $this->contextResolver->profileFor($user)->forceFill(['duress_pin_hash' => null])->save();
    }

    /** The profile whose duress PIN this is, if any. */
    public function profileForDuressPin(string $pin): ?SafetyProfile
    {
        if (! preg_match('/^\d{6}$/', $pin)) {
            return null;
        }

        return SafetyProfile::with('user')->where('duress_pin_hash', $this->hashPin($pin))->first();
    }

    /** A new Safety Credential ID; the old one stops resolving. */
    public function rotateCredential(User $user): SafetyProfile
    {
        $profile = $this->contextResolver->profileFor($user);
        $profile->forceFill(['safety_credential_id' => SafetyProfile::newCredentialId()])->save();

        return $profile;
    }

    /** Capture verified scan details and registered vehicle descriptions for responders. */
    public function scanContext(GatePass $pass, GateId $gate, string $direction): array
    {
        $pass->loadMissing('user.vehicles');

        return [
            'gate' => $gate->value,
            'gate_pass_id' => $pass->id,
            'event_type' => $direction === 'out' ? 'exit_attempt' : 'entry_attempt',
            'event_at' => now(),
            'severity_level' => DistressAlert::SEVERITY_URGENT_ASSISTANCE,
            'credential_reference' => $pass->pass_id,
            'vehicle_context' => $pass->user?->vehicles->map(fn ($vehicle) => trim(implode(' ', array_filter([
                $vehicle->color,
                $vehicle->year,
                $vehicle->make,
                $vehicle->model,
                $vehicle->license_plate ? '('.$vehicle->formattedPlate().')' : null,
            ]))))->filter()->values()->all() ?? [],
        ];
    }
}
