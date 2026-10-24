<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class RegisterTerminalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageBilling') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'registration_code' => ['required', 'string', 'max:64'],
            'location_id' => ['required', 'string', 'max:64'],
            'label' => ['required', 'string', 'max:80'],
        ];
    }
}
