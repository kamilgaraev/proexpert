<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantReportScopeAmbiguous;
use App\BusinessModules\Features\AIAssistant\Services\AssistantNamedProjectReadScope;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestedSourceScopes;

final class AssistantReportComparisonSpecification
{
    private const COMPARISON_STEM = '(?:(?:сравн|сопостав)[а-яё]*|свер(?:ь(?:те)?|я(?:й(?:те)?|ть|ем|ешь|ет|ете|ют|л(?:а|и|о)?)|и(?:ть|м|шь|т|те|л(?:а|и|о)?)|ят)(?:ся|сь)?)';

    private const RECONCILIATION_NOUN = '(?:сверк(?:а|и|е|у|ой|ою|ам|ами|ах)|сверок)';

    private const COMPARISON_NOUN = '(?:разниц(?:а|ы|е|у|ей|ам|ами|ах)|различи(?:е|я|й|ю|ем|ям|ями|ях)|'.self::RECONCILIATION_NOUN.')';

    public const COMPARISON_WORD = '(?<![\pL\pN])(?:'.self::COMPARISON_STEM.'|'.self::COMPARISON_NOUN.')(?![\pL\pN])';

    public static function requestsComparison(string $question, array $resolvedProjectNames = []): bool
    {
        usort($resolvedProjectNames, static fn (string $first, string $second): int => strlen($second) <=> strlen($first));
        foreach ($resolvedProjectNames as $resolvedProjectName) {
            $question = preg_replace('/(?<![\pL\pN])(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)(?![\pL\pN])(?:\s*:\s*|\s+)'.preg_quote($resolvedProjectName, '/').'(?![\pL\pN])/iu', ' ', $question) ?? $question;
        }
        $question = AssistantNamedProjectReadScope::withoutQuotedNames($question);
        $question = self::withoutNegatedComparison($question);
        $definition = '(?:(?:основн|ключев|существенн|главн)[а-яё]*\s+){0,2}';
        $noun = '(?<![\pL\pN])'.self::COMPARISON_NOUN.'(?![\pL\pN])';

        return preg_match('/(?<![\pL\pN])'.self::COMPARISON_STEM.'(?![\pL\pN])/iu', $question) === 1
            || preg_match('/(?<![\pL\pN])(?:покажи(?:те)?|найди(?:те)?|выяви(?:те)?|определи(?:те)?|объясни(?:те)?|укажи(?:те)?|в\s+ч[её]м)\s+'.$definition.$noun.'|'.$noun.'\s+между(?![\pL\pN])/iu', $question) === 1
            || preg_match('/(?:^\s*|(?<![\pL\pN])(?:нуж(?:на|ны|ен|но)|требуется|для|сдела(?:й(?:те)?|ть)|сформир(?:уй(?:те)?|овать)|подготов(?:ь(?:те)?|ить)|прове(?:ди(?:те)?|сти)|выполн(?:и(?:те)?|ить))\s+)'.$definition.self::RECONCILIATION_NOUN.'(?![\pL\pN])/iu', $question) === 1;
    }

    public static function withoutNegatedComparison(string $question): string
    {
        $definition = '(?:(?:основн|ключев|существенн|главн)[а-яё]*\s+){0,2}';
        $question = preg_replace('/(?<![\pL\pN])(?:не\s+(?:(?:надо|нуж(?:но|на|ны|ен)|следует|стоит)\s+)?(?:(?:показ[а-яё]*|ищи|искать|най[а-яё]*|выяв[а-яё]*|определ[а-яё]*|объясн[а-яё]*|укаж[а-яё]*)\s+)?|без\s+)'.$definition.self::COMPARISON_WORD.'(?:\s+их(?![\pL\pN]))?/iu', ' ', $question) ?? $question;
        $object = '(?:их|это|ничего|(?:(?:эти|этих|полученные|полученных)\s+)?(?:данные|данных|результаты|результатов|отч[её]ты|отч[её]тов|сведения|сведений))';
        $modifier = '(?:между\s+(?:собой|ними)|друг\s+с\s+другом|сейчас|пока|здесь|отдельно|дополнительно)';

        return preg_replace('/'.self::COMPARISON_WORD.'(?:\s+'.$object.')?(?:\s+'.$modifier.'){0,2}\s*(?:[—–:-]\s*)?не\s+(?:нуж(?:но|на|ны|ен)|надо|требуется|следует|стоит)(?![\pL\pN])/iu', ' ', $question) ?? $question;
    }

    public static function withoutComparisonDirective(string $question): string
    {
        return preg_replace('/'.self::COMPARISON_WORD.'|(?<![\pL\pN])(?:между|в\s+ч[её]м)(?![\pL\pN])/iu', ' ', $question) ?? $question;
    }

    public static function assertCompatible(string $question, array $sections, array $projectIds, array $resolvedProjectNames = [], array $requestedSources = []): void
    {
        if (! self::requestsComparison($question, $resolvedProjectNames)) {
            return;
        }
        if ($requestedSources !== []) {
            AssistantRequestedSourceScopes::assertReport($requestedSources, $sections);
        }
        $organizations = [];
        $projects = [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                throw new AssistantReportScopeAmbiguous;
            }
            $key = serialize(self::subject($section, $requestedSources !== []));
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

    private static function subject(array $section, bool $conditionsBound = false): array
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
            if (($filter['field'] ?? null) === 'project_id' || ($conditionsBound && ($filter['field'] ?? null) === 'status')) {
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
