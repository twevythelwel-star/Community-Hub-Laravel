<?php

namespace App\Http\Requests\Visitors;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterVisitorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('registerVisitors') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $properties = $this->user()?->propertiesForVisitorManagement()->pluck('id')->all() ?? [];

        return [
            'name' => ['required', 'string', 'max:120'],
            'contact' => ['nullable', 'string', 'max:120'],
            'vehicle' => ['nullable', 'string', 'max:120'],
            'id_type' => ['nullable', 'string', 'max:60'],
            'id_number' => ['nullable', 'string', 'max:60'],
            'type' => ['required', 'in:One-time,Recurring'],
            'expected_at' => ['required', 'date', 'after:-1 hour'],
            'arrival_window_start' => ['nullable', 'date_format:H:i', 'required_with:arrival_window_end'],
            'arrival_window_end' => ['nullable', 'date_format:H:i', 'required_with:arrival_window_start'],
            'parking_instructions' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'date_range' => ['nullable', 'string', 'max:120'],
            'id_image_url' => ['nullable', 'url', 'max:2048'],
            'notify_email' => ['nullable', 'boolean'],
            'notify_sms' => ['nullable', 'boolean'],
            'notify_whatsapp' => ['nullable', 'boolean'],
            'pass_category' => ['nullable', 'in:VISITOR,CONTRACTOR'],
            'property_id' => ['nullable', 'integer', Rule::in($properties)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'property_id.in' => 'Choose one of your own or authorized properties.',
        ];
    }
}
