<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditContextPlanner;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditExactValueWireProjection;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerCorrectionInput;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextWirePolicy;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use PHPUnit\Framework\TestCase;

final class EstimateAuditExactValueWireProjectionTest extends TestCase
{
    public function test_wire_dictionary_restores_all_values_and_keeps_server_fingerprints(): void
    {
        $proof = ['locator' => ['page' => 1, 'region' => [1, 2, 3, 4], 'version' => str_repeat('a', 64)],
            'unknown' => ['ordered' => ['second', 'first'], 'typed' => [1, '1', null, false], 'text' => str_repeat('данные ', 100)]];
        $facts = array_map(static fn (int $i): array => ['id' => 'fact:'.$i, 'value' => $proof], range(1, 6));
        $draft = ['unrecognized_metadata' => $proof, 'local_estimates' => [['sections' => [['work_items' => [
            ['key' => 'work:1', 'quantity' => '40.0000', 'unit' => 'm2', 'total_cost' => '12000.00',
                'materials' => [['code' => 'material:1', 'quantity' => '80.0000', 'price' => '150.00']], 'metadata' => $proof],
            ['key' => 'work:2', 'quantity' => '12.5000', 'unit' => 'm', 'total_cost' => '2500.00', 'metadata' => $proof],
        ]]]]]];
        $input = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, $facts, [], $draft, [], 'estimate-auditor:v2');
        $source = $input->canonicalPayload(false);
        $wire = $input->canonicalPayload();
        self::assertSame(EstimateAuditExactValueWireProjection::CONTRACT, $wire['_wire']);
        self::assertSame(CanonicalPipelineJson::encode($source), CanonicalPipelineJson::encode(self::expand($wire)));
        self::assertLessThan(strlen(CanonicalPipelineJson::encode($source)) / 2, strlen(CanonicalPipelineJson::encode($wire)));
        self::assertSame($wire, EstimateAuditExactValueWireProjection::project($wire));
        self::assertSame($draft, $input->draft);
        self::assertSame($facts, $input->facts);
        self::assertSame(hash('sha256', CanonicalPipelineJson::encode($source)), $input->fingerprint());
        $findings = [['finding_id' => 'finding:1']];
        $correction = new EstimateComposerCorrectionInput($input, $findings);
        self::assertSame(hash('sha256', json_encode(['audit_input' => $source, 'findings' => $findings], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $correction->fingerprint());
        $changed = $facts;
        $changed[5]['value']['unknown']['ordered'] = ['first', 'second'];
        $other = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, $changed, [], $draft, [], 'estimate-auditor:v2');
        self::assertNotSame($input->fingerprint(), $other->fingerprint());
        self::assertNotSame($correction->fingerprint(), (new EstimateComposerCorrectionInput($other, $findings))->fingerprint());
    }

    public function test_distinct_types_order_reserved_fields_and_nonbeneficial_payloads_are_preserved(): void
    {
        $long = str_repeat('distinct ', 30);
        foreach ([
            ['small' => ['one', 'one', 'one']],
            ['unique' => $long],
            ['first' => ['text' => $long, 'type' => 1], 'second' => ['text' => $long, 'type' => '1']],
            ['first' => [$long, 'first'], 'second' => ['first', $long]],
            ['first' => $long, 'second' => $long, 'literal' => ['_ref' => '/_v/0']],
            ['first' => $long, 'second' => $long, '_v' => 'original value'],
        ] as $payload) {
            $wire = EstimateAuditExactValueWireProjection::project($payload);
            self::assertSame(CanonicalPipelineJson::encode($payload), CanonicalPipelineJson::encode(self::expand($wire)));
            self::assertLessThanOrEqual(strlen(CanonicalPipelineJson::encode($payload)), strlen(CanonicalPipelineJson::encode($wire)));
        }
    }

