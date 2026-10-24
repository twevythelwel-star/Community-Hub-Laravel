<?php

namespace App\Http\Requests\Household;

use App\Models\HouseholdMember;
use App\Services\HouseholdManagementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHouseholdMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $member = $this->route('member');

        return $member instanceof HouseholdMember
            ? ($this->user()?->can('update', $member) ?? false)
            : false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(array_keys(HouseholdManagementService::AVAILABLE_PERMISSIONS))],
            'access_schedule' => ['nullable', 'array'],
            'status' => ['nullable', 'string', 'in:active,suspended'],
            'valid_until' => ['nullable', 'date'],
        ];
    }
}
