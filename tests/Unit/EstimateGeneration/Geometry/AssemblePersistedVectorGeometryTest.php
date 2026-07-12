<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\AssemblePersistedVectorGeometry;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometryConfirmationCommand;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelAssembler;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\GeometryBuildingModelInputMapper;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

final class AssemblePersistedVectorGeometryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_production_assembler_loads_exact_tenant_session_and_input_version_capture(): void
    {
        $vectorPayload = $this->vectorPayload();
        $vector = VectorGeometryData::fromArray($vectorPayload);
        $confirmation = $this->confirmation($vector);
        $service = $this->service([(object) [
            'id' => 81, 'document_id' => 71, 'metadata' => json_encode(['vector_geometry' => $vectorPayload], JSON_THROW_ON_ERROR),
        ]]);

        $model = $service->handle(new GeometryConfirmationCommand(
            11, 22, 33, 44, 5, 'sha256:'.str_repeat('c', 64), 'sha256:'.str_repeat('b', 64),
            null, [], $confirmation,
        ), 92);

        self::assertSame('confirmed', $model->scaleStatus);
        self::assertSame([91], $model->floors[0]->rooms[0]->evidenceIds);
        self::assertContains(92, $model->evidenceIds);
    }

    public function test_missing_or_ambiguous_persisted_capture_fails_closed(): void
    {
        $vector = VectorGeometryData::fromArray($this->vectorPayload());
        $command = new GeometryConfirmationCommand(11, 22, 33, 44, 5, 'sha256:'.str_repeat('c', 64),
            'sha256:'.str_repeat('b', 64), null, [], $this->confirmation($vector));
        foreach ([[], [(object) ['id' => 1, 'value' => '{}'], (object) ['id' => 2, 'value' => '{}']]] as $rows) {
            try {
                $this->service($rows)->handle($command);
                self::fail('Missing or ambiguous capture must be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Confirmed geometry source was not found.', $exception->getMessage());
            }
        }
    }

    private function service(array $rows): AssemblePersistedVectorGeometry
    {
        $query = Mockery::mock();
        foreach ([['organization_id', 11], ['project_id', 22], ['session_id', 33], ['source_version', 'sha256:'.str_repeat('b', 64)], ['status', 'completed']] as $where) {
            $query->shouldReceive('where')->once()->with(...$where)->andReturnSelf();
        }
        $query->shouldReceive('whereIn')->once()->with('unit_type', ['pdf_page', 'cad_drawing'])->andReturnSelf();
        $query->shouldReceive('limit')->once()->with(2)->andReturnSelf();
        $query->shouldReceive('get')->once()->with(['id', 'document_id', 'metadata'])->andReturn(new Collection($rows));
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('table')->once()->with('estimate_generation_processing_units')->andReturn($query);
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

        return new AssemblePersistedVectorGeometry($database, new GeometryBuildingModelInputMapper, new BuildingModelAssembler);
    }

    private function vectorPayload(): array
    {
        return ['schema_version' => 1, 'runtime_version' => 'cad-geometry:v1;ezdxf:1.4.4',
            'source_fingerprint' => 'sha256:'.str_repeat('a', 64), 'source_unit' => 'mm', 'unit_status' => 'confirmed',
            'bounds' => [0, 0, 4000, 3000], 'layers' => [['name' => 'A', 'visible' => true]], 'blocks' => [],
            'entities' => [
                ['handle' => 'R1', 'type' => 'lwpolyline', 'layer' => 'A', 'points' => [[0, 0], [4000, 0], [4000, 3000], [0, 3000]], 'closed' => true],
                ['handle' => 'W1', 'type' => 'line', 'layer' => 'A', 'points' => [[0, 0], [4000, 0]]],
            ], 'texts' => [], 'dimensions' => [], 'pages' => [], 'scale_candidates' => [], 'warnings' => []];
    }

    private function confirmation(VectorGeometryData $vector): array
    {
        return ['schema_version' => 1, 'source_fingerprint' => $vector->sourceFingerprint,
            'geometry_payload_sha256' => $vector->payloadSha256(),
            'scale_evidence' => [['role' => 'measured_segment', 'entity_handle' => 'W1', 'point_indexes' => [0, 1],
                'real_world_value' => 4000, 'unit' => 'mm']],
            'elements' => [
                ['key' => 'room-1', 'type' => 'room', 'boundary_handle' => 'R1'],
                ['key' => 'wall-1', 'type' => 'wall', 'segment_handles' => ['W1']],
            ]];
    }
}
