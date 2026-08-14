<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis;

use InvalidArgumentException;

final readonly class ProjectSynthesisValidator
{
    /** @param array<string, mixed> $payload @return array{links:list<array<string,mixed>>,questions:list<array<string,mixed>>,limitations:list<string>} */
    public function validate(array $payload, ProjectSynthesisInput $input): array
    {
        if (array_diff(array_keys($payload), ['links', 'questions', 'limitations']) !== []
            || ! is_array($payload['links'] ?? null) || ! array_is_list($payload['links'])
            || ! is_array($payload['questions'] ?? null) || ! array_is_list($payload['questions'])
            || (isset($payload['limitations'])
                && (! is_array($payload['limitations']) || ! array_is_list($payload['limitations'])))) {
            throw new InvalidArgumentException('project_synthesis_result_invalid');
        }
        $currentFacts = [];
        foreach ($input->facts as $fact) {
            if (is_string($fact['id'] ?? null)
                && is_string($fact['source_version'] ?? null)
                && ($fact['current'] ?? false) === true
                && in_array($fact['source_version'], $input->sourceVersions, true)) {
                $currentFacts[$fact['id']] = true;
            }
        }
        $links = [];
        $seenFacts = [];
        foreach ($payload['links'] as $link) {
            if (! is_array($link) || ! is_string($link['id'] ?? null)
                || ! is_array($link['fact_ids'] ?? null) || ! array_is_list($link['fact_ids'])
                || count($link['fact_ids']) < 2) {
                throw new InvalidArgumentException('project_synthesis_link_invalid');
            }
            $factIds = $link['fact_ids'];
            sort($factIds, SORT_STRING);
            if (count(array_unique($factIds)) !== count($factIds)) {
                throw new InvalidArgumentException('project_synthesis_duplicate_physical_fact');
            }
            foreach ($factIds as $factId) {
                if (! is_string($factId) || ! isset($currentFacts[$factId]) || isset($seenFacts[$factId])) {
                    throw new InvalidArgumentException('project_synthesis_non_current_or_repeated_fact');
                }
                $seenFacts[$factId] = true;
            }
            $link['fact_ids'] = $factIds;
            $links[] = $link;
        }
        $questions = [];
        foreach ($payload['questions'] as $question) {
            if (! is_array($question)
                || ! is_string($question['conflict_id'] ?? null)
                || ! is_array($question['fact_ids'] ?? null) || ! array_is_list($question['fact_ids'])
                || ! is_string($question['reason_code'] ?? null)
                || ! is_array($question['source_locator'] ?? null)
                || $question['source_locator'] === []) {
                throw new InvalidArgumentException('project_synthesis_question_invalid');
            }
            foreach ($question['fact_ids'] as $factId) {
                if (! is_string($factId) || ! isset($currentFacts[$factId])) {
                    throw new InvalidArgumentException('project_synthesis_question_fact_invalid');
                }
            }
            $questions[] = $question;
        }
        $limitations = array_values(array_unique(array_filter(
            $payload['limitations'] ?? [],
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        )));
        usort($links, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        usort($questions, static fn (array $left, array $right): int => $left['conflict_id'] <=> $right['conflict_id']);
        sort($limitations, SORT_STRING);

        return ['links' => $links, 'questions' => $questions, 'limitations' => $limitations];
    }
}
