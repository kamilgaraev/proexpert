<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Questions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Questions\EloquentEstimateClarificationSource;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ProjectUnderstandingQuestionProjector;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ResolveCurrentEstimateClarification;
use PHPUnit\Framework\TestCase;

final class EloquentEstimateClarificationSourceTest extends TestCase
{
    private const SOURCE_VERSION = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_reads_questions_only_from_current_merged_project_understanding(): void
    {
        $models = $this->createMock(ProjectModelRepository::class);
        $models->expects(self::once())->method('currentUnderstanding')->with(10, 20, 40)->willReturn([
            'source_version' => 'sha256:'.str_repeat('c', 64),
            'questions' => [[
                'conflict_id' => 'conflict:wall-material',
                'text' => 'Какой материал наружных стен использовать?',
                'fact_ids' => ['fact:wall-material'],
                'evidence_ids' => ['evidence:1'],
                'options' => [],
            ]],
        ]);
        $models->expects(self::once())->method('snapshotForPlanning')->with(10, 20, 40, 10_001)->willReturn([
            'snapshot' => $this->snapshot(),
            'token' => str_repeat('b', 64),
        ]);
        $source = new EloquentEstimateClarificationSource(
            $models,
            new ResolveCurrentEstimateClarification($this->projector()),
            10_001,
        );

        $questions = $source->allCurrent(10, 20, 40);

        self::assertCount(1, $questions);
        self::assertSame('fact:wall-material', $questions[0]->targetFactId);
        self::assertCount(2, $questions[0]->question->choices);
        self::assertSame('other', $questions[0]->question->choices[0]->kind);
        self::assertSame('leave_unresolved', $questions[0]->question->choices[1]->kind);
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
        return new ProjectModelSnapshot([], [new Fact(
            'fact:wall-material', 10, 20, 40, self::SOURCE_VERSION, 'entity:wall', 'wall_material', null,
            null, 0.0, 'unresolved', 'unresolved', ['evidence:1'],
        )], [new Evidence(
            'evidence:1', 10, 20, 40, self::SOURCE_VERSION, 'document:7', 'document', 4,
        )], []);
    }
}
