<?php

namespace App\Http\Requests\GateDevice;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class GateDeviceHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('gate_device') !== null;
    }

    /**
     * Telemetry is merged into the device's stored metadata on every
     * heartbeat, so it is kept to a few flat readings (battery, temperature
     * and the like) rather than whatever a device chooses to send.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'firmware_version' => ['nullable', 'string', 'max:50'],
            'telemetry' => ['nullable', 'array', 'max:50'],
            'telemetry.*' => [function (string $attribute, mixed $value, Closure $fail): void {
                if (! ($value === null || is_bool($value) || is_int($value) || is_float($value)
                    || (is_string($value) && mb_strlen($value) <= 255))) {
                    $fail('Each telemetry reading must be a number, true/false, or text of at most 255 characters.');
                }
            }],
        ];
    }
}
