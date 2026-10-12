<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Operations;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal as Decimal;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateItemResource;
use App\Models\EstimateItemTotal;
use App\Models\EstimateItemWork;
use App\Models\EstimateOperation;
use App\Models\EstimateSection;
use Illuminate\Support\Str;

final class EstimateOperationWriter
{
    public function __construct(private readonly EstimateCalculationService $totals)
    {
    }

    public function write(EstimateOperation $operation, array $sources, array $output): array
    {
        $ids = [];
        $groups = [];
        foreach ($output as $row) {
            $groups[$row['group']][] = $row;
        }
        if ($operation->mode === 'reprice') {
            foreach ($sources as $source) {
                $groups[(string) $source['id']] ??= [];
            }
        } else {
            $groups['combined'] ??= [];
        }
        foreach ($groups as $group => $rows) {
            $source = $operation->mode === 'reprice'
                ? array_values(array_filter($sources, fn (array $s): bool => (string) $s['id'] === (string) $group))[0]
                : array_values(array_filter($sources, fn (array $s): bool => $s['role'] === 'base'))[0] ?? $sources[0];
            $template = $source['header'];
            $attributes = array_intersect_key($template, array_flip((new Estimate())->getFillable()));
            foreach (['current_version_id', 'parent_estimate_id', 'approved_at', 'approved_by_user_id', 'structure_cache_path', 'regional_price_snapshot', 'statistics', 'import_diagnostics'] as $field) {
                $attributes[$field] = null;
            }
            $parameters = $operation->parameters;
            $reserve = '0';
            foreach ($sources as $reserveSource) {
                if ($reserveSource['role'] !== 'subtract' && ($operation->mode !== 'reprice' || (string) $reserveSource['id'] === (string) $group)) {
                    $reserve = Decimal::add($reserve, (string) ($reserveSource['header']['contingency_amount'] ?? '0'));
                }
            }
            $reprice = $operation->mode !== 'adjust' || ($parameters['reprice'] ?? false);
            if ($reprice || count($sources) > 1) {
                foreach (['estimate_region_id', 'estimate_price_zone_id', 'estimate_price_period_id', 'estimate_regional_price_version_id', 'base_price_date'] as $field) {
                    $attributes[$field] = null;
                }
            }
            $attributes = array_merge($attributes, [
                'organization_id' => $operation->organization_id, 'project_id' => $operation->project_id,
                'contract_id' => null, 'status' => 'draft', 'version' => 1, 'is_onboarding_demo' => false,
                'number' => 'OP-'.Str::upper(Str::random(12)),
                'name' => Str::limit($parameters['name'].($operation->mode === 'reprice' ? ' — '.$source['name'] : ''), 255, ''),
                'estimate_date' => $parameters['target_date'] ?? now()->toDateString(),
                'vat_rate' => $parameters['vat_rate'], 'overhead_rate' => $parameters['overhead_rate'], 'profit_rate' => $parameters['profit_rate'],
                'contingency_amount' => $reserve,
                'metadata' => ['currency' => $parameters['currency'], 'operation' => [
                    'id' => $operation->id, 'mode' => $operation->mode, 'reprice' => $operation->mode !== 'adjust' || ($parameters['reprice'] ?? false),
                    'source_digest' => $operation->source_snapshot['digest'], 'source_estimate_ids' => array_column($sources, 'id'),
                    'target_date' => $parameters['target_date'] ?? null, 'region_code' => $parameters['region_code'] ?? null,
                ]],
            ]);
            $estimate = Estimate::query()->create($attributes);
            $sections = [];
            foreach ($rows as $row) {
                if (\App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal::compare($row['quantity'], '0') === 0) {
                    continue;
                }
                $parent = null;
                $path = [];
                foreach ($row['section_path'] as $name) {
                    $path[] = $name;
                    $key = json_encode($path, JSON_THROW_ON_ERROR);
                    if (! isset($sections[$key])) {
                        $sections[$key] = EstimateSection::query()->create([
                            'estimate_id' => $estimate->id, 'stable_key' => (string) Str::uuid(), 'parent_section_id' => $parent,
                            'name' => $name, 'section_number' => count($sections) + 1, 'sort_order' => count($sections), 'is_summary' => false,
                        ]);
                    }
                    $parent = $sections[$key]->id;
                }
                $map = [];
                $resources = [];
                $this->node($estimate, $row['node'], $parent, null, $row['sources'], $operation, $map, $resources);
                foreach ($resources as [$resource, $oldRepresented]) {
                    $resource->update(['represented_by_item_id' => $oldRepresented === null ? null : ($map[$oldRepresented] ?? null)]);
                }
            }
            foreach ($sections as $section) {
                if ($section->parent_section_id === null) {
                    $this->totals->calculateSectionTotal($section);
                }
            }
            $this->totals->calculateEstimateTotal($estimate);
            $sums = array_fill_keys(['total_amount', 'total_direct_costs', 'total_equipment_costs', 'total_overhead_costs', 'total_estimated_profit', 'total_base_direct_costs', 'total_base_materials_cost', 'total_base_machinery_cost', 'total_base_labor_cost', 'total_base_overhead_amount', 'total_base_profit_amount'], '0');
            $knownBase = true;
            foreach ($rows as $row) {
                $attrs = $row['node']['attributes'];
                if ($attrs['is_not_accounted'] || Decimal::compare($row['quantity'], '0') === 0) {
                    continue;
                }
                foreach (['total_amount' => 'total_amount', 'total_direct_costs' => 'direct_costs', 'total_equipment_costs' => 'equipment_cost', 'total_overhead_costs' => 'overhead_amount', 'total_estimated_profit' => 'profit_amount', 'total_base_materials_cost' => 'base_materials_cost', 'total_base_machinery_cost' => 'base_machinery_cost', 'total_base_labor_cost' => 'base_labor_cost', 'total_base_overhead_amount' => 'base_overhead_amount', 'total_base_profit_amount' => 'base_profit_amount'] as $target => $field) {
                    $sums[$target] = Decimal::add($sums[$target], (string) ($attrs[$field] ?? '0'));
                }
                $knownBase = $knownBase && $attrs['base_unit_price'] !== null;
                $sums['total_base_direct_costs'] = Decimal::add($sums['total_base_direct_costs'], Decimal::multiply(Decimal::multiply($row['quantity'], (string) ($attrs['base_unit_price'] ?? '0'), 8), Decimal::multiply((string) ($attrs['price_coefficient'] ?: '1'), (string) ($attrs['coefficient_total'] ?: '1'), 12)));
            }
            if (! $knownBase) {
                foreach (array_keys($sums) as $field) {
                    if (str_starts_with($field, 'total_base_') && ! in_array($field, ['total_base_overhead_amount', 'total_base_profit_amount'], true)) {
                        $sums[$field] = null;
                    }
                }
            }
            $sums['total_amount'] = Decimal::add($sums['total_amount'], $reserve);
            $sums['total_amount_with_vat'] = Decimal::multiply($sums['total_amount'], Decimal::add('1', Decimal::divide((string) $parameters['vat_rate'], '100', 8)));
            $estimate->forceFill($sums)->save();
            $ids[] = $estimate->id;
        }

        return $ids;
    }

