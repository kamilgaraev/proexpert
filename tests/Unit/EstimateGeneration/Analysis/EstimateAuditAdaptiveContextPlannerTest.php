<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditContextPlanner;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInput;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextWirePolicy;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use PHPUnit\Framework\TestCase;

final class EstimateAuditAdaptiveContextPlannerTest extends TestCase
{
    public function test_compressible_large_snapshot_still_obeys_the_raw_part_bound_and_preserves_unused_sources(): void
    {
        $facts = array_map(static fn (int $i): array => ['id' => 'fact:'.$i, 'value' => str_repeat('source proof ', 1000)], range(1, 210));
        $items = array_map(static fn (int $i): array => ['key' => 'work:'.$i, 'name' => 'Работа '.$i], range(1, 65));
        $root = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, $facts, [],
            ['local_estimates' => [['sections' => [['work_items' => $items]]]]], [], 'estimate-auditor:v3');
        self::assertGreaterThan(2097152, strlen(CanonicalPipelineJson::encode($root->canonicalPayload(false))));
        $policy = ['contract' => EstimatorTextWirePolicy::CONTRACT, 'profile' => 'normal',
            'token_limits' => ['input_tokens' => 24576, 'output_tokens' => 8192, 'max_calls' => 5],
            'maximum_serialized_input_bytes' => 65536];
        $parts = (new EstimateAuditContextPlanner($policy))->prepare($root, 458752);
        $seen = [];
        foreach ($parts as $part) {
            self::assertLessThanOrEqual(2097152, strlen(CanonicalPipelineJson::encode($part['input']->canonicalPayload(false))));
            self::assertLessThanOrEqual(65536, EstimatorTextWirePolicy::serializedInputUpper([
                ['role' => 'system', 'content' => str_repeat('context ', 1024)],
                ['role' => 'user', 'content' => CanonicalPipelineJson::encode(['audit_input' => $part['input']->canonicalPayload()])],
            ]));
            array_push($seen, ...array_column($part['input']->facts, 'id'));
        }
        self::assertEqualsCanonicalizing(array_column($facts, 'id'), array_values(array_unique($seen)));
        self::assertSame($facts, $root->facts);
    }

    public function test_two_heavy_works_do_not_force_all_light_works_into_singleton_parts(): void
    {
        $items = [];
        for ($i = 0; $i < 33; $i++) {
            $items[] = ['key' => 'work:'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'proof' => $i < 2 ? str_repeat('heavy '.$i.' proof; ', 1700) : str_repeat('light '.$i.' ', 20)];
        }
        $facts = [['id' => 'fact:unused', 'entity_id' => 'entity:unused', 'value' => '1', 'unit' => 'm']];
        $quantities = [['id' => 'quantity:unused', 'operand_fact_ids' => ['fact:unused'], 'value' => '1', 'unit' => 'm']];
        $evidence = [['fact_id' => 'fact:unused', 'locator' => ['page' => 1]]];
        $draft = ['global_proof' => str_repeat('retained global source; ', 800),
            'local_estimates' => [['key' => 'building', 'sections' => [['key' => 'works', 'work_items' => $items]]]]];
        $root = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, $facts, $quantities, $draft, $evidence, 'estimate-auditor:v3');
        $policy = ['contract' => EstimatorTextWirePolicy::CONTRACT, 'profile' => 'normal',
            'token_limits' => ['input_tokens' => 24576, 'output_tokens' => 8192, 'max_calls' => 5],
            'maximum_serialized_input_bytes' => 65536];
        $planner = new EstimateAuditContextPlanner($policy);
        $parts = $planner->prepare($root, 458752);
        self::assertCount(7, $parts);
        self::assertContains(16, array_map(static fn (array $part): int => count($part['work_keys']), $parts));
        self::assertEqualsCanonicalizing(array_column($items, 'key'), array_merge(...array_column($parts, 'work_keys')));
        $seenFacts = $seenQuantities = $seenEvidence = [];
        foreach ($parts as $part) {
            $input = $part['input'];
            $wire = $input->canonicalPayload();
            self::assertSame(CanonicalPipelineJson::encode($input->canonicalPayload(false)), CanonicalPipelineJson::encode(EstimateAuditTableWireProjectionTest::expand($wire)));
            self::assertLessThanOrEqual(65536, EstimatorTextWirePolicy::serializedInputUpper([
                ['role' => 'system', 'content' => str_repeat('context ', 1024)],
                ['role' => 'user', 'content' => CanonicalPipelineJson::encode(['audit_input' => $wire])],
            ]));
            self::assertSame($root->fingerprint(), $input->contextManifest['parent_fingerprint']);
            self::assertCount(33, $input->contextManifest['work_manifest']);
            array_push($seenFacts, ...array_column($input->facts, 'id'));
            array_push($seenQuantities, ...array_column($input->derivedQuantities, 'id'));
            array_push($seenEvidence, ...array_column($input->evidence, 'fact_id'));
        }
        self::assertSame(['fact:unused'], array_values(array_unique($seenFacts)));
        self::assertSame(['quantity:unused'], array_values(array_unique($seenQuantities)));
        self::assertSame(['fact:unused'], array_values(array_unique($seenEvidence)));
        self::assertSame(array_column($parts, 'id'), array_column($planner->prepare($root, 458752), 'id'));
        self::assertSame($draft, $root->draft);
    }
}
