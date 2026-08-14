<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Questions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ClarificationQuestionProjector;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ResolveCurrentEstimateClarification;
use PHPUnit\Framework\TestCase;

final class ResolveCurrentEstimateClarificationTest extends TestCase
{
    private const SOURCE_VERSION = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_question_is_bound_to_the_exact_evidenced_fact_and_snapshot(): void
    {
        $resolver = new ResolveCurrentEstimateClarification(new ClarificationQuestionProjector(
            static fn (string $key): string => match ($key) {
                'estimate_generation.ai_questions.other' => 'Другое',
                'estimate_generation.ai_questions.leave_unresolved' => 'Оставить нерешённым',
                default => $key,
            },
        ));
        $page = [
            'document_id' => 7,
            'page_id' => 9,
            'page_number' => 4,
            'source_version' => self::SOURCE_VERSION,
            'ai_questions' => [[
                'code' => 'wall_material_required',
                'subject' => 'Материал наружных стен',
                'reason' => 'В документах указаны разные материалы стен.',
                'impact' => 'Выбор изменяет состав кладочных работ и стоимость материалов.',
                'recommendation' => 'Рекомендуется выбрать материал из основной спецификации.',
                'choices' => ['Газобетон', 'Керамический блок'],
                'source_locator' => [
                    'page_number' => 4,
                    'evidence_refs' => ['wall-material-note'],
                    'authority' => 'explicit_document',
                ],
            ]],
        ];
        $fact = new Fact(
            'fact:wall-material',
            10,
            20,
            40,
            self::SOURCE_VERSION,
            'entity:wall',
            'wall_material',
            null,
            null,
            0.0,
            'unresolved',
            'unresolved',
            ['evidence:1'],
        );
        $evidence = new Evidence(
            'evidence:1',
            10,
            20,
            40,
            self::SOURCE_VERSION,
            'document:7',
            'document',
            4,
        );
        $snapshot = new ProjectModelSnapshot([], [$fact], [$evidence], []);

        $first = $resolver->resolve([$page], $snapshot, str_repeat('b', 64), 'wall_material_required');
        $second = $resolver->resolve([$page], $snapshot, str_repeat('c', 64), 'wall_material_required');

        self::assertNotNull($first);
        self::assertSame('fact:wall-material', $first->targetFactId);
        self::assertSame(self::SOURCE_VERSION, $first->sourceVersion);
        self::assertSame([[
            'document_id' => 7,
            'page_id' => 9,
            'page_number' => 4,
            'source_version' => self::SOURCE_VERSION,
        ]], $first->question->sourceLocator['sources'] ?? null);
        self::assertNotSame($first->answerFingerprint, $second?->answerFingerprint);
    }
}
