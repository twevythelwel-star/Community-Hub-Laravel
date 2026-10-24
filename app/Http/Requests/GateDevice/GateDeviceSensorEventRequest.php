<?php

namespace App\Http\Requests\GateDevice;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One barrier cycle counted by a gate's occupancy sensor. The gate is the
 * device's own and cannot be named here.
 */
class GateDeviceSensorEventRequest extends FormRequest
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
            'direction' => ['nullable', 'string', 'in:in,out'],
            'pass_id' => ['nullable', 'string', 'max:64'],
            'license_plate' => ['nullable', 'string', 'max:32'],
            'authorized_occupants' => ['nullable', 'integer', 'min:1', 'max:50'],
            'detected_occupants' => ['required', 'integer', 'min:0', 'max:50'],
            'sensor_type' => ['nullable', 'string', 'max:128'],
            'confidence' => ['nullable', 'numeric', 'between:0,100'],
            'transit_duration_ms' => ['nullable', 'integer', 'min:0', 'max:600000'],
            // A reading cannot come from the future.
            'occurred_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
        ];
    }
}
