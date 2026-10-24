<?php

namespace App\Http\Requests\Household;

use App\Models\HouseholdMember;
use App\Services\HouseholdManagementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHouseholdMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', HouseholdMember::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'role_in_household' => ['required', 'string', 'in:spouse,child,long_term_occupant,caregiver,other'],
            'relationship_label' => ['nullable', 'string', 'max:100'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(array_keys(HouseholdManagementService::AVAILABLE_PERMISSIONS))],
            'access_schedule' => ['nullable', 'array'],
            'valid_until' => ['nullable', 'date'],
        ];
    }
}
