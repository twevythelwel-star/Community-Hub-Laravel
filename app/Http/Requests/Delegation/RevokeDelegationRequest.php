<?php

namespace App\Http\Requests\Delegation;

use App\Enums\UserRole;
use App\Models\DelegatedAccess;
use App\Models\User;
use App\Services\AccessVisibilityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RevokeDelegationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function () {
            $user = $this->user();
            /** @var DelegatedAccess|null $delegation */
            $delegation = $this->route('delegation');
            if (! $delegation) {
                return;
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

            if ($isShortTerm || $isRenter) {
                if ($delegation->grantor_user_id !== $user->id) {
                    abort(403, $isShortTerm
                        ? 'Short-term guests cannot revoke property-level authorizations granted by the homeowner.'
                        : 'Renters cannot revoke property-level authorizations granted by the homeowner.');
                }
                $delegateUser = $delegation->delegate ?? User::where('email', $delegation->email)->first();
                if (($delegateUser && $delegateUser->role === UserRole::Homeowner) || $delegation->relationship === 'Homeowner' || $delegation->access_level === 'Homeowner') {
                    abort(403, $isShortTerm
                        ? 'Short-term guests cannot remove or revoke the property homeowner.'
                        : 'Renters cannot remove or revoke the property homeowner.');
                }
            }
        });
    }
}
