<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\SessionBuildingModelUnitData;
use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ProjectModelEvidenceWriterTest extends TestCase
{
    #[Test]
    public function room_six_is_recorded_as_an_ai_candidate_with_its_source_locator(): void
    {
        $candidates = $this->candidates($this->unit(501, 1, [
            'vision_analysis' => ['elements' => [[
                'type' => 'room', 'label' => 'Санузел 7,94', 'confidence' => 0.91,
                'identity' => ['room_number' => '6', 'floor' => '1', 'section_marker' => 'B'],
            ]]],
        ]));

        self::assertCount(1, $candidates);
        self::assertSame('ai_candidate', $candidates[0]['source']);
        self::assertSame(['value' => 7.94, 'unit' => 'm2'], $candidates[0]['value']);
        self::assertSame(0, $candidates[0]['locator']['element_index']);
    }

    #[Test]
    public function plan_and_table_join_only_on_explicit_room_floor_and_section_identity(): void
    {
        $first = $this->candidates($this->unit(501, 1, $this->trustedRoom('7.94', ['room_number' => '6', 'floor' => '1', 'section_marker' => 'B'])));
        $second = $this->candidates($this->unit(502, 2, $this->trustedRoom('8.10', ['room_number' => '6', 'floor' => '1', 'section_marker' => 'B'])));
        $ambiguous = $this->candidates($this->unit(503, 3, $this->trustedRoom('7.94', ['room_number' => '6'])));

        self::assertSame($first[0]['entity_key'], $second[0]['entity_key']);
        self::assertNotSame($first[0]['entity_key'], $ambiguous[0]['entity_key']);
        self::assertSame('table', $first[0]['source']);
        self::assertSame(7.94, $first[0]['value']['value']);
    }

    #[Test]
    public function section_axis_and_elevation_are_typed_as_an_independent_dimension_candidate(): void
    {
        $candidates = $this->candidates($this->unit(504, 4, ['project_model_candidates' => [[
            'identity' => ['axis' => '2', 'elevation' => '+6.500'],
            'entity' => ['kind' => 'dimension', 'value' => 6.5, 'unit' => 'm'],
            'assertion' => ['type' => 'dimension', 'source' => 'cad', 'value' => ['value' => 6.5, 'unit' => 'm']],
        ]]]));

        self::assertCount(1, $candidates);
        self::assertSame('dimension', $candidates[0]['assertion_type']);
        self::assertSame(['value' => 6.5, 'unit' => 'm'], $candidates[0]['value']);
        self::assertSame('cad', $candidates[0]['source']);

        $other = $this->candidates($this->unit(505, 5, ['project_model_candidates' => [[
            'identity' => ['axis' => '2', 'elevation' => '+6.500'],
            'entity' => ['kind' => 'dimension', 'value' => 6.5, 'unit' => 'm'],
            'assertion' => ['type' => 'dimension', 'source' => 'cad', 'value' => ['value' => 6.5, 'unit' => 'm']],
        ]]]));

        self::assertNotSame($candidates[0]['entity_key'], $other[0]['entity_key']);
    }

    #[Test]
    public function writer_contract_pins_active_evidence_and_never_deletes_historical_projection(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/BuildingModel/ProjectModelEvidenceWriter.php');

        foreach ([
            'whereNull(\'evidence.invalidated_at\')',
            "->where('evidence.source_version', \$unit->sourceVersion)",
            "'candidate_value_fingerprint' => ProjectModelValueFingerprint::for(\$candidate['value'])",
            "if (\$candidate['source'] === 'ai_candidate')",
            "'axis'",
            "'room_number'",
            "'section_marker'",
            "'elevation'",
            'insertOrIgnore',
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }
        self::assertStringNotContainsString("->delete()", $source);
    }

    /** @return list<array<string,mixed>> */
    private function candidates(SessionBuildingModelUnitData $unit): array
    {
        $writer = new ProjectModelEvidenceWriter($this->createMock(Connection::class));
        $method = new ReflectionMethod($writer, 'candidates');
        $method->setAccessible(true);

        return $method->invoke($writer, [$unit]);
    }

    /** @param array<string,mixed> $payload */
    private function unit(int $documentId, int $index, array $payload): SessionBuildingModelUnitData
    {
        return new SessionBuildingModelUnitData(
            100 + $index,
            $documentId,
            600 + $index,
            'raster_image',
            $index,
            'sha256:'.str_repeat((string) $index, 64),
            0.9,
            $payload,
        );
    }

    /** @param array<string,string> $identity @return array<string,mixed> */
    private function trustedRoom(string $area, array $identity): array
    {
        return ['project_model_candidates' => [[
            'identity' => $identity,
            'entity' => ['kind' => 'room', 'area_m2' => (float) $area],
            'assertion' => ['type' => 'area', 'source' => 'table', 'value' => ['value' => (float) $area, 'unit' => 'm2']],
        ]]];
    }
}
