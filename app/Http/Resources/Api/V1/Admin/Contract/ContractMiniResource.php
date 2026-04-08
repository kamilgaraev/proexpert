<?php

namespace App\Http\Resources\Api\V1\Admin\Contract;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\Project\ProjectCustomerResolverService;

class ContractMiniResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'date' => $this->date,
            'total_amount' => (float) ($this->total_amount ?? 0),
            'status' => $this->status->value,
            'status_label' => $this->status->name,
            'customer' => $this->resolveCustomer(),
        ];
    }

    private function resolveCustomer(): ?array
    {
        if (!$this->project) {
            return null;
        }

        $resolved = app(ProjectCustomerResolverService::class)->resolve($this->project);

        if ($resolved === null) {
            return null;
        }

        return [
            'id' => $resolved['organization']->id,
            'name' => $resolved['organization']->name,
            'source' => $resolved['source'],
        ];
    }
} 
