<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Operations;

use App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal as Decimal;

final class EstimateOperationCalculator
{
    private array $childrenByParent = [];

    public function calculate(array $sources, array $parameters, array $prices, array $resolutions = []): array
    {
        $this->childrenByParent = [];
        foreach ($sources as $source) {
            foreach ($source['items'] as $item) {
                if ($item['attributes']['parent_work_id'] !== null) {
                    $this->childrenByParent[$source['id'].':'.$item['attributes']['parent_work_id']][] = $item;
                }
            }
        }
        $mode = $parameters['mode'];
        $reprice = $mode !== 'adjust' || ($parameters['reprice'] ?? false);
        $signedSubtraction = $mode === 'adjust' && ! $reprice && ($parameters['subtraction_quantity_mode'] ?? 'positive') === 'signed';
        $rows = [];
        $originalTrees = [];
        $base = [];
        $excluded = [];
        $reserves = [];
        foreach ($sources as $source) {
            if ($source['role'] !== 'subtract') {
                $group = $mode === 'reprice' ? (string) $source['id'] : 'combined';
                $reserves[$group] = Decimal::add($reserves[$group] ?? '0', (string) ($source['header']['contingency_amount'] ?? '0'));
            }
            foreach ($source['items'] as $item) {
                if ($item['attributes']['parent_work_id'] !== null) {
                    continue;
                }
                $key = $source['id'].':'.$item['attributes']['id'];
                $node = $this->tree($item, $source['role']);
                $node['rates'] = ['overhead_rate' => $source['header']['overhead_rate'] ?? '0', 'profit_rate' => $source['header']['profit_rate'] ?? '0'];
                $originalTrees[$key] = $node;
                $row = [
                    'key' => $key, 'estimate_id' => $source['id'], 'estimate_name' => $source['name'],
                    'source_item_id' => $item['attributes']['id'], 'name' => $item['attributes']['name'],
                    'unit' => $item['unit'], 'old_unit' => $item['unit'], 'section_path' => $item['section_path'],
                    'old_quantity' => $item['quantity'], 'quantity' => $item['quantity'],
                    'executed_quantity' => $item['execution']['quantity'] ?? '0',
                    'execution_unknown' => $item['execution']['unknown'] ?? false,
                    'old_price' => $this->unitPrice($item), 'new_price' => $this->unitPrice($item),
                    'role' => $source['role'], 'group' => $mode === 'reprice' ? (string) $source['id'] : 'combined',
                    'node' => $node, 'conflicts' => [], 'sources' => [['estimate_id' => $source['id'], 'item_id' => $item['attributes']['id'], 'quantity' => $item['quantity'], 'unit' => $item['unit'], 'role' => $source['role']]],
                    'price_source' => null, 'target_key' => null,
                ];
                if (! $reprice && $source['role'] !== 'subtract' && ($source['header']['metadata']['currency'] ?? 'RUB') !== $parameters['currency']) {
                    $row['conflicts'][] = $this->conflict('price:'.$key, 'currency_mismatch');
                }
                $rows[$key] = $row;
                if ($source['role'] === 'base') {
                    $floor = $this->executedFloor($node);
                    $row['execution_unknown'] = $floor === null;
                    $row['executed_quantity'] = $floor ?? $row['executed_quantity'];
                    $rows[$key] = $row;
                    $base[$key] = $row;
                }
            }
        }
        $baseIndex = $this->indexRows($base);
        foreach ($rows as $key => &$row) {
            if ($row['role'] !== 'subtract') {
                continue;
            }
            if (Decimal::compare($row['quantity'], '0') === 0 || (! $signedSubtraction && Decimal::compare($row['quantity'], '0') < 0)) {
                $row['conflicts'][] = $this->conflict('match:'.$key, 'quantity_invalid');
                continue;
            }
            $candidates = $this->candidates($row, $base, $baseIndex);
            $signature = $this->signature($row, includeSection: ! $signedSubtraction);
            $exact = array_filter($candidates, fn (array $candidate): bool => $signature !== null && $signature === $this->signature($candidate, includeSection: ! $signedSubtraction));
            $decision = $resolutions['match:'.$key] ?? null;
            $target = $decision['target_key'] ?? (count($exact) === 1 ? array_key_first($exact) : null);
            if ($target === null || ! isset($base[$target])) {
                $row['conflicts'][] = $this->conflict('match:'.$key, 'subtract_match_required', $candidates);
                continue;
            }
            $factor = (string) ($decision['unit_factor'] ?? '1');
            if ($row['unit'] !== $base[$target]['unit'] && ! isset($decision['unit_factor'])) {
                $row['conflicts'][] = $this->conflict('match:'.$key, 'subtract_match_required', [$target => $base[$target]]);
                continue;
            }
            if (Decimal::compare($factor, '0') <= 0 || $row['node']['attributes']['item_type'] !== $base[$target]['node']['attributes']['item_type']) {
                $row['conflicts'][] = $this->conflict('match:'.$key, 'subtract_match_required', $candidates);
                continue;
            }
            $quantity = Decimal::multiply($row['quantity'], $factor, 8);
            if ($signedSubtraction && Decimal::compare($quantity, '0') * Decimal::compare($base[$target]['old_quantity'], '0') !== -1) {
                $row['conflicts'][] = $this->conflict('match:'.$key, 'subtraction_sign_invalid');
                continue;
            }
            $excluded[$target] = Decimal::add($excluded[$target] ?? '0', $this->magnitude($quantity));
            $row['target_key'] = $target;
            $remaining = $signedSubtraction && Decimal::compare($base[$target]['old_quantity'], '0') < 0
                ? Decimal::add($base[$target]['old_quantity'], $excluded[$target])
                : Decimal::subtract($base[$target]['old_quantity'], $excluded[$target]);
            if ($base[$target]['execution_unknown']) {
                $row['conflicts'][] = $this->conflict('match:'.$key, 'execution_unknown');
            } elseif (Decimal::compare($excluded[$target], Decimal::subtract($this->magnitude($base[$target]['old_quantity']), $base[$target]['executed_quantity'])) > 0
                || ! $this->preservesExecution($base[$target]['node'], $remaining)) {
                $row['conflicts'][] = $this->conflict('match:'.$key, 'execution_exceeded');
            }
            $row['quantity'] = '0';
        }
        unset($row);
        foreach ($excluded as $key => $quantity) {
            $rows[$key]['quantity'] = $signedSubtraction && Decimal::compare($rows[$key]['old_quantity'], '0') < 0
                ? Decimal::add($rows[$key]['quantity'], $quantity)
                : Decimal::subtract($rows[$key]['quantity'], $quantity);
            foreach ($rows as $excludedRow) {
                if ($excludedRow['role'] === 'subtract' && $excludedRow['target_key'] === $key) {
                    $rows[$key]['sources'] = array_merge($rows[$key]['sources'], $excludedRow['sources']);
                }
            }
        }
        $output = [];
        $outputIndex = [];
        foreach ($rows as $key => &$row) {
            if ($row['role'] === 'subtract') {
                $row['total_amount'] = '0.00';
                continue;
            }
            $negativeBase = $signedSubtraction && $row['role'] === 'base' && Decimal::compare($row['old_quantity'], '0') < 0;
            if ((! $negativeBase && Decimal::compare($row['quantity'], '0') < 0) || ($row['role'] === 'add' && Decimal::compare($row['quantity'], '0') <= 0)) {
                $row['conflicts'][] = $this->conflict('match:'.$key, 'quantity_invalid');
                $row['total_amount'] = '0.00';
                continue;
            }
            $this->priceNode($row['node'], $row['quantity'], $reprice, $prices, $resolutions, $row['conflicts'], $parameters);
            $row['new_price'] = $row['node']['attributes']['current_unit_price'] ?? $row['node']['attributes']['unit_price'];
            $row['price_source'] = $row['node']['price_source'] ?? null;
            $row['total_amount'] = $row['node']['attributes']['is_not_accounted'] ? '0.00' : $row['node']['attributes']['total_amount'];
            if ($mode === 'reprice' || Decimal::compare($row['quantity'], '0') === 0) {
                $output[$key] = $row;
                continue;
            }
            $candidates = $this->candidates($row, $output, $outputIndex);
            if ($signedSubtraction) {
                $candidates = array_filter($candidates, fn (array $candidate): bool => Decimal::compare($row['quantity'], '0') === Decimal::compare($candidate['quantity'], '0'));
            }
            $exact = array_filter($candidates, fn (array $candidate): bool => $this->signature($row, true) !== null && $this->signature($row, true) === $this->signature($candidate, true));
            $decision = $resolutions['match:'.$key] ?? null;
            $target = ($decision['action'] ?? null) === 'separate' ? null : ($decision['target_key'] ?? (count($exact) === 1 ? array_key_first($exact) : null));
            if ($target !== null && isset($output[$target])) {
                if ($row['unit'] !== $output[$target]['unit'] && isset($decision['unit_factor']) && Decimal::compare((string) $decision['unit_factor'], '0') > 0) {
                    $factor = (string) $decision['unit_factor'];
                    $row['quantity'] = Decimal::multiply($row['quantity'], $factor, 8);
                    $row['unit'] = $output[$target]['unit'];
                    $row['node']['unit'] = $row['unit'];
                    $row['node']['quantity'] = Decimal::multiply($row['node']['quantity'], $factor, 8);
                    $row['node']['attributes']['measurement_unit_id'] = $output[$target]['node']['attributes']['measurement_unit_id'];
                    $row['node']['attributes']['quantity_total'] = $row['quantity'];
                    $row['node']['attributes']['quantity'] = $this->rawQuantity($output[$target]['node'], $row['quantity']);
                    $row['node']['attributes']['quantity_coefficient'] = $output[$target]['node']['attributes']['quantity_coefficient'];
                    foreach ($row['node']['source_items'] as &$sourceItem) {
                        $sourceItem['result_quantity'] = Decimal::multiply($sourceItem['result_quantity'], $factor, 8);
                        $sourceItem['result_unit'] = $row['unit'];
                    }
                    unset($sourceItem);
                    foreach (['unit_price', 'current_unit_price', 'base_unit_price'] as $field) {
                        if (isset($row['node']['attributes'][$field])) {
                            $row['node']['attributes'][$field] = Decimal::divide((string) $row['node']['attributes'][$field], $factor, 4);
                        }
                    }
                    foreach ($row['node']['resources'] as &$resource) {
                        $resource['quantity_per_unit'] = Decimal::divide((string) $resource['quantity_per_unit'], $factor, 18);
                    }
                    unset($resource);
                    $row['new_price'] = $row['node']['attributes']['current_unit_price'];
                    $row['conversion'] = ['factor' => $factor, 'from' => $row['old_unit'], 'to' => $row['unit']];
                }
                if ($row['unit'] !== $output[$target]['unit'] || $this->terms($row['node'], ! $reprice) !== $this->terms($output[$target]['node'], ! $reprice)
                    || ($signedSubtraction && Decimal::compare($row['quantity'], '0') !== Decimal::compare($output[$target]['quantity'], '0'))) {
                    $row['conflicts'][] = $this->conflict('match:'.$key, 'match_required', $candidates);
                    $output[$key] = $row;
                    $this->indexRow($outputIndex, $row);
                    continue;
                }
                if ($reprice && $decision !== null) {
                    $row['conflicts'] = array_values(array_filter($row['conflicts'], fn (array $conflict): bool => ! str_starts_with($conflict['key'], 'price:')));
                    $contribution = $row['node'];
                    $row['node'] = $originalTrees[$target];
                    $this->priceNode($row['node'], $row['quantity'], true, $prices, $resolutions, $row['conflicts'], $parameters);
                    $this->copySourceItems($row['node'], $contribution);
                    $row['new_price'] = $row['node']['attributes']['current_unit_price'];
                    $row['price_source'] = $row['node']['price_source'] ?? null;
                    $row['total_amount'] = $row['node']['attributes']['is_not_accounted'] ? '0.00' : $row['node']['attributes']['total_amount'];
                }
                $output[$target]['quantity'] = Decimal::add($output[$target]['quantity'], $row['quantity']);
                $output[$target]['sources'] = array_merge($output[$target]['sources'], $row['sources']);
                $output[$target]['conflicts'] = array_merge($output[$target]['conflicts'], $row['conflicts']);
                $this->mergeNodes($output[$target]['node'], $row['node']);
                $output[$target]['total_amount'] = Decimal::add($output[$target]['total_amount'], $row['total_amount']);
                $row['target_key'] = $target;
            } else {
                $differentPricesOnly = ! $reprice && count($candidates) > 0 && count($exact) === 0
                    && count(array_filter($candidates, fn (array $candidate): bool => $this->signature($row) !== null && $this->signature($row) === $this->signature($candidate) && Decimal::compare($this->unitPrice($row['node']), $this->unitPrice($candidate['node'])) !== 0)) > 0;
                if ($candidates !== [] && $decision === null && ! $differentPricesOnly) {
                    $row['conflicts'][] = $this->conflict('match:'.$key, 'match_required', $candidates);
                }
                $output[$key] = $row;
                $this->indexRow($outputIndex, $row);
            }
        }
        unset($row);
        $total = '0';
        $groupTotals = [];
        $groupComponents = [];
        $groupBaseKnown = [];
        $componentFields = ['direct_costs', 'equipment_cost', 'overhead_amount', 'profit_amount', 'other_cost',
            'base_materials_cost', 'base_machinery_cost', 'base_labor_cost', 'base_overhead_amount', 'base_profit_amount'];
        foreach ($output as $outputRow) {
            $group = $outputRow['group'];
            $groupTotals[$group] = Decimal::add($groupTotals[$group] ?? '0', $outputRow['total_amount'] ?? '0');
            $attrs = $outputRow['node']['attributes'];
            if (! $attrs['is_not_accounted'] && Decimal::compare($outputRow['quantity'], '0') !== 0) {
                $groupBaseKnown[$group] = ($groupBaseKnown[$group] ?? true) && ($attrs['base_unit_price'] ?? null) !== null;
                foreach ($componentFields as $field) {
                    $groupComponents[$group][$field] = Decimal::add($groupComponents[$group][$field] ?? '0', (string) ($attrs[$field] ?? '0'));
                }
                $baseDirect = Decimal::multiply(Decimal::multiply($outputRow['quantity'], (string) ($attrs['base_unit_price'] ?? '0'), 8),
                    Decimal::multiply((string) ($attrs['price_coefficient'] ?: '1'), (string) ($attrs['coefficient_total'] ?: '1'), 12));
                $groupComponents[$group]['base_direct_costs'] = Decimal::add($groupComponents[$group]['base_direct_costs'] ?? '0', $baseDirect);
            }
            if (! $this->fits($outputRow['node'])) {
                $rows[$outputRow['key']]['conflicts'][] = $this->conflict('match:'.$outputRow['key'], 'numeric_range');
            }
        }
        foreach ($groupTotals as $group => $amount) {
            $amount = Decimal::add($amount, $reserves[$group] ?? '0');
            $componentOverflow = Decimal::compare($this->magnitude($reserves[$group] ?? '0'), '9999999999999.99') > 0;
            foreach ($groupComponents[$group] ?? [] as $field => $component) {
                if (str_starts_with($field, 'base_') && ! ($groupBaseKnown[$group] ?? false) && ! in_array($field, ['base_overhead_amount', 'base_profit_amount'], true)) {
                    continue;
                }
                $componentOverflow = $componentOverflow || Decimal::compare($this->magnitude($component), '9999999999999.99') > 0;
            }
            if ($componentOverflow || Decimal::compare($this->magnitude($amount), '9999999999999.99') > 0 || Decimal::compare($this->magnitude(Decimal::multiply($amount, Decimal::add('1', Decimal::divide((string) $parameters['vat_rate'], '100', 8)))), '9999999999999.99') > 0) {
                foreach ($rows as &$rangeRow) {
                    if ($rangeRow['group'] === $group && $rangeRow['role'] !== 'subtract') {
                        $rangeRow['conflicts'][] = $this->conflict('match:'.$rangeRow['key'], 'numeric_range');
                        break;
                    }
                }
                unset($rangeRow);
            }
        }
        $conflictCount = 0;
        foreach ($rows as $row) {
            $conflictCount += count($row['conflicts']);
        }
        foreach ($output as $row) {
            $total = Decimal::add($total, $row['total_amount'] ?? '0');
        }
        $reserve = '0';
        foreach ($reserves as $amount) {
            $reserve = Decimal::add($reserve, $amount);
        }
        $total = Decimal::add($total, $reserve);
        foreach ($rows as &$row) {
            $target = $row['role'] === 'subtract' ? null : ($output[$row['target_key'] ?? $row['key']] ?? null);
            $row['result_quantity'] = $target['quantity'] ?? '0';
            $row['result_amount'] = $target['total_amount'] ?? '0';
        }
        unset($row);

        return ['rows' => array_values($rows), 'output' => array_values($output), 'summary' => [
            'total_amount' => Decimal::value($total),
            'contingency_amount' => Decimal::value($reserve),
            'total_amount_with_vat' => Decimal::multiply($total, Decimal::add('1', Decimal::divide((string) $parameters['vat_rate'], '100', 8))),
            'conflict_count' => $conflictCount, 'source_row_count' => count($rows), 'result_row_count' => count($output),
        ]];
    }

