<?php

namespace App\Http\Requests\Delegation;

use App\Models\EmergencyContinuityPlan;
use App\Services\AccessVisibilityService;
use Illuminate\Foundation\Http\FormRequest;

class SaveContinuityPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
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
            abort(403, 'Emergency & Incapacity Continuity Plans are reserved for property homeowners.');
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'primary_delegate_id' => ['nullable', 'exists:delegated_accesses,id'],
            'secondary_delegate_id' => ['nullable', 'exists:delegated_accesses,id'],
            'activation_conditions' => ['required', 'array', 'min:1'],
            'activation_conditions.*' => ['string', 'in:'.implode(',', array_keys(EmergencyContinuityPlan::CONDITIONS))],
            'required_verification' => ['required', 'string', 'in:'.implode(',', array_keys(EmergencyContinuityPlan::VERIFICATIONS))],
            'authorized_actions' => ['required', 'array', 'min:1'],
            'authorized_actions.*' => ['string', 'in:'.implode(',', array_keys(EmergencyContinuityPlan::ACTIONS))],
            'max_duration_days' => ['required', 'integer', 'min:1', 'max:180'],
            'requires_admin_approval' => ['required', 'boolean'],
            'notify_homeowner_on_trigger' => ['required', 'boolean'],
            'notify_community_security' => ['required', 'boolean'],
            'notification_recipients' => ['nullable', 'array'],
            'special_instructions' => ['nullable', 'string', 'max:1500'],
        ];
    }
}