    public function test_nested_candidates_do_not_leave_unused_or_unprofitable_dictionary_entries(): void
    {
        $child = str_repeat('repeated inner value ', 20);
        $parent = ['child' => $child, 'other' => str_repeat('other value ', 20)];
        $payload = ['first' => $parent, 'second' => $parent, 'third' => $parent, 'unique' => ['child' => $child, 'other' => 1]];
        $wire = EstimateAuditExactValueWireProjection::project($payload);
        self::assertSame(CanonicalPipelineJson::encode($payload), CanonicalPipelineJson::encode(self::expand($wire)));
        $references = [];
        $walk = static function (mixed $node) use (&$walk, &$references): void {
            if (is_array($node) && array_keys($node) === ['_ref']) {
                $references[$node['_ref']] = ($references[$node['_ref']] ?? 0) + 1;
            } elseif (is_array($node)) {
                foreach ($node as $child) {
                    $walk($child);
                }
            }
        };
        $content = $wire;
        unset($content['_v'], $content['_wire']);
        $walk($content);
        foreach ($wire['_v'] as $index => $_) {
            self::assertGreaterThanOrEqual(2, $references['/_v/'.$index] ?? 0);
        }
    }

    public function test_legacy_contract_stays_unchanged_and_index_growth_is_bounded(): void
    {
        $proof = str_repeat('exact old proof ', 100);
        $legacy = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, [], [],
            ['first' => $proof, 'second' => $proof], [], 'estimate-auditor:v1');
        self::assertSame($legacy->canonicalPayload(false), $legacy->canonicalPayload());
        $records = [];
        for ($i = 0; $i < 2300; $i++) {
            $value = ['identity' => $i, 'value' => str_repeat('source '.$i.' ', 24)];
            $records[] = $value;
            $records[] = $value;
        }
        $payload = ['records' => $records];
        $wire = EstimateAuditExactValueWireProjection::project($payload);
        self::assertLessThanOrEqual(2048, count($wire['_v']));
        self::assertLessThanOrEqual(2097152, array_sum(array_map(static fn (mixed $value): int => strlen(CanonicalPipelineJson::encode([$value])) - 2, $wire['_v'])));
        self::assertSame(CanonicalPipelineJson::encode($payload), CanonicalPipelineJson::encode(self::expand($wire)));
    }

    public function test_native_planner_packs_repeated_proofs_with_complete_work_and_context_coverage(): void
    {
        $proof = ['source' => str_repeat('полное основание ', 180), 'locator' => ['page' => 1, 'region' => [1, 2, 3, 4]]];
        $items = array_map(static fn (int $i): array => ['key' => 'work:'.$i, 'name' => 'Работа '.$i,
            'metadata' => ['proof' => $proof, 'unique_value' => $i]], range(1, 69));
        $draft = ['traceability' => $proof, 'local_estimates' => [['key' => 'local:1', 'sections' => [['key' => 'section:1', 'work_items' => $items]]]]];
        $facts = [['id' => 'fact:1', 'value' => $proof]];
        $root = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, $facts, [], $draft, [], 'estimate-auditor:v2');
        $policy = ['contract' => EstimatorTextWirePolicy::CONTRACT, 'profile' => 'normal',
            'token_limits' => ['input_tokens' => 24576, 'output_tokens' => 8192, 'max_calls' => 5],
            'maximum_serialized_input_bytes' => 65536];
        $parts = (new EstimateAuditContextPlanner($policy))->prepare($root, 458752);
        self::assertCount(3, $parts);
        self::assertCount(69, array_merge(...array_column($parts, 'work_keys')));
        foreach ($parts as $part) {
            $wire = $part['input']->canonicalPayload();
            $expanded = self::expand($wire);
            self::assertSame(CanonicalPipelineJson::encode($part['input']->canonicalPayload(false)), CanonicalPipelineJson::encode($expanded));
            self::assertLessThanOrEqual(65536, EstimatorTextWirePolicy::serializedInputUpper([
                ['role' => 'system', 'content' => str_repeat('context ', 1024)],
                ['role' => 'user', 'content' => CanonicalPipelineJson::encode(['audit_input' => $wire])],
            ]));
            self::assertCount(69, $expanded['context_manifest']['work_manifest']);
        }
        self::assertSame($draft, $root->draft);
        self::assertSame($facts, $root->facts);
    }

    public static function expand(array $wire): array
    {
        if (($wire['_wire'] ?? null) !== EstimateAuditExactValueWireProjection::CONTRACT) {
            return $wire;
        }
        $values = $wire['_v'];
        unset($wire['_wire'], $wire['_v']);
        $expand = static function (mixed $node) use (&$expand, $values): mixed {
            if (is_array($node) && array_keys($node) === ['_ref']) {
                self::assertMatchesRegularExpression('~\A/_v/(0|[1-9][0-9]*)\z~', $node['_ref']);
                $index = (int) substr($node['_ref'], 4);
                self::assertArrayHasKey($index, $values);

                return $values[$index];
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
