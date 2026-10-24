<?php

namespace App\Http\Requests\Delegation;

use App\Models\DelegatedAccess;
use App\Services\AccessVisibilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class UpdateDelegationRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'access_rules' => ['required', 'array'],
            'permissions' => ['nullable', 'array'],
            'duration_type' => ['nullable', 'string', 'in:'.implode(',', array_keys(DelegatedAccess::DURATION_TYPES))],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function () {
            $user = $this->user();
            /** @var DelegatedAccess $delegation */
            $delegation = $this->route('delegation');

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
                if ($delegation && $delegation->grantor_user_id !== $user->id) {
                    abort(403, 'Short-term guests cannot modify rules for property services or host credentials.');
                }
                if (($this->input('duration_type') ?? '') === 'manual_revocation') {
                    throw ValidationException::withMessages([
                        'duration_type' => 'Short-term guests cannot create unrestricted permanent credentials. Credential duration must be temporary or bounded by your checkout date.',
                    ]);
                }
                if ($renterRecord && $renterRecord->lease_end && ! empty($this->input('expires_at'))) {
                    $inputExpires = Carbon::parse($this->input('expires_at'));
                    if ($inputExpires->isAfter($renterRecord->lease_end)) {
                        $this->merge(['expires_at' => $renterRecord->lease_end->toDateString()]);
                    }
                }
            } elseif ($isRenter && ($this->input('duration_type') ?? '') === 'manual_revocation') {
                throw ValidationException::withMessages([
                    'duration_type' => 'Renters cannot create unrestricted permanent credentials. Credential duration must be temporary or bounded by your lease end date.',
                ]);
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

            if (($isShortTerm || $isRenter) && $renterRecord && $renterRecord->lease_end && ! empty($validated['expires_at'])) {
                $inputExpires = Carbon::parse($validated['expires_at']);
                if ($inputExpires->isAfter($renterRecord->lease_end)) {
                    $validated['expires_at'] = $renterRecord->lease_end->toDateString();
                }
            }
        }

        return $key ? data_get($validated, $key, $default) : $validated;
    }
}
