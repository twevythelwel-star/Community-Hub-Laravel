<?php

namespace App\Http\Requests\Visitors;

use App\Models\Visitor;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVisitorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $visitor = $this->route('visitor');
        if (! ($visitor instanceof Visitor)) {
            return false;
        }

        return $this->user()?->can('update', $visitor) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'contact' => ['nullable', 'string', 'max:120'],
            'vehicle' => ['nullable', 'string', 'max:120'],
            'type' => ['sometimes', 'in:One-time,Recurring'],
            'expected_at' => ['sometimes', 'date'],
            'date_range' => ['nullable', 'string', 'max:120'],
            'notify_email' => ['nullable', 'boolean'],
            'notify_sms' => ['nullable', 'boolean'],
            'notify_whatsapp' => ['nullable', 'boolean'],
        ];
    }
}
