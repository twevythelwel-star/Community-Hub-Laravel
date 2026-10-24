<?php

namespace App\Http\Requests\GatePass;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScanGatePassRequest extends FormRequest
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
            'token' => ['required', 'string', 'max:4096'],
            'gate' => ['nullable', 'string', Rule::in(array_keys(config('gatepass.gates')))],
            'direction' => ['nullable', 'string', Rule::in(['in', 'out', 'auto'])],
            'method' => ['nullable', 'string', 'max:100'],
            'override_passback' => ['nullable', 'boolean'],
            // An override is logged against the guard: their own words, not a default.
            'override_reason' => ['nullable', 'required_if:override_passback,true,1', 'string', 'min:5', 'max:255'],
        ];
    }
}
