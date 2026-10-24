<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentPlanRequest extends FormRequest
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
            'invoice_id' => ['required', 'exists:invoices,id'],
            'total_installments' => ['required', 'integer', 'between:2,12'],
            'frequency' => ['required', 'string', 'in:monthly,biweekly,weekly'],
        ];
    }
}