    private function tree(array $item, string $role): array
    {
        $item['key'] = $item['attributes']['estimate_id'].':'.$item['attributes']['id'];
        $item['source_items'] = [[
            'estimate_id' => $item['attributes']['estimate_id'], 'item_id' => $item['attributes']['id'], 'role' => $role,
            'quantity' => $item['quantity'], 'unit' => $item['unit'], 'result_quantity' => $item['quantity'], 'result_unit' => $item['unit'],
        ]];
        $item['children'] = [];
        foreach ($this->childrenByParent[$item['key']] ?? [] as $child) {
            $item['children'][] = $this->tree($child, $role);
        }

        return $item;
    }

    private function fits(array $node): bool
    {
        $attrs = $node['attributes'];
        $limits = ['quantity' => '999999999999.99999999', 'quantity_total' => '999999999999.99999999', 'unit_price' => '9999999999999999.9999', 'current_unit_price' => '9999999999999999.9999'];
        foreach (['direct_costs', 'materials_cost', 'machinery_cost', 'labor_cost', 'equipment_cost', 'other_cost',
            'base_materials_cost', 'base_machinery_cost', 'base_labor_cost', 'base_overhead_amount', 'base_profit_amount', 'base_machinery_labor_cost',
            'overhead_amount', 'profit_amount', 'total_amount', 'current_total_amount'] as $field) {
            $limits[$field] = '9999999999999.99';
        }
        foreach ($limits as $field => $limit) {
            if (Decimal::compare($this->magnitude((string) ($attrs[$field] ?? '0')), $limit) > 0) {
                return false;
            }
        }
        foreach ($node['children'] as $child) {
            if (! $this->fits($child)) {
                return false;
            }
        }
        foreach ($node['resources'] as $resource) {
            foreach (['total_quantity' => '999999999999.99999999', 'unit_price' => '9999999999999999.9999', 'total_amount' => '9999999999999.99'] as $field => $limit) {
                if (Decimal::compare($this->magnitude((string) ($resource[$field] ?? '0')), $limit) > 0) {
                    return false;
                }
            }
        }

        return true;
    }

