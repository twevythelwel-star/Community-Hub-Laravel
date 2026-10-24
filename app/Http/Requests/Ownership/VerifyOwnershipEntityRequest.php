<?php

namespace App\Http\Requests\Ownership;

use App\Models\OwnershipEntity;
use Illuminate\Foundation\Http\FormRequest;

/** Vouching for an entity is the estate's job, never the entity's own. */
class VerifyOwnershipEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administer', OwnershipEntity::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $text = ['nullable', 'string', 'max:255'];

        return [
            'notes' => ['nullable', 'string', 'max:2000'],
            'documents' => ['nullable', 'array', 'max:50'],
            // Company
            'registration_number' => $text,
            'company_status' => $text,
            'directors' => ['nullable', 'array', 'max:50'],
            'shareholders' => ['nullable', 'array', 'max:100'],
            'registered_office' => $text,
            // Trust
            'deed_reference_number' => $text,
            'settlement_date' => ['nullable', 'date'],
            'trustees' => ['nullable', 'array', 'max:50'],
            'beneficiaries' => ['nullable', 'array', 'max:100'],
            // Estate
            'deceased_name' => $text,
            'date_of_death' => ['nullable', 'date'],
            'probate_suit_number' => $text,
            'executors' => ['nullable', 'array', 'max:50'],
            // Individual
            'id_type' => $text,
            'id_number' => $text,
            'trn' => $text,
            'volume' => $text,
            'folio' => $text,
            'beneficial_ownership' => ['nullable', 'array'],
        ];
    }
}
