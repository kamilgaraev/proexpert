<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceAccess;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceQuery;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class AssistantEstimateEvidenceService
{
    public function __construct(private readonly AssistantDataAccessPolicy $access, private readonly EstimateFinanceAccess $financeAccess,
        private readonly EstimateFinanceQuery $financeQuery) {}

    public function snapshot(int $estimateId, int $organizationId, User $actor): array
    {
        if (! $this->access->canReadEntityContent($actor, $organizationId, 'estimate', $estimateId)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($estimateId, $organizationId, $actor): array {
            $estimate = Estimate::query()->where('organization_id', $organizationId)->sharedLock()->findOrFail($estimateId);
            if ((int) $estimate->project_id > 0) {
                $this->financeAccess->project($actor, (int) $estimate->project_id);
            }
            $items = EstimateItem::query()->where('estimate_id', $estimateId)->orderBy('id')->sharedLock()->get();
            $targets = $this->financeQuery->targets($estimate);
            $positions = [];
            $missingFields = [];
            $totals = ['total_amount' => '0.00', 'direct_costs' => '0.0000', 'overhead_amount' => '0.00', 'profit_amount' => '0.00', 'equipment_cost' => '0.0000'];
            $fetchedAt = now()->toIso8601String();
            foreach ($items as $item) {
                $target = $targets['i:'.$item->id];
                $included = ! $target['excluded'] && $item->parent_work_id === null;
                $position = ['id' => (int) $item->id, 'position_number' => (string) $item->position_number,
                    'name' => (string) $item->name, 'parent_work_id' => $item->parent_work_id,
                    'unit' => $target['unit'], 'quantity' => FinanceDecimal::value($target['quantity'], 8),
                    'excluded' => (bool) $target['excluded'], 'included_in_total' => $included];
                foreach (['unit_price' => 4, 'current_unit_price' => 4, 'total_amount' => 2, 'current_total_amount' => 2,
                    'direct_costs' => 4, 'materials_cost' => 4, 'machinery_cost' => 4, 'labor_cost' => 4,
                    'equipment_cost' => 4, 'overhead_amount' => 2, 'profit_amount' => 2] as $field => $scale) {
                    $position[$field] = $item->$field === null ? null : FinanceDecimal::value((string) $item->$field, $scale);
                }
                foreach ($totals as $field => $value) {
                    if ($included) {
                        if ($position[$field] === null) {
                            $missingFields[$field] = true;
                        }
                        $totals[$field] = FinanceDecimal::add($value, $position[$field] ?? '0');
                    }
                }
                $position['version'] = hash('sha256', json_encode($position, JSON_THROW_ON_ERROR));
                $position['navigation'] = ['url' => '/estimates/'.$estimateId.'?position_id='.$item->id];
                $positions[] = $position;
            }
            foreach ($totals as $field => $value) {
                $totals[$field] = FinanceDecimal::value($value, in_array($field, ['direct_costs', 'equipment_cost'], true) ? 4 : 2);
                if (isset($missingFields[$field])) {
                    $totals[$field] = null;
                }
            }
            $storedTotals = [];
            foreach (['total_amount', 'total_amount_with_vat', 'total_direct_costs', 'total_overhead_costs', 'total_estimated_profit', 'total_equipment_costs'] as $field) {
                $storedTotals[$field] = $estimate->$field === null ? null : FinanceDecimal::value((string) $estimate->$field);
            }
            $header = ['id' => $estimateId, 'number' => (string) $estimate->number, 'name' => (string) $estimate->name,
                'project_id' => $estimate->project_id === null ? null : (int) $estimate->project_id, 'version' => $estimate->version,
                'vat_rate' => $estimate->vat_rate === null ? null : (string) $estimate->vat_rate];
            $aggregation = ['method' => 'sum_top_level_accounted_positions', 'money_scale' => 2, 'quantity_scale' => 8, 'direct_cost_scale' => 4];
            $version = hash('sha256', json_encode([$header, $storedTotals, $positions, $totals, $aggregation], JSON_THROW_ON_ERROR));
            $source = ['source_type' => 'estimate', 'entity_type' => 'estimate', 'entity_id' => $estimateId, 'organization_id' => $organizationId,
                'project_id' => $header['project_id'],
                'version' => $version, 'fetched_at' => $fetchedAt, 'position_count' => count($positions), 'aggregation' => $aggregation,
                'navigation' => ['url' => '/estimates/'.$estimateId]];
            $complete = $positions !== [] && $missingFields === [] && $storedTotals['total_amount'] !== null
                && FinanceDecimal::compare($storedTotals['total_amount'], $totals['total_amount']) === 0;

            return ['estimate' => $header, 'totals' => $totals, 'stored_totals' => $storedTotals, 'positions' => $positions,
                'position_count' => count($positions), 'fetched_at' => $fetchedAt, 'version' => $version,
                'validation_status' => $complete ? 'verified' : 'partial', 'source_refs' => [$source],
                'totals_validation_status' => $complete ? 'verified' : 'partial',
                'missing_total_fields' => array_keys($missingFields),
                'aggregation' => $aggregation];
        });
    }

    public static function publicSourceReferences(array $evidence, array $shownPositions = []): array
    {
        $parent = $evidence['source_refs'][0];
        $sources = [$parent];
        $positions = array_column($evidence['positions'], null, 'id');
        $seen = [];
        foreach ($shownPositions as $shownPosition) {
            $id = (int) $shownPosition['id'];
            if (! isset($positions[$id]) || isset($seen[$id])) {
                continue;
            }
            $position = $positions[$id];
            $seen[$id] = true;
            $sources[] = ['source_type' => 'estimate', 'entity_type' => 'estimate_item', 'entity_id' => $id,
                'estimate_id' => (int) $evidence['estimate']['id'], 'project_id' => $parent['project_id'],
                'organization_id' => $parent['organization_id'], 'version' => $position['version'],
                'fetched_at' => $evidence['fetched_at'],
                'navigation' => ['url' => '/estimates/'.(int) $evidence['estimate']['id'].'?position_id='.$id]];
            if (count($seen) === 50) {
                break;
            }
        }

        return $sources;
    }
}
