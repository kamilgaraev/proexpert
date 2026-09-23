<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MobileOneCExchangeRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction,
            'scope' => $this->scope,
            'status' => $this->status,
            'total_count' => (int) $this->total_count,
            'created_count' => (int) $this->created_count,
            'updated_count' => (int) $this->updated_count,
            'skipped_count' => (int) $this->skipped_count,
            'error_count' => (int) $this->error_count,
            'errors' => $this->errors ?? [],
            'summary' => $this->summary ?? [],
            'started_at' => $this->started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