    private function node(Estimate $estimate, array $node, ?int $sectionId, ?int $parentId, array $sources, EstimateOperation $operation, array &$map, array &$resources): void
    {
        $attributes = array_intersect_key($node['attributes'], array_flip((new EstimateItem())->getFillable()));
        $attributes = array_merge($attributes, [
            'estimate_id' => $estimate->id, 'estimate_section_id' => $sectionId, 'parent_work_id' => $parentId, 'stable_key' => (string) Str::uuid(),
            'actual_quantity' => null, 'actual_unit_price' => null, 'procurement_status' => 'pending',
            'metadata' => array_merge($attributes['metadata'] ?? [], ['operation' => ['id' => $operation->id, 'sources' => $sources, 'source_node' => $node['key'], 'price_source' => $node['price_source'] ?? null, 'equipment_price_source' => $node['equipment_price_source'] ?? null]]),
        ]);
        $item = new EstimateItem($attributes);
        $item->forceFill(array_intersect_key($node['attributes'], array_flip(['code', 'machinery_id', 'labor_resource_id', 'base_overhead_amount', 'base_profit_amount', 'overhead_rate', 'profit_rate', 'base_machinery_labor_cost'])));
        $item->save();
        $map[$node['attributes']['id']] = $item->id;
        foreach ($node['children'] as $child) {
            $this->node($estimate, $child, $sectionId, $item->id, $sources, $operation, $map, $resources);
        }
        foreach ($node['resources'] as $source) {
            $attributes = array_intersect_key($source, array_flip((new EstimateItemResource())->getFillable()));
            $attributes['estimate_item_id'] = $item->id;
            $attributes['represented_by_item_id'] = null;
            $attributes['finance_source_hash'] = null;
            $resources[] = [EstimateItemResource::query()->create($attributes), $source['represented_by_item_id'] ?? null];
        }
        foreach (['works' => EstimateItemWork::class, 'totals' => EstimateItemTotal::class] as $relation => $model) {
            foreach ($node[$relation] as $source) {
                $attributes = array_intersect_key($source, array_flip((new $model())->getFillable()));
                $attributes['estimate_item_id'] = $item->id;
                $model::query()->create($attributes);
            }
        }
    }
}
