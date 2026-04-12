<?php

declare(strict_types=1);

namespace App\Http\Resources\Brigades;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BrigadeResponse */
class BrigadeResponseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_id' => $this->request_id,
            'brigade_id' => $this->brigade_id,
            'cover_message' => $this->cover_message,
            'status' => $this->status,
            'brigade' => new BrigadeProfileResource($this->whenLoaded('brigade')),
            'request' => new BrigadeRequestResource($this->whenLoaded('request')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
