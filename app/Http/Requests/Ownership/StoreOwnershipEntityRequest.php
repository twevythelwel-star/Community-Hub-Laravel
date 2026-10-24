<?php

namespace App\Http\Requests\Ownership;

use App\Enums\OwnershipEntityType;
use App\Models\OwnershipEntity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOwnershipEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administer', OwnershipEntity::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:255'],
            'entity_type' => ['required', Rule::enum(OwnershipEntityType::class)],
            'registration_number' => ['nullable', 'string', 'max:64'],
            'jurisdiction' => ['nullable', 'string', 'max:64'],
            'primary_email' => ['nullable', 'email', 'max:255'],
            'primary_phone' => ['nullable', 'string', 'max:32'],
            'billing_address' => ['nullable', 'string', 'max:500'],
        ];
    }
}
