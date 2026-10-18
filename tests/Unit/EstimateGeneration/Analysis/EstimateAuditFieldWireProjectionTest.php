<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditContextPlanner;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditExactValueWireProjection;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditFieldWireProjection;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditTableWireProjection;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerCorrectionInput;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextWirePolicy;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use PHPUnit\Framework\TestCase;

final class EstimateAuditFieldWireProjectionTest extends TestCase
{
    public function test_field_names_tables_and_values_restore_all_original_nested_data(): void
    {
        $records = [];
        for ($i = 0; $i < 69; $i++) {
            $record = ['original_work_identity' => 'work:'.$i, 'original_work_quantity' => '20.0000',
                'original_measurement_unit' => 'm2', 'unknown_original_metadata_'.$i => [
                    'original_work_identity' => '$0', 'original_measurement_unit' => null,
                    'nested_original_metadata' => [['original_work_identity' => 1], ['original_work_identity' => '1']],
                    'original_ordered_values' => [1.0, false, null, 'second', 'first'],
                ], 'original_source_proof' => str_repeat('полное основание ', 15)];
            if ($i % 2 === 0) {
                $record['original_optional_quantity'] = null;
            }
            $records[] = $record;
        }
        $payload = ['contract_version' => 'estimate-auditor:v4', 'records' => $records];
        $wire = EstimateAuditExactValueWireProjection::forAudit($payload);
        self::assertSame(EstimateAuditFieldWireProjection::CONTRACT, $wire['_key_wire']);
        self::assertSame(CanonicalPipelineJson::encode($payload), CanonicalPipelineJson::encode(self::expand($wire)));
        self::assertLessThan(strlen(CanonicalPipelineJson::encode($payload)) / 2, strlen(CanonicalPipelineJson::encode($wire)));
        self::assertSame($wire, EstimateAuditExactValueWireProjection::forAudit($wire));
        self::assertSame($wire, EstimateAuditExactValueWireProjection::forAudit($payload));
        $withoutAliases = EstimateAuditExactValueWireProjection::project(EstimateAuditTableWireProjection::forAudit($payload));
        self::assertLessThan(strlen(CanonicalPipelineJson::encode($withoutAliases)), strlen(CanonicalPipelineJson::encode($wire)));
    }

    public function test_legacy_contracts_colliding_keys_depth_and_dictionary_bounds_are_safe(): void
    {
        $records = array_fill(0, 6, ['original_long_field_name' => 'value', 'another_original_long_field_name' => null]);
        foreach (['estimate-auditor:v1', 'estimate-auditor:v2', 'estimate-auditor:v3'] as $contract) {
            $payload = ['contract_version' => $contract, 'records' => $records];
            self::assertSame($payload, EstimateAuditFieldWireProjection::forAudit($payload));
        }
        foreach ([['_key_wire' => 'literal'], ['_key_names' => ['literal']], ['literal' => ['$0' => 'original value']]] as $literal) {
            $payload = ['contract_version' => 'estimate-auditor:v4', 'records' => $records, ...$literal];
            self::assertSame($payload, EstimateAuditFieldWireProjection::forAudit($payload));
        }
        $deep = 'source';
        for ($i = 0; $i < 66; $i++) {
            $deep = ['original_nested_field_name' => $deep];
        }
        $payload = ['contract_version' => 'estimate-auditor:v4', 'records' => $records, 'deep' => $deep];
        self::assertSame($payload, EstimateAuditFieldWireProjection::forAudit($payload));
        $columns = [];
        for ($i = 0; $i < 2200; $i++) {
            $columns['original_unknown_field_'.$i] = $i;
        }
        $payload = ['contract_version' => 'estimate-auditor:v4', 'records' => array_fill(0, 6, $columns)];
        $wire = EstimateAuditFieldWireProjection::forAudit($payload);
        self::assertLessThanOrEqual(512, count($wire['_key_names']));
        self::assertLessThanOrEqual(65536, strlen(CanonicalPipelineJson::encode($wire['_key_names'])));
        self::assertSame(CanonicalPipelineJson::encode($payload), CanonicalPipelineJson::encode(self::expand($wire)));
        $small = ['contract_version' => 'estimate-auditor:v4', 'records' => [['unique_original_field_name' => 1]]];
        self::assertSame($small, EstimateAuditFieldWireProjection::forAudit($small));
    }

