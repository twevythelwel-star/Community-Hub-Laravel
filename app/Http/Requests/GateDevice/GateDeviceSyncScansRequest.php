<?php

namespace App\Http\Requests\GateDevice;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Scans a gate device queued while it was offline. The same limits as the
 * dashboard's sync (SyncOfflineScansRequest), and the gate is the device's
 * own: naming another gate is refused rather than recorded there.
 */
class GateDeviceSyncScansRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('gate_device') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'gate' => ['nullable', 'string', Rule::in([$this->attributes->get('gate_device')?->gate_id])],
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
