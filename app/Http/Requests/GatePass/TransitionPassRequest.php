<?php

namespace App\Http\Requests\GatePass;

use App\Enums\PassStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionPassRequest extends FormRequest
{
    public const MANUAL_TRANSITIONS = [
        PassStatus::Approved, PassStatus::Rejected, PassStatus::Cancelled,
        PassStatus::Suspended, PassStatus::Active, PassStatus::Revoked,
    ];

    public function authorize(): bool
    {
        return true; // Controller / Action checks granular policy (manageSecurity vs host cancelling)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_map(fn (PassStatus $s) => $s->value, self::MANUAL_TRANSITIONS))],
            'reason' => [
                Rule::requiredIf(fn () => in_array($this->input('status'), ['REJECTED', 'REVOKED', 'SUSPENDED'], true)),
                'nullable', 'string', 'max:500',
            ],
        ];
    }
}
