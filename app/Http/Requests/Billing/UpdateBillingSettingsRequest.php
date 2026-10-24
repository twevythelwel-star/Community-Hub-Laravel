<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBillingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageBilling') ?? false;
    }

    public function rules(): array
    {
        return [
            'monthly_fee' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'due_day_of_month' => ['required', 'integer', 'between:1,28'],
        ];
    }
}
