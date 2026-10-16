<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationQuestionPrioritizer;
use PHPUnit\Framework\TestCase;

final class SourceConflictQuestionPriorityTest extends TestCase
{
    public function test_an_unbound_source_conflict_is_visible_before_routine_parameter_questions(): void
    {
        $candidates = [];
        foreach (range(1, 5) as $index) {
            $candidates[] = ['key' => 'parameter:'.$index, 'question' => 'Уточните параметр.',
                'affected_position_keys' => array_map(static fn (int $number): string => 'work:'.$number, range(1, 100)),
                'correctness_blocker' => true, 'cost_blocker' => true, 'easy_to_answer' => true];
        }
        $candidates[] = ['key' => 'conflict:area', 'question' => 'В источниках расходятся площади.',
            'affected_position_keys' => [], 'correctness_blocker' => true, 'cost_blocker' => true,
            'easy_to_answer' => true, 'source_conflict_blocker' => true, 'next_action' => 'review_source_questions'];
        $questions = (new EvaluationQuestionPrioritizer)->prioritize($candidates);
        self::assertCount(3, $questions);
        self::assertSame('conflict:area', $questions[0]['key']);
        self::assertSame([], $questions[0]['affected_position_keys']);
        self::assertSame('review_source_questions', $questions[0]['next_action']);
        self::assertSame($questions, (new EvaluationQuestionPrioritizer)->prioritize(array_reverse($candidates)));
    }
}
