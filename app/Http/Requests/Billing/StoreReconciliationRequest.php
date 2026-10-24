<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class StoreReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageBilling') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bank_statement_date' => ['required', 'date'],
            'statement_balance' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'verify_payment_ids' => ['nullable', 'array'],
            'verify_payment_ids.*' => ['integer'],
        ];
    }
}
