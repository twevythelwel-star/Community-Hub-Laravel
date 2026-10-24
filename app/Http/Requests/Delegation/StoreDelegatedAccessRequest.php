<?php

namespace App\Http\Requests\Delegation;

use App\Models\DelegatedAccess;
use App\Services\AccessVisibilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class StoreDelegatedAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        return $user->role->isResident() || $user->role->isAdministrative();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'relationship' => ['required', 'string', 'max:100'],
            'authorization_type' => ['nullable', 'string'],
            'access_level' => ['required', 'string', 'in:'.implode(',', array_keys(DelegatedAccess::ACCESS_LEVELS))],
            'duration_type' => ['nullable', 'string', 'in:'.implode(',', array_keys(DelegatedAccess::DURATION_TYPES))],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'in:'.implode(',', array_keys(DelegatedAccess::PERMISSIONS))],
            'access_rules' => ['nullable', 'array'],
            'property_id' => ['nullable', 'exists:properties,id'],
            'activation_method' => ['nullable', 'string', 'in:immediate,emergency_trigger,incapacity_proof,admin_approval_required'],
            'starts_at' => ['nullable', 'date', 'after_or_equal:today'],
            'expires_at' => ['nullable', 'date', 'after:now', 'after_or_equal:starts_at'],
            'security_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function () {
            $user = $this->user();
            $propertyId = $this->input('property_id');

            if (! empty($propertyId) && ! $user->role->isAdministrative()) {
                if (! $user->properties()->whereKey($propertyId)->exists()) {
                    abort(403, 'You can only authorize people for your own property.');
                }
            }

            $visibilityService = app(AccessVisibilityService::class);
            $roleName = $visibilityService->resolveUserRole($user);
            $viewAs = strtolower((string) ($this->input('view_as') ?: $this->query('view_as', '')));
            $renterRecord = $user->renter ?? $user->activeStay();

            $isShortTermRenter = false;
            if ($renterRecord) {
                $stay = strtolower((string) ($renterRecord->stay_type ?? ''));
                $notes = strtolower((string) ($renterRecord->notes ?? ''));
                if (str_contains($stay, 'short') || str_contains($stay, 'airbnb') || str_contains($notes, 'airbnb') || str_contains($stay, 'vacation')) {
                    $isShortTermRenter = true;
                }
            }

            $isShortTerm = in_array($viewAs, ['short_term', 'airbnb', 'guest', 'vacation'], true)
                || $roleName === 'Short-Term Rental / Airbnb Host/Guest'
                || $roleName === 'Short-Term Renter'
                || $isShortTermRenter;

            $isRenter = ! $isShortTerm && ($roleName === 'Long-Term Renter' || $viewAs === 'renter');

            if ($isShortTerm) {
                if (($this->input('duration_type') ?? '') === 'manual_revocation') {
                    throw ValidationException::withMessages([
                        'duration_type' => 'Short-term guests cannot create unrestricted permanent credentials. All passes must have a temporary duration bounded by your reservation check-out.',
                    ]);
                }
                if (! in_array($this->input('access_level'), ['Visitor', 'Guest Pass', 'Day Visitor', 'Pre-Cleared Visitor'], true)) {
                    abort(403, 'Short-term guests can only register visitors for their reservation stay.');
                }
                if ($renterRecord && $renterRecord->lease_end) {
                    $inputExpires = ! empty($this->input('expires_at')) ? Carbon::parse($this->input('expires_at')) : null;
                    if (! $inputExpires || $inputExpires->isAfter($renterRecord->lease_end)) {
                        $this->merge(['expires_at' => $renterRecord->lease_end->copy()->endOfDay()]);
                    }
                }
            } elseif ($isRenter) {
                if (($this->input('duration_type') ?? '') === 'manual_revocation') {
                    throw ValidationException::withMessages([
                        'duration_type' => 'Renters cannot create unrestricted permanent credentials. All passes must have a temporary duration bounded by your lease.',
                    ]);
                }
                if (($this->input('authorization_type') ?? '') === 'short_term_rental' || stripos($this->input('access_level') ?? '', 'short') !== false) {
                    abort(403, 'Renters are not permitted to authorize short-term rentals.');
                }
                if ($this->input('access_level') === 'Homeowner' || $this->input('relationship') === 'Homeowner') {
                    abort(403, 'Renters cannot configure homeowner credentials or change property ownership.');
                }
                if ($renterRecord && $renterRecord->lease_end) {
                    $inputExpires = ! empty($this->input('expires_at')) ? Carbon::parse($this->input('expires_at')) : null;
                    if ($inputExpires && $inputExpires->isAfter($renterRecord->lease_end)) {
                        $this->merge(['expires_at' => $renterRecord->lease_end->copy()->endOfDay()]);
                    }
                }
            }
        });
    }

    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        if (is_array($validated)) {
            $user = $this->user();
            $visibilityService = app(AccessVisibilityService::class);
            $roleName = $visibilityService->resolveUserRole($user);
            $viewAs = strtolower((string) ($this->input('view_as') ?: $this->query('view_as', '')));
            $renterRecord = $user?->renter ?? $user?->activeStay();

            $isShortTermRenter = false;
            if ($renterRecord) {
                $stay = strtolower((string) ($renterRecord->stay_type ?? ''));
                $notes = strtolower((string) ($renterRecord->notes ?? ''));
                if (str_contains($stay, 'short') || str_contains($stay, 'airbnb') || str_contains($notes, 'airbnb') || str_contains($stay, 'vacation')) {
                    $isShortTermRenter = true;
                }
            }

            $isShortTerm = in_array($viewAs, ['short_term', 'airbnb', 'guest', 'vacation'], true)
                || $roleName === 'Short-Term Rental / Airbnb Host/Guest'
                || $roleName === 'Short-Term Renter'
                || $isShortTermRenter;

            $isRenter = ! $isShortTerm && ($roleName === 'Long-Term Renter' || $viewAs === 'renter');

            if (($isShortTerm || $isRenter) && $renterRecord && $renterRecord->lease_end) {
                $inputExpires = ! empty($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null;
                if (! $inputExpires || $inputExpires->isAfter($renterRecord->lease_end)) {
                    $validated['expires_at'] = $renterRecord->lease_end->copy()->endOfDay();
                }
            }
        }

        return $key ? data_get($validated, $key, $default) : $validated;
    }
}
