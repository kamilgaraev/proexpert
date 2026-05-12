<?php

declare(strict_types=1);

namespace App\Services\OneCExchange;

use App\Models\OneCExchangeMapping;
use Illuminate\Support\Collection;

final class OneCMappingService
{
    public function upsert(int $organizationId, array $data): OneCExchangeMapping
    {
        return OneCExchangeMapping::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'scope' => $data['scope'],
                'external_id' => $data['external_id'],
            ],
            [
                'external_name' => $data['external_name'] ?? null,
                'local_type' => $data['local_type'],
                'local_id' => (int) $data['local_id'],
                'payload' => $data['payload'] ?? null,
            ]
        );
    }

    public function list(int $organizationId, ?string $scope = null): Collection
    {
        return OneCExchangeMapping::query()
            ->where('organization_id', $organizationId)
            ->when($scope, static fn ($query) => $query->where('scope', $scope))
            ->orderBy('scope')
            ->orderBy('external_name')
            ->get();
    }
}
