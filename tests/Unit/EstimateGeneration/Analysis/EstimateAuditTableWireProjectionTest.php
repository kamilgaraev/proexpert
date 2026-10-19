<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditExactValueWireProjection;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditTableWireProjection;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerCorrectionInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposerCorrection;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use PHPUnit\Framework\TestCase;

final class EstimateAuditTableWireProjectionTest extends TestCase
{
    public function test_table_and_dictionary_restore_nested_records_with_missing_null_and_typed_values(): void
    {
        $records = [];
        for ($i = 0; $i < 69; $i++) {
            $record = ['original_work_identity' => 'work:'.$i, 'original_work_name' => 'Работа '.$i,
                'original_measurement_unit' => 'm2', 'unknown_original_metadata' => [
                    ['kind' => 'first', 'number' => 1], ['kind' => 'second', 'number' => '1'], ['kind' => 'third', 'number' => 1.0],
                ], 'source_reference_identity' => str_repeat('полное основание ', 15)];
            if ($i % 2 === 0) {
                $record['optional_original_value'] = null;
            }
            if ($i % 3 === 0) {
                $record['ordered_original_list'] = ['second', 'first', false, null];
            }
            $records[] = $record;
        }
        $payload = ['contract_version' => 'estimate-auditor:v3', 'records' => $records];
        $wire = EstimateAuditExactValueWireProjection::forAudit($payload);
        self::assertSame(EstimateAuditTableWireProjection::CONTRACT, $wire['_tables']);
        self::assertSame(CanonicalPipelineJson::encode($payload), CanonicalPipelineJson::encode(self::expand($wire)));
        self::assertLessThan(strlen(CanonicalPipelineJson::encode($payload)) / 2, strlen(CanonicalPipelineJson::encode($wire)));
        self::assertSame($wire, EstimateAuditExactValueWireProjection::forAudit($wire));
    }

    public function test_legacy_contracts_reserved_literals_unique_schemas_and_large_columns_stay_compatible(): void
    {
        $records = array_map(static fn (int $i): array => ['long_original_field_name' => $i,
            'another_long_original_field_name' => 'value'], range(1, 6));
        foreach (['estimate-auditor:v1', 'estimate-auditor:v2'] as $contract) {
            $payload = ['contract_version' => $contract, 'records' => $records];
            self::assertSame($payload, EstimateAuditTableWireProjection::forAudit($payload));
        }
        foreach ([
            ['records' => $records, 'literal' => ['_table' => ['schemas' => 'original data']]],
            ['records' => array_map(static fn (int $i): array => ['unique_original_column_'.$i => $i], range(1, 140))],
            ['records' => array_fill(0, 3, array_fill_keys(array_map(static fn (int $i): string => 'column_'.$i, range(1, 65)), 'value'))],
        ] as $data) {
            $payload = ['contract_version' => 'estimate-auditor:v3', ...$data];
            self::assertSame($payload, EstimateAuditTableWireProjection::forAudit($payload));
        }
    }

    public function test_native_input_keeps_complete_business_data_and_replay_fingerprints_outside_wire_tables(): void
    {
        $facts = array_map(static fn (int $i): array => ['id' => 'fact:'.$i, 'entity_id' => 'room:'.$i,
            'source_version' => str_repeat('a', 64), 'value' => '20.0000', 'unit' => 'm2', 'status' => 'confirmed'], range(1, 8));
        $items = array_map(static fn (int $i): array => ['key' => 'work:'.$i, 'name' => 'Работа '.$i,
            'unit' => 'm2', 'quantity' => '20.0000', 'total_cost' => '10000.00',
            'materials' => [['code' => 'material:'.$i, 'price' => '500.00', 'quantity' => '20.0000']]], range(1, 8));
        $draft = ['local_estimates' => [['sections' => [['work_items' => $items]]]]];
        $input = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, $facts, [], $draft, [], 'estimate-auditor:v3');
        $source = $input->canonicalPayload(false);
        $wire = $input->canonicalPayload();
        self::assertSame(CanonicalPipelineJson::encode($source), CanonicalPipelineJson::encode(self::expand($wire)));
        self::assertSame($draft, $input->draft);
        self::assertSame($facts, $input->facts);
        self::assertSame(hash('sha256', CanonicalPipelineJson::encode($source)), $input->fingerprint());
        $findings = [['finding_id' => 'finding:1']];
        $correction = new EstimateComposerCorrectionInput($input, $findings);
        self::assertSame(hash('sha256', json_encode(['audit_input' => $source, 'findings' => $findings,
            'correction_contract' => RunEstimateComposerCorrection::PROMPT_CONTRACT], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $correction->fingerprint());
        self::assertSame($wire, $correction->canonicalPayload()['audit_input']);
    }

    public static function expand(array $wire): array
    {
        $wire = EstimateAuditExactValueWireProjectionTest::expand($wire);
        if (($wire['_tables'] ?? null) !== EstimateAuditTableWireProjection::CONTRACT) {
            return $wire;
        }
        unset($wire['_tables']);
        $expand = static function (mixed $node) use (&$expand): mixed {
            if (is_array($node) && array_keys($node) === ['_table']) {
                $records = [];
                foreach ($node['_table']['rows'] as $row) {
                    $schemaIndex = array_shift($row);
                    self::assertIsInt($schemaIndex);
                    self::assertArrayHasKey($schemaIndex, $node['_table']['schemas']);
                    $columns = $node['_table']['schemas'][$schemaIndex];
                    self::assertCount(count($columns), $row);
                    $record = array_combine($columns, $row);
                    $records[] = $expand($record);
                }

                return $records;
            }
            if (is_array($node)) {
                foreach ($node as $key => $child) {
                    $node[$key] = $expand($child);
                }
            }

            return $node;
        };

        return $expand($wire);
    }
}
