<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetCurrentEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationRevision;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaim;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PublishUniversalEvaluation;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EvaluationRevisionsPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_result_revisions_are_immutable_idempotent_and_operational_state_is_not_business_content(): void
    {
        [$actor, $session] = $this->fixture();
        $inputs = app(EvaluationInputFingerprint::class);
        $before = $inputs->fromSession($session);
        $session->input_payload = [...$session->input_payload, 'generation_requested' => true, 'generation_attempt_id' => (string) Str::uuid(), 'credit_reservation_id' => 42];
        $session->processing_progress = 90;
        $session->save();
        self::assertSame($before, $inputs->fromSession($session));
        $save = app(SaveEvaluationRevision::class);
        $operation = (string) Str::uuid();
        $result = $this->evaluationResultFixture();
        $receipt = $save->save($actor, (int) $session->organization_id, (int) $session->project_id, (int) $session->id, 0, $operation, $before, $result);
        self::assertSame($receipt, $save->save($actor, (int) $session->organization_id, (int) $session->project_id, (int) $session->id, 0, $operation, $before, $result));
        self::assertSame(1, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count());
        try {
            DB::transaction(fn () => DB::table('estimate_generation_evaluation_revisions')->where('public_id', $receipt['revision_id'])->update(['result' => '{}']));
            self::fail('Business history was changed.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('evaluation_revision_immutable', $exception->getMessage());
        }
        try {
            $save->save($actor, (int) $session->organization_id, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid(), $before, [...$result, 'reserved_units' => 50]);
            self::fail('Operational charge was included in signed business content.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('evaluation_business_content_contains_operational_state', $exception->getMessage());
        }
    }

    public function test_changed_raw_input_and_foreign_tenant_cannot_publish_a_revision(): void
    {
        [$actor, $session] = $this->fixture();
        $hash = app(EvaluationInputFingerprint::class)->fromSession($session);
        $session->input_payload = [...$session->input_payload, 'description' => 'Другие исходные данные'];
        $session->save();
        try {
            app(SaveEvaluationRevision::class)->save($actor, (int) $session->organization_id, (int) $session->project_id, (int) $session->id,
                0, (string) Str::uuid(), $hash, $this->evaluationResultFixture());
            self::fail('Changed input was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('evaluation_input_changed', $exception->getMessage());
        }
        self::assertSame(0, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count());
        $foreign = Organization::factory()->create();
        try {
            DB::transaction(fn () => DB::table('estimate_generation_evaluation_revisions')->insert([
                'public_id' => (string) Str::uuid(), 'organization_id' => $foreign->id, 'project_id' => $session->project_id, 'session_id' => $session->id,
                'revision' => 1, 'input_state_version' => 0, 'operation_id' => (string) Str::uuid(), 'input_hash' => $hash, 'content_hash' => $hash,
                'result_class' => 'refined_estimate', 'profile_version' => 'universal-scope:v1', 'result' => '{}', 'created_at' => now(),
            ]));
            self::fail('Cross-organization source was accepted.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('eg_evaluation_session_scope_fk', $exception->getMessage());
        }
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => ['description' => 'Ремонт помещения']]);

        return [$actor, $session];
    }

    public function test_universal_publication_and_workflow_transition_are_atomic_and_result_reading_is_free(): void
    {
        [$actor, $session] = $this->fixture();
        $operation = (string) Str::uuid();
        $session->input_payload = [...$session->input_payload, 'evaluation_mode' => 'universal', 'price_policy' => 'catalog',
            'profile_id' => 'renovation', 'selected_sections' => ['finishing']];
        $session->save();
        $hash = app(EvaluationInputFingerprint::class)->fromSession($session);
        $session->fill(['status' => 'generating', 'input_payload' => [...$session->input_payload,
            'generation_actor_type' => 'user', 'generation_actor_id' => $actor->id,
            'generation_attempt_id' => $operation, 'generation_evaluation_input_hash' => $hash]])->save();
        $claim = CheckpointClaim::acquired(new PipelineContext((int) $session->id, (int) $session->organization_id,
            (int) $session->project_id, 0, 'sha256:'.$hash, 'generating', generationAttemptId: $operation, baseInputVersion: 'sha256:'.$hash), ProcessingStage::ValidateDraft, (string) Str::uuid());
        $draft = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector)->project([
            'source_input_version' => 'sha256:'.$hash, 'generation_contract' => \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector::CONTRACT,
            'evaluation_result' => $this->evaluationResultFixture(), 'local_estimates' => [
                ['key' => 'finishing', 'title' => 'Отделка', 'sections' => [['key' => 'finishing-section', 'title' => 'Отделка',
                    'work_items' => [['key' => 'finish:1', 'name' => 'Отделка пола', 'item_type' => 'priced_work',
                        'quantity' => '20', 'unit' => 'm2', 'pricing_status' => 'not_calculated', 'total_cost' => null]]]]]]]);
        $draft = (new BuildMostEstimateDraft)->seal($draft);
        try {
            DB::transaction(function () use ($claim, $draft): void {
                app(PublishUniversalEvaluation::class)->publish($claim, $draft);
                throw new \RuntimeException('rollback_after_publication');
            });
            self::fail('Injected rollback did not occur.');
        } catch (\RuntimeException $exception) {
            self::assertSame('rollback_after_publication', $exception->getMessage());
        }
        self::assertSame(0, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count());
        self::assertSame('generating', $session->fresh()->status->value);
        DB::transaction(fn () => app(PublishUniversalEvaluation::class)->publish($claim, $draft));
        $first = app(GetCurrentEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id);
        self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($this->evaluationResultFixture()),
            \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($first['result']));
        self::assertSame('universal', $first['evaluation_mode']);
        self::assertFalse($first['result_stale']);
        self::assertSame('estimate_review_required', $session->fresh()->status->value);
        $storedDraft = $session->fresh()->draft_payload;
        self::assertTrue(\App\BusinessModules\Addons\EstimateGeneration\Services\Quality\ReviewSummarySnapshot::isFresh($storedDraft, $storedDraft['quality_summary']['review_items']));
        self::assertTrue((new BuildMostEstimateDraft)->verifyArtifact($storedDraft));
        self::assertNull($session->fresh()->applied_estimate_id);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        $session->refresh();
        $session->input_payload = [...$session->input_payload, 'description' => 'Уточнённый состав'];
        $session->save();
        self::assertTrue(app(GetCurrentEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id)['result_stale']);
        self::assertSame(1, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count());
    }

    private function evaluationResultFixture(): array
    {
        return [...(new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ScenarioEstimateCalculator)->calculate([
            ['id' => 'base', 'assumptions' => [], 'positions' => [['key' => 'finish:1', 'section' => 'finishing', 'unit' => 'm2', 'quantity' => '20', 'price_snapshot' => null]]],
        ], ['finishing'], ['price_missing']), 'profile_version' => 'universal-scope:v1'];
    }
}
