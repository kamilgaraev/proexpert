<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\BuildingQuantityCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\DrawingGeometryAnalyzer;
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
            'context' => ['id' => 'survey-1', 'version' => '1'], 'metric_independent' => true,
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

    /** @param array<string, mixed> $override */
    private function model(array $override): array
    {
        return array_replace(['model_version' => 'building-model.v1', 'scale' => ['status' => 'confirmed', 'unit' => 'm']], $override);
    }
}
