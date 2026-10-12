<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Operations;

use App\Models\EstimateOperationPriceList;
use App\Models\NormativeCollection;
use App\Models\PriceIndex;
use App\Models\User;
use Carbon\Carbon;

final class EstimateOperationOptions
{
    public function __construct(private readonly EstimateOperationAccess $access)
    {
    }

    public function get(User $actor, int $projectId, string $date): array
    {
        $this->access->project($actor, (int) $actor->current_organization_id, $projectId);
        $target = Carbon::parse($date);

        return [
            'collections' => NormativeCollection::query()->orderBy('name')->get(['id', 'name', 'code'])->toArray(),
            'indices' => PriceIndex::query()->where('index_type', 'construction_general')->whereNotNull('region_code')->where('year', $target->year)
                ->where(fn ($q) => $q->where('month', $target->month)->orWhere(fn ($quarter) => $quarter->whereNull('month')->where('quarter', (int) ceil($target->month / 3)))->orWhere(fn ($year) => $year->whereNull('month')->whereNull('quarter')))
                ->orderBy('region_name')->orderByDesc('publication_date')->get(['id', 'region_code', 'region_name', 'index_value', 'source', 'year', 'quarter', 'month'])->toArray(),
            'price_lists' => EstimateOperationPriceList::query()->where('organization_id', $actor->current_organization_id)->where('project_id', $projectId)
                ->orderByDesc('created_at')->limit(100)->get(['id', 'name', 'currency', 'vat_rate', 'includes_vat', 'effective_date', 'checksum'])->toArray(),
        ];
    }
}
