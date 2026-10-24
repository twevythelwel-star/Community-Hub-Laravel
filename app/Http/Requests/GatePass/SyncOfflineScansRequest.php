<?php

namespace App\Http\Requests\GatePass;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Scans a gate recorded while it was offline, sent in one batch on reconnect. */
class SyncOfflineScansRequest extends FormRequest
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
            'gate' => ['nullable', 'string', Rule::in(array_keys(config('gatepass.gates')))],
            'scans' => ['required', 'array', 'min:1', 'max:500'],
            'scans.*.offline_id' => ['required', 'string', 'max:100'],
            'scans.*.token_or_pin' => ['required', 'string', 'max:4096'],
            'scans.*.method' => ['nullable', 'string', 'max:100'],
            // A scan cannot have happened in the future.
            'scans.*.scanned_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'scans.*.action' => ['nullable', 'string', 'in:check_in,check_out'],
            'scans.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
