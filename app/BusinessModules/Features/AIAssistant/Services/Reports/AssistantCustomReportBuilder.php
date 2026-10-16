<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class AssistantCustomReportBuilder
{
    public function __construct(private AssistantCustomReportSource $sources, private AssistantCustomReportReference $references,
        private AssistantDataAccessPolicy $policy) {}

    public function build(array $specification, User $actor, int $organizationId): array
    {
        if (array_diff(array_keys($specification), ['title', 'format', 'sections']) !== []) {
            throw ValidationException::withMessages(['report' => trans_message('ai_custom_reports.invalid_plan')]);
        }
        $specification = Validator::make($specification, [
            'title' => ['required', 'string', 'max:120'], 'format' => ['required', 'in:xlsx,csv,pdf'],
            'sections' => ['required', 'array', 'min:1', 'max:5'],
            'sections.*' => ['required', 'array:title,domain,entity_type,project_id,columns,filters,group_by,metrics,order_by,row_limit'],
            'sections.*.title' => ['required', 'string', 'max:80'],
            'sections.*.domain' => ['required', 'string', 'max:80'], 'sections.*.entity_type' => ['required', 'string', 'max:80'],
            'sections.*.project_id' => ['nullable', 'integer', 'min:1'],
            'sections.*.row_limit' => ['integer', 'min:1', 'max:1000'],
            'sections.*.columns' => ['present', 'array', 'max:16'], 'sections.*.columns.*' => ['string', 'max:80'],
            'sections.*.filters' => ['present', 'array', 'max:12'],
            'sections.*.filters.*' => ['array:field,operator,value'],
            'sections.*.filters.*.field' => ['required', 'string', 'max:80'],
            'sections.*.filters.*.operator' => ['required', 'in:eq,neq,gt,gte,lt,lte,contains,in,is_null,is_not_null'],
            'sections.*.filters.*.value' => ['present'],
            'sections.*.group_by' => ['present', 'array', 'max:3'], 'sections.*.group_by.*' => ['string', 'max:80'],
            'sections.*.metrics' => ['present', 'array', 'max:8'],
            'sections.*.metrics.*' => ['array:field,operation,label'],
            'sections.*.metrics.*.field' => ['present', 'nullable', 'string', 'max:80'],
            'sections.*.metrics.*.operation' => ['required', 'in:count,sum,avg,min,max'],
            'sections.*.metrics.*.label' => ['required', 'string', 'max:80'],
            'sections.*.order_by' => ['present', 'array', 'max:3'],
            'sections.*.order_by.*' => ['array:field,direction'],
            'sections.*.order_by.*.field' => ['required', 'string', 'max:80'],
            'sections.*.order_by.*.direction' => ['required', 'in:asc,desc'],
        ])->validate();
        $build = fn (): array => $this->policy->withCurrentChecks($actor, $organizationId, function () use ($specification, $actor, $organizationId): array {
            $report = ['title' => $specification['title'], 'format' => $specification['format'], 'generated_at' => now()->toISOString(),
                'sections' => [], 'source_refs' => [], 'required_domains' => []];
            foreach ($specification['sections'] as $section) {
                [$data, $references, $domains] = $this->section($section, $actor, $organizationId, $report['generated_at']);
                $report['sections'][] = $data;
                if (strlen(json_encode($report['sections'], JSON_THROW_ON_ERROR)) > 5 * 1024 * 1024) {
                    throw ValidationException::withMessages(['columns' => trans_message('ai_custom_reports.content_limit')]);
                }
                $report['source_refs'] = [...$report['source_refs'], ...$references];
                $report['required_domains'] = [...$report['required_domains'], ...$domains];
            }
            $report['required_domains'] = array_values(array_unique($report['required_domains']));

            return $report;
        }, true);

        return app()->bound(\App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext::class)
            ? app(\App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext::class)->withOperationBudget($build, 10000, 'pgsql')
            : $build();
    }

    private function section(array $section, User $actor, int $organizationId, string $fetchedAt): array
    {
        $scope = ['domain' => $section['domain'], 'entity_type' => $section['entity_type'], 'project_id' => isset($section['project_id']) ? (int) $section['project_id'] : null];
        $source = $this->sources->resolve($actor, $organizationId, $scope['domain'], $scope['entity_type'], $scope['project_id']);
        if (count(array_unique($section['columns'])) !== count($section['columns'])
            || count(array_unique($section['group_by'])) !== count($section['group_by'])) {
            throw ValidationException::withMessages(['columns' => trans_message('ai_custom_reports.unsupported_fields')]);
        }
        $metricAliases = array_map(static fn (int $index): string => 'metric_'.($index + 1), array_keys($section['metrics']));
        $orderFields = array_column($section['order_by'], 'field');
        $fields = array_values(array_unique(array_merge($section['columns'], $section['group_by'], array_column($section['filters'], 'field'),
            array_filter(array_column($section['metrics'], 'field'), 'is_string'), array_diff($orderFields, $metricAliases))));
        if ($fields === [] && $section['metrics'] !== []) {
            $fields = [$source['query']->getModel()->getKeyName()];
        }
        $this->sources->assertFields($fields, $source['fields']);
        if (($section['group_by'] !== [] && $section['metrics'] === [])
            || ($section['metrics'] !== [] && array_diff($section['columns'], $section['group_by']) !== [])) {
            throw ValidationException::withMessages(['columns' => trans_message('ai_custom_reports.group_columns')]);
        }
        $query = $source['query'];
        $model = $query->getModel();
        $table = $model->getTable();
        foreach ($section['filters'] as $filter) {
            $this->filter($query, $table.'.'.$filter['field'], $source['fields'][$filter['field']]['type'], $filter);
        }
        foreach ($section['order_by'] as $order) {
            if ($section['metrics'] === []) {
                $query->orderBy($table.'.'.$order['field'], $order['direction']);
            } elseif (! in_array($order['field'], [...$section['group_by'], ...$metricAliases], true)) {
                throw ValidationException::withMessages(['order_by' => trans_message('ai_custom_reports.invalid_sort')]);
            }
        }
        $selected = array_values(array_unique([$model->getKeyName(), ...$fields]));
        foreach (['currency', 'budget_currency', 'material_unit', 'unit_code', 'unit'] as $dimension) {
            if (isset($source['fields'][$dimension])) {
                $selected[] = $dimension;
                $fields[] = $dimension;
            }
        }
        $grammar = $query->getQuery()->getGrammar();
        $select = [];
        foreach (array_unique($selected) as $field) {
            $select[] = ($source['fields'][$field]['type'] ?? null) === 'string'
                ? \Illuminate\Support\Facades\DB::raw('LEFT(CAST('.$grammar->wrap($table.'.'.$field).' AS text), 4001) AS '.$grammar->wrap($field))
                : $table.'.'.$field;
        }
        $rows = $query->select($select)
            ->orderBy($model->getQualifiedKeyName())->limit(1001)->get();
        if ($rows->count() > 1000) {
            throw ValidationException::withMessages(['filters' => trans_message('ai_custom_reports.too_many_rows')]);
        }
        $raw = $rows->map(static fn ($row): array => $row->getRawOriginal())->all();
        foreach ($raw as $row) {
            foreach ($row as $value) {
                if (is_string($value) && mb_strlen($value) > 4000) {
                    throw ValidationException::withMessages(['columns' => trans_message('ai_custom_reports.content_limit')]);
                }
            }
        }
        foreach ($section['metrics'] as $metric) {
            $field = $metric['field'];
            if (($field === null && $metric['operation'] !== 'count') || ($field !== null
                && ! in_array($metric['operation'], $source['fields'][$field]['aggregations'], true))) {
                throw ValidationException::withMessages(['metrics' => trans_message('ai_custom_reports.invalid_metric')]);
            }
            if ($field !== null && $metric['operation'] !== 'count') {
                $this->assertUnits($field, $section['group_by'], $raw, $source['fields'], $metric['operation']);
            }
        }
        [$headers, $values] = $this->tabulate($section, $source['fields'], $raw);
        if ($section['metrics'] !== [] && $section['order_by'] !== []) {
            $keys = array_flip([...$section['group_by'], ...$metricAliases]);
            usort($values, static function (array $left, array $right) use ($section, $keys, $metricAliases, $source): int {
                foreach ($section['order_by'] as $order) {
                    $index = $keys[$order['field']];
                    $a = $left[$index];
                    $b = $right[$index];
                    if ($a === null || $b === null) {
                        $comparison = ($a === null ? 1 : 0) <=> ($b === null ? 1 : 0);
                        if ($comparison !== 0) {
                            return $comparison;
                        }
                    } else {
                        $comparison = (in_array($order['field'], $metricAliases, true) || ($source['fields'][$order['field']]['type'] ?? null) === 'number')
                            ? FinanceDecimal::compare((string) $a, (string) $b) : strcmp((string) $a, (string) $b);
                        if ($comparison !== 0) {
                            return $order['direction'] === 'desc' ? -$comparison : $comparison;
                        }
                    }
                }

                return 0;
            });
        }
        $outputLimited = count($values) > ($section['row_limit'] ?? 1000);
        $values = array_slice($values, 0, $section['row_limit'] ?? 1000);
        $outputFields = $section['metrics'] === [] ? $section['columns'] : $section['group_by'];
        foreach ($values as &$valuesRow) {
            foreach ($outputFields as $index => $field) {
                if (is_string($valuesRow[$index] ?? null) && $source['fields'][$field]['type'] === 'string') {
                    $valuesRow[$index] = \App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactLabels::display($scope['entity_type'], $field, $valuesRow[$index]);
                }
            }
        }
        unset($valuesRow);
        [$values, $labelRefs, $labelDomains] = $this->relatedLabels($values, $outputFields, $actor, $organizationId, $scope['project_id'], $fetchedAt);
        $types = array_map(static fn (string $field): string => preg_match('/(^id$|_id$|number$|version$)/', $field) ? 'string' : $source['fields'][$field]['type'], $outputFields);
        $types = [...$types, ...array_fill(0, count($section['metrics']), 'number')];
        $data = ['title' => $section['title'], 'headers' => $headers, 'rows' => $values, 'source_row_count' => count($raw),
            'column_types' => $types, 'filters' => $section['filters'], 'project_id' => $scope['project_id'], 'output_limited' => $outputLimited,
            'filter_summary' => implode('; ', array_map(static fn (array $filter): string => $source['fields'][$filter['field']]['label'].' '
                .trans_message('ai_custom_reports.operators.'.$filter['operator']).' '
                .(is_array($filter['value']) ? implode(', ', $filter['value']) : (is_bool($filter['value']) ? ($filter['value'] ? '1' : '0') : (string) $filter['value'])), $section['filters']))];
        if ($scope['project_id'] !== null) {
            $data['filter_summary'] = trans_message('ai_custom_reports.project_scope', ['id' => $scope['project_id']]).'; '.$data['filter_summary'];
        }
        if ($outputLimited) {
            $data['filter_summary'] .= '; '.trans_message('ai_custom_reports.output_limit', ['count' => count($values)]);
        }
        $scope['plan'] = $section;
        $reference = $this->references->create($actor, $organizationId, $scope, array_values(array_unique($fields)),
            $rows->modelKeys(), hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), $fetchedAt);

        return [$data, [$reference, ...$labelRefs], [$source['domain'], ...$labelDomains]];
    }

    private function relatedLabels(array $rows, array $fields, User $actor, int $organizationId, ?int $projectId, string $fetchedAt): array
    {
        $types = ['project_id' => ['project', 'name'], 'contractor_id' => ['contractor', 'name'], 'contract_id' => ['contract', 'number'],
            'user_id' => ['user', 'name'], 'assigned_to' => ['user', 'name'], 'warehouse_id' => ['warehouse', 'name'], 'material_id' => ['material', 'name']];
        $refs = $domains = [];
        foreach ($fields as $index => $field) {
            if (! isset($types[$field])) {
                continue;
            }
            $ids = array_values(array_unique(array_filter(array_column($rows, $index), static fn (mixed $id): bool => is_int($id) || is_string($id))));
            if ($ids === []) {
                continue;
            }
            [$type, $labelField] = $types[$field];
            $related = $this->sources->related($actor, $organizationId, $type, $labelField, $ids);
            if ($related === null || $related['labels'] === []) {
                continue;
            }
            $scope = $related['scope'] + ['context_project_id' => $projectId];
            $refs[] = $this->references->create($actor, $organizationId, $scope, $related['fields'], array_keys($related['labels']),
                hash('sha256', json_encode($related['labels'], JSON_THROW_ON_ERROR)), $fetchedAt);
            $domains[] = $related['domain'];
            foreach ($rows as &$row) {
                if (isset($related['labels'][$row[$index]])) {
                    $row[$index] = '#'.$row[$index].' — '.$related['labels'][$row[$index]];
                }
            }
            unset($row);
        }

        return [$rows, $refs, array_values(array_unique($domains))];
    }

    private function filter($query, string $column, string $type, array $filter): void
    {
        $operator = $filter['operator'];
        $value = $filter['value'];
        if (in_array($operator, ['is_null', 'is_not_null'], true)) {
            if ($value !== null) {
                throw ValidationException::withMessages(['filters' => trans_message('ai_custom_reports.invalid_filter')]);
            }
            $operator === 'is_null' ? $query->whereNull($column) : $query->whereNotNull($column);

            return;
        }
        $values = $operator === 'in' ? $value : [$value];
        if (! is_array($values) || $values === [] || count($values) > 50) {
            throw ValidationException::withMessages(['filters' => trans_message('ai_custom_reports.invalid_filter')]);
        }
        foreach ($values as $item) {
            $valid = is_scalar($item) && mb_strlen((string) $item) <= 200 && match ($type) {
                'number' => preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', (string) $item) === 1,
                'boolean' => in_array($item, [true, false, 0, 1, '0', '1'], true),
                'date' => is_string($item) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $item) === 1
                    && ($date = \DateTimeImmutable::createFromFormat('!Y-m-d', $item)) !== false && $date->format('Y-m-d') === $item,
                default => is_string($item) || is_int($item),
            };
            if (! $valid || ($operator === 'contains' && $type !== 'string')) {
                throw ValidationException::withMessages(['filters' => trans_message('ai_custom_reports.invalid_filter')]);
            }
        }
        if ($operator === 'in') {
            if ($type === 'date') {
                throw ValidationException::withMessages(['filters' => trans_message('ai_custom_reports.invalid_filter')]);
            }
            $query->whereIn($column, $values);
        } elseif ($operator === 'contains') {
            $query->whereRaw('CAST('.$query->getQuery()->getGrammar()->wrap($column).' AS text) ILIKE ?',
                ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $value).'%']);
        } else {
            $sqlOperator = ['eq' => '=', 'neq' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$operator];
            $type === 'date' ? $query->whereDate($column, $sqlOperator, $value) : $query->where($column, $sqlOperator, $value);
        }
    }

    private function assertUnits(string $field, array $groupBy, array $rows, array $fields, string $operation): void
    {
        if ($operation === 'sum' && preg_match('/price|percent|rate/', $field)) {
            throw ValidationException::withMessages(['metrics' => trans_message('ai_custom_reports.non_additive')]);
        }
        $dimension = match (true) {
            str_contains($field, 'budget') => 'budget_currency',
            (bool) preg_match('/amount|price|cost/', $field) => 'currency',
            (bool) preg_match('/quantity|volume/', $field) => isset($fields['material_unit']) ? 'material_unit' : (isset($fields['unit_code']) ? 'unit_code' : 'unit'),
            default => null,
        };
        if ($dimension === null || $rows === []) {
            return;
        }
        if (isset($fields[$dimension])) {
            $units = array_unique(array_map(static fn (array $row): string => (string) ($row[$dimension] ?? ''), $rows));
            if (! in_array('', $units, true) && (in_array($dimension, $groupBy, true) || count($units) === 1)) {
                return;
            }
        }
        throw ValidationException::withMessages(['metrics' => trans_message('ai_custom_reports.mixed_units')]);
    }

    private function tabulate(array $section, array $fields, array $rows): array
    {
        if ($section['metrics'] === []) {
            if ($section['columns'] === []) {
                throw ValidationException::withMessages(['columns' => trans_message('ai_custom_reports.unsupported_fields')]);
            }

            return [array_map(static fn (string $field): string => $fields[$field]['label'], $section['columns']),
                array_map(static fn (array $row): array => array_map(static fn (string $field): mixed => $row[$field] ?? null, $section['columns']), $rows)];
        }
        $headers = [...array_map(static fn (string $field): string => $fields[$field]['label'], $section['group_by']), ...array_column($section['metrics'], 'label')];
        $groups = [];
        foreach ($rows as $row) {
            $key = json_encode(array_map(static fn (string $field): mixed => $row[$field] ?? null, $section['group_by']), JSON_THROW_ON_ERROR);
            $groups[$key][] = $row;
        }
        if ($groups === [] && $section['group_by'] === []) {
            $groups['[]'] = [];
        }
        $result = [];
        foreach ($groups as $key => $members) {
            $values = json_decode($key, true, 512, JSON_THROW_ON_ERROR);
            foreach ($section['metrics'] as $metric) {
                $numbers = $metric['field'] === null ? $members
                    : array_values(array_filter(array_column($members, $metric['field']), static fn (mixed $value): bool => $value !== null));
                if ($metric['operation'] === 'count') {
                    $values[] = count($numbers);
                } elseif ($numbers === []) {
                    $values[] = null;
                } else {
                    $sum = '0';
                    $minimum = $maximum = (string) $numbers[0];
                    foreach ($numbers as $number) {
                        $sum = FinanceDecimal::add($sum, (string) $number);
                        if (FinanceDecimal::compare((string) $number, $minimum) < 0) {
                            $minimum = (string) $number;
                        }
                        if (FinanceDecimal::compare((string) $number, $maximum) > 0) {
                            $maximum = (string) $number;
                        }
                    }
                    $values[] = match ($metric['operation']) {
                        'sum' => $sum, 'avg' => FinanceDecimal::divide($sum, (string) count($numbers), 4),
                        'min' => $minimum, 'max' => $maximum,
                    };
                }
            }
            $result[] = $values;
        }

        return [$headers, $result];
    }
}
