<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Questions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ProjectUnderstandingQuestionProjector;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ResolveCurrentEstimateClarification;
use PHPUnit\Framework\TestCase;

final class ResolveCurrentEstimateClarificationTest extends TestCase
{
    private const SOURCE_VERSION = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_large_ai_choice_set_is_bounded_at_questions_ai_and_bound_to_exact_fact(): void
    {
        $resolver = new ResolveCurrentEstimateClarification($this->projector());
        $options = array_map(static fn (int $index): array => [
            'value' => 'select:fact:wall-material:'.$index,
            'fact_id' => 'fact:wall-material',
            'label' => 'Вариант '.$index,
            'evidence_ids' => ['evidence:1'],
        ], range(1, 12));
        $questions = [[
            'conflict_id' => 'conflict:wall-material',
            'text' => 'Какой материал наружных стен использовать?',
            'fact_ids' => ['fact:wall-material'],
            'evidence_ids' => ['evidence:1'],
            'options' => $options,
        ]];
        $snapshot = $this->snapshot();

        $first = $resolver->resolveAll($questions, 'sha256:'.str_repeat('c', 64), $snapshot, str_repeat('b', 64));
        $second = $resolver->resolveAll($questions, 'sha256:'.str_repeat('c', 64), $snapshot, str_repeat('d', 64));

        self::assertCount(1, $first);
        self::assertCount(10, $first[0]->question->choices);
        self::assertSame('fact:wall-material', $first[0]->targetFactId);
        self::assertSame(self::SOURCE_VERSION, $first[0]->sourceVersion);
        self::assertSame('other', $first[0]->question->choices[8]->kind);
        self::assertSame('leave_unresolved', $first[0]->question->choices[9]->kind);
        self::assertNotSame($first[0]->answerFingerprint, $second[0]->answerFingerprint);
    }

    private function projector(): ProjectUnderstandingQuestionProjector
    {
        return new ProjectUnderstandingQuestionProjector(static fn (string $key): string => match ($key) {
            'estimate_generation.ai_questions.other' => 'Другое',
            'estimate_generation.ai_questions.leave_unresolved' => 'Оставить нерешённым',
            default => $key,
        });
    }

    private function snapshot(): ProjectModelSnapshot
    {
        $fact = new Fact(
            'fact:wall-material', 10, 20, 40, self::SOURCE_VERSION, 'entity:wall', 'wall_material', null,
            null, 0.0, 'unresolved', 'unresolved', ['evidence:1'],
        );
        $evidence = new Evidence(
            'evidence:1', 10, 20, 40, self::SOURCE_VERSION, 'document:7', 'document', 4,
        );

        return new ProjectModelSnapshot([], [$fact], [$evidence], []);
    }
}
