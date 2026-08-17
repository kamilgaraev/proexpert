<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Services;

use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use Illuminate\Pagination\LengthAwarePaginator;

final class MachineryAssetReadRepository
{
    private const RELATIONS = [
        'machinery:id,name,code,category',
        'currentProject:id,name',
        'currentScheduleTask:id,name',
        'currentAssignment:id,asset_request_id,asset_id,organization_asset_id,project_id,schedule_task_id,status,planned_start_at,planned_end_at,actual_start_at',
        'currentAssignment.assetRequest:id,site_request_id,origin_type',
        'organizationAsset.operationProfile',
        'organizationAsset.machinery:id,name,code,category',
        'organizationAsset.currentProject:id,name',
    ];

    public function paginate(int $organizationId, int $perPage, array $filters = []): LengthAwarePaginator
    {
        return MachineryAsset::forOrganization($organizationId)
            ->with(self::RELATIONS)
            ->when(array_key_exists('project_ids', $filters), function ($query) use ($filters): void {
                $query->where(function ($projectQuery) use ($filters): void {
                    $projectQuery->whereNull('current_project_id')
                        ->orWhereIn('current_project_id', $filters['project_ids'])
                        ->orWhereHas('organizationAsset', fn ($canonical) => $canonical->whereIn('current_project_id', $filters['project_ids']));
                });
            })
            ->when(! empty($filters['project_id']), function ($query) use ($filters): void {
                $projectId = (int) $filters['project_id'];
                $query->where(fn ($nested) => $nested->where('current_project_id', $projectId)
                    ->orWhereHas('organizationAsset', fn ($canonical) => $canonical->where('current_project_id', $projectId)));
            })
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function ($query) use ($filters): void {
                $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $filters['search'])).'%';
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery->where('name', 'ilike', $search)
                        ->orWhere('asset_code', 'ilike', $search)
                        ->orWhere('inventory_number', 'ilike', $search)
                        ->orWhereHas('organizationAsset', fn ($canonical) => $canonical
                            ->where('name', 'ilike', $search)
                            ->orWhere('inventory_number', 'ilike', $search)
                            ->orWhere('serial_number', 'ilike', $search));
                });
            })
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function find(int $organizationId, int $id): ?MachineryAsset
    {
        return MachineryAsset::forOrganization($organizationId)
            ->with(self::RELATIONS)
            ->find($id);
    }
}
