<?php

namespace App\Http\Requests\Ownership;

use App\Models\OwnershipEntity;
use Illuminate\Foundation\Http\FormRequest;

/** Moving a property to a new legal owner: administrators only. */
class AssignPropertyRequest extends FormRequest
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
        return [];
    }
}