    private function priceNode(array &$node, string $quantity, bool $reprice, array $prices, array $resolutions, array &$conflicts, array $parameters): void
    {
        $oldQuantity = $node['quantity'];
        $ratio = Decimal::compare($oldQuantity, '0') === 0 ? '0' : Decimal::divide($quantity, $oldQuantity, 12);
        $attrs = &$node['attributes'];
        $original = $attrs;
        $factor = Decimal::multiply((string) ($attrs['price_coefficient'] ?: '1'), (string) ($attrs['coefficient_total'] ?: '1'), 12);
        $oldParts = $this->childCosts($node);
        $oldEquipment = Decimal::compare((string) ($original['equipment_cost'] ?? '0'), $oldParts['equipment']) > 0 ? (string) $original['equipment_cost'] : $oldParts['equipment'];
        $originalDirect = (string) ($original['direct_costs'] ?? '0');
        if ($attrs['item_type'] === 'work' && Decimal::compare((string) ($original['total_amount'] ?? '0'), '0') > 0) {
            $netDirect = Decimal::subtract((string) $original['total_amount'], Decimal::add($oldEquipment, Decimal::add((string) ($original['overhead_amount'] ?? '0'), (string) ($original['profit_amount'] ?? '0'))));
            if (Decimal::compare($netDirect, '0') >= 0) {
                $originalDirect = $netDirect;
            }
        }
        $completeResources = $node['children'] !== [] && Decimal::compare($originalDirect, '0') > 0
            && Decimal::compare(Decimal::multiply($oldParts['direct'], $factor), Decimal::value($originalDirect)) === 0;
        if (Decimal::compare((string) ($attrs['quantity_coefficient'] ?? '1'), '0') <= 0 || (Decimal::compare($oldQuantity, '0') === 0 && ! $attrs['is_not_accounted'] && Decimal::compare((string) ($attrs['total_amount'] ?? '0'), '0') !== 0)) {
            $conflicts[] = $this->conflict('match:'.$node['key'], 'quantity_invalid');

            return;
        }
        $attrs['quantity'] = $this->rawQuantity($node, $quantity);
        $attrs['quantity_total'] = $quantity;
        $node['quantity'] = $quantity;
        foreach ($node['source_items'] as &$sourceItem) {
            $sourceItem['result_quantity'] = Decimal::multiply($sourceItem['result_quantity'], $ratio, 8);
        }
        unset($sourceItem);
        foreach (['direct_costs', 'materials_cost', 'machinery_cost', 'labor_cost', 'equipment_cost', 'other_cost', 'labor_hours', 'machinery_hours', 'base_materials_cost', 'base_machinery_cost', 'base_labor_cost', 'base_overhead_amount', 'base_profit_amount', 'base_machinery_labor_cost', 'overhead_amount', 'profit_amount', 'total_amount', 'current_total_amount'] as $field) {
            $attrs[$field] = Decimal::multiply((string) ($attrs[$field] ?? '0'), $ratio, str_contains($field, 'hours') ? 8 : 2);
        }
        foreach ($node['resources'] as &$resource) {
            $resource['total_quantity'] = Decimal::multiply((string) $resource['total_quantity'], $ratio, 18);
            $resource['total_amount'] = Decimal::multiply((string) $resource['total_amount'], $ratio);
        }
        unset($resource);
        foreach ($node['totals'] as &$detail) {
            foreach (['quantity_total', 'total_curr', 'total_base'] as $field) {
                if (isset($detail[$field])) {
                    $detail[$field] = Decimal::multiply((string) $detail[$field], $ratio, $field === 'quantity_total' ? 8 : 2);
                }
            }
        }
        unset($detail);
        foreach ($node['children'] as &$child) {
            $this->priceNode($child, Decimal::multiply($child['quantity'], $ratio, 8), $reprice, $prices, $resolutions, $conflicts, $parameters);
        }
        unset($child);
        if (! $reprice || Decimal::compare($quantity, '0') === 0) {
            return;
        }
        $decision = $resolutions['price:'.$node['key']] ?? null;
        $resolved = $prices[$node['key']] ?? ['error' => 'price_missing'];
        $derived = $completeResources && ! $attrs['is_manual'] && $attrs['item_type'] === 'work';
        if ($derived && isset($resolved['error'])) {
            $resolved = ['unit_price' => '0', 'source' => ['kind' => 'resources']];
        }
        if (isset($decision['price_candidate'])) {
            foreach ($resolved['candidates'] ?? [] as $candidate) {
                if ($candidate['id'] === $decision['price_candidate']) {
                    $resolved = $candidate;
                    break;
                }
            }
        }
        if (isset($decision['unit_price']) && trim((string) ($decision['source'] ?? '')) !== '') {
            $resolved = ['unit_price' => (string) $decision['unit_price'], 'source' => ['kind' => 'manual', 'description' => $decision['source']]];
            $derived = false;
        }
        if (isset($resolved['error'])) {
            $conflict = $this->conflict('price:'.$node['key'], $resolved['error']);
            $conflict['position_name'] = $attrs['name'];
            $conflict['unit'] = $node['unit'];
            $conflict['price_candidates'] = $resolved['candidates'] ?? [];
            $conflicts[] = $conflict;
            return;
        }
        $attrs['unit_price'] = Decimal::value($resolved['unit_price'], 4);
        $attrs['current_unit_price'] = Decimal::multiply($attrs['unit_price'], $factor, 4);
        $node['price_source'] = $resolved['source'];
        if (($resolved['source']['kind'] ?? null) === 'normative') {
            $attrs['normative_rate_id'] = $resolved['source']['rate_id'];
        } elseif (($resolved['source']['kind'] ?? null) === 'manual') {
            $attrs['is_manual'] = true;
        }
        $direct = Decimal::multiply(Decimal::multiply($quantity, $attrs['unit_price'], 8), $factor);
        $attrs['direct_costs'] = $direct;
        $attrs['equipment_cost'] = $attrs['item_type'] === 'equipment' ? $direct : '0';
        $labor = isset($resolved['labor_unit_price']) ? Decimal::multiply(Decimal::multiply($quantity, $resolved['labor_unit_price'], 8), $factor) : $direct;
        $attrs['labor_cost'] = isset($resolved['labor_unit_price']) ? $labor : ($attrs['item_type'] === 'labor' ? $direct : '0');
        $attrs['materials_cost'] = isset($resolved['materials_unit_price']) ? Decimal::multiply(Decimal::multiply($quantity, $resolved['materials_unit_price'], 8), $factor) : ($attrs['item_type'] === 'material' ? $direct : '0');
        $attrs['machinery_cost'] = isset($resolved['machinery_unit_price']) ? Decimal::multiply(Decimal::multiply($quantity, $resolved['machinery_unit_price'], 8), $factor) : ($attrs['item_type'] === 'machinery' ? $direct : '0');
        $attrs['other_cost'] = Decimal::compare($originalDirect, '0') > 0 ? Decimal::multiply($direct, Decimal::divide((string) ($original['other_cost'] ?? '0'), $originalDirect, 12)) : '0';
        if ($original['is_manual'] && $attrs['item_type'] === 'work' && ! isset($resolved['labor_unit_price']) && Decimal::compare($originalDirect, '0') > 0) {
            foreach (['materials', 'machinery', 'labor'] as $kind) {
                $attrs[$kind.'_cost'] = Decimal::multiply($direct, Decimal::divide((string) ($original[$kind.'_cost'] ?? '0'), $originalDirect, 12));
            }
        }
        $root = $attrs['parent_work_id'] === null;
        $attrs['overhead_amount'] = $root && $attrs['item_type'] !== 'equipment' ? Decimal::multiply($direct, Decimal::divide((string) $parameters['overhead_rate'], '100', 8)) : '0';
        $attrs['profit_amount'] = $root && $attrs['item_type'] !== 'equipment' ? Decimal::multiply($direct, Decimal::divide((string) $parameters['profit_rate'], '100', 8)) : '0';
        $attrs['total_amount'] = Decimal::add($direct, Decimal::add($attrs['overhead_amount'], $attrs['profit_amount']));
        $attrs['current_total_amount'] = $attrs['total_amount'];
        if (isset($resolved['base_unit_price'])) {
            $attrs['base_unit_price'] = $resolved['base_unit_price'];
            $attrs['price_index'] = $resolved['index'];
            foreach (['materials', 'machinery', 'labor'] as $kind) {
                $attrs[$kind.'_index'] = $resolved['index'];
                if (isset($resolved[$kind.'_unit_price'])) {
                    $attrs['base_'.$kind.'_cost'] = Decimal::divide($attrs[$kind.'_cost'], $resolved['index'], 4);
                }
            }
        } else {
            $attrs['base_unit_price'] = null;
            foreach (['price_index', 'materials_index', 'machinery_index', 'labor_index'] as $field) {
                $attrs[$field] = null;
            }
            foreach (['base_materials_cost', 'base_machinery_cost', 'base_labor_cost', 'base_overhead_amount', 'base_profit_amount', 'base_machinery_labor_cost'] as $field) {
                $attrs[$field] = '0';
            }
        }
        $attrs['resource_calculation'] = null;
        $node['totals'] = [];
        foreach ($node['resources'] as &$resource) {
            $resourcePrice = $resolved['resource_prices'][$resource['operation_price_key']] ?? null;
            $resourceKey = 'price:'.$node['key'].':resource:'.$resource['id'];
            $manual = $resolutions[$resourceKey] ?? null;
            if (isset($manual['unit_price']) && trim((string) ($manual['source'] ?? '')) !== '') {
                $resourcePrice = $manual['unit_price'];
            }
            if ($resourcePrice === null) {
                $conflict = $this->conflict($resourceKey, 'price_missing');
                $conflict['position_name'] = $resource['name'];
                $conflicts[] = $conflict;
                continue;
            }
            $resource['unit_price'] = $resourcePrice;
            $resource['total_amount'] = Decimal::multiply($resource['total_quantity'], $resourcePrice);
        }
        unset($resource);
        if ($attrs['item_type'] === 'work') {
            $attrs['equipment_cost'] = $this->childCosts($node)['equipment'];
            if (Decimal::compare($oldEquipment, $oldParts['equipment']) > 0) {
                $equipmentKey = 'price:'.$node['key'].':equipment';
                $equipmentQuote = $resolutions[$equipmentKey] ?? null;
                if (isset($equipmentQuote['unit_price']) && trim((string) ($equipmentQuote['source'] ?? '')) !== '') {
                    $attrs['equipment_cost'] = Decimal::add($attrs['equipment_cost'], Decimal::multiply($quantity, (string) $equipmentQuote['unit_price']));
                    $node['equipment_price_source'] = ['kind' => 'manual', 'description' => $equipmentQuote['source']];
                } else {
                    $equipmentConflict = $this->conflict($equipmentKey, 'price_missing');
                    $equipmentConflict['position_name'] = $attrs['name'].' — оборудование без расшифровки';
                    $equipmentConflict['unit'] = $node['unit'];
                    $conflicts[] = $equipmentConflict;
                }
            }
        }
        if ($attrs['item_type'] === 'equipment') {
            $quoted = $direct;
            $parts = $this->childCosts($node);
            $attrs['direct_costs'] = $parts['direct'];
            $attrs['overhead_amount'] = $root ? Decimal::multiply($parts['direct'], Decimal::divide((string) $parameters['overhead_rate'], '100', 8)) : '0';
            $attrs['profit_amount'] = $root ? Decimal::multiply($parts['direct'], Decimal::divide((string) $parameters['profit_rate'], '100', 8)) : '0';
            $extras = Decimal::add($attrs['direct_costs'], Decimal::add($attrs['overhead_amount'], $attrs['profit_amount']));
            $remainder = Decimal::subtract($quoted, $extras);
            $attrs['equipment_cost'] = Decimal::compare($remainder, '0') >= 0 ? $remainder : $quoted;
            $attrs['total_amount'] = Decimal::add($extras, $attrs['equipment_cost']);
            $attrs['current_total_amount'] = $attrs['total_amount'];
        }
        if ($derived) {
            $parts = $this->childCosts($node);
            foreach ($parts as $kind => &$amount) {
                if ($kind !== 'equipment') {
                    $amount = Decimal::multiply($amount, $factor);
                }
            }
            unset($amount);
            $attrs['direct_costs'] = $parts['direct'];
            foreach (['materials', 'machinery', 'labor'] as $kind) {
                $attrs[$kind.'_cost'] = $parts[$kind];
            }
            $attrs['overhead_amount'] = $root ? Decimal::multiply($parts['direct'], Decimal::divide((string) $parameters['overhead_rate'], '100', 8)) : '0';
            $attrs['profit_amount'] = $root ? Decimal::multiply($parts['direct'], Decimal::divide((string) $parameters['profit_rate'], '100', 8)) : '0';
            $attrs['total_amount'] = Decimal::add(Decimal::add($parts['direct'], $parts['equipment']), Decimal::add($attrs['overhead_amount'], $attrs['profit_amount']));
            $attrs['current_total_amount'] = $attrs['total_amount'];
            $attrs['current_unit_price'] = Decimal::divide($parts['direct'], $quantity, 4);
            $attrs['unit_price'] = Decimal::divide($attrs['current_unit_price'], $factor, 4);
            $node['price_source'] = ['kind' => 'resources', 'basis' => $resolved['source']];
            // Mixed resource quotes have no single confirmed normative base.
            $attrs['base_unit_price'] = null;
            foreach (['base_materials_cost', 'base_machinery_cost', 'base_labor_cost'] as $field) {
                $attrs[$field] = '0';
            }
        }
        foreach (['overhead' => 'overhead_rate', 'profit' => 'profit_rate'] as $kind => $rateField) {
            $field = $kind.'_amount';
            if (! $root) {
                $attrs[$field] = '0';
            } elseif ($original['is_manual'] && ! in_array($rateField, $parameters['rate_overrides'] ?? [], true)) {
                $oldBasis = (string) ($original['labor_cost'] ?? '0');
                $newBasis = (string) $attrs['labor_cost'];
                if (Decimal::compare($oldBasis, '0') <= 0 || Decimal::compare($newBasis, '0') <= 0) {
                    $oldBasis = $originalDirect;
                    $newBasis = (string) $attrs['direct_costs'];
                }
                if (Decimal::compare($oldBasis, '0') <= 0 && Decimal::compare((string) ($original[$field] ?? '0'), '0') > 0) {
                    $conflicts[] = $this->conflict('price:'.$node['key'], 'basis_missing');
                    $attrs[$field] = '0';
                } else {
                    $attrs[$field] = Decimal::compare($oldBasis, '0') > 0 ? Decimal::multiply($newBasis, Decimal::divide((string) ($original[$field] ?? '0'), $oldBasis, 12)) : '0';
                }
            } else {
                $rate = in_array($rateField, $parameters['rate_overrides'] ?? [], true) ? $parameters[$rateField] : ($node['rates'][$rateField] ?? $parameters[$rateField]);
                $attrs[$field] = Decimal::multiply((string) $attrs['direct_costs'], Decimal::divide((string) $rate, '100', 8));
            }
        }
        if ($attrs['item_type'] === 'equipment') {
            $remainder = Decimal::subtract($direct, Decimal::add($attrs['direct_costs'], Decimal::add($attrs['overhead_amount'], $attrs['profit_amount'])));
            if (Decimal::compare($remainder, '0') < 0) {
                $conflicts[] = $this->conflict('price:'.$node['key'], 'basis_missing');
            } else {
                $attrs['equipment_cost'] = $remainder;
            }
        }
        $attrs['total_amount'] = Decimal::add(Decimal::add($attrs['direct_costs'], $attrs['equipment_cost']), Decimal::add($attrs['overhead_amount'], $attrs['profit_amount']));
        $attrs['current_total_amount'] = $attrs['total_amount'];
        foreach (['overhead', 'profit'] as $kind) {
            $attrs['base_'.$kind.'_amount'] = isset($attrs['base_unit_price'], $resolved['index']) ? Decimal::divide($attrs[$kind.'_amount'], $resolved['index'], 2) : '0';
        }
    }

