<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\InitialProjectFactsWriter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationRequirementDecision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\DocumentRequirementAcceptance;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationChoiceProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\RequirementApplicability;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
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
    public function test_document_of_another_source_entity_requires_explicit_versioned_application_to_one_asset(): void
    {
        [$actor, $session] = $this->fixture();
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $saved = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts::class)
            ->handle($actor, $scope[1], $scope[2], 0, (string) Str::uuid(), ['entities' => [
                ['key' => 'pump-p1', 'type' => 'equipment', 'name' => 'Насос P1',
                    'parameters' => ['equipment_count' => ['value' => '3', 'unit' => 'count', 'basis' => 'input']]],
            ]]);
        $target = $saved['quantities']['equipment.count']['formula_inputs']['entity_id'];
        $document = EstimateGenerationDocument::query()->create(['session_id' => $session->id, 'organization_id' => $scope[0],
            'project_id' => $scope[1], 'user_id' => $actor->id, 'filename' => 'specification-p1.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'storage_path' => 'fixture/spec-p1.xlsx',
            'file_size_bytes' => 100, 'checksum_sha256' => str_repeat('d', 64), 'status' => 'ready', 'page_count' => 1]);
        $version = \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion::fromDocument($document);
        $context = new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext(
            1, ...[...$scope, (int) $document->id, \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType::SpreadsheetSheet,
                1, $version, [], $document->storage_path, $document->mime_type, $document->filename, 'fixture-source', 1, 1, 'draft']);
        $publication = (new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\NativeNumericFactFactory)->spreadsheet($context,
            ['sheet' => 'Spec', 'header_cells' => [['address' => 'A1', 'value' => 'Объект'], ['address' => 'B1', 'value' => 'Параметр'],
                ['address' => 'C1', 'value' => 'Значение'], ['address' => 'D1', 'value' => 'Ед. изм.']],
                'cells' => [['address' => 'A2', 'value' => 'equipment.p1'], ['address' => 'B2', 'value' => 'equipment_specification'],
                    ['address' => 'C2', 'value' => 'Спецификация насоса P1: модель и требования монтажа', 'formula' => null], ['address' => 'D2', 'value' => '']]]);
        app(\App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter::class)
            ->writeArbitration($publication->claims, $publication->decisions, (int) $document->id, 1, $publication->numericSources);
        $models = app(ProjectModelRepository::class);
        $snapshot = $models->snapshotForPlanning(...[...$scope, 100])['snapshot'];
        $fact = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->type === 'equipment_specification'))[0];
        self::assertNotSame($target, $fact->entityId);
        $payload = [...$session->fresh()->input_payload, 'profile_id' => 'construction', 'construction_type' => 'new_construction', 'selected_sections' => ['equipment']];
        $session->update(['input_payload' => $payload]);
        $draft = ['evaluation_result' => ['coverage' => ['selected_scope' => ['equipment' => [$target]]]],
            'source_input_version' => 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session->fresh())];
        $session->update(['draft_payload' => app(UniversalReviewSummaryProjector::class)->project($draft)]);
        $query = ['entity_id' => $target, 'section' => 'equipment', 'requirement' => 'equipment_specification', 'state_version' => 1];
        $reader = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetEvaluationRequirementSources::class);
        self::assertSame([], $reader->handle($actor, $scope[1], $scope[2], $query)['sources']);
        $other = $reader->handle($actor, $scope[1], $scope[2], [...$query, 'source_scope' => 'other_entities']);
        self::assertCount(1, $other['sources']);
        self::assertTrue($other['sources'][0]['requires_applicability_confirmation']);
        $input = [...$query, 'request_id' => (string) Str::uuid(), 'disposition' => 'document_verified', 'document_fact_id' => $fact->id,
            'reason' => 'Проверена применимость спецификации P1 к выбранному насосу'];
        $save = app(SaveEvaluationRequirementDecision::class);
        foreach ([[], ['document_applicability_confirmed' => true, 'document_fact_version' => 2, 'document_value_hash' => $other['sources'][0]['value_hash']],
            ['document_applicability_confirmed' => true, 'document_fact_version' => 1, 'document_value_hash' => str_repeat('0', 64)]] as $invalid) {
            try {
                $save->handle($actor, $scope[1], $scope[2], [...$input, ...$invalid]);
                self::fail('An unconfirmed or changed source was applied to another entity.');
            } catch (ValidationException) {
                self::assertSame(1, $session->fresh()->state_version);
            }
        }
        $input += ['document_applicability_confirmed' => true, 'document_fact_version' => $fact->version,
            'document_value_hash' => $other['sources'][0]['value_hash']];
        $receipt = $save->handle($actor, $scope[1], $scope[2], $input);
        self::assertSame('0', $receipt['cost_units']);
        self::assertSame(CanonicalPipelineJson::encode($receipt), CanonicalPipelineJson::encode($save->handle($actor, $scope[1], $scope[2], $input)));
        $snapshot = $models->snapshotForPlanning(...[...$scope, 100])['snapshot'];
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, [$receipt['fact_id']]]);
        self::assertTrue((new DocumentRequirementAcceptance)->accepted($snapshot, $decisions, $fact, 'unspecified', $target, 'equipment'));
        $evaluationDraft = ['evaluation_policy' => ['profile_id' => 'construction', 'selected_sections' => ['equipment']],
            'local_estimates' => [['key' => 'equipment', 'sections' => [['work_items' => [['key' => 'buy-pump', 'name' => 'Покупка насоса',
                'quantity' => null, 'unit' => 'count', 'metadata' => ['scope_entity_id' => $target, 'requirement_key' => 'equipment_purchase']]]]]]]];
        $evaluation = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder)->build($evaluationDraft, $snapshot, $decisions);
        $specification = array_values(array_filter($evaluation['coverage']['covered_requirements'], static fn (array $row): bool => $row['requirement'] === 'equipment_specification'))[0];
        self::assertSame('Насос P1', $specification['entity_name']);
        self::assertSame($target, $specification['entity_id']);
        self::assertSame($fact->id, $specification['sources'][0]['fact_id']);
        self::assertSame((int) $document->id, $specification['sources'][0]['locators'][0]['document_id']);
        self::assertSame($version, $specification['sources'][0]['locators'][0]['source_version']);
        self::assertNotNull($specification['sources'][0]['locators'][0]['native_reference']);
        self::assertFalse($evaluation['coverage']['scope_complete']);
        self::assertFalse($evaluation['can_confirm_for_apply']);
        $quantities = app(\App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService::class)->previewInput(...$scope)['quantities'];
        $targetQuantities = array_values(array_filter($quantities, static fn ($quantity): bool => ($quantity->formulaInputs['entity_id'] ?? null) === $target && $quantity->formulaKey === 'equipment_count'));
        self::assertCount(1, $targetQuantities);
        self::assertSame('3', $targetQuantities[0]->amount);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        $document->update(['status' => 'ignored']);
        $fresh = $models->snapshotForPlanning(...[...$scope, 100])['snapshot'];
        self::assertNotContains($fact->id, array_column($fresh->facts, 'id'));
        $afterIgnore = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder)->build($evaluationDraft, $fresh, $decisions);
        self::assertNotContains('equipment_specification', array_column($afterIgnore['coverage']['covered_requirements'], 'requirement'));
        self::assertFalse((new DocumentRequirementAcceptance)->accepted($fresh, $decisions, $fact, 'unspecified', $target, 'equipment'));
        self::assertContains($fact->id, array_column($models->snapshot(...$scope)->facts, 'id'));
        $document->update(['status' => 'ready', 'checksum_sha256' => str_repeat('e', 64)]);
        $changed = $models->snapshotForPlanning(...[...$scope, 100])['snapshot'];
        self::assertNotContains($fact->id, array_column($changed->facts, 'id'));
        self::assertFalse((new DocumentRequirementAcceptance)->accepted($changed, $decisions, $fact, 'unspecified', $target, 'equipment'));
    }

    public function test_native_specification_reaches_canonical_facts_and_requires_a_scoped_human_document_decision(): void
    {
        [$actor, $session] = $this->fixture();
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $document = EstimateGenerationDocument::query()->create(['session_id' => $session->id, 'organization_id' => $scope[0],
            'project_id' => $scope[1], 'user_id' => $actor->id, 'filename' => 'equipment.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'storage_path' => 'org-'.$scope[0].'/fixture-equipment.xlsx', 'file_size_bytes' => 100,
            'checksum_sha256' => str_repeat('d', 64), 'status' => 'ready', 'page_count' => 1]);
        $version = \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion::fromDocument($document);
        $context = new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext(
            1, ...[...$scope, (int) $document->id, \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType::SpreadsheetSheet,
                1, $version, [], $document->storage_path, $document->mime_type, $document->filename, 'fixture-claim', 1, 0, 'draft']);
        $text = 'Спецификация оборудования P1: насос, модель и монтажные требования.';
        $native = ['sheet' => 'Спецификация', 'header_cells' => [
            ['address' => 'A1', 'value' => 'Объект'], ['address' => 'B1', 'value' => 'Параметр'],
            ['address' => 'C1', 'value' => 'Значение'], ['address' => 'D1', 'value' => 'Ед. изм.']],
            'cells' => [['address' => 'A2', 'value' => 'equipment.p1'], ['address' => 'B2', 'value' => 'equipment_specification'],
                ['address' => 'C2', 'value' => $text, 'raw_value' => $text, 'formula' => null], ['address' => 'D2', 'value' => '']]];
        foreach ([['roof_facet.f1', 'Промышленная спецификация', 'Документальные требования для маркированного ската F1.', ''],
            ['room.r1', 'Технологические требования', 'Документальные требования для маркированного помещения R1.', '']] as $index => $row) {
            foreach ($row as $column => $value) {
                $native['cells'][] = ['address' => chr(65 + $column).($index + 3), 'value' => $value, 'raw_value' => $value, 'formula' => null];
            }
        }
        foreach (['equipment.p1', 'equipment_count', '3', 'шт'] as $column => $value) {
            $native['cells'][] = ['address' => chr(65 + $column).'5', 'value' => $value, 'raw_value' => $value, 'formula' => null];
        }
        $publication = (new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\NativeNumericFactFactory)->spreadsheet($context, $native);
        app(\App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter::class)
            ->writeArbitration($publication->claims, $publication->decisions, (int) $document->id, 1, $publication->numericSources);
        $models = app(ProjectModelRepository::class);
        $snapshot = $models->snapshot(...$scope);
        self::assertContains('industrial_specification', array_column($snapshot->facts, 'type'));
        self::assertContains('technology_requirements', array_column($snapshot->facts, 'type'));
        $fact = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->type === 'equipment_specification'))[0];
        $count = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->type === 'equipment_count'))[0];
        self::assertSame($fact->entityId, $count->entityId);
        self::assertSame('document', $count->origin);
        self::assertSame('confirmed', $count->status);
        $quantities = app(\App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService::class)->previewInput(...$scope);
        self::assertSame('3', $quantities['quantities']['equipment.count']->amount);
        self::assertSame($count->entityId, $quantities['quantities']['equipment.count']->formulaInputs['entity_id']);
        self::assertSame($count->id, $quantities['quantities']['equipment.count']->formulaInputs['operands'][0]['fact_id']);
        self::assertSame($text, $fact->value);
        self::assertNull($fact->unit);
        self::assertSame('confirmed', $fact->status);
        self::assertFalse((new DocumentRequirementAcceptance)->accepted($snapshot, [], $fact, 'unspecified'));
        $proof = array_column($snapshot->evidence, null, 'id')[$fact->evidenceIds[0]];
        self::assertSame('xlsx:sheet:Спецификация!C2', $proof->nativeReference);
        $session->update(['input_payload' => [...$session->input_payload, 'selected_sections' => ['equipment']],
            'draft_payload' => ['evaluation_result' => ['coverage' => ['selected_scope' => ['equipment' => [$fact->entityId]]]]]]);
        $target = ['entity_id' => $fact->entityId, 'section' => 'equipment', 'requirement' => 'equipment_specification', 'state_version' => 0];
        $sources = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetEvaluationRequirementSources::class)
            ->handle($actor, $scope[1], $scope[2], $target);
        self::assertSame('0', $sources['cost_units']);
        self::assertSame(0, $sources['state_version']);
        self::assertCount(1, $sources['sources']);
        self::assertSame($fact->id, $sources['sources'][0]['fact_id']);
        self::assertSame($text, $sources['sources'][0]['excerpt']);
        self::assertSame((int) $document->id, $sources['sources'][0]['locators'][0]['document_id']);
        foreach ([['entity_id' => 'entity:foreign'], ['section' => 'heating'], ['requirement' => 'heating_test_program']] as $change) {
            try {
                app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetEvaluationRequirementSources::class)
                    ->handle($actor, $scope[1], $scope[2], [...$target, ...$change]);
                self::fail('An unrelated document requirement was exposed.');
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
        try {
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetEvaluationRequirementSources::class)
                ->handle($actor, $scope[1], $scope[2], [...$target, 'state_version' => 1]);
            self::fail('A source response ignored the current version.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState) {
            self::addToAssertionCount(1);
        }
        $receipt = app(SaveEvaluationRequirementDecision::class)->handle($actor, $scope[1], $scope[2], [...$target,
            'request_id' => (string) Str::uuid(), 'document_fact_id' => $fact->id, 'disposition' => 'document_verified',
            'reason' => 'Проверена спецификация указанного оборудования P1']);
        $snapshot = $models->snapshot(...$scope);
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, [$receipt['fact_id']]]);
        self::assertTrue((new DocumentRequirementAcceptance)->accepted($snapshot, $decisions, $fact, 'unspecified'));
        $document->update(['checksum_sha256' => str_repeat('e', 64)]);
        self::assertSame([], app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetEvaluationRequirementSources::class)
            ->handle($actor, $scope[1], $scope[2], [...$target, 'state_version' => 1])['sources']);
        $document->update(['checksum_sha256' => str_repeat('d', 64)]);
        $document->update(['status' => 'ignored']);
        self::assertSame([], app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetEvaluationRequirementSources::class)
            ->handle($actor, $scope[1], $scope[2], [...$target, 'state_version' => 1])['sources']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

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
