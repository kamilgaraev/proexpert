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
