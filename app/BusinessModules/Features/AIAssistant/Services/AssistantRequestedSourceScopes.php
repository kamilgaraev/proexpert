<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantReportScopeAmbiguous;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportComparisonSpecification;

final class AssistantRequestedSourceScopes
{
    public const PREFIX = '__most_source_';

    public const MARKER = self::PREFIX.'scope_';

    public const ADDITIVE_CONNECTOR = '(?:а\s+также|вместе\s+с(?:о)?|а|и)';

    public const SECTION_MODIFIER = '(?:отдельно|также)';

    private const ACT_STATUS_PATTERNS = ['approved' => 'утвержд[её]нн[а-яё]*', 'draft' => 'чернов[а-яё]*', 'signed' => 'подписанн[а-яё]*',
        'rejected' => 'отклон[её]нн[а-яё]*', 'annulled' => 'аннулированн[а-яё]*'];

    public static function compile(string $question, array $projectIds, bool $comparison = false): array
    {
        if ($comparison && ! self::containsStatusCondition($question)) {
            return [];
        }
        $requiredSides = array_fill_keys(['organization', ...array_map(static fn (int $id): string => 'project:'.$id, $projectIds)], true);
        $mentions = AssistantOrganizationRecordVocabulary::subjectMentions($question);
        foreach (array_reverse(array_keys($mentions)) as $index) {
            $mention = $mentions[$index];
            $question = substr_replace($question, self::PREFIX.'subject_'.$index.'__', $mention['offset'], $mention['length']);
        }
        $question = AssistantReportComparisonSpecification::withoutNegatedComparison($question);
        if ($comparison) {
            if (! self::containsStatusCondition($question)) {
                return [];
            }
            $question = AssistantReportComparisonSpecification::withoutComparisonDirective($question);
        }
        $relations = $comparison ? '|с|со|против|относительно' : '';
        $parts = preg_split('/[,;.!?\r\n]+|(?<![\pL\pN])(?:'.self::ADDITIVE_CONNECTOR.'|или'.$relations.')(?![\pL\pN])/iu', $question) ?: [];
        $requirements = [];
        $coveredSides = [];
        $pending = [];
        $previous = [];
        $marker = '/'.self::MARKER.'(organization|project_([1-9]\d*))__/u';
        foreach ($parts as $part) {
            preg_match_all($marker, $part, $matches, PREG_SET_ORDER);
            $text = preg_replace($marker, ' ', $part) ?? $part;
            preg_match_all('/'.self::PREFIX.'subject_(\d+)__/u', $text, $subjectTokens);
            $subjects = array_map(static fn (string $index): array => $mentions[(int) $index]['subject'], $subjectTokens[1]);
            $text = preg_replace('/'.self::PREFIX.'subject_\d+__/u', ' ', $text) ?? $text;
            $filters = self::requestedFilters($text);
            if ($filters !== [] && count($subjects) > 1) {
                throw new AssistantReportScopeAmbiguous;
            }
            $entries = array_map(static fn (array $subject): array => ['subject' => $subject, 'filters' => $filters], $subjects);
            if ($matches === []) {
                if ($subjects === [] && $filters !== []) {
                    throw new AssistantReportScopeAmbiguous;
                }
                $pending = [...$pending, ...$entries];

                continue;
            }
            $entries = [...$pending, ...$entries];
            if ($entries === []) {
                $entries = $previous;
                if ($filters !== [] && count($entries) !== 1) {
                    throw new AssistantReportScopeAmbiguous;
                }
                if ($filters !== []) {
                    $entries[0]['filters'] = $filters;
                }
            } elseif ($subjects === [] && $filters !== []) {
                throw new AssistantReportScopeAmbiguous;
            }
            if ($entries === []) {
                throw new AssistantReportScopeAmbiguous;
            }
            foreach ($entries as $entry) {
                if ($entry['filters'] !== [] && ($entry['subject']['domain'] !== 'acts' || $entry['subject']['entity_type'] !== 'performance_act')) {
                    throw new AssistantReportScopeAmbiguous;
                }
            }
            foreach ($matches as $match) {
                $side = $match[1] === 'organization' ? 'organization' : 'project:'.$match[2];
                if (! isset($requiredSides[$side])) {
                    throw new AssistantReportScopeAmbiguous;
                }
                $coveredSides[$side] = true;
                foreach ($entries as $entry) {
                    $requirements[self::key($side, $entry['subject'], $entry['filters'])] = true;
                }
            }
            $pending = [];
            $previous = $entries;
        }
        if ($pending !== [] || array_diff_key($requiredSides, $coveredSides) !== []) {
            throw new AssistantReportScopeAmbiguous;
        }

        return $requirements;
    }

