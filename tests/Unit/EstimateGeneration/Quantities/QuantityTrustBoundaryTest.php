<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\BuildingQuantityCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\DrawingGeometryAnalyzer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuantityTrustBoundaryTest extends TestCase
{
    public function test_analyzer_blocks_when_normalized_model_is_missing_and_reports_honest_metadata(): void
    {
        $result = (new DrawingGeometryAnalyzer)->analyze(1, 'drawing.pdf', new OcrRecognitionResult(
            provider: 'test', model: 'test', pages: [new OcrPageResult(pageNumber: 7, text: '', rawPayload: [])]
        ));

        self::assertSame(['normalized_building_model_missing'], $result['review_reasons']);
        self::assertSame([7], $result['review_required_pages']);
        self::assertSame(1, $result['metrics']['page_count']);
        self::assertSame('unavailable', $result['metrics']['geometry_metrics_status']);
    }

    public function test_evidence_less_operand_never_becomes_evidenced(): void
    {
        $result = (new BuildingQuantityCalculator)->calculate($this->model([
            'rooms' => [['id' => 'r', 'area' => '10']],
        ]));

        self::assertNull($result->get('floor_area'));
        self::assertContains('operand_provenance_missing', array_column($result->diagnostics, 'code'));
    }

    public function test_unconfirmed_scale_rejects_untyped_direct_area_but_accepts_explicit_metric_measurement(): void
    {
        $model = $this->model([
            'scale' => ['status' => 'unconfirmed'],
            'rooms' => [['id' => 'r', 'area' => '10', 'evidence_ids' => ['e']]],
        ]);
        self::assertNull((new BuildingQuantityCalculator)->calculate($model)->get('floor_area'));

        $model['rooms'][0]['area'] = [
            'value' => '10', 'unit' => 'm2', 'source' => 'evidenced', 'evidence_ids' => ['e'],
            'context' => ['id' => 'survey-1', 'version' => '1'], 'provenance_version' => '1', 'metric_independent' => true,
        ];
        self::assertSame('10.000000', (new BuildingQuantityCalculator)->calculate($model)->get('floor_area')?->amount);
    }

    public function test_unknown_engineering_semantics_are_blocked(): void
    {
        $result = (new BuildingQuantityCalculator)->calculate($this->model([
            'engineering' => [['id' => 'e', 'system' => 'warp', 'measurement' => 'flux', 'amount' => '1', 'unit' => 'banana', 'evidence_ids' => ['e']]],
        ]));

        self::assertNull($result->get('engineering.warp.flux'));
        self::assertContains('unknown_engineering_measurement', array_column($result->diagnostics, 'code'));
    }

    public function test_rotated_and_reversed_polygons_have_same_identity(): void
    {
        $polygon = [['0', '0'], ['4', '0'], ['4', '3'], ['0', '3']];
        $result = (new BuildingQuantityCalculator)->calculate($this->model(['rooms' => [
            ['id' => 'one', 'polygon' => $polygon, 'evidence_ids' => ['one']],
            ['id' => 'two', 'polygon' => [['4', '3'], ['4', '0'], ['0', '0'], ['0', '3']], 'evidence_ids' => ['two']],
        ]]));

        self::assertSame('12.000000', $result->get('floor_area')?->amount);
        self::assertContains('duplicate_room_geometry', array_column($result->diagnostics, 'code'));
    }

    public function test_holes_partial_overlap_shared_wall_and_orphan_opening_block(): void
    {
        $result = (new BuildingQuantityCalculator)->calculate($this->model([
            'rooms' => [
                ['id' => 'hole', 'polygon' => [['0', '0'], ['4', '0'], ['4', '4'], ['0', '4']], 'holes' => [[['1', '1'], ['2', '1'], ['2', '2'], ['1', '2']]], 'evidence_ids' => ['h']],
                ['id' => 'a', 'polygon' => [['0', '0'], ['3', '0'], ['3', '3'], ['0', '3']], 'evidence_ids' => ['a']],
                ['id' => 'b', 'polygon' => [['2', '2'], ['5', '2'], ['5', '5'], ['2', '5']], 'evidence_ids' => ['b']],
            ],
            'walls' => [['id' => 'w', 'length' => '2', 'height' => '2', 'shared' => true, 'opening_ids' => [], 'evidence_ids' => ['w']]],
            'openings' => [['id' => 'o', 'wall_id' => 'missing', 'width' => '1', 'height' => '1', 'evidence_ids' => ['o']]],
        ]));

        $codes = array_column($result->diagnostics, 'code');
        self::assertContains('polygon_holes_unsupported', $codes);
        self::assertContains('ambiguous_polygon_overlap', $codes);
        self::assertContains('shared_wall_side_policy_missing', $codes);
        self::assertContains('orphan_or_unidirectional_opening_reference', $codes);
        self::assertNull($result->get('floor_area'));
        self::assertNull($result->get('opening_area'));
    }

    public function test_oversize_collection_is_rejected_before_formula_expansion(): void
    {
        $rooms = array_fill(0, 10_001, ['id' => 'x', 'area' => '1', 'evidence_ids' => ['e']]);
        $result = (new BuildingQuantityCalculator)->calculate($this->model(['rooms' => $rooms]));

        self::assertNull($result->get('floor_area'));
        self::assertContains('collection_resource_limit_exceeded', array_column($result->diagnostics, 'code'));
    }

    public function test_oversize_mixed_model_is_rejected_before_operand_expansion(): void
    {
        $result = (new BuildingQuantityCalculator)->calculate($this->model([
            'rooms' => array_fill(0, 2500, ['id' => 'r', 'area' => '1', 'evidence_ids' => ['r']]),
            'engineering' => array_fill(0, 2501, ['id' => 'e', 'system' => 'water', 'measurement' => 'length', 'amount' => '1', 'unit' => 'm', 'evidence_ids' => ['e']]),
        ]));

        self::assertSame([], $result->all());
        self::assertContains('model_record_resource_limit_exceeded', array_column($result->diagnostics, 'code'));
    }

    #[DataProvider('invalidOperandProvider')]
    public function test_every_formula_family_rejects_malformed_typed_operands(array $override, string $quantityKey): void
    {
        $result = (new BuildingQuantityCalculator)->calculate($this->model($override));

        self::assertNull($result->get($quantityKey));
        self::assertContains('invalid_typed_operand', array_column($result->diagnostics, 'code'));
    }

    public static function invalidOperandProvider(): array
    {
        $bad = ['value' => '1', 'unit' => 'kg', 'source' => 'unknown', 'evidence_ids' => [], 'context' => []];

        return [
            'room area' => [['rooms' => [['id' => 'r', 'area' => $bad]]], 'floor_area'],
            'wall' => [['walls' => [['id' => 'w', 'length' => $bad, 'height' => '2', 'opening_ids' => [], 'evidence_ids' => ['w']]]], 'gross_wall_area'],
            'opening' => [['walls' => [['id' => 'w', 'length' => '2', 'height' => '2', 'opening_ids' => ['o'], 'evidence_ids' => ['w']]], 'openings' => [['id' => 'o', 'wall_id' => 'w', 'width' => $bad, 'height' => '1', 'evidence_ids' => ['o']]]], 'opening_area'],
            'foundation' => [['foundations' => [['id' => 'f', 'length' => $bad, 'width' => '1', 'depth' => '1', 'evidence_ids' => ['f']]]], 'foundation_volume'],
            'roof' => [['roofs' => [['id' => 'r', 'area' => $bad, 'evidence_ids' => ['r']]]], 'roof_area'],
            'engineering' => [['engineering' => [['id' => 'e', 'system' => 'water', 'measurement' => 'length', 'amount' => $bad, 'unit' => 'm', 'evidence_ids' => ['e']]]], 'engineering.water.length'],
        ];
    }

    public function test_mixed_production_model_stays_within_budget_and_preserves_exact_counts(): void
    {
        $rooms = $walls = $openings = $engineering = [];
        for ($i = 0; $i < 1250; $i++) {
            $rooms[] = ['id' => "r-$i", 'area' => '1.0000001', 'evidence_ids' => ["r-$i"]];
            $walls[] = ['id' => "w-$i", 'length' => '2', 'height' => '2', 'opening_ids' => ["o-$i"], 'evidence_ids' => ["w-$i"]];
            $openings[] = ['id' => "o-$i", 'wall_id' => "w-$i", 'width' => '0.5', 'height' => '1', 'evidence_ids' => ["o-$i"]];
            $engineering[] = ['id' => "e-$i", 'system' => 'water', 'measurement' => 'length', 'amount' => '1.25', 'unit' => 'm', 'evidence_ids' => ["e-$i"]];
        }
        $before = memory_get_usage(true);
        $result = (new BuildingQuantityCalculator)->calculate($this->model(compact('rooms', 'walls', 'openings', 'engineering')));
        $delta = memory_get_usage(true) - $before;

        self::assertSame('1250.000125', $result->get('floor_area')?->amount);
        self::assertSame('4375.000000', $result->get('net_wall_area')?->amount);
        self::assertSame('1562.500000', $result->get('engineering.water.length')?->amount);
        self::assertCount(1250, $result->get('floor_area')?->evidenceIds ?? []);
        self::assertLessThan(128 * 1024 * 1024, $delta);
    }

    public function test_mixed_operand_sources_taint_formula_and_preserve_assumption(): void
    {
        $estimated = [
            'value' => '2', 'unit' => 'm', 'source' => 'estimated', 'evidence_ids' => ['survey'],
            'context' => ['id' => 'wall-context'], 'assumptions' => ['user_wall_length'], 'provenance_version' => '1',
        ];
        $evidenced = [
            'value' => '3', 'unit' => 'm', 'source' => 'evidenced', 'evidence_ids' => ['drawing'],
            'context' => ['id' => 'wall-context'], 'assumptions' => [], 'provenance_version' => '1',
        ];
        $result = (new BuildingQuantityCalculator)->calculate($this->model([
            'walls' => [['id' => 'w', 'length' => $estimated, 'height' => $evidenced, 'opening_ids' => []]],
        ]));

        self::assertSame('estimated', $result->get('gross_wall_area')?->source->value);
        self::assertSame(['user_wall_length'], $result->get('gross_wall_area')?->assumptions);
        self::assertSame(['drawing', 'survey'], $result->get('gross_wall_area')?->evidenceIds);
    }

    /** @param array<string, mixed> $override */
    private function model(array $override): array
    {
        return array_replace(['model_version' => 'building-model.v1', 'scale' => ['status' => 'confirmed', 'unit' => 'm']], $override);
    }
}