    private function childCosts(array $node): array
    {
        $totals = ['direct' => '0', 'equipment' => '0', 'materials' => '0', 'machinery' => '0', 'labor' => '0'];
        foreach ($node['children'] as $child) {
            if ($child['attributes']['is_not_accounted']) {
                continue;
            }
            $attrs = $child['attributes'];
            if ($attrs['item_type'] === 'equipment' && $child['children'] === []) {
                $attrs['direct_costs'] = '0';
                $attrs['equipment_cost'] = Decimal::subtract((string) ($attrs['total_amount'] ?? '0'), Decimal::add((string) ($attrs['overhead_amount'] ?? '0'), (string) ($attrs['profit_amount'] ?? '0')));
            }
            foreach (['direct' => 'direct_costs', 'equipment' => 'equipment_cost', 'materials' => 'materials_cost', 'machinery' => 'machinery_cost', 'labor' => 'labor_cost'] as $field => $attribute) {
                $totals[$field] = Decimal::add($totals[$field], (string) ($attrs[$attribute] ?? '0'));
            }
        }

        return $totals;
    }

    private function executedFloor(array $node): ?string
    {
        if ($node['execution']['unknown'] ?? false) {
            return null;
        }
        $floor = $node['execution']['quantity'] ?? '0';
        if (Decimal::compare($floor, '0') < 0) {
            return null;
        }
        foreach ($node['children'] as $child) {
            $childFloor = $this->executedFloor($child);
            if ($childFloor === null) {
                return null;
            }
            if (Decimal::compare($childFloor, '0') > 0) {
                if (Decimal::compare($child['quantity'], '0') === 0) {
                    return null;
                }
                $parentFloor = Decimal::divide(Decimal::multiply($childFloor, $this->magnitude($node['quantity']), 12), $this->magnitude($child['quantity']), 8);
                if (Decimal::compare($parentFloor, $floor) > 0) {
                    $floor = $parentFloor;
                }
            }
        }

        return $floor;
    }

