<?php

namespace App\Http\Requests\Billing;

use App\Services\Payments\PaymentOrchestratorService;
use Illuminate\Foundation\Http\FormRequest;

class PayInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('payment_channel') && ! $this->has('channel')) {
            $this->merge(['channel' => $this->input('payment_channel')]);
        }
        if ($this->has('wallet_amount') && ! $this->has('split_wallet_amount')) {
            $this->merge(['split_wallet_amount' => $this->input('wallet_amount')]);
        }
    }

    public function rules(): array
    {
        $channelKeys = app(PaymentOrchestratorService::class)->channelKeys();

        return [
            'channel' => ['required', 'string', 'in:'.implode(',', $channelKeys)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'in:JMD,USD,CAD,GBP,EUR,jmd,usd,cad,gbp,eur'],
            'invoice_id' => ['nullable', 'integer'],
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer'],
            'split_wallet_amount' => ['nullable', 'numeric', 'min:0'],
            'transaction_id' => ['nullable', 'string', 'max:32'],
            'payer_reference' => ['nullable', 'string', 'max:100'],
            'device_identifier' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated();

        if ($this->has('payment_channel') && ! isset($validated['channel'])) {
            $validated['channel'] = $this->input('payment_channel');
        }
        if ($this->has('wallet_amount') && ! isset($validated['split_wallet_amount'])) {
            $validated['split_wallet_amount'] = $this->input('wallet_amount');
        }

        if ($key !== null) {
            return data_get($validated, $key, $default);
        }

        return $validated;
    }
}
