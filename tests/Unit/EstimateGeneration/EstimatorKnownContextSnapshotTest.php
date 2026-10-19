<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateCompositionProjector;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorKnownContextParts;
use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorKnownContextSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingBudget;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\TargetedConflictResolver;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class EstimatorKnownContextSnapshotTest extends TestCase
{
    public function test_post_audit_views_are_not_reaudited_but_remain_bound_by_the_complete_saved_draft_hash(): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository([]));
        Container::setInstance($container);
        try {
            $models = new InMemoryProjectModelRepository;
            $source = 'sha256:'.str_repeat('a', 64);
            $models->saveSourceModel([new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity('room:1', 10, 20, 30, $source, 'room', 'room:1')],
                [new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:new', 10, 20, 30, $source, 'room:1', 'room_name',
                    'Updated room name', null, 0.0, 'user_assumption', 'confirmed', [])], []);
            $draft = ['generation_contract' => \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector::CONTRACT,
                'local_estimates' => [['key' => 'local:1', 'sections' => [['key' => 'section:1', 'work_items' => [[
                    'key' => 'work:1', 'item_type' => 'priced_work', 'name' => 'Paint', 'unit' => 'm2', 'quantity' => '10',
                    'source_refs' => [['fact_id' => 'fact:new']],
                ]]]]]], 'evaluation_result' => ['derived_view' => str_repeat('snapshot ', 20000)],
                'quality_summary' => ['derived_review' => str_repeat('review ', 20000)]];
            $session = new EstimateGenerationSession;
            $session->setRawAttributes(['id' => 30, 'organization_id' => 10, 'project_id' => 20,
                'input_payload' => '{}', 'draft_payload' => json_encode($draft, JSON_THROW_ON_ERROR)]);
            $capture = new EstimatorKnownContextSnapshot($models, new EstimateComposerInputFactory($models, 2000),
                new EstimateAuditInputFactory($models, $this->createMock(EvidenceRepository::class), 2000),
                new ProjectSynthesisInputFactory($models), new EstimateCompositionProjector,
                new TargetedConflictResolver(static fn (string $key): string => $key), ProjectUnderstandingBudget::defaults(), new EstimatorKnownContextParts, $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\GenerationPipelineDataGateway::class));
            $plan = $capture->capture($session, false);
            self::assertSame(1, $plan['part_limits']['audit']);
            self::assertSame(hash('sha256', \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($draft)), $plan['saved_draft_hash']);
            self::assertSame($draft, $session->draft_payload);
            $changed = [...$draft, 'quality_summary' => ['derived_review' => 'changed']];
            $session->setRawAttributes([...$session->getAttributes(), 'draft_payload' => json_encode($changed, JSON_THROW_ON_ERROR)]);
            self::assertNotSame($plan['fingerprint'], $capture->capture($session, false)['fingerprint']);
            $session->setRawAttributes([...$session->getAttributes(), 'draft_payload' => json_encode($draft, JSON_THROW_ON_ERROR)]);
            $this->expectExceptionMessage('estimator_known_context_requires_source_split');
            $capture->capture($session, false, \App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextWirePolicy::legacyCapture());
        } finally {
            Container::setInstance($previous);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('draftStates')]
    public function test_an_old_draft_fact_reference_does_not_block_a_quote_for_recomposition_with_current_facts(string $reference, int $technologyRevision, string $status, int $auditParts): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository([]));
        Container::setInstance($container);
        try {
            $models = new InMemoryProjectModelRepository;
            $source = 'sha256:'.str_repeat('a', 64);
            $models->saveSourceModel([new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity('room:1', 10, 20, 30, $source, 'room', 'room:1')],
                [new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:new', 10, 20, 30, $source, 'room:1', 'room_name',
                    'Updated room name', null, 0.0, 'user_assumption', 'confirmed', [])], []);
            $draft = ['local_estimates' => [['key' => 'local:1', 'sections' => [['key' => 'section:1', 'work_items' => [[
                'key' => 'work:1', 'item_type' => 'priced_work', 'name' => 'Paint', 'unit' => 'm2', 'quantity' => '10',
                'source_refs' => [['fact_id' => $reference]],
            ]]]]]]];
            $session = new EstimateGenerationSession;
            $session->setRawAttributes(['id' => 30, 'organization_id' => 10, 'project_id' => 20,
                'input_payload' => json_encode(['technology_selection_revision' => $technologyRevision], JSON_THROW_ON_ERROR),
                'draft_payload' => json_encode($draft, JSON_THROW_ON_ERROR)]);
            $capture = new EstimatorKnownContextSnapshot($models, new EstimateComposerInputFactory($models, 2000),
                new EstimateAuditInputFactory($models, $this->createMock(EvidenceRepository::class), 2000),
                new ProjectSynthesisInputFactory($models), new EstimateCompositionProjector,
                new TargetedConflictResolver(static fn (string $key): string => $key), ProjectUnderstandingBudget::defaults(), new EstimatorKnownContextParts, $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\GenerationPipelineDataGateway::class));
            $plan = $capture->capture($session, false);
            self::assertSame($status, $plan['draft_context_status']);
            self::assertSame(1, $plan['known_fact_count']);
            self::assertSame(1, $plan['known_candidate_count']);
            self::assertSame($auditParts, $plan['part_limits']['audit']);
            self::assertSame($draft, $session->draft_payload);
        } finally {
            Container::setInstance($previous);
        }
    }

    public static function draftStates(): array
    {
        return [['fact:old', 0, 'recomposition_required', 0], ['fact:new', 1, 'recomposition_required', 0], ['fact:new', 0, 'current', 1]];
    }

    public function test_a_single_oversized_fact_has_an_actionable_split_error_before_any_model_call(): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository([]));
        Container::setInstance($container);
        try {
            $models = new InMemoryProjectModelRepository;
            $source = 'sha256:'.str_repeat('a', 64);
            $models->saveSourceModel([new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity('room:1', 10, 20, 30, $source, 'room', 'room:1')],
                [new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:large', 10, 20, 30, $source, 'room:1', 'room_name',
                    str_repeat('word ', 20000), null, 0.0, 'user_assumption', 'confirmed', [])], []);
            $session = new EstimateGenerationSession;
            $session->setRawAttributes(['id' => 30, 'organization_id' => 10, 'project_id' => 20, 'input_payload' => '{}', 'draft_payload' => '{}']);
            $capture = new EstimatorKnownContextSnapshot($models, new EstimateComposerInputFactory($models, 2000),
                new EstimateAuditInputFactory($models, $this->createMock(EvidenceRepository::class), 2000), new ProjectSynthesisInputFactory($models),
                new EstimateCompositionProjector, new TargetedConflictResolver(static fn (string $key): string => $key),
                ProjectUnderstandingBudget::defaults(), new EstimatorKnownContextParts, $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\GenerationPipelineDataGateway::class));
            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('estimator_known_context_requires_source_split');
            $capture->capture($session, false);
        } finally {
            Container::setInstance($previous);
        }
    }

    public function test_oversized_evidence_is_rejected_before_loading_the_full_project_snapshot(): void
    {
        $models = new InMemoryProjectModelRepository;
        $models->understandingWithinBudget = false;
        $session = new EstimateGenerationSession;
        $session->setRawAttributes(['id' => 30, 'organization_id' => 10, 'project_id' => 20, 'input_payload' => '{}', 'draft_payload' => '{}']);
        $capture = new EstimatorKnownContextSnapshot($models, new EstimateComposerInputFactory($models, 2000),
            new EstimateAuditInputFactory($models, $this->createMock(EvidenceRepository::class), 2000),
            new ProjectSynthesisInputFactory($models), new EstimateCompositionProjector,
            new TargetedConflictResolver(static fn (string $key): string => $key), ProjectUnderstandingBudget::defaults(),
            new EstimatorKnownContextParts, $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\GenerationPipelineDataGateway::class));
        try {
            $capture->capture($session, false);
            self::fail('A full snapshot was loaded despite the evidence preflight limit.');
        } catch (\DomainException $error) {
            self::assertSame('estimator_known_context_requires_source_split', $error->getMessage());
        }
        self::assertSame(1, $models->understandingPreflightCalls);
        self::assertSame(0, $models->snapshotCalls);
    }
}