    public function test_native_wire_keeps_raw_draft_prices_quantities_and_replay_identity(): void
    {
        $items = [];
        for ($i = 0; $i < 8; $i++) {
            $items[] = ['key' => 'work:'.$i, 'quantity' => '20.0000', 'unit' => 'm2', 'total_cost' => '10000.00',
                'metadata' => ['original_quantity_identity' => 'quantity:'.$i, 'original_source_location' => ['page' => 1],
                    'original_price_evidence_'.$i => ['original_quantity_identity' => 'quantity:'.$i, 'original_measurement_unit' => 'm2']],
                'materials' => [['code' => 'material:'.$i, 'quantity' => '20.0000', 'price' => '500.00']]];
        }
        $draft = ['local_estimates' => [['sections' => [['work_items' => $items]]]]];
        $input = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, [], [], $draft, [], 'estimate-auditor:v4');
        $source = $input->canonicalPayload(false);
        self::assertSame(CanonicalPipelineJson::encode($source), CanonicalPipelineJson::encode(self::expand($input->canonicalPayload())));
        self::assertSame($draft, $input->draft);
        self::assertSame(hash('sha256', CanonicalPipelineJson::encode($source)), $input->fingerprint());
        $findings = [['finding_id' => 'finding:1']];
        $correction = new EstimateComposerCorrectionInput($input, $findings);
        self::assertSame(hash('sha256', json_encode(['audit_input' => $source, 'findings' => $findings], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $correction->fingerprint());
        self::assertSame($input->canonicalPayload(), $correction->canonicalPayload()['audit_input']);
    }

    public function test_v4_pairs_use_tables_and_do_not_keep_an_unprofitable_field_dictionary(): void
    {
        $identity = str_repeat('original_identity_field_', 4);
        $quantity = str_repeat('original_quantity_field_', 4);
        $records = [[$identity => 1, $quantity => '20.0000'], [$identity => 2, $quantity => '21.0000']];
        $legacy = ['contract_version' => 'estimate-auditor:v3', 'records' => $records];
        self::assertSame($legacy, EstimateAuditTableWireProjection::forAudit($legacy));
        $payload = ['contract_version' => 'estimate-auditor:v4', 'records' => $records];
        $wire = EstimateAuditExactValueWireProjection::forAudit($payload);
        self::assertArrayHasKey('_tables', $wire);
        self::assertSame(EstimateAuditTableWireProjection::CONTRACT, $wire['_tables']);
        self::assertArrayNotHasKey('_key_wire', $wire);
        self::assertSame(CanonicalPipelineJson::encode($payload), CanonicalPipelineJson::encode(self::expand($wire)));
        self::assertSame($wire, EstimateAuditExactValueWireProjection::forAudit($wire));
    }

    public function test_field_heavy_work_metadata_requires_fewer_native_audit_parts_without_dropping_data(): void
    {
        $items = [];
        for ($i = 0; $i < 69; $i++) {
            $provenance = [];
            for ($j = 0; $j < 30; $j++) {
                $provenance['original_resource_quantity_provenance_identity_'.$j] = 'proof-'.$i.'-'.$j;
            }
            $items[] = ['key' => 'work:'.$i, 'metadata' => ['original_evidence_'.$i => $provenance]];
        }
        $draft = ['local_estimates' => [['sections' => [['work_items' => $items]]]]];
        $policy = ['contract' => EstimatorTextWirePolicy::CONTRACT, 'profile' => 'normal',
            'token_limits' => ['input_tokens' => 24576, 'output_tokens' => 8192, 'max_calls' => 5],
            'maximum_serialized_input_bytes' => 65536];
        $legacy = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, [], [], $draft, [], 'estimate-auditor:v3');
        $current = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, [], [], $draft, [], 'estimate-auditor:v4');
        $oldParts = (new EstimateAuditContextPlanner($policy))->prepare($legacy, 458752);
        $parts = (new EstimateAuditContextPlanner($policy))->prepare($current, 458752);
        self::assertLessThan(count($oldParts), count($parts));
        self::assertCount(3, $parts);
        self::assertEqualsCanonicalizing(array_column($items, 'key'), array_merge(...array_column($parts, 'work_keys')));
        foreach ($parts as $part) {
            self::assertSame(CanonicalPipelineJson::encode($part['input']->canonicalPayload(false)),
                CanonicalPipelineJson::encode(self::expand($part['input']->canonicalPayload())));
            self::assertLessThanOrEqual(65536, EstimatorTextWirePolicy::serializedInputUpper([
                ['role' => 'system', 'content' => str_repeat('context ', 1024)],
                ['role' => 'user', 'content' => CanonicalPipelineJson::encode(['audit_input' => $part['input']->canonicalPayload()])],
            ]));
        }
        self::assertSame($draft, $current->draft);
    }

    public static function expand(array $wire): array
    {
        $wire = EstimateAuditTableWireProjectionTest::expand($wire);
        if (($wire['_key_wire'] ?? null) !== EstimateAuditFieldWireProjection::CONTRACT) {
            return $wire;
        }
        $names = $wire['_key_names'];
        unset($wire['_key_wire'], $wire['_key_names']);
        $expand = static function (mixed $node) use (&$expand, $names): mixed {
            if (! is_array($node)) {
                return $node;
            }
            $result = [];
            foreach ($node as $key => $value) {
                if (is_string($key) && preg_match('/\A\$([0-9]+)\z/D', $key, $match) === 1) {
                    self::assertArrayHasKey((int) $match[1], $names);
                    $key = $names[(int) $match[1]];
                }
                $result[$key] = $expand($value);
            }

            return $result;
        };

        return $expand($wire);
    }
}
