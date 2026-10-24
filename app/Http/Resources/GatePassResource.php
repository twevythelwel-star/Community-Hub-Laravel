<?php

namespace App\Http\Resources;

use App\Models\GatePass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GatePass
 */
class GatePassResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'passId' => $this->pass_id,
            'category' => $this->category->value,
            'holderName' => $this->holder_name,
            'property' => $this->property,
            'accessZone' => $this->access_zone,
            'gate' => $this->designated_gate->value,
            'rotationSeq' => $this->rotation_seq,
            'status' => $this->status->value,
        ];
    }
}
