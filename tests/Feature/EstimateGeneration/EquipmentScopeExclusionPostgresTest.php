<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\NativeNumericFactFactory;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetEvaluationRequirementSources;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\InitialProjectFactsWriter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationRequirementDecision;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ScopeCompletenessEvaluator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EquipmentScopeExclusionPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_explicit_equipment_scope_decisions_close_only_the_excluded_phases_and_change_the_input_fingerprint(): void
    {
        [$actor, $session, $entityId] = $this->fixture();
        $before = app(EvaluationInputFingerprint::class)->fromSession($session->fresh());
        self::assertSame(['equipment_purchase', 'delivery', 'commissioning'], array_column($this->evaluation($session)['coverage']['missing_requirements'], 'requirement'));
        foreach (['equipment_purchase', 'delivery', 'commissioning'] as $phase) {
            $input = $this->target($session, $entityId, $phase);
            $sources = app(GetEvaluationRequirementSources::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
            self::assertSame(['not_applicable'], $sources['allowed_dispositions']);
            self::assertSame([], $sources['sources']);
            $this->exclude($actor, $session, $entityId, $phase);
        }
        $result = $this->evaluation($session);
        self::assertTrue($result['coverage']['scope_complete']);
        self::assertSame([], $result['coverage']['missing_requirements']);
        self::assertSame(['equipment_purchase', 'delivery', 'commissioning'], array_column($result['coverage']['not_applicable_requirements'], 'requirement'));
        self::assertSame('universal-scope:v2', $result['coverage']['profile_version']);
        self::assertNotSame($before, app(EvaluationInputFingerprint::class)->fromSession($session->fresh()));
        self::assertSame(4, $session->fresh()->state_version);
        self::assertArrayNotHasKey('confirmed_evaluation', $session->fresh()->input_payload);
    }

    #[DataProvider('excludedPhases')]
    public function test_a_current_rebuild_cannot_include_a_phase_that_has_a_signed_scope_exclusion(string $phase): void
    {
        [$actor, $session, $entityId] = $this->fixture();
        foreach (['equipment_purchase', 'delivery', 'commissioning'] as $requirement) {
            $this->exclude($actor, $session, $entityId, $requirement);
        }
        $draft = $session->fresh()->draft_payload;
        $consumables = [['key' => 'installation-consumable', 'quantity' => '0.3', 'total_price' => '30.00']];
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['materials'] = $consumables;
        $draft['local_estimates'][0]['sections'][0]['work_items'][] = $this->work($entityId, $phase);
        $result = $this->evaluation($session, $draft);
        $conflicts = array_values(array_filter($result['coverage']['missing_requirements'], static fn (array $row): bool => $row['reason'] === 'scope_exclusion_conflict'));
        self::assertCount(1, $conflicts);
        self::assertSame($phase, $conflicts[0]['requirement']);
        self::assertSame([$phase], $conflicts[0]['position_keys']);
        self::assertNotSame([], $conflicts[0]['fact_ids']);
        self::assertFalse($result['coverage']['scope_complete']);
        self::assertFalse($result['can_confirm_for_apply']);
        self::assertContains($phase, array_column($result['scenarios'][0]['positions'], 'key'));
        self::assertSame($consumables, $draft['local_estimates'][0]['sections'][0]['work_items'][0]['materials']);
    }

    public function test_old_state_technology_and_source_do_not_reuse_an_equipment_scope_exclusion(): void
    {
        [$actor, $session, $entityId] = $this->fixture();
        $old = $this->target($session, $entityId, 'equipment_purchase');
        $this->exclude($actor, $session, $entityId, 'equipment_purchase');
        try {
            app(SaveEvaluationRequirementDecision::class)->handle($actor, (int) $session->project_id, (int) $session->id,
                [...$old, 'request_id' => (string) Str::uuid(), 'disposition' => 'not_applicable', 'reason' => 'Этот этап не входит в согласованный состав этой сметы.']);
            self::fail('An old state accepted a scope decision.');
        } catch (StaleEstimateGenerationState) {
            self::assertSame(2, $session->fresh()->state_version);
        }
        [$snapshot, $decisions] = $this->model($session);
        $position = [['key' => 'installation', 'section' => 'equipment', 'entity_id' => $entityId, 'coverage_requirement' => 'installation']];
        $changedTechnology = (new ScopeCompletenessEvaluator)->evaluate($snapshot, 'construction', 'new_construction', ['equipment' => [$entityId]], $position, $decisions, ['equipment' => [$entityId => 'changed-technology']]);
        self::assertContains('equipment_purchase', array_column($changedTechnology['missing_requirements'], 'requirement'));
        $entities = array_map(static fn (Entity $entity): Entity => new Entity($entity->id, $entity->organizationId, $entity->projectId,
            $entity->sessionId, 'sha256:'.str_repeat('a', 64), $entity->type, $entity->stableKey, $entity->attributes), $snapshot->entities);
        $changedSource = new ProjectModelSnapshot($entities, $snapshot->facts, $snapshot->evidence, $snapshot->conflicts);
        $changed = (new ScopeCompletenessEvaluator)->evaluate($changedSource, 'construction', 'new_construction', ['equipment' => [$entityId]], $position, $decisions);
        self::assertContains('equipment_purchase', array_column($changed['missing_requirements'], 'requirement'));
        self::assertSame([], $changed['not_applicable_requirements']);
    }

    public function test_specification_installation_and_another_section_cannot_be_excluded(): void
    {
        [$actor, $session, $entityId] = $this->fixture();
        foreach (['equipment_specification', 'installation'] as $requirement) {
            $sources = app(GetEvaluationRequirementSources::class)->handle($actor, (int) $session->project_id, (int) $session->id,
                $this->target($session, $entityId, $requirement));
            self::assertNotContains('not_applicable', $sources['allowed_dispositions']);
            try {
                $this->exclude($actor, $session, $entityId, $requirement);
                self::fail('A mandatory requirement was excluded.');
            } catch (ValidationException) {
                self::assertSame(1, $session->fresh()->state_version);
            }
        }
        try {
            app(SaveEvaluationRequirementDecision::class)->handle($actor, (int) $session->project_id, (int) $session->id,
                [...$this->target($session, $entityId, 'delivery'), 'section' => 'heating', 'request_id' => (string) Str::uuid(),
                    'disposition' => 'not_applicable', 'reason' => 'Этот этап не входит в согласованный состав этой сметы.']);
            self::fail('Another section accepted an equipment exclusion.');
        } catch (ValidationException) {
            self::assertSame(1, $session->fresh()->state_version);
        }
    }

    public function test_the_existing_v2_public_repair_decision_keeps_its_section_and_remains_valid(): void
    {
        [$actor, $session] = $this->sessionFixture(['area' => '20', 'profile_id' => 'renovation', 'construction_type' => 'current_repair', 'selected_sections' => ['finishing']]);
        app(InitialProjectFactsWriter::class)->write($session, $actor);
        [$snapshot] = $this->model($session);
        $entityId = $snapshot->entities[0]->id;
        $draft = ['source_input_version' => 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session->fresh()),
            'evaluation_policy' => ['selected_sections' => ['finishing'], 'profile_id' => 'renovation', 'construction_type' => 'current_repair'],
            'local_estimates' => [['key' => 'finishing', 'sections' => [['work_items' => array_map(fn (string $phase): array => $this->work($entityId, $phase),
                ['preparation', 'finishing', 'waste_removal', 'existing_structures_protection'])]]]]];
        $draft['evaluation_result'] = (new UniversalEvaluationBuilder)->build($draft, $snapshot);
        $session->update(['draft_payload' => (new UniversalReviewSummaryProjector)->project($draft)]);
        app(SaveEvaluationRequirementDecision::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            ['entity_id' => $entityId, 'section' => 'finishing', 'requirement' => 'demolition', 'state_version' => 0,
                'request_id' => (string) Str::uuid(), 'disposition' => 'not_applicable', 'reason' => 'Покрытие сохраняется, демонтаж не требуется.']);
        [$current] = $this->model($session);
        $choice = array_values(array_filter($current->facts, static fn (Fact $fact): bool => $fact->type === 'evaluation_requirement.demolition'))[0];
        self::assertSame('finishing', $choice->value['section']);
        self::assertSame('universal-scope:v2', $choice->value['scope_version']);
        self::assertTrue($this->evaluation($session)['coverage']['scope_complete']);
    }

    public static function excludedPhases(): array
    {
        return ['purchase' => ['equipment_purchase'], 'delivery' => ['delivery'], 'commissioning' => ['commissioning']];
    }

    private function exclude(User $actor, EstimateGenerationSession $session, string $entityId, string $phase): void
    {
        app(SaveEvaluationRequirementDecision::class)->handle($actor, (int) $session->project_id, (int) $session->id,
            [...$this->target($session, $entityId, $phase), 'request_id' => (string) Str::uuid(), 'disposition' => 'not_applicable',
                'reason' => 'Этот этап не входит в согласованный состав этой сметы.']);
    }

    private function target(EstimateGenerationSession $session, string $entityId, string $requirement): array
    {
        return ['entity_id' => $entityId, 'section' => 'equipment', 'requirement' => $requirement, 'state_version' => $session->fresh()->state_version];
    }

    private function model(EstimateGenerationSession $session): array
    {
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $snapshot = $models->snapshotForPlanning(...[...$scope, 100])['snapshot'];

        return [$snapshot, $models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')])];
    }

    private function evaluation(EstimateGenerationSession $session, ?array $draft = null): array
    {
        [$snapshot, $decisions] = $this->model($session);

        return (new UniversalEvaluationBuilder)->build($draft ?? $session->fresh()->draft_payload, $snapshot, $decisions);
    }

    private function work(string $entityId, string $phase): array
    {
        return ['key' => $phase, 'name' => 'Этап оборудования '.$phase, 'item_type' => 'priced_work', 'unit' => 'pcs', 'quantity' => '3',
            'total_cost' => '30.00', 'pricing_status' => 'calculated', 'metadata' => ['scope_entity_id' => $entityId,
                'composition_work_key' => $phase, 'requirement_key' => $phase]];
    }

    private function fixture(): array
    {
        [$actor, $session] = $this->sessionFixture();
        $organization = $session->organization;
        $project = $session->project;
        $document = EstimateGenerationDocument::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'session_id' => $session->id, 'user_id' => $actor->id, 'filename' => 'equipment-scope.xlsx', 'status' => 'ready',
            'checksum_sha256' => str_repeat('d', 64), 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        $context = new DocumentUnitExecutionContext(1, (int) $organization->id, (int) $project->id, (int) $session->id, (int) $document->id,
            DocumentUnitType::SpreadsheetSheet, 1, DocumentSourceVersion::fromDocument($document), [], 'fixture/equipment-scope.xlsx',
            $document->mime_type, $document->filename, 'fixture-source', 1, 0, 'draft');
        $publication = (new NativeNumericFactFactory)->spreadsheet($context, ['sheet' => 'Оборудование',
            'header_cells' => [['address' => 'A1', 'value' => 'entity_key'], ['address' => 'B1', 'value' => 'parameter'],
                ['address' => 'C1', 'value' => 'value'], ['address' => 'D1', 'value' => 'unit']],
            'cells' => [['address' => 'A2', 'value' => 'equipment.p1'], ['address' => 'B2', 'value' => 'equipment_specification'],
                ['address' => 'C2', 'value' => 'Спецификация насоса P1, монтажные требования.', 'formula' => null], ['address' => 'D2', 'value' => ''],
                ['address' => 'A3', 'value' => 'equipment.p1'], ['address' => 'B3', 'value' => 'equipment_count'],
                ['address' => 'C3', 'value' => '3', 'formula' => null], ['address' => 'D3', 'value' => 'шт']]]);
        app(ProjectModelEvidenceWriter::class)->writeArbitration($publication->claims, $publication->decisions,
            (int) $document->id, 1, $publication->numericSources);
        [$snapshot] = $this->model($session);
        $specification = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->type === 'equipment_specification'))[0];
        $draft = ['source_input_version' => 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session->fresh()),
            'evaluation_policy' => ['selected_sections' => ['equipment'], 'profile_id' => 'construction', 'construction_type' => 'new_construction'],
            'local_estimates' => [['key' => 'equipment', 'sections' => [['work_items' => [$this->work($specification->entityId, 'installation')]]]]]];
        $draft['evaluation_result'] = (new UniversalEvaluationBuilder)->build($draft, $snapshot);
        $session->update(['draft_payload' => (new UniversalReviewSummaryProjector)->project($draft)]);
        app(SaveEvaluationRequirementDecision::class)->handle($actor, (int) $project->id, (int) $session->id,
            [...$this->target($session, $specification->entityId, 'equipment_specification'), 'request_id' => (string) Str::uuid(),
                'disposition' => 'document_verified', 'document_fact_id' => $specification->id,
                'reason' => 'Проверена спецификация именно выбранного насоса P1.']);

        return [$actor, $session, $specification->entityId];
    }

    private function sessionFixture(array $input = []): array
    {
        config(['estimate-generation.universal_enabled' => true, 'cache.default' => 'array']);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->createQuietly();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => ['evaluation_mode' => 'universal',
                'profile_id' => 'construction', 'construction_type' => 'new_construction', 'selected_sections' => ['equipment'], ...$input]]);

        return [$actor, $session];
    }
}
