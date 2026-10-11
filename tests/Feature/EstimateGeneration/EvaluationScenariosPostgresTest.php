<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScenarioSet;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EvaluationScenariosPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_free_scenario_choices_use_real_facts_evidence_and_decisions_and_replay_once(): void
    {
        [$actor, $session, $definitions] = $this->fixture();
        Queue::fake();
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'definitions' => $definitions];
        $service = app(SaveEvaluationScenarioSet::class);
        $result = $service->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        self::assertSame('0', $result['cost_units']);
        self::assertTrue($result['recalculation_required']);
        self::assertSame(1, $result['state_version']);
        self::assertEquals($result, $service->handle($actor, (int) $session->project_id, (int) $session->id, $input));
        $session = $session->fresh();
        $models = app(ProjectModelRepository::class);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $snapshot = $models->snapshot(...$scope);
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        self::assertCount(1, $snapshot->facts);
        self::assertSame('evaluation_scenario', $snapshot->facts[0]->type);
        self::assertCount(1, $decisions);
        $draft = $session->draft_payload;
        $draft['evaluation_policy']['scenario_selections'] = $session->input_payload['scenario_selections'];
        $evaluation = (new UniversalEvaluationBuilder)->build($draft, $snapshot, $decisions);
        self::assertSame(['RUB' => ['minimum' => '1200.00', 'maximum' => '3000.00']], $evaluation['scenario_range']);
        self::assertSame('assumed', $evaluation['scenarios'][0]['positions'][0]['quantity_status']);
        self::assertNull($session->draft_payload['local_estimates'][0]['sections'][0]['work_items'][0]['quantity']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->count());
        self::assertSame(0, DB::table('ai_credit_reservations')->count());
        Queue::assertNothingPushed();
    }

    public function test_foreign_organization_cannot_save_scenarios(): void
    {
        [$actor, $session, $definitions] = $this->fixture();
        $actor->current_organization_id = Organization::factory()->create()->id;
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        try {
            app(SaveEvaluationScenarioSet::class)->handle($actor, (int) $session->project_id, (int) $session->id,
                ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'definitions' => $definitions]);
        } finally {
            self::assertSame(0, $session->fresh()->state_version);
        }
    }

    public function test_capabilities_report_server_rollout_without_grants_or_model_calls(): void
    {
        [$actor, $session] = $this->fixture();
        $service = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetEstimatorCapabilities::class);
        self::assertTrue($service->handle($actor, (int) $session->project_id)['universal_available']);
        config(['estimate-generation.universal_enabled' => false]);
        $disabled = $service->handle($actor, (int) $session->project_id);
        self::assertFalse($disabled['universal_available']);
        self::assertSame('legacy', $disabled['default_evaluation_mode']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->count());
        self::assertSame(0, DB::table('ai_credit_reservations')->count());
    }

    public function test_a_stale_active_actor_cannot_get_a_source_url_after_deactivation(): void
    {
        [$actor, $session] = $this->fixture();
        $staleActor = $actor->fresh();
        $document = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument::query()->create([
            'organization_id' => $session->organization_id, 'project_id' => $session->project_id, 'session_id' => $session->id,
            'user_id' => $actor->id, 'filename' => 'plan.pdf', 'mime_type' => 'application/pdf', 'status' => 'ready',
            'storage_path' => 'org-'.$session->organization_id.'/estimate-generation/sessions/'.$session->id.'/documents/plan.pdf',
            'checksum_sha256' => hash('sha256', 'source-permission-fixture'), 'file_size_bytes' => 25]);
        $document->setRelation('session', $session);
        User::query()->whereKey($actor->id)->update(['is_active' => false]);
        self::assertTrue($staleActor->is_active);
        $files = \Mockery::mock(\App\Services\Storage\FileService::class);
        $files->shouldNotReceive('temporaryUrl');
        $preview = new \App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\EstimateGenerationDocumentPreviewService(
            app(\App\Domain\Authorization\Services\AuthorizationService::class), $files);
        self::assertNull($preview->forDocument($document, $staleActor));
    }

    public function test_product_access_is_checked_again_before_reserving_the_shared_wallet(): void
    {
        [$actor, $session] = $this->fixture();
        config(['estimate-generation.credits.enabled' => true]);
        $allowed = true;
        // Use a closure by reference so revocation models fresh product policy, not stale actor permission.
        $entitlements = \Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);
        $entitlements->allows('hasModuleAccess')->with((int) $session->organization_id, 'ai-estimates')
            ->andReturnUsing(static function () use (&$allowed): bool {
                return $allowed;
            });
        $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class, $entitlements);
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $requestId = (string) Str::uuid();
        $quote = $operations->quote($actor, (int) $session->project_id, (int) $session->id, 0, $requestId, 'generation');
        $allowed = false;
        try {
            DB::transaction(fn () => $operations->begin($actor, $session->fresh(), 0, $requestId, $quote['quote_id'], 'generation'));
            self::fail('Revoked product reserved shared credits');
        } catch (\DomainException $exception) {
            self::assertSame('estimator_product_not_available', $exception->getMessage());
        }
        self::assertSame(0, DB::table('ai_credit_reservations')->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->count());
    }

    public function test_a_permission_allowance_does_not_quote_an_unavailable_estimator_product(): void
    {
        [$actor, $session] = $this->fixture();
        config(['estimate-generation.credits.enabled' => true]);
        $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class,
            new \App\Services\Entitlements\OrganizationEntitlementService(app(\App\Services\Modules\PackageCatalogService::class)));
        $quotesBefore = DB::table('ai_credit_quotes')->count();
        try {
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
                ->quote($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid(), 'generation');
            self::fail('Actor permission bypassed missing product entitlement');
        } catch (\DomainException $exception) {
            self::assertSame('estimator_product_not_available', $exception->getMessage());
        }
        self::assertSame($quotesBefore, DB::table('ai_credit_quotes')->count());
        self::assertSame(0, DB::table('ai_credit_reservations')->count());
    }

    public function test_scaled_manual_work_unit_is_saved_as_a_base_fact_with_the_entered_measure_preserved(): void
    {
        [$actor, $session] = $this->fixture();
        $draft = $session->draft_payload;
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['unit'] = '100 м2';
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['commercial_price_snapshot']['unit'] = '100 м2';
        $models = app(ProjectModelRepository::class);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $draft['evaluation_result'] = (new UniversalEvaluationBuilder)->build($draft, $models->snapshot(...$scope));
        $draft = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector)->project($draft);
        $session->update(['draft_payload' => (new BuildMostEstimateDraft)->seal($draft)]);
        $result = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationWorkQuantity::class)->handle(
            $actor, (int) $session->project_id, (int) $session->id, ['request_id' => (string) Str::uuid(), 'state_version' => 0,
                'work_item_key' => 'floor', 'value' => '1.25', 'unit' => '100 м2', 'reason' => 'Явно подтверждённый объём в единице работы']);
        $fact = array_column($models->snapshot(...$scope)->facts, null, 'id')[$result['source_fact_id']];
        self::assertSame('125', $fact->value);
        self::assertSame('m2', $fact->unit);
        $selection = $session->fresh()->input_payload['quantity_selections']['floor'];
        self::assertSame('1.25', $selection['entered_value']);
        self::assertSame('100 м2', $selection['entered_unit']);
        self::assertSame('0', $result['cost_units']);
    }

    private function fixture(): array
    {
        config(['estimate-generation.universal_enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $entitlements = \Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);
        $entitlements->allows('hasModuleAccess')->with((int) $organization->id, 'ai-estimates')->andReturn(true);
        $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class, $entitlements);
        $session = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $actor->id,
            'status' => \App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus::EstimateReviewRequired, 'state_version' => 0,
            'input_payload' => ['evaluation_mode' => 'universal', 'selected_sections' => ['finishing'], 'price_policy' => 'catalog']]);
        $draft = ['generation_contract' => UniversalDraftProjector::CONTRACT,
            'evaluation_policy' => ['selected_sections' => ['finishing'], 'price_policy' => 'catalog'],
            'local_estimates' => [['key' => 'rough_finishing', 'sections' => [['work_items' => [['key' => 'floor', 'name' => 'Пол',
                'item_type' => 'priced_work', 'quantity' => null, 'unit' => 'm2',
                'commercial_price_snapshot' => ['unit_price' => '100', 'unit' => 'm2', 'currency' => 'RUB', 'verified' => true,
                    'source_type' => 'catalog', 'source_reference' => 'catalog:1', 'as_of_date' => '2026-10-10']]]]]]]];
        $draft['evaluation_result'] = (new UniversalEvaluationBuilder)->build($draft,
            new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot([], [], [], []));
        $draft['source_input_version'] = 'sha256:'.app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint::class)->fromSession($session);
        $draft = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector)->project($draft);
        $session->update(['draft_payload' => (new BuildMostEstimateDraft)->seal($draft)]);
        $definitions = array_map(static fn (string $id, string $quantity): array => ['id' => $id,
            'basis' => 'Явно выбранная пользователем предполагаемая площадь',
            'quantities' => [['work_item_key' => 'floor', 'value' => $quantity, 'unit' => 'm2']]],
            ['minimum', 'base', 'maximum'], ['12', '20', '30']);

        return [$actor, $session, $definitions];
    }
}
