<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkContractException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkExpectedContract;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BenchmarkContractValidatorTest extends TestCase
{
    #[Test]
    public function expected_contract_requires_matching_schema_version_and_closed_shape(): void
    {
        $payload = $this->validExpected();
        self::assertSame($payload['expected'], BenchmarkExpectedContract::expected($payload, 'benchmark-expected:v1'));

        foreach ([
            fn (array $value): array => [...$value, 'unknown' => true],
            function (array $value): array {
                $value['expected_model_schema_version'] = 'other:v1';

                return $value;
            },
            function (array $value): array {
                $value['expected']['areas'] = ['room' => -1];

                return $value;
            },
        ] as $mutate) {
            try {
                BenchmarkExpectedContract::expected($mutate($payload), 'benchmark-expected:v1');
                self::fail('Malformed expected contract accepted.');
            } catch (BenchmarkContractException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function prediction_contract_rejects_unknown_nested_duplicate_and_foreign_evidence_data(): void
    {
        $prediction = $this->validExpected()['expected'] + ['model_schema_version' => 'benchmark-prediction:v1'];
        self::assertSame($prediction, BenchmarkExpectedContract::prediction($prediction));

        $invalid = [];
        $invalid[] = [...$prediction, 'unknown' => []];
        $copy = $prediction;
        $copy['room_cells'] = ['r1', 'r1'];
        $invalid[] = $copy;
        $copy = $prediction;
        $copy['normative_rankings'] = ['work' => ['n1', ['nested']]];
        $invalid[] = $copy;
        $copy = $prediction;
        $copy['evidence_ids_by_item'] = ['foreign' => ['e1']];
        $invalid[] = $copy;

        foreach ($invalid as $payload) {
            try {
                BenchmarkExpectedContract::prediction($payload);
                self::fail('Malformed prediction accepted.');
            } catch (BenchmarkContractException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @return array<string, mixed> */
    private function validExpected(): array
    {
        return [
            'schema_version' => 1,
            'expected_model_schema_version' => 'benchmark-expected:v1',
            'expected' => [
                'sheet_type' => 'floor_plan', 'room_cells' => ['r1'], 'wall_cells' => ['w1'],
                'opening_ids' => ['o1'], 'areas' => ['r1' => '1'], 'quantities' => ['q1' => '1'],
                'work_ids' => ['work'], 'normative_rankings' => ['work' => ['n1']], 'costs' => ['work' => '1'],
                'applicable_item_ids' => ['work'], 'evidence_ids_by_item' => ['work' => ['e1']],
            ],
        ];
    }
}
