<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uid' => $this->uid,
            'name' => $this->name,
            'displayName' => $this->display_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role->value,
            'lot' => $this->lot,
            'street' => $this->street,
            'title' => $this->title,
            'avatarUrl' => $this->avatar_url,
            'property' => $this->propertyLabel(),
        ];
    }
}
