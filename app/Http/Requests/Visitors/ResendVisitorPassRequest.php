<?php

namespace App\Http\Requests\Visitors;

use App\Models\Visitor;
use Illuminate\Foundation\Http\FormRequest;

class ResendVisitorPassRequest extends FormRequest
{
    public function authorize(): bool
    {
        $visitor = $this->route('visitor');

        return $visitor instanceof Visitor
            ? ($this->user()?->can('update', $visitor) ?? false)
            : false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'channel' => ['required', 'string', 'in:sms,whatsapp,email,all'],
        ];
    }
}
