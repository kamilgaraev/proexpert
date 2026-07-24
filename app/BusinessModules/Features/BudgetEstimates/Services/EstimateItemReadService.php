<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Support\EstimatePositionOrder;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\Response;

class EstimateItemReadService
{
    public function findProjectEstimate(int $organizationId, int $projectId, int $estimateId): Estimate
    {
        return Estimate::query()
            ->where('id', $estimateId)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->firstOrFail();
    }

    public function paginate(Estimate $estimate, int $perPage): LengthAwarePaginator
    {
        return EstimatePositionOrder::apply(
            $estimate->items()
                ->with(['workType', 'measurementUnit', 'section'])
        )
            ->orderBy('id', 'asc')
            ->paginate($perPage);
    }

    public function loadDetails(EstimateItem $item): EstimateItem
    {
        return $item->load(['workType', 'measurementUnit', 'resources']);
    }

    public function resolveProjectItem(EstimateItem $item, Estimate $estimate): EstimateItem
    {
        if ((int) $item->estimate_id !== (int) $estimate->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $item->loadMissing('estimate');

        if (
            !$item->estimate
            || (int) $item->estimate->organization_id !== (int) $estimate->organization_id
            || (int) $item->estimate->project_id !== (int) $estimate->project_id
        ) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $item;
    }
}
