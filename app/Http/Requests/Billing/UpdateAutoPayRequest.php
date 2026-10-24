<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAutoPayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
            'cadence' => ['required', 'string', 'in:monthly,quarterly,annual,custom'],
            'charge_day_of_month' => ['required', 'integer', 'between:1,28'],
            'payment_channel' => ['required', 'string'],
        ];
    }
}
