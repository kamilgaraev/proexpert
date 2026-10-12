<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisInput;
use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorKnownContextParts;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextWirePolicy;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

final class EstimatorKnownContextPartsTest extends TestCase
{
    private Container $previous;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['estimate-generation' => ['estimate_composer' => ['max_input_bytes' => 5000]]]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
        parent::tearDown();
    }

    public function test_known_facts_without_work_candidates_are_partitioned_before_any_paid_operation(): void
    {
        $input = $this->input();
        $parts = (new EstimatorKnownContextParts)->measure($input, null, null, [], [], EstimatorTextWirePolicy::capture());
        self::assertSame(2, $parts['part_limits']['composer']);
        self::assertSame(0, $parts['part_limits']['audit']);
        self::assertSame($parts, (new EstimatorKnownContextParts)->measure($input, null, null, [], [], EstimatorTextWirePolicy::capture()));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $parts['fingerprint']);
    }

    public function test_a_changed_source_changes_the_quote_identity_even_when_the_part_count_is_the_same(): void
    {
        $measure = new EstimatorKnownContextParts;
        $before = $measure->measure($this->input(), null, null, [], [], EstimatorTextWirePolicy::capture());
        $after = $measure->measure($this->input('changed '), null, null, [], [], EstimatorTextWirePolicy::capture());
        self::assertSame($before['part_limits'], $after['part_limits']);
        self::assertNotSame($before['fingerprint'], $after['fingerprint']);
    }

    public function test_audit_and_engineer_parts_use_their_real_context_and_dependency_planners(): void
    {
        config()->set('estimate-generation.estimate_auditor.max_input_bytes', 5000);
        config()->set('estimate-generation.project_engineer.max_input_bytes', 5000);
        $facts = $this->input()->facts;
        $draft = ['local_estimates' => [['key' => 'estimate:1', 'sections' => [['key' => 'section:1', 'work_items' => [
            ['key' => 'work:1', 'entity_id' => 'room:1'], ['key' => 'work:2', 'entity_id' => 'room:2'],
        ]]]]]];
        $audit = new EstimateAuditInput(10, 20, 30, str_repeat('a', 64), 0, $facts, [], $draft, [], 'estimate-audit:v1');
        $synthesis = new ProjectSynthesisInput(10, 20, 30, ['sha256:'.str_repeat('a', 64)], $facts, [], [],
            ['arbiter' => [], 'geometry_expert' => []], 'project-synthesis:v1');
        $links = [['id' => 'link:1', 'fact_ids' => ['fact:1']], ['id' => 'link:2', 'fact_ids' => ['fact:2']]];
        $parts = (new EstimatorKnownContextParts)->measure(null, $audit, $synthesis, $links, [], EstimatorTextWirePolicy::capture());
        self::assertSame(2, $parts['part_limits']['audit']);
        self::assertSame(2, $parts['part_limits']['synthesis']);
        self::assertSame(0, $parts['part_limits']['composer']);
    }

    public function test_audit_uses_the_smaller_correction_bound_and_replay_retains_its_frozen_limits(): void
    {
        $draft = ['local_estimates' => [['key' => 'estimate:1', 'sections' => [['key' => 'section:1', 'work_items' => [
            ['key' => 'work:1', 'entity_id' => 'room:1'], ['key' => 'work:2', 'entity_id' => 'room:2'],
        ]]]]]];
        $audit = new EstimateAuditInput(10, 20, 30, str_repeat('a', 64), 0, $this->input()->facts, [], $draft, [], 'estimate-audit:v1');
        $measure = new EstimatorKnownContextParts;
        $policy = EstimatorTextWirePolicy::capture();
        $parts = $measure->measure(null, $audit, null, [], [], $policy);
        self::assertSame(13192, $parts['role_limits']['policies']['audit']['maximum_serialized_input_bytes']);
        self::assertSame(2, $parts['part_limits']['audit']);
        config()->set('estimate-generation.estimate_composer.max_input_bytes', 20000);
        self::assertSame($parts, $measure->measure(null, $audit, null, [], [], $policy, $parts['role_limits']));
        $stored = json_decode(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($parts['role_limits']), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame($parts, $measure->measure(null, $audit, null, [], [], $policy, $stored));
        self::assertSame(1, $measure->measure(null, $audit, null, [], [], $policy)['part_limits']['audit']);
    }

    private function input(string $prefix = ''): EstimateComposerInput
    {
        return new EstimateComposerInput(10, 20, 30, str_repeat('a', 64), [
            ['id' => 'fact:1', 'entity_id' => 'room:1', 'value' => $prefix.str_repeat('word ', 500), 'status' => 'confirmed'],
            ['id' => 'fact:2', 'entity_id' => 'room:2', 'value' => str_repeat('word ', 500), 'status' => 'confirmed'],
        ], [], [], [], [], RunEstimateComposer::PROMPT_CONTRACT);
    }
}
