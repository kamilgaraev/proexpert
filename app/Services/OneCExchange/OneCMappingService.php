<?php

declare(strict_types=1);

namespace App\Services\OneCExchange;

use App\Models\OneCExchangeMapping;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
                'external_type' => $data['external_type'] ?? null,
                'external_name' => $data['external_name'] ?? null,
                'local_type' => $data['local_type'],
                'local_id' => (int) $data['local_id'],
                'local_display_name' => $data['local_display_name'] ?? null,
                'status' => $data['status'] ?? 'active',
                'confidence_score' => $data['confidence_score'] ?? null,
                'source' => $data['source'] ?? 'manual',
                'duplicate_warning' => (bool) ($data['duplicate_warning'] ?? false),
                'safe_payload_preview' => $data['safe_payload_preview'] ?? null,
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

    public function registry(int $organizationId, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $paginator = OneCExchangeMapping::query()
            ->where('organization_id', $organizationId)
            ->when($filters['scope'] ?? null, static fn (Builder $query, string $scope): Builder => $query->where('scope', $scope))
            ->when($filters['status'] ?? null, static fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($filters['source'] ?? null, static fn (Builder $query, string $source): Builder => $query->where('source', $source))
            ->when($filters['search'] ?? null, static function (Builder $query, string $search): Builder {
                return $query->where(static function (Builder $nested) use ($search): void {
                    $nested
                        ->where('external_id', 'like', "%{$search}%")
                        ->orWhere('external_name', 'like', "%{$search}%")
                        ->orWhere('local_display_name', 'like', "%{$search}%")
                        ->orWhere('local_id', 'like', "%{$search}%");
                });
            })
            ->orderBy('scope')
            ->orderByDesc('updated_at')
            ->paginate(min(max($perPage, 1), 100));

        $paginator->getCollection()->transform(fn (OneCExchangeMapping $mapping): array => $this->payload($mapping));

        return $paginator;
    }

    public function show(int $organizationId, int $mappingId): ?array
    {
        $mapping = OneCExchangeMapping::query()
            ->where('organization_id', $organizationId)
            ->find($mappingId);

        return $mapping ? $this->payload($mapping) : null;
    }

    public function payload(OneCExchangeMapping $mapping): array
    {
        return [
            'id' => (int) $mapping->id,
            'scope' => $mapping->scope,
            'external_type' => $mapping->external_type,
            'external_id' => $mapping->external_id,
            'external_name' => $mapping->external_name,
            'local_type' => $mapping->local_type,
            'local_id' => (int) $mapping->local_id,
            'local_display_name' => $mapping->local_display_name,
            'status' => $mapping->status ?? 'active',
            'confidence_score' => $mapping->confidence_score !== null ? (int) $mapping->confidence_score : null,
            'source' => $mapping->source ?? 'manual',
            'duplicate_warning' => (bool) $mapping->duplicate_warning || $this->hasDuplicateWarning($mapping),
            'safe_payload_preview' => $mapping->safe_payload_preview,
            'verified_at' => $mapping->verified_at?->toJSON(),
            'archived_at' => $mapping->archived_at?->toJSON(),
            'created_at' => $mapping->created_at?->toJSON(),
            'updated_at' => $mapping->updated_at?->toJSON(),
        ];
    }

    private function hasDuplicateWarning(OneCExchangeMapping $mapping): bool
    {
        if (($mapping->status ?? 'active') !== 'active') {
            return false;
        }

        $localDuplicates = OneCExchangeMapping::query()
            ->where('organization_id', $mapping->organization_id)
            ->where('scope', $mapping->scope)
            ->where('local_type', $mapping->local_type)
            ->where('local_id', $mapping->local_id)
            ->where('status', 'active')
            ->whereKeyNot($mapping->id)
            ->exists();

        if ($localDuplicates) {
            return true;
        }

        return OneCExchangeMapping::query()
            ->where('organization_id', $mapping->organization_id)
            ->where('scope', $mapping->scope)
            ->where('external_id', $mapping->external_id)
            ->where('status', 'active')
            ->whereKeyNot($mapping->id)
            ->exists();
    }
}
