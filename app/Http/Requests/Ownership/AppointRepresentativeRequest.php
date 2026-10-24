<?php

namespace App\Http\Requests\Ownership;

use App\Enums\AuthorizedPersonRole;
use App\Enums\RepresentativePermission;
use App\Models\OwnershipEntity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Appointing someone to act for an entity. The entity comes from the route
 * (/ownership-entities/{entity}/representatives) or the form
 * (ownership_entity_id, from the Ownership page).
 */
class AppointRepresentativeRequest extends FormRequest
{
    public function entity(): ?OwnershipEntity
    {
        $routed = $this->route('entity');

        return $routed instanceof OwnershipEntity
            ? $routed
            : OwnershipEntity::find($this->integer('ownership_entity_id'));
    }

    public function authorize(): bool
    {
        $entity = $this->entity();

        return $entity === null || ($this->user()?->can('manage', $entity) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $entity = $this->entity();
        $roles = $entity ? [...$entity->entity_type->allowedRoles(), AuthorizedPersonRole::Other] : [];

        return [
            'ownership_entity_id' => [Rule::requiredIf(! $this->route('entity')), 'nullable', 'integer', 'exists:ownership_entities,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'role' => ['required', Rule::in(array_map(fn (AuthorizedPersonRole $role) => $role->value, $roles))],
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'id_document_type' => ['nullable', 'string', 'max:50'],
            'id_document_number' => ['nullable', 'string', 'max:64'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [Rule::enum(RepresentativePermission::class)],
            'is_primary_contact' => ['nullable', 'boolean'],
            'is_signatory' => ['nullable', 'boolean'],
            // A representative acts only for this entity's own properties.
            'property_ids' => ['nullable', 'array'],
            'property_ids.*' => ['integer', Rule::exists('properties', 'id')->where('ownership_entity_id', $entity?->id ?? 0)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.in' => 'Choose a role this kind of owner can appoint.',
            'property_ids.*.exists' => 'A representative can only act for properties this owner holds.',
        ];
    }
}
