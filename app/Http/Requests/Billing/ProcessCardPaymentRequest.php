<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class ProcessCardPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'invoice_id' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
