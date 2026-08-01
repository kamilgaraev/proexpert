<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\AssemblePersistedVectorGeometry;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometryConfirmationCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometrySourceConfirmationFactory;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelAssembler;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\GeometryBuildingModelInputMapper;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\TestCase;

final class AssemblePersistedVectorGeometryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_production_assembler_accepts_a_current_session_input_with_a_different_document_source_version(): void
    {
        $vectorPayload = $this->vectorPayload('sha256:'.str_repeat('b', 64));
        $vector = VectorGeometryData::fromArray($vectorPayload);
        $confirmation = $this->confirmation($vector);
        $service = $this->service([(object) [
            'id' => 81, 'document_id' => 71, 'normalized_payload' => ['vector_geometry' => $vectorPayload],
        ]]);

        $result = $service->handle(new GeometryConfirmationCommand(
            11, 22, 33, 44, 5, 'sha256:'.str_repeat('c', 64), 'sha256:'.str_repeat('e', 64),
            null, [], $confirmation, $this->reviewedSource(),
        ), 92);

        self::assertSame('confirmed', $result->model->scaleStatus);
        self::assertSame([91], $result->model->floors[0]->rooms[0]->evidenceIds);
        self::assertContains(92, $result->model->evidenceIds);
        self::assertSame($this->reviewedSource(), $result->sourceConfirmationContext->toArray());
    }

    public function test_missing_persisted_capture_is_stale(): void
    {
        $vector = VectorGeometryData::fromArray($this->vectorPayload());
        $command = new GeometryConfirmationCommand(11, 22, 33, 44, 5, 'sha256:'.str_repeat('c', 64),
            'sha256:'.str_repeat('b', 64), null, [], $this->confirmation($vector), $this->reviewedSource());
        $this->expectException(StaleEstimateGenerationState::class);
        $this->service([])->handle($command);
    }

    public function test_source_confirmation_rejects_a_source_context_that_does_not_match_the_reviewed_capture(): void
    {
        $vectorPayload = $this->vectorPayload();
        $vector = VectorGeometryData::fromArray($vectorPayload);
        $documents = Mockery::mock();
        $documents->shouldReceive('where')->once()->with('id', 72)->andReturnSelf();
        $documents->shouldReceive('where')->once()->with('organization_id', 11)->andReturnSelf();
        $documents->shouldReceive('where')->once()->with('project_id', 22)->andReturnSelf();
        $documents->shouldReceive('where')->once()->with('session_id', 33)->andReturnSelf();
        $documents->shouldReceive('where')->once()->with('status', '<>', 'ignored')->andReturnSelf();
        $documents->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $documents->shouldReceive('first')->once()->with(['id', 'source_version'])->andReturn((object) [
            'id' => 72,
            'source_version' => 'sha256:'.str_repeat('b', 64),
        ]);
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('table')->once()->with('estimate_generation_documents')->andReturn($documents);
        $service = new AssemblePersistedVectorGeometry(
            $database,
            new GeometryBuildingModelInputMapper,
            new BuildingModelAssembler,
            new GeometrySourceConfirmationFactory,
        );

        $this->expectException(StaleEstimateGenerationState::class);
        $service->handle(new GeometryConfirmationCommand(
            11, 22, 33, 44, 5, 'sha256:'.str_repeat('c', 64), 'sha256:'.str_repeat('b', 64),
            null, [], $this->confirmation($vector), [
                'document_id' => 72,
                'page_id' => 91,
                'source_version' => 'sha256:'.str_repeat('f', 64),
            ],
        ));
    }

    public function test_server_derived_semantic_payload_is_accepted_for_the_same_current_vector_capture(): void
    {
        $sourceVersion = 'sha256:'.str_repeat('b', 64);
        $payload = $this->vectorPayload($sourceVersion);
        $confirmation = (new GeometrySourceConfirmationFactory)->makeFromNormalizedPayload(
            ['vector_geometry' => $payload],
            $sourceVersion,
        );
        self::assertIsArray($confirmation);
        $service = $this->service([(object) [
            'id' => 81,
            'document_id' => 71,
            'normalized_payload' => ['vector_geometry' => $payload],
        ]]);

        $result = $service->handle(new GeometryConfirmationCommand(
            11, 22, 33, 44, 5, 'sha256:'.str_repeat('c', 64), 'sha256:'.str_repeat('e', 64),
            null, [], $confirmation, $this->reviewedSource(),
        ), 92);

        self::assertSame('confirmed', $result->model->scaleStatus);
        self::assertSame($this->reviewedSource(), $result->sourceConfirmationContext->toArray());
    }

    public function test_valid_semantic_subset_is_rejected_when_it_differs_from_the_server_canonical_capture(): void
    {
        $payload = $this->vectorPayload('sha256:'.str_repeat('b', 64));
        $payload['entities'][] = [
            'handle' => 'R2',
            'type' => 'lwpolyline',
            'layer' => 'A',
            'points' => [[5000, 0], [9000, 0], [9000, 3000], [5000, 3000]],
            'closed' => true,
        ];
        $confirmation = (new GeometrySourceConfirmationFactory)->make(VectorGeometryData::fromArray($payload));
        self::assertIsArray($confirmation);
        $confirmation['elements'] = array_values(array_filter(
            $confirmation['elements'],
            static fn (array $element): bool => ($element['boundary_handle'] ?? null) !== 'R2',
        ));
        $service = $this->service([(object) [
            'id' => 81,
            'document_id' => 71,
            'normalized_payload' => ['vector_geometry' => $payload],
        ]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('geometry_confirmation_not_canonical');
        $service->handle(new GeometryConfirmationCommand(
            11, 22, 33, 44, 5, 'sha256:'.str_repeat('c', 64), 'sha256:'.str_repeat('e', 64),
            null, [], $confirmation, $this->reviewedSource(),
        ));
    }

    private function service(array $rows): AssemblePersistedVectorGeometry
    {
        $unit = $rows === [] ? null : (object) [...get_object_vars($rows[0]), 'source_version' => 'sha256:'.str_repeat('b', 64), 'unit_type' => 'cad_drawing'];
        $normalizedPayload = $rows === [] ? [] : $rows[0]->normalized_payload;
        $documents = Mockery::mock();
        foreach ([['id', 71], ['organization_id', 11], ['project_id', 22], ['session_id', 33], ['status', '<>', 'ignored']] as $where) {
            $documents->shouldReceive('where')->once()->with(...$where)->andReturnSelf();
        }
        $documents->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $documents->shouldReceive('first')->once()->with(['id', 'source_version'])->andReturn((object) [
            'id' => 71,
            'source_version' => 'sha256:'.str_repeat('b', 64),
        ]);
        $pages = Mockery::mock();
        foreach ([['id', 91], ['document_id', 71], ['organization_id', 11], ['project_id', 22], ['session_id', 33]] as $where) {
            $pages->shouldReceive('where')->once()->with(...$where)->andReturnSelf();
        }
        $pages->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $pages->shouldReceive('first')->once()->with(['id', 'processing_unit_id', 'source_version', 'normalized_payload'])->andReturn((object) [
            'id' => 91,
            'processing_unit_id' => 81,
            'source_version' => 'sha256:'.str_repeat('b', 64),
            'normalized_payload' => $normalizedPayload,
        ]);
        $units = Mockery::mock();
        foreach ([['id', 81], ['organization_id', 11], ['project_id', 22], ['session_id', 33], ['document_id', 71], ['status', 'completed']] as $where) {
            $units->shouldReceive('where')->once()->with(...$where)->andReturnSelf();
        }
        $units->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $units->shouldReceive('first')->once()->with(['id', 'document_id', 'source_version', 'unit_type'])->andReturn($unit);
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('table')->once()->with('estimate_generation_documents')->andReturn($documents);
        $database->shouldReceive('table')->once()->with('estimate_generation_document_pages')->andReturn($pages);
        $database->shouldReceive('table')->once()->with('estimate_generation_processing_units')->andReturn($units);
        if (count($rows) === 1) {
            $evidence = Mockery::mock();
            foreach ([['organization_id', 11], ['project_id', 22], ['session_id', 33], ['source_version', 'sha256:'.str_repeat('b', 64)],
                ['source_ref', 'document:71'], ['producer_name', 'pdf_geometry']] as $where) {
                $evidence->shouldReceive('where')->once()->with(...$where)->andReturnSelf();
            }
            $evidence->shouldReceive('whereNull')->once()->with('invalidated_at')->andReturnSelf();
            $evidence->shouldReceive('limit')->once()->with(2)->andReturnSelf();
            $evidence->shouldReceive('get')->once()->with(['id'])->andReturn(new Collection([(object) ['id' => 91]]));
            $database->shouldReceive('table')->once()->with('estimate_generation_evidence')->andReturn($evidence);
        }

        return new AssemblePersistedVectorGeometry(
            $database,
            new GeometryBuildingModelInputMapper,
            new BuildingModelAssembler,
            new GeometrySourceConfirmationFactory,
        );
    }

    private function vectorPayload(?string $sourceFingerprint = null): array
    {
        return ['source_kind' => 'cad', 'schema_version' => 1, 'runtime_version' => 'cad-geometry:v1;ezdxf:1.4.4',
            'source_fingerprint' => $sourceFingerprint ?? 'sha256:'.str_repeat('a', 64), 'source_unit' => 'mm', 'unit_status' => 'confirmed',
            'bounds' => [0, 0, 4000, 3000], 'layers' => [['name' => 'A', 'visible' => true]], 'blocks' => [],
            'entities' => [
                ['handle' => 'R1', 'type' => 'lwpolyline', 'layer' => 'A', 'points' => [[0, 0], [4000, 0], [4000, 3000], [0, 3000]], 'closed' => true],
                ['handle' => 'W1', 'type' => 'line', 'layer' => 'A', 'points' => [[0, 0], [4000, 0]]],
            ], 'texts' => [], 'dimensions' => [], 'pages' => [], 'scale_candidates' => [], 'warnings' => []];
    }

    private function confirmation(VectorGeometryData $vector): array
    {
        $confirmation = (new GeometrySourceConfirmationFactory)->make($vector);
        self::assertIsArray($confirmation);

        return $confirmation;
    }

    private function reviewedSource(): array
    {
        return [
            'document_id' => 71,
            'page_id' => 91,
            'source_version' => 'sha256:'.str_repeat('b', 64),
        ];
    }
}
