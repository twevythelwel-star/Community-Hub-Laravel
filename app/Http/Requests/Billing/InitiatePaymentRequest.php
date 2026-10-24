<?php

namespace App\Http\Requests\Billing;

use App\Models\Transaction;
use App\Services\Payments\PaymentOrchestratorService;
use Illuminate\Foundation\Http\FormRequest;

class InitiatePaymentRequest extends FormRequest
{
    public const PAYMENT_PURPOSES = [
        Transaction::PURPOSE_HOA_ASSESSMENT,
        Transaction::PURPOSE_MAINTENANCE_FEE,
        Transaction::PURPOSE_LATE_FEE,
        Transaction::PURPOSE_AMENITY_BOOKING,
        Transaction::PURPOSE_GATE_ACCESS_FEE,
        Transaction::PURPOSE_EVENT_TICKET,
        Transaction::PURPOSE_FUNDRAISING_DONATION,
        Transaction::PURPOSE_FUNDRAISING_SPONSORSHIP,
        Transaction::PURPOSE_COMMUNITY_PROJECT,
        Transaction::PURPOSE_EMERGENCY_FUND,
    ];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('payment_channel') && ! $this->has('channel')) {
            $this->merge(['channel' => $this->input('payment_channel')]);
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
            'purpose' => ['nullable', 'string', 'in:'.implode(',', self::PAYMENT_PURPOSES)],
            'device_identifier' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated();

        if ($this->has('payment_channel') && ! isset($validated['channel'])) {
            $validated['channel'] = $this->input('payment_channel');
        }

        if ($key !== null) {
            return data_get($validated, $key, $default);
        }

        return $validated;
    }
}
