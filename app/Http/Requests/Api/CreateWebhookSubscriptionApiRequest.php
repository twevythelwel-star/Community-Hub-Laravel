<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

class CreateWebhookSubscriptionApiRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'url' => ['required', 'url', 'max:500'],
            'secret' => ['nullable', 'string', 'min:16', 'max:128'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'max:80'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
