<?php

namespace App\Http\Requests\Visitors;

use Illuminate\Foundation\Http\FormRequest;

class CheckOutVisitorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageSecurity') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'gate' => ['nullable', 'string', 'max:100'],
        ];
    }
}
