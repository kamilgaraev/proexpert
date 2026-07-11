<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VectorGeometryDataContractTest extends TestCase
{
    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'unknown nested page field' => [static function (array $data): array {
            $data['pages'][0]['secret'] = 'x';

            return $data;
        }, 'geometry_contract_page_invalid'];
        yield 'unknown path segment field' => [static function (array $data): array {
            $data['entities'][0]['segments'] = [['operator' => 'line', 'points' => [[0, 0], [1, 1]], 'source_indices' => [1], 'closes_subpath' => false, 'unknown' => true]];

            return $data;
        }, 'geometry_contract_segment_invalid'];
        yield 'blocking completeness warning' => [static function (array $data): array {
            $data['warnings'] = [['code' => 'unsupported_entities', 'count' => 1]];

            return $data;
        }, 'geometry_contract_blocking_warning'];
        yield 'layer visible must be boolean' => [static function (array $data): array {
            $data['layers'][0]['visible'] = 'yes';

            return $data;
        }, 'geometry_contract_layer_invalid'];
        yield 'page number must be integer' => [static function (array $data): array {
            $data['pages'] = [['page_number' => '1', 'width' => 10, 'height' => 10, 'rotation' => 0, 'media_box' => [0, 0, 10, 10], 'crop_box' => [0, 0, 10, 10], 'transform' => [1, 0, 0, 1, 0, 0], 'classification' => 'vector']];

            return $data;
        }, 'geometry_contract_page_invalid'];
        yield 'point must contain two finite numbers' => [static function (array $data): array {
            $data['entities'][0]['points'][0] = [0];

            return $data;
        }, 'geometry_contract_coordinate_invalid'];
        yield 'line requires exactly two points' => [static function (array $data): array {
            unset($data['entities'][0]['points']);

            return $data;
        }, 'geometry_contract_entity_geometry_invalid'];
        yield 'insert requires block and 4x4 transform' => [static function (array $data): array {
            $data['entities'][0] = ['handle' => 'A1', 'type' => 'insert', 'layer' => 'WALLS', 'layout' => 'Model'];

            return $data;
        }, 'geometry_contract_entity_geometry_invalid'];
        yield 'text position is coordinate pair' => [static function (array $data): array {
            $data['texts'][0]['position'] = ['x', 0];

            return $data;
        }, 'geometry_contract_coordinate_invalid'];
        yield 'bounds reject numeric strings' => [static function (array $data): array {
            $data['bounds'][0] = '0';

            return $data;
        }, 'geometry_contract_bounds_invalid'];
        yield 'optional layout must be string' => [static function (array $data): array {
            $data['entities'][0]['layout'] = [];

            return $data;
        }, 'geometry_contract_entity_invalid'];
        yield 'source indices require ordered unique non-negative integers' => [static function (array $data): array {
            $data['entities'][0] = ['handle' => 'A1', 'type' => 'path', 'layer' => 'page', 'segments' => [['operator' => 'line', 'points' => [[0, 0], [1, 1]], 'source_indices' => [2, -1], 'closes_subpath' => false]]];

            return $data;
        }, 'geometry_contract_segment_invalid'];
        yield 'NaN point' => [static function (array $data): array {
            $data['entities'][0]['points'][0][0] = NAN;

            return $data;
        }, 'geometry_contract_number_invalid'];
        yield 'reversed bounds' => [static function (array $data): array {
            $data['bounds'] = [10, 0, 0, 10];

            return $data;
        }, 'geometry_contract_bounds_invalid'];
        yield 'duplicate handle across collections' => [static function (array $data): array {
            $data['texts'][0]['handle'] = 'A1';

            return $data;
        }, 'geometry_contract_duplicate_handle'];
        yield 'confirmed unit without source unit' => [static function (array $data): array {
            $data['source_unit'] = null;
            $data['unit_status'] = 'confirmed';

            return $data;
        }, 'geometry_contract_unit_invalid'];
        yield 'unsupported runtime provenance' => [static function (array $data): array {
            $data['runtime_version'] = 'unknown:v9';

            return $data;
        }, 'geometry_contract_runtime_invalid'];
        yield 'excessive nesting' => [static function (array $data): array {
            $data['warnings'][0]['safe_context'] = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => ['i' => ['j' => 1]]]]]]]]]];

            return $data;
        }, 'geometry_contract_depth_invalid'];
        yield 'warning safe context rejects arbitrary fields' => [static function (array $data): array {
            $data['warnings'] = [['code' => 'diagnostic', 'safe_context' => ['document' => 'secret']]];

            return $data;
        }, 'geometry_contract_warning_invalid'];
        yield 'reference strings must be non-empty' => [static function (array $data): array {
            $data['entities'][0]['source_lineage'] = [''];

            return $data;
        }, 'geometry_contract_entity_invalid'];
        yield 'reference strings are bounded' => [static function (array $data): array {
            $data['blocks'] = [['name' => 'B', 'handle' => 'B1', 'owner' => str_repeat('x', 513), 'entities' => ['A1']]];

            return $data;
        }, 'geometry_contract_block_invalid'];
        yield 'path style requires the exact complete shape' => [static function (array $data): array {
            $data['entities'][0] = [
                'handle' => 'P1', 'type' => 'path', 'layer' => 'page',
                'segments' => [['operator' => 'move', 'points' => [[0, 0]], 'source_indices' => [0], 'closes_subpath' => false]],
                'style' => ['fill_mode' => 0, 'stroke' => true, 'stroke_width' => 1, 'line_cap' => 0, 'line_join' => 0, 'fill_rgba' => null],
            ];

            return $data;
        }, 'geometry_contract_style_invalid'];
    }

    #[Test]
    public function bounded_numeric_warning_context_is_accepted(): void
    {
        $data = $this->validPayload();
        $data['warnings'] = [[
            'code' => 'decoder_diagnostic',
            'count' => 2,
            'safe_context' => [
                'decoder_counts' => ['unknown' => 2],
                'reconciliation' => ['entity_records' => 10, 'represented_records' => 8],
            ],
        ]];

        self::assertSame(1, VectorGeometryData::fromArray($data)->schemaVersion);
    }

    #[Test]
    public function production_sized_payload_passes_and_nested_over_limit_rejects_without_traversal_allocation(): void
    {
        $near = $this->validPayload();
        $near['entities'] = [];
        for ($index = 0; $index < 5_000; $index++) {
            $near['entities'][] = ['handle' => 'L'.$index, 'type' => 'line', 'layer' => 'WALLS', 'points' => [[0, 0], [10, 10]], 'layout' => 'Model'];
        }
        self::assertSame(1, VectorGeometryData::fromArray($near)->schemaVersion);

        $over = $this->validPayload();
        $over['blocks'] = [['name' => 'B', 'handle' => 'B1', 'owner' => 'blocks', 'entities' => array_fill(0, 100_001, 'A')]];
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $baseline = memory_get_usage(true);
        try {
            VectorGeometryData::fromArray($over);
            self::fail('Over-limit payload must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('geometry_contract_nested_items_limit', $exception->getMessage());
            self::assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
        }
    }

    #[Test]
    #[DataProvider('invalidPayloads')]
    public function malformed_closed_schema_payload_is_rejected(callable $mutation, string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);
        VectorGeometryData::fromArray($mutation($this->validPayload()));
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'schema_version' => 1,
            'runtime_version' => 'cad-geometry:v1;ezdxf:1.4.4',
            'source_fingerprint' => 'sha256:'.str_repeat('a', 64),
            'source_unit' => 'mm',
            'unit_status' => 'confirmed',
            'bounds' => [0, 0, 10, 10],
            'layers' => [['name' => 'WALLS', 'visible' => true]],
            'blocks' => [],
            'entities' => [['handle' => 'A1', 'type' => 'line', 'layer' => 'WALLS', 'points' => [[0, 0], [10, 10]], 'layout' => 'Model']],
            'texts' => [['handle' => 'A2', 'type' => 'text', 'layer' => 'WALLS', 'text' => 'Plan', 'position' => [0, 0], 'layout' => 'Model']],
            'dimensions' => [],
            'pages' => [],
            'scale_candidates' => [],
            'warnings' => [],
        ];
    }
}
