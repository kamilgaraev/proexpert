<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditDraftPreview;
use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationPackageController;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPipelineCheckpoint;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentPipelineOutputRepository;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EvidenceAwarePipelineBaseInputVersionResolver;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineInputVersion;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineOutputRepository;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageOutput;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsData;
use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Credits\AICreditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EstimateAuditDraftPreviewPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_current_retained_draft_can_be_preflighted_without_publication_calls_or_credit_changes(): void
    {
        [$actor, $project, $session] = $this->fixture();
        $before = $this->databaseState($session);
        $ordinary = $this->read($actor, $project, $session, false);
        self::assertSame([], $ordinary['data']);
        $preview = $this->read($actor, $project, $session)['data'];

        self::assertTrue($preview['preview_only']);
        self::assertTrue($preview['available']);
        self::assertFalse($preview['validated']);
        self::assertSame(0, $preview['cost_units']);
        self::assertSame(4, $preview['state_version']);
        self::assertTrue($preview['audit_preview']['fits']);
        self::assertCount(1, $preview['audit_preview']['parts']);
        self::assertSame(['work:house'], $preview['audit_preview']['parts'][0]['work_keys']);
        self::assertSame('quantity:house', $preview['audit_preview']['derived_quantities'][0]['id']);
        self::assertSame(0, $preview['audit_preview']['derived_quantities'][0]['operand_count']);
        self::assertArrayNotHasKey('draft', $preview);
        self::assertStringNotContainsString('private-provider-trace', json_encode($preview, JSON_THROW_ON_ERROR));
        self::assertSame($before, $this->databaseState($session));
        Http::assertNothingSent();
    }

    public function test_indivisible_context_reports_a_blocker_without_running_audit_or_publishing(): void
    {
        [$actor, $project, $session] = $this->fixture(true);
        $before = $this->databaseState($session);
        $preview = $this->read($actor, $project, $session)['data'];

        self::assertTrue($preview['available']);
        self::assertFalse($preview['validated']);
        self::assertFalse($preview['audit_preview']['fits']);
        self::assertSame('estimate_audit_context_requires_source_split', $preview['audit_preview']['blocked_by']);
        self::assertSame($before, $this->databaseState($session));
        Http::assertNothingSent();
    }

    public function test_preview_works_without_a_credit_operation_when_the_wallet_is_disabled(): void
    {
        [$actor, $project, $session] = $this->fixture(legacy: true);
        $before = $this->databaseState($session);
        $preview = $this->read($actor, $project, $session)['data'];

        self::assertTrue($preview['available']);
        self::assertTrue($preview['audit_preview']['fits']);
        self::assertFalse($preview['validated']);
        self::assertSame($before, $this->databaseState($session));
        Http::assertNothingSent();
    }

    #[DataProvider('deniedScopes')]
    public function test_the_service_authorizes_direct_calls_before_reading_an_artifact(string $scope): void
    {
        [$actor, $project, $session] = $this->fixture();
        if ($scope === 'organization') {
            $actor = User::factory()->create(['current_organization_id' => Organization::factory()->create()->id]);
        } elseif ($scope === 'project') {
            $project = Project::factory()->for(Organization::factory()->create())->create();
        } else {
            $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
            $permissions->allows('canCurrent')->andReturn(false);
            $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        }
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake();
        $before = $this->databaseState($session);
        try {
            app(EstimateAuditDraftPreview::class)->read($actor, (int) $project->id, (int) $session->id);
            self::fail('A direct preview call bypassed actor authorization.');
        } catch (AuthorizationException) {
            self::assertSame($before, $this->databaseState($session));
            Http::assertNothingSent();
        }
    }

    public static function deniedScopes(): array
    {
        return ['organization' => ['organization'], 'project' => ['project'], 'permission' => ['permission']];
    }

    #[DataProvider('staleCases')]
    public function test_old_attempt_or_stale_inputs_are_rejected_without_invalidating_checkpoints(string $change): void
    {
        [$actor, $project, $session] = $this->fixture(staleBase: $change === 'base');
        if ($change === 'attempt') {
            $session->update(['input_payload' => [...$session->input_payload, 'generation_attempt_id' => (string) Str::uuid()]]);
        } elseif ($change === 'input') {
            $session->update(['input_payload' => [...$session->input_payload, 'description' => 'Изменённый дом']]);
        }
        $before = $this->databaseState($session);
        $preview = $this->read($actor, $project, $session->fresh())['data'];

        self::assertFalse($preview['available']);
        self::assertFalse($preview['validated']);
        self::assertArrayNotHasKey('audit_preview', $preview);
        self::assertSame($before, $this->databaseState($session));
        Http::assertNothingSent();
    }

    public static function staleCases(): array
    {
        return ['old attempt' => ['attempt'], 'checkpoint base' => ['base'], 'changed input' => ['input']];
    }

    public function test_preview_keeps_existing_organization_and_project_guards(): void
    {
        [$actor, $project, $session] = $this->fixture();
        $actor->current_organization_id = Organization::factory()->create()->id;
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);
        try {
            $this->read($actor, $project, $session);
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
            throw $exception;
        }
    }

    private function read(User $actor, Project $project, EstimateGenerationSession $session, bool $preview = true): array
    {
        $request = Request::create('/draft', 'GET', $preview ? ['include_audit_preview' => '1'] : []);
        $request->setUserResolver(static fn (): User => $actor);
        $response = app(EstimateGenerationPackageController::class)->draft($request, $project, $session);
        self::assertSame(200, $response->getStatusCode(), $response->getContent());

        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function databaseState(EstimateGenerationSession $session): array
    {
        return ['session' => $session->fresh()->getRawOriginal(),
            'checkpoints' => DB::table('estimate_generation_pipeline_checkpoints')->where('session_id', $session->id)->orderBy('id')->get()->toJson(),
            'operations' => DB::table('estimate_generation_credit_operations')->where('session_id', $session->id)->orderBy('operation_id')->get()->toJson(),
            'attempts' => DB::table('estimate_generation_credit_attempts')->where('session_id', $session->id)->count(),
            'usage' => DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count(),
            'reservations' => DB::table('ai_credit_reservations')->where('organization_id', $session->organization_id)->count(),
            'revisions' => DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count(),
            'packages' => $session->packages()->count()];
    }

    private function fixture(bool $oversized = false, bool $staleBase = false, bool $legacy = false): array
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->seedSettings();
        $entitlements = \Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);
        $entitlements->allows('hasModuleAccess')->andReturn(true);
        $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class, $entitlements);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        config(['ai-assistant-credits.enforce' => false, 'estimate-generation.credits.enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0,
            'input_payload' => ['selected_sections' => ['finishing', 'structure', 'equipment']]]);
        app(AICreditService::class)->grant($organization, 5000000, 'purchase', null, 'test:free-audit-preview');
        $operation = (string) Str::uuid();
        if (! $legacy) {
            app(EstimatorCreditOperations::class)->quote($actor, (int) $project->id, (int) $session->id, 0, $operation, 'generation');
        }
        $attempt = (string) Str::uuid();
        $session->update(['status' => 'failed', 'state_version' => 4,
            'input_payload' => [...$session->input_payload, 'generation_attempt_id' => $attempt,
                ...($legacy ? [] : ['generation_credit_operation_id' => $operation])]]);
        if ($legacy) {
            config(['estimate-generation.credits.enabled' => false]);
        }
        $base = app(EvidenceAwarePipelineBaseInputVersionResolver::class)->fromSession($session);
        $artifacts = new InMemoryPipelineArtifactStore;
        $this->app->instance(PipelineOutputRepository::class, new EloquentPipelineOutputRepository($artifacts));
        $versions = [];
        foreach (PipelineDefinitionGraph::standard()->ordered() as $definition) {
            $dependencies = [];
            foreach ($definition->dependencies as $dependency) {
                $dependencies[$dependency->value] = $versions[$dependency->value];
            }
            $input = PipelineInputVersion::for($definition, $base, $dependencies);
            $context = new PipelineContext((int) $session->id, (int) $organization->id, (int) $project->id, 4, $input, 'generating',
                generationAttemptId: $attempt, baseInputVersion: $base, stage: $definition->stage, dependencyVersions: $dependencies);
            $draft = ['local_estimates' => [['key' => 'house', 'sections' => [['key' => 'works', 'work_items' => [
                ['key' => 'work:house', 'name' => 'Дом', 'quantity' => '40', 'unit' => 'm2'],
            ]]]]], 'traceability' => ['source' => $oversized ? str_repeat('x', 200000) : 'private-provider-trace'],
                'estimate_composition' => ['derived_quantities' => [['id' => 'quantity:house',
                    'quantity_basis' => ['formula_inputs' => ['operands' => []]], 'operand_fact_ids' => []]]]];
            $artifact = $artifacts->write($context, $definition, $definition->stage === ProcessingStage::BuildDraft ? ['draft' => $draft] : []);
            $output = PipelineStageOutput::create($definition, $input, $dependencies, $artifact);
            EstimateGenerationPipelineCheckpoint::query()->create([
                'session_id' => $session->id, 'organization_id' => $organization->id, 'project_id' => $project->id,
                'generation_attempt_id' => $attempt, 'base_input_version' => $staleBase && $definition->stage === ProcessingStage::BuildDraft
                    ? 'sha256:'.str_repeat('f', 64) : $base, 'stage' => $definition->stage->value,
                'input_version' => $input, 'dependency_versions' => (object) $dependencies, 'output_version' => $output->version,
                'output_payload' => $output->envelope(), 'artifact_bytes' => $artifact->bytes, 'status' => 'completed', 'attempt_count' => 1,
                'started_at' => now(), 'completed_at' => now(),
            ]);
            $versions[$definition->stage->value] = $output->version;
            if ($definition->stage === ProcessingStage::BuildDraft) {
                break;
            }
        }

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake();

        return [$actor, $project, $session];
    }

    private function seedSettings(): void
    {
        if (DB::table('estimate_generation_setting_snapshots')->where('scope', 'global')->whereNull('organization_id')->exists()) {
            return;
        }
        $snapshot = EstimateGenerationSettingsData::fromArray([
            'scope' => 'global', 'organization_id' => null, 'expected_version' => 0, 'idempotency_key' => (string) Str::ulid(),
            'models' => ['vision' => 'openai/gpt-6-luna', 'classification' => 'openai/gpt-6-luna', 'normative_matching' => 'openai/gpt-6-luna'],
            'limits' => ['max_files' => 8, 'max_pages_per_file' => 80, 'max_total_pages' => 500],
            'timeouts' => ['vision' => 60, 'classification' => 60, 'normative_matching' => 60],
            'retries' => ['vision' => 0, 'classification' => 0, 'normative_matching' => 0],
            'confidence' => ['classification' => '0.7100', 'geometry' => '0.7200', 'normative_matching' => '0.7300'],
            'enabled_formats' => ['pdf', 'png'], 'manual_review' => ['low_confidence' => true],
            'budgets' => ['daily' => '250.00', 'monthly' => '4000.00', 'currency' => 'RUB']])->snapshot();
        $hash = SettingsSnapshotHash::calculate($snapshot);
        $admin = DB::table('system_admins')->insertGetId(['name' => 'Fixture', 'email' => Str::uuid().'@example.test',
            'password' => 'not-used', 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('estimate_generation_setting_snapshots')->insertGetId(['scope' => 'global', 'organization_id' => null,
            'version' => 1, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'snapshot_hash' => $hash,
            'daily_budget' => '250.00', 'monthly_budget' => '4000.00', 'currency' => 'RUB', 'created_by_system_admin_id' => $admin, 'created_at' => now()]);
        DB::table('estimate_generation_setting_snapshot_hashes')->insert(['setting_snapshot_id' => $id,
            'algorithm' => 'jcs-sha256-v1', 'snapshot_hash' => $hash, 'created_at' => now()]);
    }
}
