<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use InvalidArgumentException;

final class EvaluationQuestionPrioritizer
{
    public function prioritize(array $candidates, array $confirmedKeys = []): array
    {
        $questions = [];
        foreach ($candidates as $candidate) {
            $key = $candidate['key'] ?? null;
            if (! is_string($key) || $key === '' || ! is_string($candidate['question'] ?? null)
                || ! is_array($candidate['affected_position_keys'] ?? null)) {
                throw new InvalidArgumentException('evaluation_question_contract_invalid');
            }
            if (in_array($key, $confirmedKeys, true)) {
                continue;
            }
            $score = (($candidate['correctness_blocker'] ?? false) === true ? 1000 : 0)
                + (($candidate['cost_blocker'] ?? false) === true ? 500 : 0)
                + min(count($candidate['affected_position_keys']), 100) * 2
                + (($candidate['easy_to_answer'] ?? false) === true ? 20 : 0);
            if (! isset($questions[$key])) {
                $questions[$key] = [...$candidate, 'priority_score' => $score];
            } else {
                $questions[$key]['affected_position_keys'] = array_values(array_unique([
                    ...$questions[$key]['affected_position_keys'], ...$candidate['affected_position_keys']]));
                $questions[$key]['priority_score'] = max($questions[$key]['priority_score'], $score);
            }
        }
        $questions = array_values($questions);
        usort($questions, static fn (array $left, array $right): int => $right['priority_score'] <=> $left['priority_score'] ?: strcmp($left['key'], $right['key']));

        return array_slice($questions, 0, 3);
    }
}
