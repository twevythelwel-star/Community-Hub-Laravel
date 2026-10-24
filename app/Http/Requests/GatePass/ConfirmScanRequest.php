<?php

namespace App\Http\Requests\GatePass;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('scanPasses') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'accept' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
