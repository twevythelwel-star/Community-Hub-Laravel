<?php

namespace App\Http\Requests\GatePass;

use Illuminate\Foundation\Http\FormRequest;

class RevokePassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageSecurity') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