    private function preservesExecution(array $node, string $quantity): bool
    {
        $executed = $node['execution']['quantity'] ?? '0';
        if (($node['execution']['unknown'] ?? false) || Decimal::compare($executed, '0') < 0 || Decimal::compare($this->magnitude($quantity), $executed) < 0) {
            return false;
        }
        $ratio = Decimal::compare($node['quantity'], '0') === 0 ? '0' : Decimal::divide($quantity, $node['quantity'], 12);
        foreach ($node['children'] as $child) {
            if (! $this->preservesExecution($child, Decimal::multiply($child['quantity'], $ratio, 8))) {
                return false;
            }
        }

        return true;
    }

    private function magnitude(string $value): string
    {
        return Decimal::compare($value, '0') < 0 ? Decimal::subtract('0', $value) : $value;
    }

    private function signature(array $row, bool $includePrice = false, bool $includeSection = true): ?string
    {
        $attrs = $row['node']['attributes'];
        $code = $attrs['normative_rate_code'] ?: ($attrs['code'] ?? null) ?: ($attrs['material_id'] ? 'material:'.$attrs['material_id'] : ($attrs['catalog_item_id'] ? 'catalog:'.$attrs['catalog_item_id'] : null));
        if ($code === null || $row['unit'] === '') {
            return null;
        }

        return hash('sha256', json_encode([$attrs['item_type'], $attrs['normative_rate_id'], $this->text((string) $code), $row['unit'], $includeSection ? $row['section_path'] : null, $this->terms($row['node'], $includePrice)], JSON_THROW_ON_ERROR));
    }

