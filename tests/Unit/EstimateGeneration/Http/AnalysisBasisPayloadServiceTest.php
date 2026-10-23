<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Http;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\AnalysisBasisPayloadService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\TestCase;

final class AnalysisBasisPayloadServiceTest extends TestCase
{
    public function test_work_question_basis_reads_only_catalog_bound_current_fact_evidence(): void
    {
        $code = 'composer_work_technology_'.str_repeat('a', 40);
        $question = new \App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationQuestion($code,
            'Как выполняется установка?', 'Способ не указан в исходных данных.', 'Ответ остаётся допущением пользователя.',
            'Уточните способ выполнения.', [
                new \App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationChoice('other', 'Другое', 'other'),
                new \App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationChoice('leave_unresolved', 'Оставить нерешённым', 'leave_unresolved'),
            ], ['kind' => 'composer_work_technology']);
        $current = new \App\BusinessModules\Addons\EstimateGeneration\Questions\CurrentEstimateClarification($question,
            'sha256:'.str_repeat('b', 64), str_repeat('c', 64), str_repeat('d', 64), null,
            ['schema_version' => 2, 'work_context' => ['binding' => ['source_fact_ids' => ['fact:source']]]]);
        $catalog = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationCatalog::class);
        $catalog->expects(self::once())->method('allCurrent')->with(10, 20, 30)->willReturn([$current]);
        $fact = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:source', 10, 20, 30,
            'sha256:'.str_repeat('e', 64), 'entity:equipment', 'description', 'Требуется установка', null, 1.0, 'user_input', 'confirmed', ['evidence:6']);
        $models = $this->createMock(ProjectModelRepository::class);
        $models->expects(self::never())->method('currentUnderstanding');
        $models->expects(self::once())->method('factsByIds')->with(10, 20, 30, ['fact:source'])->willReturn([$fact]);
        $query = Mockery::mock(Builder::class);
        foreach (['organization_id' => 10, 'project_id' => 20, 'session_id' => 30] as $column => $value) {
            $query->shouldReceive('where')->once()->with($column, $value)->andReturnSelf();
        }
        $query->shouldReceive('whereNull')->once()->with('invalidated_at')->andReturnSelf();
        $query->shouldReceive('whereIn')->once()->with('id', [6])->andReturnSelf();
        $query->shouldReceive('orderBy')->once()->with('id')->andReturnSelf();
        $query->shouldReceive('get')->once()->with(['id', 'source_ref', 'locator'])->andReturn(new Collection([
            (object) ['id' => 6, 'source_ref' => 'input:30', 'locator' => '{"field":"description"}'],
        ]));
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('table')->once()->with('estimate_generation_evidence')->andReturn($query);
        $payload = (new AnalysisBasisPayloadService($database, $models, static fn (string $key): string => $key, $catalog))
            ->handle(10, 20, 30, 'question', $code);
        self::assertSame($question->impact, $payload['impact']);
        self::assertSame([['id' => 'evidence:6', 'document_id' => null, 'locator' => ['field' => 'description']]], $payload['sources']);
        self::assertArrayNotHasKey('work_context', $payload);
        Mockery::close();
    }

    public function test_question_basis_is_scoped_and_returns_only_subject_impact_recommendation_and_locator(): void
    {
        $models = $this->createMock(ProjectModelRepository::class);
        $models->expects(self::once())->method('currentUnderstanding')->with(10, 20, 30)->willReturn([
            'questions' => [[
                'conflict_id' => 'conflict:roof-material',
                'reason' => 'На плане кровли не указан материал покрытия.',
                'impact' => 'От ответа зависит состав работ и материалов кровли.',
                'recommendation' => 'Выберите покрытие из проектного решения.',
                'source_locator' => ['document_id' => 44, 'page' => 3],
                'provider_response' => 'raw text must not be exposed',
            ]],
        ]);
        $service = new AnalysisBasisPayloadService(
            $this->createMock(DatabaseManager::class),
            $models,
            static fn (string $key): string => $key,
        );

        $payload = $service->handle(10, 20, 30, 'question', 'conflict:roof-material');

        self::assertSame('question', $payload['type'] ?? null);
        self::assertSame('На плане кровли не указан материал покрытия.', $payload['explanation'] ?? null);
        self::assertSame('От ответа зависит состав работ и материалов кровли.', $payload['impact'] ?? null);
        self::assertSame('Выберите покрытие из проектного решения.', $payload['recommendation'] ?? null);
        self::assertSame([['locator' => ['document_id' => 44, 'page' => 3]]], $payload['sources'] ?? null);
        self::assertArrayNotHasKey('provider_response', $payload);
    }

    public function test_unknown_basis_type_is_rejected_without_storage_access(): void
    {
        $service = new AnalysisBasisPayloadService(
            $this->createMock(DatabaseManager::class),
            $this->createMock(ProjectModelRepository::class),
            static fn (string $key): string => $key,
        );

        self::assertNull($service->handle(10, 20, 30, 'geometry', 'fact:1'));
    }

    public function test_document_question_basis_uses_the_same_canonical_question_id_as_admin(): void
    {
        $models = $this->createMock(ProjectModelRepository::class);
        $models->expects(self::once())
            ->method('currentUnderstanding')
            ->with(10, 20, 30)
            ->willReturn(['questions' => [[
                'conflict_id' => 'partial_opening_geometry_abc123',
                'reason' => 'На разрезе не указана высота проёма.',
                'impact' => 'Без высоты нельзя точно определить площадь.',
                'recommendation' => 'Укажите высоту по ведомости проёмов.',
                'source_locator' => [
                    'sources' => [[
                        'document_id' => 44,
                        'page_id' => 55,
                        'page_number' => 3,
                        'source_version' => 'sha256:'.str_repeat('a', 64),
                    ]],
                ],
            ]]]);

        $payload = (new AnalysisBasisPayloadService(
            $this->createMock(DatabaseManager::class),
            $models,
            static fn (string $key): string => $key,
        ))->handle(10, 20, 30, 'question', 'partial_opening_geometry_abc123');

        self::assertSame('partial_opening_geometry_abc123', $payload['id'] ?? null);
        self::assertSame(44, $payload['sources'][0]['locator']['document_id'] ?? null);
    }

    public function test_quantity_basis_rejects_ambiguous_current_source_versions(): void
    {
        $query = Mockery::mock(Builder::class);
        foreach (['join', 'where', 'orderBy', 'limit'] as $method) {
            $query->shouldReceive($method)->andReturnSelf();
        }
        $query->shouldReceive('get')->once()->andReturn(new Collection([
            (object) ['value' => '80', 'unit' => 'm2'],
            (object) ['value' => '82', 'unit' => 'm2'],
        ]));
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('table')
            ->once()
            ->with('estimate_generation_project_model_derived_quantity_projections as projection')
            ->andReturn($query);

        $payload = (new AnalysisBasisPayloadService(
            $database,
            $this->createMock(ProjectModelRepository::class),
            static fn (string $key): string => $key,
        ))->handle(10, 20, 30, 'quantity', 'floor:1:area');

        self::assertNull($payload);
        Mockery::close();
    }
}
