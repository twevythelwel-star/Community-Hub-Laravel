<?php

namespace App\Http\Requests\Directory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mirrors every field the StaffForm submits, so an edit does not silently
 * discard the ID details the form displayed. Who may edit which staff member
 * is checked by the controller, as for deleting one.
 */
class UpdateStaffRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:120'],
            'job' => ['sometimes', 'string', 'max:120'],
            'property' => ['sometimes', 'string', 'max:160'],
            'id_type' => ['sometimes', 'string', 'max:60'],
            'id_number' => ['sometimes', 'string', 'max:60'],
            'id_expiry' => ['sometimes', 'date'],
            'photo_url' => ['nullable', 'url', 'max:2048'],
            // "Expired ID" is derived from id_expiry, never set directly.
            'status' => ['sometimes', 'in:Active,Inactive'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'Status must be Active or Inactive; "Expired ID" follows from the ID expiry date.',
            'photo_url.url' => 'The photo must be a web address.',
        ];
    }

    /**
     * The validated fields as Staff columns: the form's `property` is stored
     * as `property_label`.
     *
     * @return array<string, mixed>
     */
    public function staffAttributes(): array
    {
        $attributes = $this->validated();

        if (array_key_exists('property', $attributes)) {
            $attributes['property_label'] = $attributes['property'];
            unset($attributes['property']);
        }

        return $attributes;
    }
}
