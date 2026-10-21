<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantReportScopeAmbiguous;

final class AssistantRequestedSourceScopes
{
    public const PREFIX = '__most_source_';

    public const MARKER = self::PREFIX.'scope_';

    public static function compile(string $question, array $projectIds): array
    {
        $requiredSides = array_fill_keys(['organization', ...array_map(static fn (int $id): string => 'project:'.$id, $projectIds)], true);
        $mentions = AssistantOrganizationRecordVocabulary::subjectMentions($question);
        foreach (array_reverse(array_keys($mentions)) as $index) {
            $mention = $mentions[$index];
            $question = substr_replace($question, self::PREFIX.'subject_'.$index.'__', $mention['offset'], $mention['length']);
        }
        $parts = preg_split('/[,;.!?\r\n]+|(?<![\pL\pN])(?:а\s+также|а|и|или)(?![\pL\pN])/iu', $question) ?: [];
        $requirements = [];
        $coveredSides = [];
        $pending = [];
        $previous = [];
        $marker = '/'.self::MARKER.'(organization|project_([1-9]\d*))__/u';
        foreach ($parts as $part) {
            preg_match_all($marker, $part, $matches, PREG_SET_ORDER);
            $text = preg_replace($marker, ' ', $part) ?? $part;
            $text = preg_replace('/(?<![\pL\pN])(?:с\s+(?:колонками|полями|группировкой)|(?:колонки|поля)\s*:|в\s+разрезе|с\s+детализацией)\b.*$/isu', ' ', $text) ?? $text;
            preg_match_all('/'.self::PREFIX.'subject_(\d+)__/u', $text, $subjectTokens);
            $subjects = array_map(static fn (string $index): array => $mentions[(int) $index]['subject'], $subjectTokens[1]);
            $text = preg_replace('/'.self::PREFIX.'subject_\d+__/u', ' ', $text) ?? $text;
            if ($matches === []) {
                $pending = [...$pending, ...$subjects];

                continue;
            }
            $subjects = [...$pending, ...$subjects];
            if ($subjects === []) {
                $remainder = preg_replace('/(?<![\pL\pN])(?:по|для|в|во|на|из|а|также|отдельно|лист(?:е|а)?|втор(?:ой|ом)|треть(?:ем|ий)|перв(?:ый|ом)|эти|те|же|данные|сведения|отч[её]т|снизу|сверху|пожалуйста)(?![\pL\pN])/iu', ' ', $text) ?? $text;
                if (preg_match('/[\pL\pN]/u', $remainder) === 1) {
                    throw new AssistantReportScopeAmbiguous;
                }
                $subjects = $previous;
            }
            if ($subjects === []) {
                throw new AssistantReportScopeAmbiguous;
            }
            foreach ($matches as $match) {
                $side = $match[1] === 'organization' ? 'organization' : 'project:'.$match[2];
                if (! isset($requiredSides[$side])) {
                    throw new AssistantReportScopeAmbiguous;
                }
                $coveredSides[$side] = true;
                foreach ($subjects as $subject) {
                    $requirements[self::key($side, $subject)] = true;
                }
            }
            $pending = [];
            $previous = $subjects;
        }
        if ($pending !== [] || array_diff_key($requiredSides, $coveredSides) !== []) {
            throw new AssistantReportScopeAmbiguous;
        }

        return $requirements;
    }

    public static function key(string $side, array $subject): string
    {
        return json_encode([$side, $subject['domain'] ?? null, $subject['entity_type'] ?? null], JSON_THROW_ON_ERROR);
    }

    public static function assertReport(array $requirements, array $sections): void
    {
        $covered = [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                throw new AssistantReportScopeAmbiguous;
            }
            $side = ($section['scope'] ?? null) === 'organization' ? 'organization' : 'project:'.($section['project_id'] ?? '');
            $key = self::key($side, $section);
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
