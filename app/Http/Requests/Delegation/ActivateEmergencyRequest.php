<?php

namespace App\Http\Requests\Delegation;

use Illuminate\Foundation\Http\FormRequest;

class ActivateEmergencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
