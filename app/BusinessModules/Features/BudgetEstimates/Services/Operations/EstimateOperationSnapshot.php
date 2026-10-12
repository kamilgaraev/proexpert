<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Operations;

use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceExecutionQuantity;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal as Decimal;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateOperation;
use App\Models\EstimateOperationPriceList;
use App\Models\NormativeRate;
use App\Models\PriceIndex;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

final class EstimateOperationSnapshot
{
    public function __construct(private readonly EstimateOperationAccess $access, private readonly EstimateFinanceExecutionQuantity $execution)
    {
    }

    public function capture(EstimateOperation $operation, User $actor, bool $lock = false): array
    {
        $parameters = $operation->parameters;
        $roles = [];
        foreach ($parameters['source_ids'] ?? [] as $id) {
            $roles[(int) $id] = 'source';
        }
        if ($operation->mode === 'adjust') {
            $roles = [(int) $parameters['base_id'] => 'base'];
            foreach ($parameters['addition_ids'] ?? [] as $id) {
                $roles[(int) $id] = 'add';
            }
            foreach ($parameters['subtraction_ids'] ?? [] as $id) {
                $roles[(int) $id] = 'subtract';
            }
        }
        ksort($roles);
        $sources = [];
        $allItems = [];
        $count = 0;
        foreach ($roles as $id => $role) {
            $this->access->source($actor, (int) $operation->organization_id, (int) $operation->project_id, $id);
            $estimate = Estimate::query()->whereKey($id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
            $sourceCount = $estimate->items()->count();
            foreach ([\App\Models\EstimateItemResource::class, \App\Models\EstimateItemWork::class, \App\Models\EstimateItemTotal::class] as $model) {
                $sourceCount += $model::query()->whereIn('estimate_item_id', $estimate->items()->select('estimate_items.id'))->count();
            }
            if ($count + $sourceCount > 20000) {
                throw ValidationException::withMessages(['sources' => trans_message('estimate_operations.too_large')]);
            }
            $count += $sourceCount;
            if ($lock) {
                EstimateItem::query()->where('estimate_id', $id)->orderBy('id')->lockForUpdate()->get(['id']);
                \App\Models\EstimateSection::query()->where('estimate_id', $id)->orderBy('id')->lockForUpdate()->get(['id']);
                foreach ([\App\Models\EstimateItemResource::class, \App\Models\EstimateItemWork::class, \App\Models\EstimateItemTotal::class] as $model) {
                    $model::query()->whereIn('estimate_item_id', $estimate->items()->select('estimate_items.id'))->orderBy('id')->lockForUpdate()->get(['id']);
                }
            }
            $estimate->load(['sections', 'items.measurementUnit', 'items.resources.measurementUnit', 'items.works', 'items.totals', 'items.normativeRate.resources']);
            $execution = $this->execution->snapshot($estimate, $lock);
            $sections = $estimate->sections->keyBy('id');
            $items = [];
            foreach ($estimate->items as $item) {
                $path = [];
                $section = $sections->get($item->estimate_section_id);
                $visited = [];
                while ($section !== null) {
                    if (isset($visited[$section->id])) {
                        throw ValidationException::withMessages(['sources' => trans_message('estimate_operations.invalid')]);
                    }
                    $visited[$section->id] = true;
                    array_unshift($path, $section->name);
                    $section = $sections->get($section->parent_section_id);
                }
                $attributes = $item->attributesToArray();
                $fallbackUnit = $item->metadata['unit'] ?? $item->metadata['unit_name'] ?? '';
                $entry = [
                    'attributes' => $attributes,
                    'unit' => $this->text((string) ($item->measurementUnit?->short_name ?: $item->measurementUnit?->name ?: $item->normativeRate?->measurement_unit ?: (is_string($fallbackUnit) ? $fallbackUnit : ''))),
                    'section_path' => $path,
                    'quantity' => Decimal::value((string) ($item->quantity_total ?? $item->quantity ?? '0'), 8),
                    'resources' => $item->resources->map(fn ($r): array => $r->attributesToArray() + ['operation_price_key' => $this->text($r->name).'|'.$r->resource_type.'|'.$this->text((string) ($r->measurementUnit?->short_name ?: $r->measurementUnit?->name ?: ''))])->all(),
                    'works' => $item->works->map(fn ($r): array => $r->attributesToArray())->all(),
                    'totals' => $item->totals->map(fn ($r): array => $r->attributesToArray())->all(),
                    'execution' => $execution[$item->id] ?? ['quantity' => '0', 'unknown' => false],
                ];
                $items[] = $entry;
                $allItems[$id.':'.$item->id] = $entry;
            }
            $parents = array_column(array_column($items, 'attributes'), 'parent_work_id', 'id');
            foreach ($parents as $itemId => $parentId) {
                $visited = [$itemId => true];
                while ($parentId !== null) {
                    if (isset($visited[$parentId]) || ! array_key_exists($parentId, $parents) || count($visited) > 100) {
                        throw ValidationException::withMessages(['sources' => trans_message('estimate_operations.invalid')]);
                    }
                    $visited[$parentId] = true;
                    $parentId = $parents[$parentId];
                }
            }
            $sources[] = ['id' => $id, 'name' => $estimate->name, 'role' => $role, 'header' => $estimate->attributesToArray(), 'items' => $items];
        }
        [$prices, $priceSnapshot] = $this->prices($operation, $allItems, $lock);
        $snapshot = ['sources' => $sources, 'price_sources' => $priceSnapshot];

        return ['sources' => $sources, 'prices' => $prices, 'price_sources' => $priceSnapshot, 'digest' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 'headers' => array_map(fn (array $s): array => ['id' => $s['id'], 'header' => $s['header']], $sources)];
    }

    private function prices(EstimateOperation $operation, array $items, bool $lock): array
    {
        $parameters = $operation->parameters;
        if ($operation->mode === 'adjust' && ! ($parameters['reprice'] ?? false)) {
            return [[], []];
        }
        $date = Carbon::parse($parameters['target_date']);
        $index = isset($parameters['price_index_id']) ? PriceIndex::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($parameters['price_index_id']) : null;
        $validIndex = $index !== null && $index->index_type === 'construction_general' && $index->region_code === $parameters['region_code']
            && (int) $index->year === $date->year
            && ($index->month !== null ? (int) $index->month === $date->month : ($index->quarter === null || (int) $index->quarter === (int) ceil($date->month / 3)))
            && Decimal::compare((string) $index->index_value, '0') > 0;
        $rates = isset($parameters['normative_collection_id']) ? NormativeRate::query()->where('collection_id', $parameters['normative_collection_id'])
            ->whereIn('code', array_values(array_filter(array_column(array_column($items, 'attributes'), 'normative_rate_code'))))->when($lock, fn ($q) => $q->lockForUpdate())->with(['resources' => fn ($q) => $q->when($lock, fn ($q) => $q->lockForUpdate())])->get() : collect();
        $lists = EstimateOperationPriceList::query()->where('organization_id', $operation->organization_id)->where('project_id', $operation->project_id)
            ->whereIn('id', $parameters['price_list_ids'] ?? [])->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $entries = [];
        foreach ($lists as $list) {
            if ($list->currency !== $parameters['currency'] || $list->effective_date->greaterThan($date)) {
                continue;
            }
            foreach ($list->entries as $entry) {
                $entry['source'] = ['kind' => 'price_list', 'id' => $list->id, 'checksum' => $list->checksum, 'entry' => $entry['row'], 'date' => $list->effective_date->toDateString(), 'target_date' => $parameters['target_date']];
                $entry['id'] = $list->id.':'.$entry['row'];
                $entry['unit_price'] = $list->includes_vat ? Decimal::divide($entry['unit_price'], Decimal::add('1', Decimal::divide((string) $list->vat_rate, '100', 8)), 4) : $entry['unit_price'];
                $entries[] = $entry;
            }
        }
        $entryIndex = [];
        foreach ($entries as $entry) {
            $entryIndex[$this->text($entry['code']).'|'.$entry['item_type'].'|'.$this->text($entry['unit'])][] = $entry;
        }
        $rateIndex = [];
        $resourceIndex = [];
        foreach ($rates as $rate) {
            $rateIndex[$rate->code.'|'.$this->text($rate->measurement_unit)][] = $rate;
            foreach ($rate->resources as $resource) {
                $resourceIndex[$resource->code.'|'.$resource->resource_type.'|'.$this->text($resource->measurement_unit)][(string) $resource->unit_price] = $resource;
            }
        }
        $result = [];
        foreach ($items as $key => $item) {
            $attrs = $item['attributes'];
            if (! $attrs['is_manual'] && ($attrs['normative_rate_id'] || ($attrs['item_type'] === 'work' && $attrs['normative_rate_code']))) {
                if ($parameters['currency'] !== 'RUB') {
                    $result[$key] = ['error' => 'currency_mismatch'];
                    continue;
                }
                $matches = $rateIndex[$attrs['normative_rate_code'].'|'.$item['unit']] ?? [];
                if (count($matches) !== 1) {
                    $result[$key] = ['error' => 'basis_missing'];
                    continue;
                }
                if (! $validIndex) {
                    $result[$key] = ['error' => 'index_missing'];
                    continue;
                }
                $rate = $matches[0];
                $base = Decimal::add((string) $rate->materials_cost, Decimal::add((string) $rate->machinery_cost, (string) $rate->labor_cost));
                if (Decimal::compare($base, '0') === 0) {
                    $base = (string) $rate->base_price;
                }
                if (Decimal::compare($base, '0') <= 0) {
                    $result[$key] = ['error' => 'basis_missing'];
                    continue;
                }
                $result[$key] = ['unit_price' => Decimal::multiply($base, (string) $index->index_value, 4), 'base_unit_price' => $base, 'index' => (string) $index->index_value,
                    'materials_unit_price' => Decimal::multiply((string) $rate->materials_cost, (string) $index->index_value, 4),
                    'machinery_unit_price' => Decimal::multiply((string) $rate->machinery_cost, (string) $index->index_value, 4),
                    'labor_unit_price' => Decimal::multiply((string) $rate->labor_cost, (string) $index->index_value, 4),
                    'source' => ['kind' => 'normative', 'rate_id' => $rate->id, 'collection_id' => $rate->collection_id, 'index_id' => $index->id, 'index' => (string) $index->index_value, 'base_year' => $rate->base_price_year],
                    'resource_prices' => $rate->resources->groupBy(fn ($resource): string => $this->text($resource->name).'|'.$resource->resource_type.'|'.$this->text($resource->measurement_unit))->filter(fn ($group): bool => $group->count() === 1)->map(fn ($group): string => Decimal::multiply((string) $group->first()->unit_price, (string) $index->index_value, 4))->all(),
                ];
                continue;
            }
            $code = (string) ($attrs['normative_rate_code'] ?: ($attrs['code'] ?? null) ?: ($attrs['metadata']['resource_code'] ?? $attrs['metadata']['code'] ?? ''));
            $matches = $code === '' ? [] : ($entryIndex[$this->text($code).'|'.$attrs['item_type'].'|'.$item['unit']] ?? []);
            $result[$key] = count($matches) === 1 ? $matches[0] : ['error' => 'price_missing', 'candidates' => $matches];
            if ($attrs['parent_work_id'] !== null && ! $attrs['is_manual'] && $validIndex && count($matches) !== 1) {
                $resourceMatches = $resourceIndex[$code.'|'.$attrs['item_type'].'|'.$item['unit']] ?? [];
                if (count($resourceMatches) === 1) {
                    $resource = array_values($resourceMatches)[0];
                    $result[$key] = ['unit_price' => Decimal::multiply((string) $resource->unit_price, (string) $index->index_value, 4), 'source' => ['kind' => 'normative_resource', 'resource_id' => $resource->id, 'index_id' => $index->id], 'base_unit_price' => (string) $resource->unit_price, 'index' => (string) $index->index_value];
                }
            }
        }

        return [$result, ['index' => $index?->attributesToArray(), 'rates' => $rates->toArray(), 'lists' => $lists->map(fn ($l): array => $l->attributesToArray())->all()]];
    }

    private function text(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
