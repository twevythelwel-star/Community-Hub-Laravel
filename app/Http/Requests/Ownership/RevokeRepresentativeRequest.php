<?php

namespace App\Http\Requests\Ownership;

use App\Models\AuthorizedPerson;
use App\Models\OwnershipEntity;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ending someone's authority to act for an entity. Reached as
 * /ownership-entities/{entity}/representatives/{person} or, from the
 * Ownership page, /ownership/representatives/{person}.
 */
class RevokeRepresentativeRequest extends FormRequest
{
    public function person(): AuthorizedPerson
    {
        return $this->route('person');
    }

    public function authorize(): bool
    {
        $routed = $this->route('entity');
        $entity = $this->person()->ownershipEntity;

        // A person under another entity's address is not this entity's to revoke.
        if ($routed instanceof OwnershipEntity && $routed->id !== $entity?->id) {
            return false;
        }

        return $entity !== null && ($this->user()?->can('manage', $entity) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
