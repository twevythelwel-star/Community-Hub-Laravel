<?php

namespace App\Http\Requests\Renters;

use App\Models\Renter;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $renter = $this->route('renter');

        return $renter instanceof Renter
            ? ($this->user()?->can('update', $renter) ?? false)
            : false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'stay_type' => ['sometimes', 'in:Long-term (Renter),Short-term (Airbnb)'],
            'contact' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lease_start' => ['sometimes', 'date'],
            'lease_end' => ['sometimes', 'date', 'after_or_equal:lease_start'],
            'status' => ['sometimes', 'in:Active,Inactive'],
        ];
    }
}
