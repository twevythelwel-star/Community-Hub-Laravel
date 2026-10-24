<?php

namespace App\Http\Requests\Ownership;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBeneficialOwnershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entity = $this->route('entity');

        return $this->user() !== null
            && $entity->canUserViewBeneficialOwnership($this->user())
            && $this->user()->can('manage', $entity);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'declaration_reference' => ['nullable', 'string', 'max:255'],
            'filing_date' => ['nullable', 'date'],
            'beneficial_owners' => ['required', 'array', 'max:100'],
            'beneficial_owners.*.full_name' => ['required', 'string', 'max:255'],
            'beneficial_owners.*.nationality' => ['nullable', 'string', 'max:100'],
            'beneficial_owners.*.id_trn' => ['nullable', 'string', 'max:64'],
            'beneficial_owners.*.percentage_interest' => ['nullable', 'numeric', 'between:0,100'],
            'beneficial_owners.*.control_nature' => ['nullable', 'string', 'max:255'],
        ];
    }
}
