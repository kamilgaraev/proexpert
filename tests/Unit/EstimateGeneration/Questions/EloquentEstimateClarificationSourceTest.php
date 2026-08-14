<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Questions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ClarificationQuestionProjector;
use App\BusinessModules\Addons\EstimateGeneration\Questions\EloquentEstimateClarificationSource;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ResolveCurrentEstimateClarification;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\TestCase;

final class EloquentEstimateClarificationSourceTest extends TestCase
{
    private const SOURCE_VERSION = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_reads_only_current_scoped_pages_and_uses_the_exact_project_model_snapshot(): void
    {
        $query = Mockery::mock(Builder::class);
        foreach (['join', 'where', 'whereColumn', 'orderBy', 'limit'] as $method) {
            $query->shouldReceive($method)->andReturnSelf();
        }
        $query->shouldReceive('get')->once()->andReturn(new Collection([(object) [
            'document_id' => 7,
            'page_id' => 9,
            'page_number' => 4,
            'source_version' => self::SOURCE_VERSION,
            'normalized_payload' => json_encode($this->payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]]));
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('table')->once()->with('estimate_generation_document_pages as page')->andReturn($query);
        $models = $this->createMock(ProjectModelRepository::class);
        $models->expects(self::once())->method('snapshotForPlanning')->with(10, 20, 40, 10_001)->willReturn([
            'snapshot' => $this->snapshot(),
            'token' => str_repeat('b', 64),
        ]);
        $source = new EloquentEstimateClarificationSource(
            $database,
            $models,
            new ResolveCurrentEstimateClarification(new ClarificationQuestionProjector(
                static fn (string $key): string => match ($key) {
                    'estimate_generation.ai_questions.other' => 'Другое',
                    'estimate_generation.ai_questions.leave_unresolved' => 'Оставить нерешённым',
                    default => $key,
                },
            )),
            10_001,
        );

        $current = $source->findCurrent(10, 20, 40, 'wall_material_required');

        self::assertNotNull($current);
        self::assertSame('fact:wall-material', $current->targetFactId);
        self::assertSame(7, $current->question->sourceLocator['sources'][0]['document_id'] ?? null);
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'schema_version' => 4,
            'ai_questions' => [[
                'code' => 'wall_material_required',
                'subject' => 'Материал наружных стен',
                'reason' => 'В документах указаны разные материалы стен.',
                'impact' => 'Выбор изменяет состав кладочных работ и стоимость материалов.',
                'recommendation' => 'Рекомендуется выбрать материал из основной спецификации.',
                'choices' => ['Газобетон', 'Керамический блок'],
                'source_locator' => ['page_number' => 4, 'evidence_refs' => ['wall-material-note']],
            ]],
        ];
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