    private function terms(array $node, bool $includePrice): array
    {
        $attrs = $node['attributes'];
        $children = array_map(fn (array $child): array => [$child['attributes']['item_type'], $child['attributes']['normative_rate_code'], $child['unit'], Decimal::compare($node['quantity'], '0') === 0 ? '0' : Decimal::divide($child['quantity'], $node['quantity'], 8), $this->terms($child, $includePrice)], $node['children']);
        usort($children, fn (array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));

        return [
            (string) ($attrs['price_coefficient'] ?: '1'), (string) ($attrs['coefficient_total'] ?: '1'),
            $attrs['applied_coefficients'], $attrs['custom_resources'],
            array_map(fn (array $r): array => [$r['resource_type'], $r['material_id'], $r['measurement_unit_id'], $r['name'], $r['quantity_per_unit'], $includePrice ? $r['unit_price'] : null], $node['resources']),
            $children, $includePrice ? $this->unitPrice($node) : null,
            $includePrice ? array_map(fn (string $field): mixed => $attrs[$field] ?? null, ['base_unit_price', 'price_index', 'materials_index', 'machinery_index', 'labor_index']) : null,
            $includePrice && Decimal::compare((string) ($attrs['quantity_total'] ?? '0'), '0') !== 0
                ? array_map(fn (string $field): string => Decimal::divide((string) ($attrs[$field] ?? '0'), (string) $attrs['quantity_total'], 8), ['total_amount', 'overhead_amount', 'profit_amount', 'equipment_cost']) : null,
        ];
    }

