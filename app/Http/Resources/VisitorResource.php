<?php

namespace App\Http\Resources;

use App\Models\Visitor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Visitor
 */
class VisitorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'status' => $this->status->value,
            'expectedAt' => $this->expected_at?->toIso8601String(),
            'homeowner' => $this->homeowner_name,
            'isBlocked' => $this->is_blocked,
            'notify_email' => $this->notify_email,
            'notify_sms' => $this->notify_sms,
            'notify_whatsapp' => $this->notify_whatsapp,
        ];
    }
}
