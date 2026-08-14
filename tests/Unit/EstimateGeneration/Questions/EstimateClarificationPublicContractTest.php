<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Questions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ApplyProjectModelDecision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ProjectModelEstimateClarificationAnswerRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class EstimateClarificationPublicContractTest extends TestCase
{
    public function test_every_public_question_error_has_a_human_russian_translation(): void
    {
        $translations = require dirname(__DIR__, 4).'/lang/ru/estimate_generation.php';

        foreach ([
            'question_not_found',
            'question_stale',
            'question_fence_required',
            'question_response_invalid',
            'question_other_invalid',
            'question_idempotency_collision',
            'question_error',
        ] as $code) {
            self::assertArrayHasKey($code, $translations);
            self::assertIsString($translations[$code]);
            self::assertMatchesRegularExpression('/[А-Яа-яЁё]/u', $translations[$code]);
            self::assertStringNotContainsString('estimate_generation.', $translations[$code]);
        }
    }

    public function test_question_and_technology_answers_use_the_existing_review_permission(): void
    {
        $root = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration';
        foreach ([
            $root.'/Questions/AnswerEstimateClarification.php',
            $root.'/Planning/TechnologyRecommendationDecisionService.php',
        ] as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);
            self::assertStringContainsString("'estimate_generation.review'", $source);
            self::assertStringNotContainsString("'estimate_generation.update'", $source);
        }
    }

    public function test_registry_counts_only_current_answers_backed_by_a_user_decision(): void
    {
        $sourceVersion = 'sha256:'.str_repeat('b', 64);
        $models = new InMemoryProjectModelRepository;
        $models->saveSourceModel(
            [new Entity('entity:wall', 10, 20, 40, $sourceVersion, 'wall', 'wall:external')],
            [new Fact(
                'fact:wall-material',
                10,
                20,
                40,
                $sourceVersion,
                'entity:wall',
                'wall_material',
                null,
                null,
                0.0,
                'unresolved',
                'unresolved',
                [],
            )],
            [],
        );
        (new ApplyProjectModelDecision($models))->applyClarificationChoice(
            10,
            20,
            40,
            $sourceVersion,
            'fact:wall-material',
            'wall_material_required',
            'selected',
            'select:gas-concrete',
            'Газобетон',
            null,
            str_repeat('a', 64),
            ['document_id' => 5, 'page_number' => 4],
            '30',
            'Подтверждено пользователем',
            'decision:question:1',
        );

        $registry = new ProjectModelEstimateClarificationAnswerRegistry($models, 100);

        self::assertSame(['wall_material_required'], $registry->answeredKeys(10, 20, 40));
        self::assertSame([], $registry->answeredKeys(10, 21, 40));
    }
}
