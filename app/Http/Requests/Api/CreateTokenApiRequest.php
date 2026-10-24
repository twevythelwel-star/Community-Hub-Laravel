<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

class CreateTokenApiRequest extends ApiRequest
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
        // No later than config('sanctum.expiration') allows: Sanctum expires
        // the token at that point anyway, so a later date would be a promise
        // it does not keep.
        $maxMinutes = (int) config('sanctum.expiration');
        $expiresAt = ['nullable', 'date', 'after:now'];
        if ($maxMinutes > 0) {
            $expiresAt[] = 'before_or_equal:'.now()->addMinutes($maxMinutes)->toIso8601String();
        }

        return [
            'name' => ['required_without:token_name', 'string', 'min:2', 'max:100'],
            'token_name' => ['required_without:name', 'string', 'min:2', 'max:100'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', 'max:50'],
            'expires_at' => $expiresAt,
        ];
    }
}
