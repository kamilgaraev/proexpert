<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantReportScopeAmbiguous;
use App\BusinessModules\Features\AIAssistant\Services\AssistantNamedProjectReadScope;

final class AssistantReportComparisonSpecification
{
    public const COMPARISON_WORD = '(?<![\pL\pN])(?:сравн|сопостав|свер)[а-яё]*(?![\pL\pN])';

    public static function requestsComparison(string $question, array $resolvedProjectNames = []): bool
    {
        usort($resolvedProjectNames, static fn (string $first, string $second): int => strlen($second) <=> strlen($first));
        foreach ($resolvedProjectNames as $resolvedProjectName) {
            $question = preg_replace('/(?<![\pL\pN])(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)(?![\pL\pN])(?:\s*:\s*|\s+)'.preg_quote($resolvedProjectName, '/').'(?![\pL\pN])/iu', ' ', $question) ?? $question;
        }
        $question = AssistantNamedProjectReadScope::withoutQuotedNames($question);
        $question = preg_replace('/(?<![\pL\pN])(?:не\s+(?:(?:надо|нужно|следует|стоит)\s+)?|без\s+)'.self::COMPARISON_WORD.'/iu', ' ', $question) ?? $question;
        $object = '(?:их|это|ничего|(?:(?:эти|этих|полученные|полученных)\s+)?(?:данные|данных|результаты|результатов|отч[её]ты|отч[её]тов|сведения|сведений))';
        $modifier = '(?:между\s+(?:собой|ними)|друг\s+с\s+другом|сейчас|пока|здесь|отдельно|дополнительно)';
        $question = preg_replace('/'.self::COMPARISON_WORD.'(?:\s+'.$object.')?(?:\s+'.$modifier.'){0,2}\s*(?:[—–:-]\s*)?не\s+(?:нуж(?:но|на|ны|ен)|надо|требуется|следует|стоит)(?![\pL\pN])/iu', ' ', $question) ?? $question;

        return preg_match('/'.self::COMPARISON_WORD.'/iu', $question) === 1;
    }

    public static function assertCompatible(string $question, array $sections, array $projectIds, array $resolvedProjectNames = []): void
    {
        if (! self::requestsComparison($question, $resolvedProjectNames)) {
            return;
        }
        $organizations = [];
        $projects = [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                throw new AssistantReportScopeAmbiguous;
            }
            $key = serialize(self::subject($section));
            if (($section['scope'] ?? null) === 'organization') {
                $organizations[$key] = true;
            } elseif (($section['scope'] ?? null) === 'project') {
                $projects[$key][$section['project_id']] = true;
            }
        }
        if (array_diff_key($organizations, $projects) !== [] || array_diff_key($projects, $organizations) !== []) {
            throw new AssistantReportScopeAmbiguous;
        }
        foreach ($projects as $ids) {
            if (array_diff($projectIds, array_keys($ids)) !== []) {
                throw new AssistantReportScopeAmbiguous;
            }
        }
    }

    private static function subject(array $section): array
    {
        foreach (['columns', 'filters', 'group_by', 'metrics', 'order_by'] as $field) {
            if (! is_array($section[$field] ?? [])) {
                throw new AssistantReportScopeAmbiguous;
            }
        }
        $filters = [];
        foreach ($section['filters'] ?? [] as $filter) {
            if (! is_array($filter)) {
                throw new AssistantReportScopeAmbiguous;
            }
            if (($filter['field'] ?? null) === 'project_id') {
                continue;
            }
            $value = $filter['value'] ?? null;
            if (($filter['operator'] ?? null) === 'in' && is_array($value)) {
                $value = self::sorted(array_values(array_unique(array_map('serialize', $value))));
            }
            $filters[] = ['field' => $filter['field'] ?? null, 'operator' => $filter['operator'] ?? null, 'value' => $value];
        }
        $metrics = [];
        $aliases = [];
        foreach ($section['metrics'] ?? [] as $index => $metric) {
            if (! is_int($index) || ! is_array($metric)) {
                throw new AssistantReportScopeAmbiguous;
            }
            $metrics[] = $aliases['metric_'.($index + 1)] = ['field' => $metric['field'] ?? null, 'operation' => $metric['operation'] ?? null];
        }
        $order = [];
        foreach ($section['order_by'] ?? [] as $item) {
            if (! is_array($item) || ! is_string($item['field'] ?? null)) {
                throw new AssistantReportScopeAmbiguous;
            }
            $order[] = ['field' => $aliases[$item['field']] ?? $item['field'], 'direction' => $item['direction'] ?? null];
        }

        return ['domain' => $section['domain'] ?? null, 'entity_type' => $section['entity_type'] ?? null,
            'columns' => self::fields($section['columns'] ?? []), 'group_by' => self::fields($section['group_by'] ?? []),
            'filters' => self::sorted($filters), 'metrics' => self::sorted($metrics), 'order_by' => $order,
            'row_limit' => $section['row_limit'] ?? 1000];
    }

    private static function fields(array $fields): array
    {
        foreach ($fields as $field) {
            if (! is_string($field)) {
                throw new AssistantReportScopeAmbiguous;
            }
        }
        $fields = array_values(array_unique($fields));
        sort($fields, SORT_STRING);

        return $fields;
    }

    private static function sorted(array $values): array
    {
        usort($values, static fn (mixed $first, mixed $second): int => strcmp(serialize($first), serialize($second)));

        return $values;
    }
}
