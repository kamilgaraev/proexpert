<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisContextPlanner;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisInput;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProjectSynthesisContextPlannerTest extends TestCase
{
    public function test_large_common_context_keeps_entity_and_formula_dependencies_with_a_full_parent_identity(): void
    {
        $root = $this->root();
        $links = [['id' => 'link:a', 'fact_ids' => ['fact:0', 'fact:1']]];
        $part = (new ProjectSynthesisContextPlanner)->prepare($root, $links, [], 524288)[0];
        self::assertSame(['fact:0', 'fact:1', 'fact:2', 'fact:3'], array_column($part['input']->facts, 'id'));
        self::assertSame(['quantity:a'], array_column($part['input']->derivedQuantities, 'id'));
        self::assertSame(['decision:relevant'], array_column($part['input']->decisions, 'id'));
        self::assertSame($root->sourceVersions, $part['input']->sourceVersions);
        self::assertSame($root->fingerprint(), $part['input']->contextManifest['parent_fingerprint']);
        self::assertSame(140, $part['input']->contextManifest['total_facts']);
        self::assertSame($links, $part['links']);
        self::assertNotSame('whole', $part['input']->partId);
        $changed = $root->facts;
        $changed[139]['value'] = 'changed unrelated fact';
        $newRoot = $this->root($changed);
        $newPart = (new ProjectSynthesisContextPlanner)->prepare($newRoot, $links, [], 524288)[0]['input'];
        self::assertSame($part['input']->facts, $newPart->facts);
        self::assertNotSame($part['input']->fingerprint(), $newPart->fingerprint());
    }

    public function test_byte_partition_preserves_every_candidate_exactly_once_and_is_order_independent(): void
    {
        $facts = $links = [];
        for ($i = 0; $i < 65; $i++) {
            foreach (['a', 'b'] as $suffix) {
                $facts[] = ['id' => 'fact:'.$i.':'.$suffix, 'source_version' => $this->source(), 'current' => true,
                    'entity_id' => 'entity:'.$i, 'value' => str_repeat('word ', 100)];
            }
            $links[] = ['id' => 'link:'.$i, 'fact_ids' => ['fact:'.$i.':a', 'fact:'.$i.':b']];
        }
        $root = new ProjectSynthesisInput(1, 2, 3, [$this->source()], $facts, [], [], ['arbiter' => [], 'geometry_expert' => []], 'project-synthesis:v1');
        $planner = new ProjectSynthesisContextPlanner;
        $parts = $planner->prepare($root, $links, [], 10000);
        self::assertGreaterThan(2, count($parts));
        $covered = [];
        foreach ($parts as $part) {
            array_push($covered, ...array_column($part['links'], 'id'));
            self::assertLessThanOrEqual(10000, strlen(json_encode(['project' => $part['input']->canonicalPayload(),
                'candidate_links' => $part['links'], 'candidate_questions' => []], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        }
        self::assertCount(65, array_unique($covered));
        self::assertCount(65, $covered);
        self::assertSame(array_column($parts, 'id'), array_column($planner->prepare($root, array_reverse($links), [], 10000), 'id'));
    }

    public function test_an_indivisible_dependency_group_is_rejected_instead_of_truncated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('project_synthesis_input_requires_source_split');
        (new ProjectSynthesisContextPlanner)->prepare($this->root(), [['id' => 'link:a', 'fact_ids' => ['fact:0', 'fact:1']]], [], 1000);
    }

    private function root(?array $facts = null): ProjectSynthesisInput
    {
        if ($facts === null) {
            $facts = [];
            for ($i = 0; $i < 140; $i++) {
                $facts[] = ['id' => 'fact:'.$i, 'entity_id' => 'entity:'.($i < 3 ? 'a' : $i),
                    'source_version' => $this->source(), 'current' => true, 'value' => str_repeat('word ', 900)];
            }
        }

        return new ProjectSynthesisInput(1, 2, 3, [$this->source()], $facts,
            [['id' => 'quantity:a', 'entity_id' => 'entity:a', 'operand_fact_ids' => ['fact:0', 'fact:3']]],
            [['id' => 'decision:relevant', 'selected_fact_id' => 'fact:3'], ['id' => 'decision:other', 'selected_fact_id' => 'fact:139']],
            ['arbiter' => [], 'geometry_expert' => []], 'project-synthesis:v1');
    }

    private function source(): string
    {
        return 'sha256:'.str_repeat('a', 64);
    }
}
