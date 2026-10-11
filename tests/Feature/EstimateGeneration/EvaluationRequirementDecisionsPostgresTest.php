<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\InitialProjectFactsWriter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationRequirementDecision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\DocumentRequirementAcceptance;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationChoiceProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\RequirementApplicability;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EvaluationRequirementDecisionsPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_document_confirmation_is_bound_to_the_exact_entity_requirement_and_current_document_version(): void
    {
        [$actor, $session, $entityId, $input] = $this->fixture();
        $models = app(ProjectModelRepository::class);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $snapshot = $models->snapshot(...$scope);
        $version = $snapshot->entities[0]->sourceVersion;
        $document = EstimateGenerationDocument::query()->create(['session_id' => $session->id, 'organization_id' => $scope[0],
            'project_id' => $scope[1], 'user_id' => $actor->id, 'filename' => 'test-program.pdf', 'mime_type' => 'application/pdf',
            'storage_path' => 'org-'.$scope[0].'/fixture-test-program.pdf', 'file_size_bytes' => 100,
            'checksum_sha256' => substr($version, 7), 'status' => 'ready', 'page_count' => 1]);
        $node = app(EvidenceRepository::class)->insertOrGet(new EvidenceData(...[...$scope, EvidenceType::SourceFact, EvidenceSourceType::Document,
            'document:'.$document->id, $version, ['document_id' => (int) $document->id, 'page' => 1],
            ['fact_key' => 'element_type_code', 'fact_value' => true], 0, EvidenceProducer::DrawingAnalyzer->value, 'sha256:'.str_repeat('a', 64)]));
        $proof = new Evidence('evidence:'.$node->id, ...[...$scope, $version, 'document:'.$document->id, 'document', 1]);
        $fact = new Fact('fact:program:'.$session->id, ...[...$scope, $version, $entityId, 'water_supply_test_program', true, null, 0, 'document', 'confirmed', [$proof->id]]);
        $models->saveSourceModel([], [$fact], [$proof]);
        $session->update(['input_payload' => [...$session->input_payload, 'selected_sections' => ['water_supply']],
            'draft_payload' => ['evaluation_result' => ['coverage' => ['selected_scope' => ['water_supply' => [$entityId]]]]]]);
        $input = [...$input, 'section' => 'water_supply', 'requirement' => 'water_supply_test_program', 'disposition' => 'document_verified',
            'document_fact_id' => $fact->id, 'reason' => 'Проверена программа испытаний выбранного водопровода'];
        $receipt = app(SaveEvaluationRequirementDecision::class)->handle($actor, $scope[1], $scope[2], $input);
        $snapshot = $models->snapshot(...$scope);
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, [$receipt['fact_id']]]);
        self::assertTrue((new DocumentRequirementAcceptance)->accepted($snapshot, $decisions, $fact, 'unspecified'));
        $document->update(['checksum_sha256' => str_repeat('b', 64)]);
        try {
            app(SaveEvaluationRequirementDecision::class)->handle($actor, $scope[1], $scope[2],
                [...$input, 'request_id' => (string) Str::uuid(), 'state_version' => 1]);
            self::fail('Changed document was accepted.');
        } catch (ValidationException) {
            self::assertSame(1, $session->fresh()->state_version);
        }
    }

    public function test_requirement_decision_persists_real_choice_evidence_replays_once_and_keeps_exact_fact_version_chain(): void
    {
        [$actor, $session, $entityId, $input] = $this->fixture();
        $save = app(SaveEvaluationRequirementDecision::class);
        $first = $save->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        self::assertSame(CanonicalPipelineJson::encode($first), CanonicalPipelineJson::encode($save->handle($actor, (int) $session->project_id, (int) $session->id, $input)));
        self::assertSame('0', $first['cost_units']);
        self::assertSame(1, $session->fresh()->state_version);
        $snapshot = app(ProjectModelRepository::class)->snapshot((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
        $decisions = app(ProjectModelRepository::class)->decisionsForSelectedFacts((int) $session->organization_id, (int) $session->project_id, (int) $session->id, [$first['fact_id']]);
        $fact = array_column($snapshot->facts, null, 'id')[$first['fact_id']];
        self::assertSame('evaluation_requirement.demolition', $fact->type);
        self::assertTrue((new EvaluationChoiceProof)->accepted($snapshot, $fact, $decisions[0]));
        self::assertNotNull((new RequirementApplicability)->notApplicable($snapshot, $decisions, $entityId, 'demolition', 'unspecified'));
        self::assertNull((new RequirementApplicability)->notApplicable($snapshot, $decisions, $entityId, 'demolition', 'changed-technology'));
        $secondInput = [...$input, 'state_version' => 1, 'request_id' => (string) Str::uuid(), 'reason' => 'Повторно проверено: существующее покрытие сохраняется'];
        $second = $save->handle($actor, (int) $session->project_id, (int) $session->id, $secondInput);
        $snapshot = app(ProjectModelRepository::class)->snapshot((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
        $fact = array_column($snapshot->facts, null, 'id')[$second['fact_id']];
        self::assertSame(2, $fact->version);
        self::assertSame($first['fact_id'], $fact->supersedesFactId);
        $decisions = app(ProjectModelRepository::class)->decisionsForSelectedFacts((int) $session->organization_id, (int) $session->project_id, (int) $session->id, [$second['fact_id']]);
        self::assertTrue((new EvaluationChoiceProof)->accepted($snapshot, $fact, $decisions[0]));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_stale_request_and_a_requirement_from_another_scope_do_not_write_evidence_or_a_fact(): void
    {
        [$actor, $session, , $input] = $this->fixture();
        foreach ([['requirement' => 'electricity_test_program'], ['section' => 'electricity'], ['entity_id' => 'entity:foreign']] as $change) {
            try {
                app(SaveEvaluationRequirementDecision::class)->handle($actor, (int) $session->project_id, (int) $session->id, [...$input, ...$change]);
                self::fail('Foreign requirement was accepted.');
            } catch (ValidationException) {
                self::assertSame(0, $session->fresh()->state_version);
            }
        }
        try {
            app(SaveEvaluationRequirementDecision::class)->handle($actor, (int) $session->project_id, (int) $session->id, [...$input, 'state_version' => 1]);
            self::fail('Stale request was accepted.');
        } catch (StaleEstimateGenerationState) {
            self::assertSame(0, $session->fresh()->state_version);
        }
        self::assertSame(0, DB::table('estimate_generation_evidence')->where('session_id', $session->id)->whereRaw("value->>'fact_key' = 'evaluation_choice'")->count());
    }

    public function test_a_changed_repeat_and_revoked_actor_cannot_replace_the_saved_user_decision(): void
    {
        [$actor, $session, , $input] = $this->fixture();
        $save = app(SaveEvaluationRequirementDecision::class);
        $save->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        try {
            $save->handle($actor, (int) $session->project_id, (int) $session->id, [...$input, 'reason' => 'Изменённая причина под тем же идентификатором']);
            self::fail('Changed repeat was accepted.');
        } catch (ValidationException) {
            self::assertSame(1, $session->fresh()->state_version);
        }
        $actor->update(['is_active' => false]);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $save->handle($actor, (int) $session->project_id, (int) $session->id, $input);
    }

    public function test_the_database_rejects_a_model_producer_for_explicit_user_choice_evidence(): void
    {
        [, $session, , $input] = $this->fixture();
        $snapshot = app(ProjectModelRepository::class)->snapshot((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
        $node = app(EvidenceRepository::class)->insertOrGet(new EvidenceData((int) $session->organization_id, (int) $session->project_id,
            (int) $session->id, EvidenceType::SourceFact, EvidenceSourceType::UserInput, 'input:'.$session->id, $snapshot->entities[0]->sourceVersion,
            ['source_key' => 'source:'.str_repeat('a', 64), 'native_reference' => 'input_payload.evaluation_choices'],
            ['fact_key' => 'evaluation_choice', 'fact_value' => str_repeat('b', 64)], 0, EvidenceProducer::UserInputNormalizer->value, 'sha256:'.str_repeat('a', 64)));
        $raw = DB::table('estimate_generation_evidence')->where('id', $node->id)->first();
        $row = (array) $raw;
        unset($row['id']);
        $row['fingerprint'] = str_repeat('c', 64);
        $row['producer_name'] = 'drawing_analyzer';
        try {
            DB::transaction(fn () => DB::table('estimate_generation_evidence')->insert($row));
            self::fail('Model produced a user choice.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('evaluation_choice_evidence_invalid', $exception->getMessage());
        }
    }

    private function fixture(): array
    {
        config(['estimate-generation.universal_enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = Mockery::mock(AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $permissions);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'state_version' => 0, 'status' => 'draft', 'input_payload' => ['evaluation_mode' => 'universal',
                'area' => '20', 'construction_type' => 'current_repair', 'profile_id' => 'renovation', 'selected_sections' => ['finishing']]]);
        app(InitialProjectFactsWriter::class)->write($session, $actor);
        $snapshot = app(ProjectModelRepository::class)->snapshot((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
        $entityId = $snapshot->entities[0]->id;
        $session->update(['draft_payload' => ['evaluation_result' => ['coverage' => ['selected_scope' => ['finishing' => [$entityId]]]]]]);
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'entity_id' => $entityId, 'section' => 'finishing',
            'requirement' => 'demolition', 'disposition' => 'not_applicable', 'reason' => 'Покрытие сохраняется, демонтаж не требуется'];

        return [$actor, $session, $entityId, $input];
    }
}
