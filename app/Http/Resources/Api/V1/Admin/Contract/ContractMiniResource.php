<?php

namespace App\Http\Resources\Api\V1\Admin\Contract;

use App\Services\Project\ProjectCustomerResolverService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractMiniResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $side = app(\App\Services\Contract\ContractSideResolverService::class)->resolve($this->resource);

        return [
            'id' => $this->id,
            'number' => $this->number,
            'date' => $this->date,
            'contract_side_type' => $this->contract_side_type?->value,
            'direction' => $side['direction'] ?? 'expense',
            'direction_label' => $side['direction_label'] ?? 'Расходный',
            'is_income' => (bool) ($side['is_income'] ?? false),
            'is_expense' => (bool) ($side['is_expense'] ?? true),
            'total_amount' => (float) ($this->total_amount ?? 0),
            'currency' => (string) ($this->currency ?? 'RUB'),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'customer' => $this->resolveCustomer(),
        ];
    }

    private function resolveCustomer(): ?array
    {
        if (! $this->project) {
            return null;
        }

        $resolved = app(ProjectCustomerResolverService::class)->resolveLegalCustomer($this->project);

        if ($resolved === null) {
            return null;
        }

        return [
            'id' => $resolved['id'],
            'name' => $resolved['name'],
            'source' => $resolved['source'],
            'entity_type' => $resolved['entity_type'] ?? 'organization',
            'counterparty_id' => $resolved['counterparty_id'] ?? null,
        ];
    }
}
