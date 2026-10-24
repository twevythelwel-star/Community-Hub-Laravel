<?php

namespace App\Http\Requests\Renters;

use App\Enums\UserRole;
use App\Models\Renter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Renter::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'homeowner_id' => ['required', Rule::exists('users', 'id')->where('role', UserRole::Homeowner->value)],
            'name' => ['required', 'string', 'max:120'],
            'stay_type' => ['required', 'in:Long-term (Renter),Short-term (Airbnb)'],
            'contact' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lease_start' => ['required', 'date'],
            'lease_end' => ['required', 'date', 'after_or_equal:lease_start'],
            'lot' => ['nullable', 'string', 'max:60'],
            'street' => ['nullable', 'string', 'max:120'],
            'user_id' => ['nullable', Rule::exists('users', 'id')->where('role', UserRole::TemporaryHomeowner->value)],
        ];
    }

    public function messages(): array
    {
        return [
            'homeowner_id.required' => 'Choose the homeowner whose property this is.',
            'homeowner_id.exists' => 'Choose a homeowner account.',
            'user_id.exists' => 'Only a Temporary Homeowner account can be linked to a stay.',
        ];
    }
}