    private function candidates(array $row, array $targets, array $index): array
    {
        $selected = [];
        foreach ($this->lookupKeys($row) as $lookupKey) {
            foreach ($index[$lookupKey] ?? [] as $key => $_) {
                $selected[$key] = $targets[$key];
            }
        }

        return array_filter($selected, function (array $target) use ($row): bool {
            $attrs = $row['node']['attributes'];
            $other = $target['node']['attributes'];

            return $row['key'] !== $target['key'] && $attrs['item_type'] === $other['item_type'] && (
                ($attrs['normative_rate_code'] && $this->text($attrs['normative_rate_code']) === $this->text((string) $other['normative_rate_code']))
                || (($attrs['code'] ?? null) && $this->text($attrs['code']) === $this->text((string) ($other['code'] ?? '')))
                || ($attrs['material_id'] && $attrs['material_id'] === $other['material_id'])
                || ($attrs['catalog_item_id'] && $attrs['catalog_item_id'] === $other['catalog_item_id'])
                || $this->text($row['name']) === $this->text($target['name'])
            );
        });
    }

    private function indexRows(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            $this->indexRow($index, $row);
        }

        return $index;
    }

    private function indexRow(array &$index, array $row): void
    {
        foreach ($this->lookupKeys($row) as $lookupKey) {
            $index[$lookupKey][$row['key']] = true;
        }
    }

    private function lookupKeys(array $row): array
    {
        $attrs = $row['node']['attributes'];
        $keys = [$attrs['item_type'].':name:'.$this->text($row['name'])];
        foreach (['normative_rate_code' => 'normative_code', 'code' => 'code', 'material_id' => 'material', 'catalog_item_id' => 'catalog'] as $field => $kind) {
            if ($attrs[$field] ?? null) {
                $keys[] = $attrs['item_type'].':'.$kind.':'.$this->text((string) $attrs[$field]);
            }
        }

        return $keys;
    }

    private function conflict(string $key, string $code, array $candidates = []): array
    {
        return ['key' => $key, 'code' => $code, 'message' => trans_message('estimate_operations.'.$code), 'candidates' => array_values(array_map(fn (array $row): array => array_intersect_key($row, array_flip(['key', 'name', 'unit', 'estimate_name', 'old_quantity', 'executed_quantity', 'section_path'])), $candidates))];
    }

    private function unitPrice(array $node): string
    {
        $current = (string) ($node['attributes']['current_unit_price'] ?? '0');

        return Decimal::compare($current, '0') > 0 ? $current : (string) ($node['attributes']['unit_price'] ?? '0');
    }

    private function text(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    private function mergeNodes(array &$target, array $source): void
    {
        $target['source_items'] = array_merge($target['source_items'], $source['source_items']);
        $quantity = Decimal::add((string) $target['attributes']['quantity_total'], (string) $source['attributes']['quantity_total']);
        $target['attributes']['quantity'] = $this->rawQuantity($target, $quantity);
        foreach (['quantity_total', 'direct_costs', 'materials_cost', 'machinery_cost', 'labor_cost', 'equipment_cost', 'other_cost', 'labor_hours', 'machinery_hours', 'base_materials_cost', 'base_machinery_cost', 'base_labor_cost', 'base_overhead_amount', 'base_profit_amount', 'base_machinery_labor_cost', 'overhead_amount', 'profit_amount', 'total_amount', 'current_total_amount'] as $field) {
            $target['attributes'][$field] = Decimal::add((string) ($target['attributes'][$field] ?? '0'), (string) ($source['attributes'][$field] ?? '0'));
        }
        $target['quantity'] = $target['attributes']['quantity_total'];
        foreach ($target['resources'] as $i => &$resource) {
            foreach (['total_quantity', 'total_amount'] as $field) {
                $resource[$field] = Decimal::add((string) $resource[$field], (string) $source['resources'][$i][$field]);
            }
        }
        unset($resource);
        $sourceChildren = [];
        foreach ($source['children'] as $child) {
            $key = json_encode([$child['attributes']['item_type'], $child['attributes']['normative_rate_code'], $child['unit'], $this->terms($child, false)], JSON_THROW_ON_ERROR);
            $sourceChildren[$key][] = $child;
        }
        foreach ($target['children'] as &$child) {
            $key = json_encode([$child['attributes']['item_type'], $child['attributes']['normative_rate_code'], $child['unit'], $this->terms($child, false)], JSON_THROW_ON_ERROR);
            $this->mergeNodes($child, array_shift($sourceChildren[$key]));
        }
        unset($child);
        $target['totals'] = [];
    }

    private function rawQuantity(array $node, string $quantity): string
    {
        if (Decimal::compare($node['quantity'], '0') === 0) {
            return '0.00000000';
        }

        return Decimal::divide(Decimal::multiply((string) $node['attributes']['quantity'], $quantity, 16), $node['quantity'], 8);
    }

    private function copySourceItems(array &$target, array $source): void
    {
        $target['source_items'] = $source['source_items'];
        $sourceChildren = [];
        foreach ($source['children'] as $child) {
            $key = json_encode([$child['attributes']['item_type'], $child['attributes']['normative_rate_code'], $child['unit'], $this->terms($child, false)], JSON_THROW_ON_ERROR);
            $sourceChildren[$key][] = $child;
        }
        foreach ($target['children'] as &$child) {
            $key = json_encode([$child['attributes']['item_type'], $child['attributes']['normative_rate_code'], $child['unit'], $this->terms($child, false)], JSON_THROW_ON_ERROR);
            $this->copySourceItems($child, array_shift($sourceChildren[$key]));
        }
        unset($child);
    }
}