    private static function requestedFilters(string $text): array
    {
        $statuses = [];
        foreach (self::ACT_STATUS_PATTERNS as $status => $pattern) {
            if (preg_match('/(?<![\pL\pN])(?:'.$pattern.')(?![\pL\pN])/iu', $text) === 1) {
                $statuses[] = $status;
                $text = preg_replace('/(?<![\pL\pN])(?:'.$pattern.')(?![\pL\pN])/iu', ' ', $text) ?? $text;
            }
        }
        $text = preg_replace('/(?<![\pL\pN])(?:по|для|в|во|на|из|а|'.self::SECTION_MODIFIER.'|лист(?:е|а|ов)?|втор(?:ой|ом)|треть(?:ем|ий)|перв(?:ый|ом)|эти|те|же|данные|сведения|отч[её]т|снизу|сверху|пожалуйста|все|всех|всем|всеми|покажи(?:те)?|сделай(?:те)?|сформируй(?:те)?|подготовь(?:те)?|расположи(?:те)?|список|excel|xlsx|csv)(?![\pL\pN])/iu', ' ', $text) ?? $text;
        if (count($statuses) > 1 || preg_match('/[\pL\pN]/u', $text) === 1) {
            throw new AssistantReportScopeAmbiguous;
        }

        return $statuses === [] ? [] : [['field' => 'status', 'operator' => 'eq', 'value' => $statuses[0]]];
    }

    private static function containsStatusCondition(string $question): bool
    {
        return preg_match('/(?<![\pL\pN])(?:'.implode('|', self::ACT_STATUS_PATTERNS).')(?![\pL\pN])/iu', $question) === 1;
    }

    public static function key(string $side, array $subject, array $filters = []): string
    {
        $key = [$side, $subject['domain'] ?? null, $subject['entity_type'] ?? null];
        $conditions = [];
        foreach ($filters as $filter) {
            if (! is_array($filter)) {
                throw new AssistantReportScopeAmbiguous;
            }
            if (($filter['field'] ?? null) !== 'project_id') {
                $conditions[] = serialize([$filter['field'] ?? null, $filter['operator'] ?? null, $filter['value'] ?? null]);
            }
        }
        if ($conditions !== []) {
            sort($conditions);
            $key[] = array_values(array_unique($conditions));
        }

        return json_encode($key, JSON_THROW_ON_ERROR);
    }

    public static function assertReport(array $requirements, array $sections): void
    {
        $covered = [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                throw new AssistantReportScopeAmbiguous;
            }
            $side = ($section['scope'] ?? null) === 'organization' ? 'organization' : 'project:'.($section['project_id'] ?? '');
            if (! is_array($section['filters'] ?? [])) {
                throw new AssistantReportScopeAmbiguous;
            }
            $key = self::key($side, $section, $section['filters'] ?? []);
            if (! isset($requirements[$key])) {
                throw new AssistantReportScopeAmbiguous;
            }
            $covered[$key] = true;
        }
        if (array_diff_key($requirements, $covered) !== []) {
            throw new AssistantReportScopeAmbiguous;
        }
    }
}
