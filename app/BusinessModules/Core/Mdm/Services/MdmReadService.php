<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

use App\BusinessModules\Core\Mdm\Models\MdmChangeLog;
use App\BusinessModules\Core\Mdm\Models\MdmChangeRequest;
use App\BusinessModules\Core\Mdm\Models\MdmDuplicateGroup;
use App\BusinessModules\Core\Mdm\Models\MdmImportBatch;
use App\BusinessModules\Core\Mdm\Models\MdmRecord;
use App\BusinessModules\Core\Mdm\Models\MdmRelationship;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

class MdmReadService
{
    public function __construct(
        private readonly MdmEntityRegistry $registry,
        private readonly MdmEntityGovernanceRegistry $governanceRegistry,
        private readonly MdmRecordService $recordService,
        private readonly MdmQualityPolicyService $qualityPolicyService
    ) {}

    public function entities(): array
    {
        return $this->registry->publicDefinitions($this->governanceRegistry);
    }

    public function dashboard(int $organizationId): array
    {
        return [
            'entities' => $this->recordService->summary($organizationId),
            'duplicates_open' => MdmDuplicateGroup::query()->where('organization_id', $organizationId)->where('status', 'open')->count(),
            'relationships' => MdmRelationship::query()->where('organization_id', $organizationId)->count(),
            'imports' => MdmImportBatch::query()->where('organization_id', $organizationId)->latest()->limit(5)->get(),
        ];
    }

    public function records(int $organizationId, array $filters): LengthAwarePaginator
    {
        return MdmRecord::query()
            ->where('organization_id', $organizationId)
            ->when(Arr::get($filters, 'entity_type'), static fn ($query, mixed $value) => $query->where('entity_type', $value))
            ->when(Arr::get($filters, 'status'), static fn ($query, mixed $value) => $query->where('status', $value))
            ->when(Arr::get($filters, 'q'), static fn ($query, mixed $value) => $query->where('display_name', 'like', '%'.(string) $value.'%'))
            ->orderByDesc('updated_at')
            ->paginate($this->perPage($filters));
    }

    public function duplicates(int $organizationId, array $filters): LengthAwarePaginator
    {
        return MdmDuplicateGroup::query()
            ->with('members')
            ->where('organization_id', $organizationId)
            ->when(Arr::get($filters, 'entity_type'), static fn ($query, mixed $value) => $query->where('entity_type', $value))
            ->when(Arr::get($filters, 'status'), static fn ($query, mixed $value) => $query->where('status', $value))
            ->orderByDesc('updated_at')
            ->paginate($this->perPage($filters));
    }

    public function relationships(int $organizationId, array $filters): LengthAwarePaginator
    {
        return MdmRelationship::query()
            ->where('organization_id', $organizationId)
            ->when(Arr::get($filters, 'source_type'), static fn ($query, mixed $value) => $query->where('source_type', $value))
            ->when(Arr::get($filters, 'source_id'), static fn ($query, mixed $value) => $query->where('source_id', (int) $value))
            ->when(Arr::get($filters, 'target_type'), static fn ($query, mixed $value) => $query->where('target_type', $value))
            ->when(Arr::get($filters, 'target_id'), static fn ($query, mixed $value) => $query->where('target_id', (int) $value))
            ->orderByDesc('updated_at')
            ->paginate($this->perPage($filters));
    }

    public function history(int $organizationId, array $filters): LengthAwarePaginator
    {
        return MdmChangeLog::query()
            ->where('organization_id', $organizationId)
            ->when(Arr::get($filters, 'entity_type'), static fn ($query, mixed $value) => $query->where('entity_type', $value))
            ->when(Arr::get($filters, 'entity_id'), static fn ($query, mixed $value) => $query->where('entity_id', (int) $value))
            ->orderByDesc('created_at')
            ->paginate($this->perPage($filters));
    }

    public function changeRequestSummary(int $organizationId): array
    {
        $statusCounts = MdmChangeRequest::query()
            ->where('organization_id', $organizationId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        $entityCounts = MdmChangeRequest::query()
            ->where('organization_id', $organizationId)
            ->selectRaw('entity_type, count(*) as total')
            ->groupBy('entity_type')
            ->pluck('total', 'entity_type')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        return [
            'statuses' => [
                MdmChangeRequest::STATUS_DRAFT => $statusCounts[MdmChangeRequest::STATUS_DRAFT] ?? 0,
                MdmChangeRequest::STATUS_SUBMITTED => $statusCounts[MdmChangeRequest::STATUS_SUBMITTED] ?? 0,
                MdmChangeRequest::STATUS_UNDER_REVIEW => $statusCounts[MdmChangeRequest::STATUS_UNDER_REVIEW] ?? 0,
                MdmChangeRequest::STATUS_APPROVED => $statusCounts[MdmChangeRequest::STATUS_APPROVED] ?? 0,
                MdmChangeRequest::STATUS_REJECTED => $statusCounts[MdmChangeRequest::STATUS_REJECTED] ?? 0,
                MdmChangeRequest::STATUS_APPLIED => $statusCounts[MdmChangeRequest::STATUS_APPLIED] ?? 0,
                MdmChangeRequest::STATUS_FAILED => $statusCounts[MdmChangeRequest::STATUS_FAILED] ?? 0,
                MdmChangeRequest::STATUS_CANCELLED => $statusCounts[MdmChangeRequest::STATUS_CANCELLED] ?? 0,
            ],
            'entity_types' => $entityCounts,
            'total' => array_sum($statusCounts),
        ];
    }

    public function qualityPolicies(int $organizationId): array
    {
        return collect(array_keys($this->registry->all()))
            ->map(fn (string $entityType): array => array_merge(['entity_type' => $entityType], $this->qualityPolicyService->get($organizationId, $entityType)))
            ->values()
            ->all();
    }

    public function entityExists(string $entityType): bool
    {
        return array_key_exists($entityType, $this->registry->all());
    }

    private function perPage(array $filters): int
    {
        return min(max((int) Arr::get($filters, 'per_page', 25), 1), 100);
    }
}
