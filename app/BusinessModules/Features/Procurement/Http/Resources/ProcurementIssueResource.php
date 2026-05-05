<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ProcurementIssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'scope' => $this->resource['scope'],
            'severity' => $this->resource['severity'],
            'type' => $this->resource['type'],
            'title' => $this->resource['title'],
            'description' => $this->resource['description'],
            'next_action' => $this->resource['next_action'],
            'entity_number' => $this->resource['entity_number'],
            'entity_href' => $this->resource['entity_href'],
            'action_href' => $this->resource['action_href'] ?? null,
            'action_label' => $this->resource['action_label'] ?? null,
            'meta' => $this->resource['meta'] ?? [],
            'created_at' => $this->resource['created_at'] ?? null,
        ];
    }
}
