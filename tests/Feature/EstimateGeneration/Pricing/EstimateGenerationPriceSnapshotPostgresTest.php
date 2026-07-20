<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationFeedback;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentPipelineCheckpointStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineArtifactReference;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageOutput;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PublishValidatedDraft;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateFeedbackService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateSelectionService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\EstimateGeneration\EstimateGenerationContractDatabaseProvisioner;
use Tests\TestCase;

#[Group('postgres-contract')]
final class EstimateGenerationPriceSnapshotPostgresTest extends TestCase
{
    public function test_plan_checkpoint_completion_materializes_accepted_quantity_atomically_and_idempotently(): void
    {
        $this->requireDisposablePostgres();
        $fixture = $this->fixture();
        $attempt = '11111111-2222-4333-8444-555555555555';
        $session = EstimateGenerationSession::query()->findOrFail($fixture['session_id']);
        $session->forceFill(['status' => 'generating', 'state_version' => 7, 'input_payload' => ['generation_attempt_id' => $attempt]])->save();
        $dependencies = [
            ProcessingStage::UnderstandObject->value => 'sha256:'.str_repeat('a', 64),
            ProcessingStage::ExtractQuantities->value => 'sha256:'.str_repeat('b', 64),
        ];
        foreach ($dependencies as $stage => $version) {
            DB::table('estimate_generation_pipeline_checkpoints')->insert([
                'organization_id' => $session->organization_id, 'project_id' => $session->project_id,
                'session_id' => $session->id, 'generation_attempt_id' => $attempt, 'stage' => $stage,
                'base_input_version' => 'sha256:'.str_repeat('d', 64),
                'input_version' => 'sha256:'.str_repeat($stage === 'understand_object' ? '1' : '2', 64),
                'dependency_versions' => '{}', 'status' => 'completed', 'output_version' => $version,
                'output_payload' => '{}', 'artifact_bytes' => 1, 'metrics' => '{}', 'warnings' => '[]',
                'attempt_count' => 1, 'started_at' => now(), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $context = new PipelineContext(
            $session->id, $session->organization_id, $session->project_id, 7,
            'sha256:'.str_repeat('c', 64), 'generating', generationAttemptId: $attempt,
            baseInputVersion: 'sha256:'.str_repeat('d', 64), stage: ProcessingStage::PlanWorkItems,
            dependencyVersions: $dependencies,
        );
        $data = new EvidenceData(
            $session->organization_id, $session->project_id, $session->id, EvidenceType::WorkItem,
            EvidenceSourceType::UserInput, 'input:99', 'contract:abcdef',
            ['item_key' => 'item:'.hash('sha256', 'checkpoint-item')],
            ['work_code' => 'work_type:99', 'quantity' => '123456789.123456', 'unit' => 'm2'],
            1.0, 'pipeline', 'contract:abcdef',
        );
        $descriptor = [
            'source_type' => 'user_input', 'source_ref' => 'input:99', 'source_version' => 'contract:abcdef',
            'locator' => $data->locator, 'work_code' => 'work_type:99', 'quantity' => '123456789.123456',
            'unit' => 'm2', 'confidence' => 1.0, 'producer_name' => 'pipeline',
            'producer_version' => 'contract:abcdef', 'fingerprint' => $data->fingerprint(),
        ];
        $transient = ['local_estimates' => [['sections' => [['work_items' => [[
            'key' => 'checkpoint-item', 'quantity_evidence_descriptor' => $descriptor,
        ]]]]]]];
        $definition = PipelineDefinitionGraph::standard()->get(ProcessingStage::PlanWorkItems);
        $artifact = new PipelineArtifactReference(
            'memory_json_v1', 'contract/checkpoint',
            'sha256:'.hash('sha256', CanonicalPipelineJson::encode($transient)), 128,
        );
        $output = PipelineStageOutput::create($definition, $context->inputVersion, $dependencies, $artifact);
        $result = new PipelineStageResult(ProcessingStage::PlanWorkItems, $output->version, [], output: $output, transientData: $transient);
        $now = new \DateTimeImmutable('2026-07-12T12:00:00+00:00');
        $store = new EloquentPipelineCheckpointStore(DB::connection(), app(PublishValidatedDraft::class));
        $claim = $store->claim($context, ProcessingStage::PlanWorkItems, $now, $now->modify('+5 minutes'));
        self::assertTrue($store->complete($claim, $result, $now->modify('+1 second')));
        $checkpoint = DB::table('estimate_generation_pipeline_checkpoints')->where('id', $claim->checkpointId)->first();
        self::assertSame('completed', $checkpoint->status);
        self::assertSame($output->version, $checkpoint->output_version);
        self::assertSame(1, DB::table('estimate_generation_accepted_evidence')->where('checkpoint_id', $claim->checkpointId)->count());
        self::assertSame(1, DB::table('estimate_generation_evidence')->where('fingerprint', $data->fingerprint())->count());
        self::assertFalse($store->complete($claim, $result, $now->modify('+2 seconds')));
        self::assertSame(1, DB::table('estimate_generation_accepted_evidence')->where('checkpoint_id', $claim->checkpointId)->count());
        self::assertSame(1, DB::table('estimate_generation_evidence')->where('fingerprint', $data->fingerprint())->count());

        $freshData = new EvidenceData(
            $session->organization_id, $session->project_id, $session->id, EvidenceType::WorkItem,
            EvidenceSourceType::UserInput, 'input:100', 'contract:fedcba',
            ['item_key' => 'item:'.hash('sha256', 'fresh-checkpoint-item')],
            ['work_code' => 'work_type:100', 'quantity' => '987654321.987654', 'unit' => 'm3'],
            1.0, 'pipeline', 'contract:fedcba',
        );
        $freshDescriptor = [
            'source_type' => 'user_input', 'source_ref' => 'input:100', 'source_version' => 'contract:fedcba',
            'locator' => $freshData->locator, 'work_code' => 'work_type:100', 'quantity' => '987654321.987654',
            'unit' => 'm3', 'confidence' => 1.0, 'producer_name' => 'pipeline',
            'producer_version' => 'contract:fedcba', 'fingerprint' => $freshData->fingerprint(),
        ];
        $freshTransient = ['local_estimates' => [['sections' => [['work_items' => [[
            'key' => 'fresh-checkpoint-item', 'quantity_evidence_descriptor' => $freshDescriptor,
        ]]]]]]];
        $secondContext = new PipelineContext(
            $session->id, $session->organization_id, $session->project_id, 7,
            'sha256:'.str_repeat('e', 64), 'generating', generationAttemptId: $attempt,
            baseInputVersion: 'sha256:'.str_repeat('d', 64), stage: ProcessingStage::PlanWorkItems,
            dependencyVersions: $dependencies,
        );
        $freshArtifact = new PipelineArtifactReference(
            'memory_json_v1', 'contract/checkpoint-fresh',
            'sha256:'.hash('sha256', CanonicalPipelineJson::encode($freshTransient)), 128,
        );
        $freshOutput = PipelineStageOutput::create($definition, $secondContext->inputVersion, $dependencies, $freshArtifact);
        $freshResult = new PipelineStageResult(
            ProcessingStage::PlanWorkItems,
            $freshOutput->version,
            [],
            output: $freshOutput,
            transientData: $freshTransient,
        );
        $productionPublisher = app(PublishValidatedDraft::class);
        $failingStore = new EloquentPipelineCheckpointStore(DB::connection(), new class($productionPublisher) implements \App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineCompletionHook
        {
            public function __construct(private readonly PublishValidatedDraft $productionPublisher) {}

            public function beforeComplete(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaim $claim, PipelineStageResult $result, \DateTimeImmutable $completedAt): void
            {
                $this->productionPublisher->beforeComplete($claim, $result, $completedAt);

                throw new \RuntimeException('injected-after-production-materialization');
            }
        });
        $failedClaim = $failingStore->claim($secondContext, ProcessingStage::PlanWorkItems, $now, $now->modify('+5 minutes'));
        try {
            $failingStore->complete($failedClaim, $freshResult, $now->modify('+1 second'));
            self::fail('Injected completion failure was not propagated.');
        } catch (\RuntimeException $exception) {
            self::assertSame('injected-after-production-materialization', $exception->getMessage());
        }
        $running = DB::table('estimate_generation_pipeline_checkpoints')->where('id', $failedClaim->checkpointId)->first();
        self::assertSame('running', $running->status);
        self::assertSame($failedClaim->claimToken, $running->claim_token);
        self::assertNull($running->output_version);
        self::assertSame(0, DB::table('estimate_generation_accepted_evidence')->where('checkpoint_id', $failedClaim->checkpointId)->count());
        self::assertSame(0, DB::table('estimate_generation_evidence')->where('fingerprint', $freshData->fingerprint())->count());

        $retryAt = $now->modify('+6 minutes');
        $retry = $store->claim($secondContext, ProcessingStage::PlanWorkItems, $retryAt, $retryAt->modify('+5 minutes'));
        self::assertFalse($store->complete($failedClaim, $freshResult, $retryAt->modify('+1 second')));
        self::assertSame(0, DB::table('estimate_generation_accepted_evidence')->where('checkpoint_id', $failedClaim->checkpointId)->count());
        self::assertTrue($store->complete($retry, $freshResult, $retryAt->modify('+1 second')));
        self::assertSame(1, DB::table('estimate_generation_accepted_evidence')->where('checkpoint_id', $retry->checkpointId)->count());
        self::assertSame(1, DB::table('estimate_generation_evidence')->where('fingerprint', $freshData->fingerprint())->count());
    }

    public function test_follow_up_migrations_roll_back_to_001400_and_reapply_cleanly(): void
    {
        $this->requireDisposablePostgres();
        $root = dirname(__DIR__, 4);
        $migration1500 = require EstimateGenerationContractDatabaseProvisioner::subjectMigration('pricing', '2026_07_12_001500_publish_accepted_evidence_and_close_pricing_provenance.php', $root);
        $migration1600 = require EstimateGenerationContractDatabaseProvisioner::subjectMigration('pricing', '2026_07_12_001600_harden_accepted_evidence_mapping.php', $root);

        $migration1600->down();
        $migration1500->down();
        self::assertSame(null, DB::selectOne("SELECT to_regclass('public.estimate_generation_accepted_evidence') AS object_name")->object_name);
        self::assertSame(null, DB::selectOne("SELECT to_regprocedure('public.eg_pricing_provenance(bigint)') AS object_name")->object_name);
        $definition = (string) DB::selectOne("SELECT pg_get_functiondef('public.eg_finalize_package_item_price(bigint)'::regprocedure) AS definition")->definition;
        self::assertStringContainsString('eg_expected_package_item_price(p_item_id)', $definition);
        self::assertStringNotContainsString('eg_expected_package_item_price_closed', $definition);

        $migration1500->up();
        $migration1600->up();
        self::assertNotNull(DB::selectOne("SELECT to_regclass('public.estimate_generation_accepted_evidence') AS object_name")->object_name);
        self::assertNotNull(DB::selectOne("SELECT to_regprocedure('public.eg_bounded_pricing_json_hash(jsonb)') AS object_name")->object_name);
    }

    public function test_manual_quantity_confirmation_persists_scoped_user_evidence_and_rolls_back_atomically(): void
    {
        $this->requireDisposablePostgres();
        $fixture = $this->fixture();
        $session = EstimateGenerationSession::query()->findOrFail($fixture['session_id']);
        $draft = $this->serviceDraft($fixture, '2.5');
        $draft['regional_context'] = [
            'normative_dataset_version' => $fixture['dataset_version_key'],
            'region_id' => $fixture['region_id'], 'price_zone_id' => $fixture['zone_id'],
            'period_id' => $fixture['period_id'], 'regional_price_version_id' => $fixture['version_id'],
        ];
        $draft['local_estimates'][0]['source_refs'] = [['type' => 'document', 'filename' => 'contract.pdf', 'page_number' => 1]];
        $draft['local_estimates'][0]['sections'][0]['title'] = 'Работы';
        $draft['local_estimates'][0]['sections'][0]['source_refs'] = [['type' => 'document', 'filename' => 'contract.pdf', 'page_number' => 1]];
        $item = &$draft['local_estimates'][0]['sections'][0]['work_items'][0];
        $item['item_type'] = 'quantity_review';
        $item['unit'] = 'm2';
        $item['pricing_status'] = 'not_applicable';
        $item['pricing_blocker'] = 'quantity_review_required';
        $item['validation_flags'] = ['quantity_review_required'];
        $item['metadata'] = ['quantity_key' => 'item-1', 'display_role' => 'quantity_review'];
        $item['source_refs'] = [['type' => 'document', 'filename' => 'contract.pdf', 'page_number' => 1]];
        $contentVersion = 'sha256:'.hash('sha256', json_encode($draft['local_estimates'], JSON_THROW_ON_ERROR));
        $draft['quality_summary'] = [
            'content_version' => $contentVersion,
            'review_items' => ['source_version' => $contentVersion, 'classifier_version' => 1],
        ];
        $session->forceFill(['status' => 'estimate_review_required', 'draft_payload' => $draft])->save();
        $beforeCount = DB::table('estimate_generation_evidence')->where('session_id', $session->id)->count();

        DB::beginTransaction();
        try {
            $feedback = EstimateGenerationFeedback::query()->create([
                'session_id' => $session->id, 'user_id' => $session->user_id, 'feedback_type' => 'quantity_confirmation',
                'work_item_key' => 'item-1', 'payload' => ['quantity' => '2.5', 'unit' => 'm2', 'quantity_basis' => 'contract'],
            ]);
            app(NormativeCandidateFeedbackService::class)->apply($session, $feedback);
            self::assertSame($beforeCount + 1, DB::table('estimate_generation_evidence')->where('session_id', $session->id)->count());
            $confirmed = $session->fresh()->draft_payload['local_estimates'][0]['sections'][0]['work_items'][0];
            $node = DB::table('estimate_generation_evidence')->where('id', $confirmed['quantity_evidence_id'])->first();
            self::assertSame('user_input', $node->source_type);
            self::assertSame((int) $session->organization_id, (int) $node->organization_id);
            self::assertSame((int) $session->project_id, (int) $node->project_id);
            self::assertSame((int) $session->id, (int) $node->session_id);
            self::assertNotSame($fixture['evidence_id'], (int) $node->id);
            self::assertNotNull(DB::table('estimate_generation_evidence')->where('id', $fixture['evidence_id'])->value('invalidated_at'));
            throw new \RuntimeException('forced-later-failure');
        } catch (\RuntimeException $exception) {
            self::assertSame('forced-later-failure', $exception->getMessage());
            DB::rollBack();
        }

        self::assertSame($beforeCount, DB::table('estimate_generation_evidence')->where('session_id', $session->id)->count());
        self::assertNull(DB::table('estimate_generation_evidence')->where('id', $fixture['evidence_id'])->value('invalidated_at'));
        self::assertSame(0, DB::table('estimate_generation_feedback')->where('session_id', $session->id)->count());

        DB::transaction(function () use ($session): void {
            $feedback = EstimateGenerationFeedback::query()->create([
                'session_id' => $session->id, 'user_id' => $session->user_id, 'feedback_type' => 'quantity_confirmation',
                'work_item_key' => 'item-1', 'payload' => ['quantity' => '2.5', 'unit' => 'm2', 'quantity_basis' => 'contract'],
            ]);
            app(NormativeCandidateFeedbackService::class)->apply($session->fresh(), $feedback);
        });
        $persistedDraft = $session->fresh()->draft_payload;
        $persistedItem = $persistedDraft['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertNotSame($fixture['evidence_id'], (int) $persistedItem['quantity_evidence_id']);
        self::assertSame('user_input', DB::table('estimate_generation_evidence')->where('id', $persistedItem['quantity_evidence_id'])->value('source_type'));
        self::assertNotNull(DB::table('estimate_generation_evidence')->where('id', $fixture['evidence_id'])->value('invalidated_at'));

        self::assertNotNull(app(EstimateNormativeMatcher::class)->matchSelectedNorm(
            $fixture['manual_norm_id'],
            $persistedItem,
            $persistedDraft['regional_context'],
        ));
        app(NormativeCandidateSelectionService::class)->select($session->fresh(), 'item-1', $fixture['manual_norm_id'], true);
        $regenerated = $session->fresh()->draft_payload;
        $regeneratedItem = &$regenerated['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertSame($fixture['manual_norm_id'], $regeneratedItem['normative_match']['norm_id']);
        $regeneratedItem['price_snapshot'] = [
            'region_id' => $fixture['region_id'], 'zone_id' => $fixture['zone_id'],
            'period_id' => $fixture['period_id'], 'version_id' => $fixture['version_id'],
        ];
        app(EstimateGenerationPackagePersistenceService::class)->syncFromDraft($session->fresh(), $regenerated);
        $finalized = DB::table('estimate_generation_package_items')
            ->join('estimate_generation_packages', 'estimate_generation_packages.id', '=', 'estimate_generation_package_items.package_id')
            ->where('estimate_generation_packages.session_id', $session->id)
            ->where('estimate_generation_packages.key', 'service-package')
            ->where('logical_key', 'item-1')->orderByDesc('revision')->first();
        self::assertNotNull($finalized);
        self::assertNotNull($finalized->pricing_finalized_at);
        self::assertSame((int) $persistedItem['quantity_evidence_id'], (int) $finalized->quantity_evidence_id);
        self::assertSame($persistedItem['quantity_evidence_fingerprint'], $finalized->quantity_evidence_fingerprint);
        self::assertNotNull($finalized->price_snapshot);
    }

    public function test_concurrent_real_persistence_serializes_revision_allocation_without_lost_update(): void
    {
        $this->requireDisposablePostgres();
        $fixture = $this->fixture();
        $session = EstimateGenerationSession::query()->findOrFail($fixture['session_id']);
        $service = app(EstimateGenerationPackagePersistenceService::class);
        $service->syncFromDraft($session, $this->serviceDraft($fixture, '2.5'));
        $packageId = (int) DB::table('estimate_generation_packages')->where('session_id', $fixture['session_id'])->where('key', 'service-package')->value('id');
        $catalog = $this->catalogPrice($fixture, $fixture['resource_code'], '700.0000', 'concurrent');
        $draft = $this->serviceDraft(array_replace($fixture, ['version_id' => $catalog['version_id'], 'price_id' => $catalog['price_id']]), '2.5');
        $encoded = base64_encode(json_encode($draft, JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, dirname(__DIR__, 3).'/Support/EstimatePricingConcurrentWriter.php', (string) $fixture['session_id'], (string) $packageId, $encoded];
        $environment = array_replace(getenv(), ['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'most_ai_estimator_contract']);
        $leader = proc_open([...$command, 'leader'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $leaderPipes, dirname(__DIR__, 4), $environment);
        self::assertIsResource($leader);
        self::assertStringContainsString('LOCKED', $this->waitForProcessToken($leader, $leaderPipes[1], $leaderPipes[2], 'LOCKED'));
        $follower = proc_open([...$command, 'follower'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $followerPipes, dirname(__DIR__, 4), $environment);
        self::assertIsResource($follower);
        fwrite($leaderPipes[0], "CONTINUE\n");
        fclose($leaderPipes[0]);
        $leaderOutput = $this->waitForProcessToken($leader, $leaderPipes[1], $leaderPipes[2], 'DONE');
        $followerOutput = $this->waitForProcessToken($follower, $followerPipes[1], $followerPipes[2], 'DONE');
        $leaderError = stream_get_contents($leaderPipes[2]);
        $followerError = stream_get_contents($followerPipes[2]);
        self::assertSame(0, proc_close($leader), $leaderError);
        self::assertSame(0, proc_close($follower), $followerError);
        self::assertStringContainsString('DONE', $leaderOutput);
        self::assertStringContainsString('DONE', $followerOutput);
        $revisions = DB::table('estimate_generation_package_items')->where('package_id', $packageId)->where('logical_key', 'item-1')->orderBy('revision')->get();
        self::assertCount(2, $revisions);
        self::assertSame([1, 2], $revisions->pluck('revision')->map(fn ($value): int => (int) $value)->all());
        self::assertSame((int) $revisions[0]->id, (int) $revisions[1]->supersedes_item_id);
        self::assertSame('87.50', $revisions[1]->total_cost);
    }

    private function waitForProcessToken($process, $stdout, $stderr, string $token): string
    {
        $output = '';
        $deadline = hrtime(true) + 15_000_000_000;
        do {
            $chunk = fread($stdout, 8192);
            if ($chunk !== false) {
                $output .= $chunk;
            }
            if (str_contains($output, $token)) {
                return $output;
            }
            $status = proc_get_status($process);
            if (! $status['running']) {
                self::fail(trim((string) stream_get_contents($stderr)) ?: 'Concurrent writer stopped before '.$token.'. Output: '.$output.' Exit: '.$status['exitcode']);
            }
        } while (hrtime(true) < $deadline);

        self::fail('Concurrent writer timed out before '.$token.'.');
    }

    public function refreshDatabase(): void {}

    public function test_database_builds_deterministic_price_snapshot_and_protects_every_trust_input(): void
    {
        $this->requireDisposablePostgres();

        DB::beginTransaction();
        try {
            $fixture = $this->fixture();
            DB::select('SELECT eg_finalize_package_item_price(?)', [$fixture['item_id']]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

            $item = DB::table('estimate_generation_package_items')->find($fixture['item_id']);
            $snapshot = json_decode((string) $item->price_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $canonical = $fixture['norm_resource_id'].':'.$fixture['norm_id'].':'.$fixture['resource_code'].':labor:min:3.000000:'
                .$fixture['price_id'].':'.$fixture['version_id'].':h:600.0000:'.$fixture['conversion_id'].':0.016666666667|'
                .$fixture['evidence_id'].':'.$fixture['fingerprint'];

            self::assertSame('75.00', $item->direct_cost);
            self::assertSame('75.00', $item->total_cost);
            self::assertSame('30.000000', $item->unit_price);
            self::assertSame('2.500000', $item->quantity);
            self::assertSame('sha256:'.hash('sha256', $canonical), $snapshot['source_reference']);
            self::assertSame('75.00', $snapshot['base_amount']);
            self::assertSame('75.00', $snapshot['final_amount']);
            self::assertSame('0.00', $snapshot['coefficients']['work_cost']);
            self::assertSame($fixture['region_id'], $snapshot['region_id']);
            self::assertSame($fixture['version_id'], $snapshot['version_id']);
            self::assertSame('3.000000', $snapshot['coefficients']['resource_evidence'][0]['norm_quantity']);
            self::assertSame('600.0000', $snapshot['coefficients']['resource_evidence'][0]['base_price']);
            self::assertSame('2.5', json_decode((string) DB::table('estimate_generation_evidence')->where('id', $fixture['evidence_id'])->value('value'), true, flags: JSON_THROW_ON_ERROR)['quantity']);
            $this->assertRejected(fn () => DB::table('estimate_generation_evidence')->insert([
                'organization_id' => DB::table('estimate_generation_evidence')->where('id', $fixture['evidence_id'])->value('organization_id'),
                'project_id' => DB::table('estimate_generation_evidence')->where('id', $fixture['evidence_id'])->value('project_id'),
                'session_id' => $fixture['session_id'], 'type' => 'work_item', 'source_type' => 'user_input',
                'source_ref' => 'input:999999', 'source_version' => 'contract:abcdef',
                'locator' => json_encode(['item_key' => 'item:'.hash('sha256', 'numeric-rejected')], JSON_THROW_ON_ERROR),
                'value' => json_encode(['work_code' => 'work_type:999999', 'quantity' => 2.5, 'unit' => 'h'], JSON_THROW_ON_ERROR),
                'confidence' => 1, 'producer_name' => 'contract', 'producer_version' => 'contract:abcdef',
                'fingerprint' => hash('sha256', 'numeric-rejected'), 'created_at' => now(), 'updated_at' => now(),
            ]));
            $provenance = $snapshot['coefficients']['provenance'];
            self::assertSame('pricing_provenance:v1', $provenance['schema_version']);
            self::assertSame($fixture['norm_resource_id'], $provenance['resources'][0]['norm_resource_id']);
            self::assertSame($fixture['price_id'], $provenance['resources'][0]['price_id']);
            self::assertSame($fixture['dataset_id'], $provenance['resources'][0]['norm_dataset']['id']);
            self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $provenance['resources'][0]['raw_payload_hash']);
            self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $provenance['resources'][0]['price_raw_payload_hash']);

            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            $session = EstimateGenerationSession::query()->findOrFail($fixture['session_id']);
            app(EstimateGenerationPackagePersistenceService::class)->syncFromDraft($session, $this->serviceDraft($fixture, '2.5'));
            $servicePackageId = DB::table('estimate_generation_packages')->where('session_id', $fixture['session_id'])->where('key', 'service-package')->value('id');
            $serviceItem = DB::table('estimate_generation_package_items')->where('package_id', $servicePackageId)->where('logical_key', 'item-1')->first();
            self::assertNotNull($serviceItem->pricing_finalized_at);
            self::assertSame('75.00', $serviceItem->total_cost);

            app(EstimateGenerationPackagePersistenceService::class)->syncFromDraft($session, $this->serviceDraft($fixture, '9'));
            $tampered = DB::table('estimate_generation_package_items')->where('package_id', $servicePackageId)->where('logical_key', 'item-1')->orderByDesc('revision')->first();
            self::assertNull($tampered->pricing_finalized_at);
            self::assertNull($tampered->price_snapshot);
            self::assertSame('0.00', $tampered->total_cost);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            $this->assertRejected(fn () => DB::table('estimate_generation_package_item_price_inputs')->insert([
                'package_item_id' => $serviceItem->id, 'norm_resource_id' => $fixture['norm_resource_id'],
                'resource_price_id' => $fixture['price_id'], 'unit_conversion_id' => $fixture['conversion_id'],
                'ordinal' => 2, 'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->assertRejected(fn () => DB::table('estimate_generation_package_items')->insert(array_replace(
                $this->itemPayload($fixture, 'forged-priced', 1, null),
                ['pricing_finalized_at' => now(), 'price_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)],
            )));
            $this->assertRejected(fn () => DB::table('estimate_regional_price_versions')->where('id', $fixture['version_id'])->update(['status' => 'superseded']));
            $this->assertRejected(fn () => DB::table('estimate_norm_resources')->where('id', $fixture['norm_resource_id'])->update(['quantity' => 99]));
            $this->assertRejected(fn () => DB::table('estimate_dataset_versions')->where('id', $fixture['price_dataset_id'])->update(['version_key' => strtolower((string) str()->ulid())]));

            $function = DB::selectOne("SELECT p.prosecdef, p.proconfig, r.rolname AS owner, current_user AS runtime_role, NOT EXISTS (SELECT 1 FROM aclexplode(COALESCE(p.proacl, acldefault('f', p.proowner))) a WHERE a.grantee=0 AND a.privilege_type='EXECUTE') AS public_revoked, has_function_privilege(current_user, p.oid, 'EXECUTE') AS runtime_granted FROM pg_proc p JOIN pg_roles r ON r.oid=p.proowner WHERE p.oid='public.eg_finalize_package_item_price(bigint)'::regprocedure");
            self::assertTrue($function->prosecdef);
            self::assertStringContainsString('search_path=pg_catalog, public', (string) $function->proconfig);
            self::assertTrue($function->public_revoked);
            self::assertSame($function->runtime_role, $function->owner);
            self::assertTrue($function->runtime_granted);

            foreach (['null' => null, 'zero' => '0.0000', 'negative' => '-1.0000'] as $case => $basePrice) {
                $invalidCatalog = $this->catalogPrice($fixture, $fixture['resource_code'], $basePrice, 'invalid-'.$case);
                $this->assertFinalizeRejected(
                    $fixture,
                    'invalid-base-'.$case,
                    ['regional_price_version_id' => $invalidCatalog['version_id']],
                    true,
                    ['resource_price_id' => $invalidCatalog['price_id']],
                );
            }

            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            foreach (['price_snapshot', 'total_cost', 'quantity', 'region_id', 'quantity_evidence_id'] as $column) {
                $this->assertRejected(fn () => DB::table('estimate_generation_package_items')
                    ->where('id', $fixture['item_id'])->update([$column => $this->mutatedValue($column, $snapshot)]));
            }
            $this->assertRejected(fn () => DB::table('estimate_generation_package_items')->where('id', $fixture['item_id'])->delete());
            $this->assertRejected(fn () => DB::table('estimate_generation_package_item_price_inputs')
                ->where('package_item_id', $fixture['item_id'])->update(['ordinal' => 2]));
            $this->assertRejected(fn () => DB::table('estimate_generation_package_item_price_inputs')
                ->where('package_item_id', $fixture['item_id'])->delete());
            $this->assertRejected(fn () => DB::table('estimate_resource_prices')->where('id', $fixture['price_id'])->update(['base_price' => 999]));
            $this->assertRejected(fn () => DB::table('estimate_resource_prices')->where('id', $fixture['price_id'])->delete());
            $this->assertRejected(fn () => DB::table('estimate_resource_prices')->insert([
                'dataset_version_id' => $fixture['dataset_id'], 'regional_price_version_id' => $fixture['version_id'],
                'region_id' => $fixture['region_id'], 'price_zone_id' => $fixture['zone_id'], 'period_id' => $fixture['period_id'],
                'resource_code' => $fixture['resource_code'].'-late', 'resource_name' => 'Late mutation', 'unit' => 'h',
                'base_price' => '1.0000', 'price_type' => 'labor', 'raw_payload' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->assertRejected(fn () => DB::table('estimate_generation_unit_conversions')->where('id', $fixture['conversion_id'])->update(['factor' => 2]));
            $this->assertRejected(fn () => DB::table('estimate_generation_unit_conversions')->where('id', $fixture['conversion_id'])->delete());
            $this->assertRejected(fn () => DB::table('estimate_generation_evidence')->where('id', $fixture['evidence_id'])
                ->update(['value' => json_encode(['work_code' => 'work_type:1', 'quantity' => 9, 'unit' => 'h'], JSON_THROW_ON_ERROR)]));

            $this->assertFinalizeRejected($fixture, 'missing-input', [], false);
            $this->assertFinalizeRejected($fixture, 'missing-conversion', [], true, ['unit_conversion_id' => null]);
            $this->assertFinalizeRejected($fixture, 'cross-context', ['region_id' => $fixture['region_id'] + 1000000]);
            $this->assertFinalizeRejected($fixture, 'wrong-fingerprint', ['quantity_evidence_fingerprint' => str_repeat('f', 64)]);
            $this->assertFinalizeRejected($fixture, 'foreign-evidence', ['quantity_evidence_id' => 999999999]);

            $this->assertNormResourceMatrixRejected($fixture);

            $historical = DB::table('estimate_generation_package_items')->find($fixture['item_id']);
            self::assertSame('75.00', $historical->total_cost);
            self::assertSame($item->price_snapshot, $historical->price_snapshot);

            $revisionId = $this->unpricedItem($fixture, 'item-1', 2, $fixture['item_id']);
            self::assertSame($fixture['item_id'], (int) DB::table('estimate_generation_package_items')->find($revisionId)->supersedes_item_id);
            $this->assertRejected(fn () => DB::table('estimate_generation_package_items')->insert($this->itemPayload($fixture, 'item-1', 1, null)));
            $this->assertExactLargeDecimalPackageTotal($fixture);

            $newVersion = DB::table('estimate_regional_price_versions')->insertGetId([
                'source' => 'fgiscs', 'region_id' => $fixture['region_id'], 'price_zone_id' => $fixture['zone_id'],
                'period_id' => $fixture['period_id'], 'version_key' => 'contract-new', 'status' => 'draft',
            ]);
            self::assertNotSame($fixture['version_id'], $newVersion);
            self::assertSame('75.00', DB::table('estimate_generation_package_items')->find($fixture['item_id'])->total_cost);
        } finally {
            DB::rollBack();
        }
    }

    public function test_database_finalizes_typed_supplementary_project_material_with_v4_provenance(): void
    {
        $this->requireDisposablePostgres();

        DB::beginTransaction();
        try {
            $fixture = $this->fixture();
            $rule = DB::table('estimate_generation_project_material_rules')
                ->where('catalog_version', 'residential_project_material:v3')
                ->where('work_item_key', 'lighting.fixtures')
                ->sole();
            $priceId = DB::table('estimate_resource_prices')->insertGetId([
                'dataset_version_id' => $fixture['dataset_id'],
                'regional_price_version_id' => null,
                'region_id' => null,
                'price_zone_id' => null,
                'period_id' => null,
                'resource_code' => '59.1.20.03-0798',
                'resource_name' => 'Светильник светодиодный потолочный',
                'unit' => 'шт',
                'base_price' => '100.0000',
                'price_type' => 'material',
                'raw_payload' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('estimate_generation_package_items')->where('id', $fixture['item_id'])->update([
                'metadata' => json_encode(['specialization_scenario' => [
                    'work_item_key' => 'lighting.fixtures',
                    'assumption_code' => 'residential_led_ceiling_luminaire_18w',
                ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
            DB::table('estimate_generation_package_item_project_price_inputs')->insert([
                'package_item_id' => $fixture['item_id'],
                'project_material_rule_id' => $rule->id,
                'resource_price_id' => $priceId,
                'ordinal' => 1,
                'selection' => json_encode([
                    'version' => 'residential_project_material:v3',
                    'work_item_key' => 'lighting.fixtures',
                    'assumption_code' => 'residential_led_ceiling_luminaire_18w',
                    'preferred_resource_code' => '59.1.20.03-0798',
                    'candidate_pool_version' => 'project_material_candidate_pool:v2',
                    'candidate_resource_price_ids' => [$priceId],
                    'selection_policy' => 'exact_code',
                    'source_unit_price' => '100.0000',
                    'source_price_unit' => 'шт',
                    'price_conversion_factor' => '1',
                    'resource_code' => '59.1.20.03-0798',
                    'resource_name' => 'Светильник светодиодный потолочный',
                    'price_unit' => 'pcs',
                    'price_source' => 'fsnb_base',
                    'price_source_version' => $fixture['dataset_version_key'],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::select('SELECT eg_finalize_package_item_price(?)', [$fixture['item_id']]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

            $item = DB::table('estimate_generation_package_items')->find($fixture['item_id']);
            $snapshot = json_decode((string) $item->price_snapshot, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('325.00', $item->total_cost);
            self::assertSame('supplementary_project_material:v4', $snapshot['coefficients']['pricing_formula_version']);
            self::assertSame('250.00', $snapshot['coefficients']['project_material_amount']);
            self::assertSame($priceId, $snapshot['coefficients']['project_material_evidence'][0]['resource_price_id']);
            self::assertSame('59.1.20.03-0798', $snapshot['coefficients']['project_material_evidence'][0]['resource_code']);
        } finally {
            DB::rollBack();
        }
    }

    public function test_database_semantic_project_material_price_matches_cross_dataset_median_and_regional_priority(): void
    {
        $this->requireDisposablePostgres();

        DB::beginTransaction();
        try {
            $fixture = $this->fixture();
            $ruleId = (int) DB::table('estimate_generation_project_material_rules')
                ->where('catalog_version', 'residential_project_material:v3')
                ->where('work_item_key', 'lighting.fixtures')
                ->value('id');
            $fsbcVersion = strtolower((string) str()->ulid());
            $fsbcDatasetId = DB::table('estimate_dataset_versions')->insertGetId([
                'source_type' => 'fsbc', 'version_key' => $fsbcVersion, 'bucket' => 'contract',
                'prefix' => $fsbcVersion, 'status' => 'parsed', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $baseCandidates = [];
            foreach ([
                [$fsbcDatasetId, $fsbcVersion, '59.1.20.03-1001', '10.0000'],
                [$fsbcDatasetId, $fsbcVersion, '59.1.20.03-1002', '20.0000'],
                [$fixture['dataset_id'], $fixture['dataset_version_key'], '59.1.20.03-1003', '30.0000'],
                [$fixture['dataset_id'], $fixture['dataset_version_key'], '59.1.20.03-1004', '40.0000'],
            ] as [$datasetId, $version, $code, $price]) {
                $baseCandidates[$code] = [
                    'id' => DB::table('estimate_resource_prices')->insertGetId([
                        'dataset_version_id' => $datasetId, 'regional_price_version_id' => null,
                        'region_id' => null, 'price_zone_id' => null, 'period_id' => null,
                        'resource_code' => $code, 'resource_name' => 'Светильник потолочный', 'unit' => 'шт',
                        'base_price' => $price, 'price_type' => 'material', 'raw_payload' => '{}',
                        'created_at' => now(), 'updated_at' => now(),
                    ]),
                    'version' => $version,
                    'price' => $price,
                ];
            }

            $finalize = function (string $logicalKey, int $priceId, array $selection) use ($fixture, $ruleId): object {
                $itemId = $this->unpricedItem($fixture, $logicalKey, 1, null);
                DB::table('estimate_generation_package_items')->where('id', $itemId)->update([
                    'metadata' => json_encode(['specialization_scenario' => [
                        'work_item_key' => 'lighting.fixtures',
                        'assumption_code' => 'residential_led_ceiling_luminaire_18w',
                    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]);
                DB::table('estimate_generation_package_item_price_inputs')->insert([
                    'package_item_id' => $itemId, 'norm_resource_id' => $fixture['norm_resource_id'],
                    'resource_price_id' => $fixture['price_id'], 'unit_conversion_id' => $fixture['conversion_id'],
                    'ordinal' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('estimate_generation_package_item_project_price_inputs')->insert([
                    'package_item_id' => $itemId, 'project_material_rule_id' => $ruleId,
                    'resource_price_id' => $priceId, 'ordinal' => 1,
                    'selection' => json_encode($selection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::select('SELECT eg_finalize_package_item_price(?)', [$itemId]);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                DB::statement('SET CONSTRAINTS ALL DEFERRED');

                return DB::table('estimate_generation_package_items')->find($itemId);
            };
            $selection = static fn (string $code, string $price, string $source, string $version, array $candidatePriceIds) => [
                'version' => 'residential_project_material:v3',
                'work_item_key' => 'lighting.fixtures',
                'assumption_code' => 'residential_led_ceiling_luminaire_18w',
                'preferred_resource_code' => '59.1.20.03-0798',
                'candidate_pool_version' => 'project_material_candidate_pool:v2',
                'candidate_resource_price_ids' => $candidatePriceIds,
                'selection_policy' => 'semantic_group_median',
                'source_unit_price' => $price,
                'source_price_unit' => 'шт',
                'price_conversion_factor' => '1',
                'resource_code' => $code,
                'resource_name' => 'Светильник потолочный',
                'price_unit' => 'pcs',
                'price_source' => $source,
                'price_source_version' => $version,
            ];

            $base = $baseCandidates['59.1.20.03-1002'];
            $baseCandidateIds = array_column($baseCandidates, 'id');
            sort($baseCandidateIds, SORT_NUMERIC);
            $baseItem = $finalize('semantic-base-median', $base['id'], $selection(
                '59.1.20.03-1002', $base['price'], 'fsbc_base', $base['version'], $baseCandidateIds,
            ));
            self::assertSame('125.00', $baseItem->total_cost);
            $this->assertRejected(fn () => DB::table('estimate_resource_prices')
                ->where('id', $baseCandidates['59.1.20.03-1001']['id'])
                ->update(['base_price' => '11.0000']));
            $this->assertRejected(fn () => DB::table('estimate_dataset_versions')
                ->where('id', $fsbcDatasetId)
                ->update(['status' => 'failed']));

            $regionalCandidates = [];
            foreach ([['59.1.20.03-2001', '50.0000'], ['59.1.20.03-2002', '60.0000'], ['59.1.20.03-2003', '70.0000']] as [$code, $price]) {
                $regionalCandidates[$code] = DB::table('estimate_resource_prices')->insertGetId([
                    'dataset_version_id' => $fixture['price_dataset_id'],
                    'regional_price_version_id' => $fixture['version_id'],
                    'region_id' => $fixture['region_id'], 'price_zone_id' => $fixture['zone_id'],
                    'period_id' => $fixture['period_id'], 'resource_code' => $code,
                    'resource_name' => 'Светильник потолочный', 'unit' => 'шт', 'base_price' => $price,
                    'price_type' => 'material', 'raw_payload' => '{}', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $regionalCandidateIds = array_values($regionalCandidates);
            sort($regionalCandidateIds, SORT_NUMERIC);
            $regionalItem = $finalize('semantic-regional-median', $regionalCandidates['59.1.20.03-2002'], $selection(
                '59.1.20.03-2002',
                '60.0000',
                'regional_catalog',
                (string) DB::table('estimate_regional_price_versions')->where('id', $fixture['version_id'])->value('version_key'),
                $regionalCandidateIds,
            ));
            $regionalSnapshot = json_decode((string) $regionalItem->price_snapshot, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('225.00', $regionalItem->total_cost);
            self::assertSame('regional_catalog', $regionalSnapshot['coefficients']['project_material_evidence'][0]['price_source']);
        } finally {
            DB::rollBack();
        }
    }

    private function serviceDraft(array $f, string $quantity): array
    {
        return ['local_estimates' => [[
            'key' => 'service-package', 'title' => 'Service contract', 'target_items_min' => 1,
            'sections' => [['work_items' => [[
                'key' => 'item-1', 'item_type' => 'priced_work', 'name' => 'Трудозатраты рабочих', 'unit' => 'h', 'quantity' => $quantity,
                'quantity_evidence_id' => $f['evidence_id'], 'quantity_evidence_fingerprint' => $f['fingerprint'],
                'normative_match' => ['status' => 'matched', 'norm_id' => $f['norm_id']],
                'labor' => [[
                    'normative_ref' => ['norm_resource_id' => $f['norm_resource_id'], 'price_id' => $f['price_id'], 'unit_conversion_id' => $f['conversion_id']],
                ]],
                'price_snapshot' => ['region_id' => $f['region_id'], 'zone_id' => $f['zone_id'], 'period_id' => $f['period_id'], 'version_id' => $f['version_id'], 'final_amount' => '999.00'],
                'pricing_status' => 'calculated', 'total_cost' => '999.00', 'labor_cost' => '999.00', 'validation_flags' => [],
            ]]]],
        ]]];
    }

    private function fixture(): array
    {
        $now = now();
        $organizationId = DB::table('organizations')->insertGetId(['name' => 'Contract', 'created_at' => $now, 'updated_at' => $now]);
        $userId = DB::table('users')->insertGetId(['name' => 'Contract', 'email' => uniqid('contract-', true).'@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
        $projectId = DB::table('projects')->insertGetId(['organization_id' => $organizationId, 'name' => 'Contract', 'created_at' => $now, 'updated_at' => $now]);
        $sessionId = DB::table('estimate_generation_sessions')->insertGetId([
            'organization_id' => $organizationId, 'project_id' => $projectId, 'user_id' => $userId,
            'status' => 'generating', 'processing_stage' => 'resolve_prices', 'input_payload' => '{}', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $packageId = DB::table('estimate_generation_packages')->insertGetId([
            'session_id' => $sessionId, 'key' => uniqid('contract-', true), 'title' => 'Contract', 'scope_type' => 'custom', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $suffix = strtolower((string) str()->ulid());
        $datasetId = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fsnb_2022', 'version_key' => $suffix, 'bucket' => 'contract', 'prefix' => $suffix, 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $priceDatasetId = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fgis_labor_prices', 'version_key' => $suffix, 'bucket' => 'contract', 'prefix' => $suffix.'-prices', 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $collectionId = DB::table('estimate_norm_collections')->insertGetId(['dataset_version_id' => $datasetId, 'code' => $suffix, 'name' => 'Contract', 'norm_type' => 'gesn', 'source_file' => 'contract', 'created_at' => $now, 'updated_at' => $now]);
        $normId = DB::table('estimate_norms')->insertGetId(['collection_id' => $collectionId, 'code' => '01-01-001-01', 'name' => 'Трудозатраты рабочих', 'unit' => 'м2', 'created_at' => $now, 'updated_at' => $now]);
        $normResourceId = DB::table('estimate_norm_resources')->insertGetId(['estimate_norm_id' => $normId, 'resource_code' => $suffix, 'resource_name' => 'Resource', 'unit' => 'min', 'quantity' => '3.000000', 'resource_type' => 'labor', 'created_at' => $now, 'updated_at' => $now]);
        $regionId = DB::table('estimate_regions')->insertGetId(['code' => 'PC-'.$suffix, 'name' => 'Contract', 'fgiscs_subject_id' => random_int(100000, 999999), 'created_at' => $now, 'updated_at' => $now]);
        $zoneId = DB::table('estimate_price_zones')->insertGetId(['estimate_region_id' => $regionId, 'name' => 'Contract', 'fgiscs_price_zone_id' => random_int(1000000, 1999999), 'created_at' => $now, 'updated_at' => $now]);
        $periodId = (int) (DB::table('estimate_price_periods')->where('year', 2099)->where('quarter', 4)->value('id')
            ?? DB::table('estimate_price_periods')->insertGetId(['fgiscs_period_id' => random_int(2000000, 2999999), 'name' => $suffix, 'year' => 2099, 'quarter' => 4, 'created_at' => $now, 'updated_at' => $now]));
        $versionId = DB::table('estimate_regional_price_versions')->insertGetId(['source' => 'fgiscs', 'region_id' => $regionId, 'price_zone_id' => $zoneId, 'period_id' => $periodId, 'version_key' => $suffix, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        $priceId = DB::table('estimate_resource_prices')->insertGetId(['dataset_version_id' => $priceDatasetId, 'regional_price_version_id' => $versionId, 'region_id' => $regionId, 'price_zone_id' => $zoneId, 'period_id' => $periodId, 'resource_code' => $suffix, 'resource_name' => 'Resource', 'unit' => 'h', 'base_price' => '600.0000', 'price_type' => 'labor', 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        $manualNormId = DB::table('estimate_norms')->insertGetId(['collection_id' => $collectionId, 'code' => '01-01-002-01', 'name' => 'Трудозатраты рабочих', 'unit' => 'м2', 'created_at' => $now, 'updated_at' => $now]);
        $manualResourceCode = $suffix.'-manual';
        DB::table('estimate_norm_resources')->insert(['estimate_norm_id' => $manualNormId, 'resource_code' => $manualResourceCode, 'resource_name' => 'Manual resource', 'unit' => 'h', 'quantity' => '1.000000', 'resource_type' => 'labor', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('estimate_resource_prices')->insert(['dataset_version_id' => $priceDatasetId, 'regional_price_version_id' => $versionId, 'region_id' => $regionId, 'price_zone_id' => $zoneId, 'period_id' => $periodId, 'resource_code' => $manualResourceCode, 'resource_name' => 'Manual resource', 'unit' => 'h', 'base_price' => '30.0000', 'price_type' => 'labor', 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('estimate_regional_price_versions')->where('id', $versionId)->update(['status' => 'active']);
        $conversionId = (int) (DB::table('estimate_generation_unit_conversions')->where('from_unit', 'min')->where('to_unit', 'h')->where('version', 1)->value('id')
            ?? DB::table('estimate_generation_unit_conversions')->insertGetId(['from_unit' => 'min', 'to_unit' => 'h', 'factor' => '0.016666666667', 'version' => 1, 'fingerprint' => str_repeat('c', 64), 'created_at' => $now, 'updated_at' => $now]));
        $fingerprint = hash('sha256', $suffix);
        $evidenceId = DB::table('estimate_generation_evidence')->insertGetId(['organization_id' => $organizationId, 'project_id' => $projectId, 'session_id' => $sessionId, 'type' => 'work_item', 'source_type' => 'user_input', 'source_ref' => 'input:1', 'source_version' => 'contract:abcdef', 'locator' => json_encode(['item_key' => 'item:'.hash('sha256', 'item-1')], JSON_THROW_ON_ERROR), 'value' => json_encode(['work_code' => 'work_type:1', 'quantity' => '2.5', 'unit' => 'h'], JSON_THROW_ON_ERROR), 'confidence' => 1, 'producer_name' => 'contract', 'producer_version' => 'contract:abcdef', 'fingerprint' => $fingerprint, 'created_at' => $now, 'updated_at' => $now]);
        $resourceCode = $suffix;
        $datasetVersionKey = $suffix;
        $fixture = compact('sessionId', 'packageId', 'datasetId', 'priceDatasetId', 'datasetVersionKey', 'collectionId', 'resourceCode', 'normId', 'manualNormId', 'normResourceId', 'regionId', 'zoneId', 'periodId', 'versionId', 'priceId', 'conversionId', 'evidenceId', 'fingerprint');
        $fixture = array_combine(array_map(fn (string $key): string => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key)), array_keys($fixture)), array_values($fixture));
        $fixture['item_id'] = $this->unpricedItem($fixture, 'item-1', 1, null);
        DB::table('estimate_generation_package_item_price_inputs')->insert(['package_item_id' => $fixture['item_id'], 'norm_resource_id' => $normResourceId, 'resource_price_id' => $priceId, 'unit_conversion_id' => $conversionId, 'ordinal' => 1, 'created_at' => $now, 'updated_at' => $now]);

        return $fixture;
    }

    private function unpricedItem(array $fixture, string $logicalKey, int $revision, ?int $supersedes): int
    {
        return DB::table('estimate_generation_package_items')->insertGetId($this->itemPayload($fixture, $logicalKey, $revision, $supersedes));
    }

    private function itemPayload(array $f, string $key, int $revision, ?int $supersedes): array
    {
        return ['package_id' => $f['package_id'], 'key' => $key.'#r'.$revision, 'logical_key' => $key, 'revision' => $revision, 'supersedes_item_id' => $supersedes, 'name' => 'Трудозатраты рабочих', 'item_type' => 'priced_work', 'quantity_evidence_id' => $f['evidence_id'], 'quantity_evidence_fingerprint' => $f['fingerprint'], 'estimate_norm_id' => $f['norm_id'], 'region_id' => $f['region_id'], 'price_zone_id' => $f['zone_id'], 'period_id' => $f['period_id'], 'regional_price_version_id' => $f['version_id'], 'unit_price' => 999, 'direct_cost' => 999, 'overhead_cost' => 999, 'profit_cost' => 999, 'total_cost' => 999, 'created_at' => now(), 'updated_at' => now()];
    }

    private function mutatedValue(string $column, array $snapshot): mixed
    {
        return match ($column) {
            'price_snapshot' => json_encode(array_replace($snapshot, ['final_amount' => '1.00']), JSON_THROW_ON_ERROR), 'total_cost' => 1, 'quantity' => 9, 'region_id', 'quantity_evidence_id' => 999999999
        };
    }

    private function assertFinalizeRejected(
        array $fixture,
        string $logicalKey,
        array $itemOverrides = [],
        bool $withInput = true,
        array $inputOverrides = [],
    ): void {
        $this->assertRejected(function () use ($fixture, $logicalKey, $itemOverrides, $withInput, $inputOverrides): void {
            $itemId = DB::table('estimate_generation_package_items')->insertGetId(array_replace(
                $this->itemPayload($fixture, $logicalKey, 1, null),
                $itemOverrides,
            ));
            if ($withInput) {
                DB::table('estimate_generation_package_item_price_inputs')->insert(array_replace([
                    'package_item_id' => $itemId,
                    'norm_resource_id' => $fixture['norm_resource_id'],
                    'resource_price_id' => $fixture['price_id'],
                    'unit_conversion_id' => $fixture['conversion_id'],
                    'ordinal' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $inputOverrides));
            }
            DB::select('SELECT eg_finalize_package_item_price(?)', [$itemId]);
        });
    }

    private function catalogPrice(array $fixture, string $resourceCode, ?string $basePrice, string $key): array
    {
        $now = now();
        $suffix = strtolower((string) str()->ulid()).'-'.$key;
        $datasetId = DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => 'fgis_labor_prices', 'version_key' => $suffix, 'bucket' => 'contract',
            'prefix' => $suffix, 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $versionId = DB::table('estimate_regional_price_versions')->insertGetId([
            'source' => 'fgiscs', 'region_id' => $fixture['region_id'], 'price_zone_id' => $fixture['zone_id'],
            'period_id' => $fixture['period_id'], 'version_key' => $suffix, 'status' => 'draft',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $priceId = DB::table('estimate_resource_prices')->insertGetId([
            'dataset_version_id' => $datasetId, 'regional_price_version_id' => $versionId,
            'region_id' => $fixture['region_id'], 'price_zone_id' => $fixture['zone_id'], 'period_id' => $fixture['period_id'],
            'resource_code' => $resourceCode, 'resource_name' => 'Resource', 'unit' => 'h',
            'base_price' => $basePrice, 'price_type' => 'labor', 'raw_payload' => '{}',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('estimate_regional_price_versions')->where('id', $versionId)->update(['status' => 'active']);

        return ['version_id' => $versionId, 'price_id' => $priceId];
    }

    private function assertNormResourceMatrixRejected(array $fixture): void
    {
        $now = now();
        $secondCode = $fixture['resource_code'].'-second';
        $this->assertRejected(fn () => DB::table('estimate_norm_resources')->insert([
            'estimate_norm_id' => $fixture['norm_id'], 'resource_code' => $secondCode, 'resource_name' => 'Second',
            'unit' => 'h', 'quantity' => '1.000000', 'resource_type' => 'labor', 'created_at' => $now, 'updated_at' => $now,
        ]));

    }

    private function assertFinalizeRejectedWithInputs(array $fixture, string $logicalKey, array $inputs, array $itemOverrides): void
    {
        $this->assertRejected(function () use ($fixture, $logicalKey, $inputs, $itemOverrides): void {
            $itemId = DB::table('estimate_generation_package_items')->insertGetId(array_replace(
                $this->itemPayload($fixture, $logicalKey, 1, null),
                $itemOverrides,
            ));
            foreach ($inputs as $ordinal => $input) {
                DB::table('estimate_generation_package_item_price_inputs')->insert(array_replace($input, [
                    'package_item_id' => $itemId, 'ordinal' => $ordinal + 1, 'created_at' => now(), 'updated_at' => now(),
                ]));
            }
            DB::select('SELECT eg_finalize_package_item_price(?)', [$itemId]);
        });
    }

    private function assertExactLargeDecimalPackageTotal(array $fixture): void
    {
        $now = now();
        $quantity = '12345.678901';
        $basePrice = '123456789.1234';
        $code = strtolower((string) str()->ulid());
        $normId = DB::table('estimate_norms')->insertGetId([
            'collection_id' => $fixture['collection_id'], 'code' => $code, 'name' => 'Decimal norm',
            'unit' => 'h', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $normResourceId = DB::table('estimate_norm_resources')->insertGetId([
            'estimate_norm_id' => $normId, 'resource_code' => $code, 'resource_name' => 'Decimal resource',
            'unit' => 'h', 'quantity' => '1.000000', 'resource_type' => 'labor', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $catalog = $this->catalogPrice($fixture, $code, $basePrice, 'large-decimal');
        $fingerprint = hash('sha256', 'decimal-'.$code);
        $evidenceId = DB::table('estimate_generation_evidence')->insertGetId([
            'organization_id' => DB::table('estimate_generation_evidence')->where('id', $fixture['evidence_id'])->value('organization_id'),
            'project_id' => DB::table('estimate_generation_evidence')->where('id', $fixture['evidence_id'])->value('project_id'),
            'session_id' => DB::table('estimate_generation_evidence')->where('id', $fixture['evidence_id'])->value('session_id'),
            'type' => 'work_item', 'source_type' => 'user_input', 'source_ref' => 'input:2',
            'source_version' => 'contract:abcdef', 'locator' => json_encode(['item_key' => 'item:'.hash('sha256', 'large-decimal')], JSON_THROW_ON_ERROR),
            'value' => json_encode(['work_code' => 'work_type:2', 'quantity' => $quantity, 'unit' => 'h'], JSON_THROW_ON_ERROR),
            'confidence' => 1, 'producer_name' => 'contract', 'producer_version' => 'contract:abcdef',
            'fingerprint' => $fingerprint, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $firstId = 0;
        foreach ([1, 2] as $revision) {
            $itemId = DB::table('estimate_generation_package_items')->insertGetId(array_replace(
                $this->itemPayload($fixture, 'large-decimal', $revision, $revision === 1 ? null : $firstId),
                ['estimate_norm_id' => $normId, 'quantity_evidence_id' => $evidenceId,
                    'quantity_evidence_fingerprint' => $fingerprint, 'regional_price_version_id' => $catalog['version_id']],
            ));
            DB::table('estimate_generation_package_item_price_inputs')->insert([
                'package_item_id' => $itemId, 'norm_resource_id' => $normResourceId,
                'resource_price_id' => $catalog['price_id'], 'unit_conversion_id' => null, 'ordinal' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::select('SELECT eg_finalize_package_item_price(?)', [$itemId]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            $firstId = $revision === 1 ? $itemId : $firstId;
        }
        $expected = (string) BigDecimal::of($quantity)->multipliedBy($basePrice)->toScale(2, RoundingMode::HalfUp);
        $latest = DB::table('estimate_generation_package_items')->where('logical_key', 'large-decimal')
            ->orderByDesc('revision')->first();
        self::assertSame($expected, $latest->total_cost);
        $packageTotal = DB::selectOne(<<<'SQL'
SELECT to_char(sum(total_cost), 'FM999999999999999990.00') AS total
FROM (
  SELECT DISTINCT ON (logical_key) logical_key, total_cost
  FROM estimate_generation_package_items
  WHERE package_id = ? AND price_snapshot IS NOT NULL
  ORDER BY logical_key, revision DESC
) latest
SQL, [$fixture['package_id']]);
        self::assertSame((string) BigDecimal::of($expected)->plus('75.00')->toScale(2), $packageTotal->total);
    }

    private function assertRejected(callable $write): void
    {
        DB::statement('SAVEPOINT price_snapshot_contract');
        try {
            $write();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            self::fail('PostgreSQL accepted an invalid pricing mutation.');
        } catch (QueryException) {
            self::addToAssertionCount(1);
        } finally {
            DB::statement('ROLLBACK TO SAVEPOINT price_snapshot_contract');
            DB::statement('RELEASE SAVEPOINT price_snapshot_contract');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
    }

    private function requireDisposablePostgres(): void
    {
        $database = (string) DB::connection()->getDatabaseName();
        if (getenv('RUN_POSTGRES_PRICE_SNAPSHOT_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql' || ! str_ends_with($database, '_contract')) {
            self::markTestSkipped('Requires explicit disposable PostgreSQL price snapshot contract database.');
        }
    }
}
