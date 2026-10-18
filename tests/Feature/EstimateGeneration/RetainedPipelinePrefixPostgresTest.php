<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryEstimateGenerationSessionCommand;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPipelineCheckpoint;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentPipelineOutputRepository;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EvidenceAwarePipelineBaseInputVersionResolver;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineCheckpointStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineInputVersion;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineOutputRepository;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelinePlanResolver;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\RetainedPipelinePrefix;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\StageResultFactory;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationRegionalContextResolver;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsData;
use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Credits\AICreditService;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class RetainedPipelinePrefixPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_new_approved_operation_rehydrates_completed_prefix_without_models_and_keeps_audit_pending(): void
    {
        $version = $this->regionalVersion();
        $regional = app(EstimateGenerationRegionalContextResolver::class)->resolve(['estimate_regional_price_version_id' => $version]);
        [$actor, $session, $sourceId] = $this->fixture(regional: $regional);
        $resolver = \Mockery::mock(EstimateGenerationRegionalContextResolver::class)->makePartial();
        $resolver->shouldReceive('retryChanges')->andReturn(
            ['regional_context' => $regional], ['regional_context' => $regional],
            ['regional_context' => [...$regional, 'estimate_regional_price_version_id' => $version + 1000000]]);
        $this->app->instance(EstimateGenerationRegionalContextResolver::class, $resolver);
        $sourceBefore = DB::table('estimate_generation_credit_operations')->where('operation_id', $sourceId)->sole();
        [$operationId, $quote] = $this->quote($actor, $session);
        self::assertSame($sourceId, $quote['continuation']['source_operation_id']);
        self::assertSame('validate_draft', $quote['continuation']['resume_stage']);
        $plan = $this->plan($operationId);
        self::assertSame(['estimate_audit', 'estimate_composer_correction'], array_keys($plan['roles']));
        self::assertLessThan($sourceBefore->maximum_calls, $plan['maximum_calls']);
        self::assertLessThan($sourceBefore->maximum_provider_cost_micro_rub, $plan['maximum_provider_cost_micro_rub']);
        self::assertSame($sourceId, $plan['retained_pipeline_prefix']['source_attempt_id']);
        self::assertCount(8, $plan['retained_pipeline_prefix']['stages']);
        Bus::fake([GenerateEstimateDraftJob::class]);
        $session = app(RetryEstimateGenerationSession::class)->handle(new RetryEstimateGenerationSessionCommand(
            (int) $session->id, (int) $session->organization_id, (int) $session->project_id, 4, $actor,
            ['request_id' => $operationId, 'quote_id' => $quote['quote_id']]));
        self::assertSame(5, (int) $session->state_version);
        self::assertSame(CanonicalPipelineJson::encode($regional), CanonicalPipelineJson::encode($session->input_payload['regional_context']));
        Bus::assertDispatched(GenerateEstimateDraftJob::class);
        $base = app(EvidenceAwarePipelineBaseInputVersionResolver::class)->fromSession($session);
        $seed = new PipelineContext((int) $session->id, (int) $session->organization_id, (int) $session->project_id,
            5, $base, 'generating', generationAttemptId: $operationId, baseInputVersion: $base, creditOperationId: $operationId);
        $outputs = app(PipelineOutputRepository::class);
        $resolver = app(PipelinePlanResolver::class);
        $reuse = app(RetainedPipelinePrefix::class);
        $store = app(PipelineCheckpointStore::class);
        $clock = new DateTimeImmutable;
        foreach (PipelineDefinitionGraph::standard()->ordered() as $definition) {
            $context = $resolver->next($seed);
            self::assertSame($definition->stage, $context->stage);
            if ($definition->stage === ProcessingStage::ValidateDraft) {
                self::assertNull($reuse->reuse($context));
                break;
            }
            $claim = $store->claim($context, $context->stage, $clock, $clock->modify('+5 minutes'));
            $result = $reuse->reuse($context->withClaim($claim, $clock->modify('+5 minutes')));
            self::assertNotNull($result);
            self::assertTrue($result->metrics['retained_prefix_reused']);
            self::assertTrue($store->complete($claim, $result, $clock));
            self::assertSame($plan['retained_pipeline_prefix']['stages'][$context->stage->value]['content_version'], $result->output->artifact->contentVersion);
            self::assertStringContainsString($operationId, $result->output->artifact->objectKey);
            self::assertStringNotContainsString($sourceId, $result->output->artifact->objectKey);
        }
        self::assertSame('40', $outputs->priorOutputs($seed)->payload(ProcessingStage::BuildDraft)['draft']['total']);
        self::assertCount(8, $outputs->priorOutputs($seed)->all());
        self::assertSame((array) $sourceBefore, (array) DB::table('estimate_generation_credit_operations')->where('operation_id', $sourceId)->sole());
        self::assertSame([], $session->fresh()->draft_payload ?? []);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        Http::assertNothingSent();
    }

    #[DataProvider('invalidSources')]
    public function test_stale_or_unsettled_source_is_not_offered_for_reuse(string $change): void
    {
        [$actor, $session] = $this->fixture($change);
        if ($change === 'invalidated') {
            $this->invalidate($session, ProcessingStage::PlanWorkItems);
        } elseif ($change === 'input') {
            $session->update(['input_payload' => [...$session->input_payload, 'description' => 'Изменённый дом']]);
        }
        [$id, $quote] = $this->quote($actor, $session);
        self::assertArrayNotHasKey('retained_pipeline_prefix', $this->plan($id));
        if ($change === 'actor') {
            Bus::fake([GenerateEstimateDraftJob::class]);
            $session = app(RetryEstimateGenerationSession::class)->handle(new RetryEstimateGenerationSessionCommand(
                (int) $session->id, (int) $session->organization_id, (int) $session->project_id, 4, $actor,
                ['request_id' => $id, 'quote_id' => $quote['quote_id']]));
            self::assertSame(5, (int) $session->state_version);
            self::assertSame((int) $actor->id, $session->input_payload['generation_actor_id']);
            $base = app(EvidenceAwarePipelineBaseInputVersionResolver::class)->fromSession($session);
            $seed = new PipelineContext((int) $session->id, (int) $session->organization_id, (int) $session->project_id,
                5, $base, 'generating', generationAttemptId: $id, baseInputVersion: $base, creditOperationId: $id);
            self::assertNull(app(RetainedPipelinePrefix::class)->reuse(app(PipelinePlanResolver::class)->next($seed)));
        }
        Http::assertNothingSent();
    }

    public static function invalidSources(): array
    {
        return array_map(static fn (string $value): array => [$value], ['invalidated', 'missing', 'base', 'active', 'scope', 'input', 'actor']);
    }

    public function test_changed_prefix_is_rejected_before_credit_reservation(): void
    {
        [$actor, $session] = $this->fixture();
        [$id, $quote] = $this->quote($actor, $session);
        self::assertArrayHasKey('retained_pipeline_prefix', $this->plan($id));
        $this->invalidate($session, ProcessingStage::BuildDraft);
        try {
            DB::transaction(fn () => app(EstimatorCreditOperations::class)->begin($actor, $session, 4, $id, $quote['quote_id'], 'generation'));
            self::fail('Changed prefix must not be reserved.');
        } catch (DomainException $error) {
            self::assertSame('estimator_retained_pipeline_prefix_changed', $error->getMessage());
        }
        self::assertNull(DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('reservation_id'));
        Http::assertNothingSent();
    }

    public function test_new_regional_version_declines_continuation_before_a_quote_is_reserved(): void
    {
        $version = $this->regionalVersion();
        $regional = app(EstimateGenerationRegionalContextResolver::class)->resolve(['estimate_regional_price_version_id' => $version]);
        [$actor, $session] = $this->fixture(regional: $regional);
        $this->regionalVersion($version);
        [$id, $quote] = $this->quote($actor, $session);

        self::assertArrayNotHasKey('continuation', $quote);
        self::assertArrayNotHasKey('retained_pipeline_prefix', $this->plan($id));
        self::assertArrayHasKey('estimate_composition', $this->plan($id)['roles']);
        self::assertNull(DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('reservation_id'));
        Http::assertNothingSent();
    }

    public function test_regional_version_change_after_a_quote_is_rejected_before_reservation(): void
    {
        $version = $this->regionalVersion();
        $regional = app(EstimateGenerationRegionalContextResolver::class)->resolve(['estimate_regional_price_version_id' => $version]);
        [$actor, $session] = $this->fixture(regional: $regional);
        [$id, $quote] = $this->quote($actor, $session);
        self::assertArrayHasKey('continuation', $quote);
        $this->regionalVersion($version);

        try {
            DB::transaction(fn () => app(EstimatorCreditOperations::class)->begin($actor, $session, 4, $id, $quote['quote_id'], 'generation'));
            self::fail('Regional source changed after the quote.');
        } catch (DomainException $error) {
            self::assertSame('estimator_retained_pipeline_prefix_changed', $error->getMessage());
        }
        self::assertNull(DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('reservation_id'));
        Http::assertNothingSent();
    }

    private function quote(User $actor, EstimateGenerationSession $session): array
    {
        $id = (string) Str::uuid();

        return [$id, app(EstimatorCreditOperations::class)->quote($actor, (int) $session->project_id, (int) $session->id, 4, $id, 'generation')];
    }

    private function plan(string $id): array
    {
        return json_decode(DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('processing_plan'), true, 64, JSON_THROW_ON_ERROR);
    }

    private function invalidate(EstimateGenerationSession $session, ProcessingStage $stage): void
    {
        $base = app(EvidenceAwarePipelineBaseInputVersionResolver::class)->fromSession($session);
        $context = new PipelineContext((int) $session->id, (int) $session->organization_id, (int) $session->project_id,
            4, $base, 'failed', generationAttemptId: $session->input_payload['generation_attempt_id'], baseInputVersion: $base);
        app(PipelineCheckpointStore::class)->invalidateDownstream($context, $stage, new DateTimeImmutable);
    }

    private function fixture(string $change = '', ?array $regional = null): array
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->seedSettings();
        config(['ai-assistant-credits.enforce' => false, 'estimate-generation.credits.enabled' => true]);
        $entitlements = \Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);
        $entitlements->allows('hasModuleAccess')->andReturn(true);
        $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class, $entitlements);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0,
            'input_payload' => ['selected_sections' => ['finishing'], 'generation_actor_type' => 'user', 'generation_actor_id' => (int) $actor->id,
                ...($regional === null ? [] : ['regional_context' => $regional])]]);
        app(AICreditService::class)->grant($organization, 5000000, 'purchase', null, 'test:retained-prefix');
        $sourceId = (string) Str::uuid();
        $sourceQuote = app(EstimatorCreditOperations::class)->quote($actor, (int) $project->id, (int) $session->id, 0, $sourceId, 'generation');
        DB::transaction(fn () => app(EstimatorCreditOperations::class)->begin($actor, $session, 0, $sourceId, $sourceQuote['quote_id'], 'generation'));
        $sourceReservation = DB::table('estimate_generation_credit_operations')->where('operation_id', $sourceId)->value('reservation_id');
        if ($change !== 'active') {
            app(AICreditService::class)->cancel(\App\Models\Credits\AICreditReservation::query()->findOrFail($sourceReservation));
        }
        DB::table('estimate_generation_credit_operations')->where('operation_id', $sourceId)
            ->update($change === 'active' ? ['status' => 'running'] : ['status' => 'failed', 'receipt' => '{"status":"failed","charged_units":"0"}']);
        $session->update(['status' => 'failed', 'resume_status' => 'generating', 'state_version' => 4,
            'input_payload' => [...$session->input_payload, 'generation_attempt_id' => $sourceId, 'generation_credit_operation_id' => $sourceId]]);
        $artifacts = new InMemoryPipelineArtifactStore;
        $this->app->instance(PipelineArtifactStore::class, $artifacts);
        $this->app->instance(PipelineOutputRepository::class, new EloquentPipelineOutputRepository($artifacts));
        $base = app(EvidenceAwarePipelineBaseInputVersionResolver::class)->fromSession($session);
        $versions = [];
        foreach (PipelineDefinitionGraph::standard()->ordered() as $definition) {
            $deps = [];
            foreach ($definition->dependencies as $dependency) {
                $deps[$dependency->value] = $versions[$dependency->value];
            }
            $input = PipelineInputVersion::for($definition, $base, $deps);
            $context = new PipelineContext((int) $session->id, (int) $organization->id, (int) $project->id, 4, $input, 'failed',
                generationAttemptId: $sourceId, baseInputVersion: $base, stage: $definition->stage, dependencyVersions: $deps, creditOperationId: $sourceId);
            $data = match ($definition->stage) {
                ProcessingStage::UnderstandDocuments => ['base_input_version' => $base, 'documents' => [], 'documents_count' => 0, 'rebuild_section_key' => null],
                ProcessingStage::UnderstandObject => ['analysis' => []],
                ProcessingStage::ExtractQuantities => ['quantity_learning_hints' => [], 'quantity_coverage_warnings' => [], 'building_quantities' => [], 'stage6_generation_context' => []],
                ProcessingStage::PlanWorkItems => ['object_profile' => [], 'package_plan' => [], 'document_requirements' => [], 'generation_mode' => 'standard', 'regional_context' => [], 'normative_context_pin' => [], 'local_estimates' => [],
                    'estimate_composition' => ['schema_version' => 1, 'snapshot_token' => str_repeat('a', 64), 'input_fingerprint' => str_repeat('b', 64), 'intents_count' => 1, 'derived_quantities' => []]],
                ProcessingStage::MatchNormatives, ProcessingStage::AssembleResources, ProcessingStage::ResolvePrices => ['regional_context' => [], 'supplementary_materials' => [], 'local_estimates' => []],
                ProcessingStage::BuildDraft => ['draft' => ['total' => '40']],
                default => throw new \LogicException,
            };
            $result = app(StageResultFactory::class)->make($context, $definition->stage, $data, warnings: ['source-warning']);
            if ($change !== 'missing' || $definition->stage !== ProcessingStage::PlanWorkItems) {
                EstimateGenerationPipelineCheckpoint::query()->create(['session_id' => $session->id, 'organization_id' => $organization->id,
                    'project_id' => $project->id, 'generation_attempt_id' => $sourceId, 'base_input_version' => $change === 'base' && $definition->stage === ProcessingStage::PlanWorkItems ? 'sha256:'.str_repeat('f', 64) : $base, 'stage' => $definition->stage->value,
                    'input_version' => $input, 'dependency_versions' => (object) $deps, 'output_version' => $result->outputVersion,
                    'output_payload' => $result->output->envelope(), 'artifact_bytes' => $result->output->artifact->bytes,
                    'status' => 'completed', 'attempt_count' => 1, 'warnings' => ['source-warning'], 'started_at' => now(), 'completed_at' => now()]);
            }
            $versions[$definition->stage->value] = $result->outputVersion;
            if ($definition->stage === ProcessingStage::BuildDraft) {
                break;
            }
        }

        if ($change === 'scope') {
            $session = EstimateGenerationSession::query()->create(['organization_id' => $session->organization_id,
                'project_id' => Project::factory()->create(['organization_id' => $session->organization_id])->id,
                'user_id' => $actor->id, 'status' => 'failed', 'resume_status' => 'generating', 'state_version' => 4,
                'input_payload' => $session->input_payload]);
        }
        if ($change === 'actor') {
            $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
            $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        }
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake();

        return [$actor, $session, $sourceId];
    }

    private function regionalVersion(?int $previous = null): int
    {
        $now = now();
        $key = strtolower((string) Str::ulid());
        $old = $previous === null ? null : DB::table('estimate_regional_price_versions')->where('id', $previous)->sole();
        $dataset = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fgis_labor_prices', 'version_key' => $key,
            'bucket' => 'test', 'prefix' => $key, 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $region = $old?->region_id ?? DB::table('estimate_regions')->insertGetId(['code' => $key, 'name' => 'Регион продолжения',
            'fgiscs_subject_id' => random_int(100000, 999999), 'created_at' => $now, 'updated_at' => $now]);
        $zone = $old?->price_zone_id ?? DB::table('estimate_price_zones')->insertGetId(['estimate_region_id' => $region, 'name' => 'Зона продолжения',
            'fgiscs_price_zone_id' => random_int(1000000, 1999999), 'created_at' => $now, 'updated_at' => $now]);
        $period = $old?->period_id ?? DB::table('estimate_price_periods')->where('year', 2099)->where('quarter', 4)->value('id')
            ?? DB::table('estimate_price_periods')->insertGetId(['fgiscs_period_id' => random_int(2000000, 2999999),
                'name' => $key, 'year' => 2099, 'quarter' => 4, 'created_at' => $now, 'updated_at' => $now]);
        $version = DB::table('estimate_regional_price_versions')->insertGetId(['source' => 'fgiscs', 'region_id' => $region,
            'price_zone_id' => $zone, 'period_id' => $period, 'version_key' => $key, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('estimate_resource_prices')->insert(['dataset_version_id' => $dataset, 'regional_price_version_id' => $version,
            'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period, 'resource_code' => $key, 'resource_name' => 'Труд рабочих',
            'unit' => 'h', 'base_price' => '30.0000', 'price_type' => 'labor', 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        if ($previous !== null) {
            DB::table('estimate_regional_price_versions')->where('id', $previous)->update(['status' => 'superseded', 'superseded_at' => $now]);
        }
        DB::table('estimate_regional_price_versions')->where('id', $version)->update([
            'status' => 'active', 'activated_at' => $now, 'superseded_at' => null, 'rolled_back_at' => null]);

        return $version;
    }

    private function seedSettings(): void
    {
        if (DB::table('estimate_generation_setting_snapshots')->where('scope', 'global')->whereNull('organization_id')->exists()) {
            return;
        }
        $snapshot = EstimateGenerationSettingsData::fromArray(['scope' => 'global', 'organization_id' => null, 'expected_version' => 0, 'idempotency_key' => (string) Str::ulid(),
            'models' => ['vision' => 'openai/gpt-6-luna', 'classification' => 'openai/gpt-6-luna', 'normative_matching' => 'openai/gpt-6-luna'],
            'limits' => ['max_files' => 8, 'max_pages_per_file' => 80, 'max_total_pages' => 500], 'timeouts' => ['vision' => 60, 'classification' => 60, 'normative_matching' => 60],
            'retries' => ['vision' => 0, 'classification' => 0, 'normative_matching' => 0], 'confidence' => ['classification' => '0.7100', 'geometry' => '0.7200', 'normative_matching' => '0.7300'],
            'enabled_formats' => ['pdf', 'png'], 'manual_review' => ['low_confidence' => true], 'budgets' => ['daily' => '250.00', 'monthly' => '4000.00', 'currency' => 'RUB']])->snapshot();
        $hash = SettingsSnapshotHash::calculate($snapshot);
        $admin = DB::table('system_admins')->insertGetId(['name' => 'Fixture', 'email' => Str::uuid().'@example.test', 'password' => 'not-used', 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('estimate_generation_setting_snapshots')->insertGetId(['scope' => 'global', 'organization_id' => null, 'version' => 1,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'snapshot_hash' => $hash, 'daily_budget' => '250.00', 'monthly_budget' => '4000.00', 'currency' => 'RUB', 'created_by_system_admin_id' => $admin, 'created_at' => now()]);
        DB::table('estimate_generation_setting_snapshot_hashes')->insert(['setting_snapshot_id' => $id, 'algorithm' => 'jcs-sha256-v1', 'snapshot_hash' => $hash, 'created_at' => now()]);
    }
}
