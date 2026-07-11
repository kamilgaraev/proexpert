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
