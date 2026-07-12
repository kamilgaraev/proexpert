<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\GeometryConfirmationData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\GeometryBuildingModelInputMapper;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class GeometryConfirmationDataTest extends TestCase
{
    public function test_confirmation_projects_only_referenced_raw_geometry_and_scale(): void
    {
        $vector = $this->vector();
        $confirmation = GeometryConfirmationData::fromArray($this->confirmation($vector));

        $result = (new GeometryBuildingModelInputMapper)->map(
            null,
            $vector,
            ['vector:R1' => 1, 'vector:W1' => 2, 'vector:W2' => 3, 'confirmation:T1' => 4],
            'floor-1',
            $confirmation,
        );

        self::assertSame('confirmed', $result->scale->status);
        self::assertSame(['opening', 'room', 'wall'], array_map(static fn ($item): string => $item->type, $result->geometry->elements));
        $room = array_values(array_filter($result->geometry->elements, static fn ($item): bool => $item->type === 'room'))[0];
        self::assertSame([[0, 0], [4000, 0], [4000, 3000], [0, 3000]], $room->geometry['polygon']);
        self::assertSame(0.001, $result->scale->metersPerUnit);
    }

    #[DataProvider('invalidConfirmationProvider')]
    public function test_mismatched_or_unrelated_confirmation_is_rejected(callable $mutation): void
    {
        $vector = $this->vector();
        $payload = $this->confirmation($vector);
        $payload = $mutation($payload);

        $this->expectException(InvalidArgumentException::class);
        (new GeometryBuildingModelInputMapper)->map(
            null,
            $vector,
            ['vector:R1' => 1, 'vector:W1' => 2, 'vector:W2' => 3, 'confirmation:T1' => 4],
            'floor-1',
            GeometryConfirmationData::fromArray($payload),
        );
    }

    public static function invalidConfirmationProvider(): array
    {
        return [
            'source hash' => [static fn (array $p): array => [...$p, 'source_fingerprint' => 'sha256:'.str_repeat('f', 64)]],
            'payload hash' => [static fn (array $p): array => [...$p, 'geometry_payload_sha256' => str_repeat('f', 64)]],
            'unknown entity' => [static function (array $p): array { $p['elements'][0]['boundary_handle'] = 'UNKNOWN'; return $p; }],
            'conflicting scale' => [static function (array $p): array { $p['scale_evidence'][] = ['role' => 'measured_segment', 'entity_handle' => 'W2', 'point_indexes' => [0, 1], 'real_world_value' => 2100, 'unit' => 'm']; return $p; }],
            'duplicate element key' => [static function (array $p): array { $p['elements'][1]['key'] = 'room-1'; return $p; }],
            'same entity has room and wall ownership' => [static function (array $p): array { $p['elements'][1]['segment_handles'] = ['R1', 'W2']; return $p; }],
            'opening references missing wall' => [static function (array $p): array { $p['elements'][2]['wall_key'] = 'missing'; return $p; }],
            'unrelated text is not unit declaration' => [static function (array $p): array { $p['scale_evidence'] = [['role' => 'unit_declaration', 'value_handle' => 'T1']]; return $p; }],
        ];
    }

    private function vector(): VectorGeometryData
    {
        return VectorGeometryData::fromArray([
            'schema_version' => 1, 'runtime_version' => 'cad-geometry:v1;ezdxf:1.4.4',
            'source_fingerprint' => 'sha256:'.str_repeat('a', 64), 'source_unit' => 'mm', 'unit_status' => 'confirmed',
            'bounds' => [0, 0, 4000, 3000], 'layers' => [['name' => 'A', 'visible' => true]], 'blocks' => [],
            'entities' => [
                ['handle' => 'R1', 'type' => 'lwpolyline', 'layer' => 'A', 'points' => [[0, 0], [4000, 0], [4000, 3000], [0, 3000]], 'closed' => true],
                ['handle' => 'W1', 'type' => 'line', 'layer' => 'A', 'points' => [[0, 0], [1000, 0]]],
                ['handle' => 'W2', 'type' => 'line', 'layer' => 'A', 'points' => [[1900, 0], [4000, 0]]],
            ],
            'texts' => [['handle' => 'T1', 'type' => 'text', 'layer' => 'A', 'text' => 'OPENING 900x2100 mm', 'position' => [1000, 100], 'layout' => 'model']],
            'dimensions' => [], 'pages' => [], 'scale_candidates' => [], 'warnings' => [],
        ]);
    }

    private function confirmation(VectorGeometryData $vector): array
    {
        return [
            'schema_version' => 1,
            'source_fingerprint' => $vector->sourceFingerprint,
            'geometry_payload_sha256' => $vector->payloadSha256(),
            'confirmation_source' => 'user_review',
            'reviewer_ref' => 'maintainer:plan3-task11',
            'confirmed_at' => '2026-07-12T00:00:00Z',
            'scale_evidence' => [['role' => 'measured_segment', 'entity_handle' => 'W1', 'point_indexes' => [0, 1], 'real_world_value' => 1000, 'unit' => 'mm']],
            'elements' => [
                ['key' => 'room-1', 'type' => 'room', 'boundary_handle' => 'R1'],
                ['key' => 'wall-1', 'type' => 'wall', 'segment_handles' => ['W1', 'W2']],
                ['key' => 'opening-1', 'type' => 'opening', 'wall_key' => 'wall-1', 'opening_type' => 'door', 'boundary_handles' => ['W1', 'W2'], 'dimension_handle' => 'T1'],
            ],
        ];
    }
}
