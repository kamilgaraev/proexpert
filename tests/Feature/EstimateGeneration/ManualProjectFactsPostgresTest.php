<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationManualFactsController;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class ManualProjectFactsPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_native_question_limits_are_explicit_and_never_trim_facts_or_decisions_to_enable_answers(): void
    {
        [$actor, $session] = $this->fixture();
        config(['estimate-generation.universal_enabled' => true]);
        $session->update(['input_payload' => ['evaluation_mode' => 'universal', 'selected_sections' => ['structures']]]);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        app(SaveManualProjectFacts::class)->handle($actor, $scope[1], $scope[2], 0, (string) Str::uuid(), ['entities' => [
            ['key' => 'beam', 'type' => 'foundation_beam', 'name' => 'Балка без размеров'],
        ]]);
        $capture = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001]);
        $snapshot = $capture['snapshot'];
        $models = Mockery::mock(ProjectModelRepository::class);
        $decisions = [];
        for ($index = 0; $index < 257; $index++) {
            $fact = $snapshot->facts[0];
            $decisions[] = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision('decision:'.$index,
                ...[...$scope, $fact->sourceVersion, 'fact', $fact->id, $fact->id, 'user', (string) $actor->id, 'Проверено', 1]);
        }
        $models->shouldReceive('decisionsForSelectedFacts')->once()->with(...[...$scope, array_column($snapshot->facts, 'id')])->andReturn($decisions);
        $backlog = new \App\BusinessModules\Addons\EstimateGeneration\Application\Review\NativeParameterBacklog($models,
            new \App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService($models,
                new \App\BusinessModules\Addons\EstimateGeneration\Quantities\DerivedQuantityFactory),
            new \App\BusinessModules\Addons\EstimateGeneration\Application\Review\NativeParameterQuestionProjector);
        $overDecisions = $backlog->current($session->fresh(), $snapshot, $capture['token']);
        self::assertFalse($overDecisions['available']);
        self::assertSame([], $overDecisions['questions']);
        self::assertSame(['input_budget_exceeded'], $overDecisions['limitations']);
        $large = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot($snapshot->entities,
            array_fill(0, 10001, $snapshot->facts[0]), $snapshot->evidence, []);
        $overFacts = $backlog->current($session->fresh(), $large, $capture['token']);
        self::assertSame($overDecisions, $overFacts);
        self::assertSame(1, $session->fresh()->state_version);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_native_backlog_before_draft_answers_once_and_rejects_stale_scope_source_and_changed_questions_for_free(): void
    {
        [$actor, $session] = $this->fixture();
        config(['estimate-generation.universal_enabled' => true]);
        $session->update(['input_payload' => ['evaluation_mode' => 'universal', 'selected_sections' => ['structures']]]);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $writer = app(SaveManualProjectFacts::class);
        $writer->handle($actor, $scope[1], $scope[2], 0, (string) Str::uuid(), ['entities' => [
            ['key' => 'beam', 'type' => 'foundation_beam', 'parameters' => [
                'length' => ['value' => '10', 'unit' => 'm', 'basis' => 'measurement'],
                'width' => ['value' => '250', 'unit' => 'mm', 'basis' => 'measurement']]],
        ]]);
        $backlog = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Review\EstimatePrerequisiteBacklog::class);
        $beforeCalls = count(Http::recorded());
        $before = $backlog->current($session->fresh());
        self::assertTrue($before['needs_refresh']);
        self::assertSame([], $before['groups']);
        self::assertTrue($before['native_questions']['available']);
        self::assertCount(1, $before['native_questions']['questions']);
        self::assertSame(1, $session->fresh()->state_version);
        $question = $before['native_questions']['questions'][0];
        self::assertSame('height', $question['answer']['parameter']);
        self::assertSame('m', $question['input_contract']['unit']);
        self::assertTrue($question['can_answer']);
        $binding = $question['answer']['native_question'];
        $answer = ['entities' => [['key' => 'answer', 'type' => 'foundation_beam', 'existing_entity_id' => $question['answer']['entity_id'],
            'native_question' => $binding, 'parameters' => ['height' => ['value' => '400', 'unit' => 'mm', 'basis' => 'measurement']]]]];
        $countBefore = DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count();
        foreach (['legacy', 'missing_scope', 'wrong_section', 'unknown_section'] as $variant) {
            $payload = $session->fresh()->input_payload;
            $payload['evaluation_mode'] = $variant === 'legacy' ? 'legacy' : 'universal';
            $payload['selected_sections'] = match ($variant) {
                'missing_scope' => [], 'wrong_section' => ['roofing'], 'unknown_section' => ['made_up'], default => ['structures']
            };
            $session->update(['input_payload' => $payload]);
            try {
                $writer->handle($actor, $scope[1], $scope[2], 1, (string) Str::uuid(), $answer);
                self::fail('A question from another mode or selected scope was accepted.');
            } catch (ValidationException) {
                self::assertSame($countBefore, DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count());
                self::assertSame(1, $session->fresh()->state_version);
            }
        }
        $payload = $session->fresh()->input_payload;
        $payload['evaluation_mode'] = 'universal';
        $payload['selected_sections'] = ['structures'];
        $session->update(['input_payload' => $payload]);
        foreach (['version', 'input_fingerprint', 'entity_source_version'] as $field) {
            $wrong = $answer;
            $wrong['entities'][0]['native_question'][$field] = ($field === 'entity_source_version' ? 'sha256:' : '').str_repeat('f', 64);
            try {
                $writer->handle($actor, $scope[1], $scope[2], 1, (string) Str::uuid(), $wrong);
                self::fail('Stale native binding accepted.');
            } catch (ValidationException) {
                self::assertSame(1, $session->fresh()->state_version);
            }
        }
        $answer['entities'][0]['native_question'] = array_reverse($binding, true);
        $request = (string) Str::uuid();
        $result = $writer->handle($actor, $scope[1], $scope[2], 1, $request, $answer);
        self::assertSame('1', $result['quantities']['foundation_volume']['amount']);
        self::assertSame(2, $result['state_version']);
        self::assertFalse($session->fresh()->input_payload['input_confirmed']);
        self::assertSame([], $backlog->current($session->fresh())['native_questions']['questions']);
        $replay = $writer->handle($actor, $scope[1], $scope[2], 1, $request, $answer);
        self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($result),
            \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($replay));
        self::assertSame(2, $session->fresh()->state_version);
        $answer['entities'][0]['parameters']['height']['value'] = '500';
        try {
            $writer->handle($actor, $scope[1], $scope[2], 2, $request, $answer);
            self::fail('A changed answer reused a frozen receipt.');
        } catch (ValidationException) {
            self::assertSame(2, $session->fresh()->state_version);
        }
        $actor->update(['current_organization_id' => Organization::factory()->create()->id]);
        try {
            $writer->handle($actor, $scope[1], $scope[2], 2, (string) Str::uuid(), $answer);
            self::fail('Foreign organization accepted.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            self::assertSame(2, $session->fresh()->state_version);
        }
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame($beforeCalls, count(Http::recorded()));
    }

    public function test_description_numeric_proposal_requires_explicit_target_and_current_source_and_replays_for_free(): void
    {
        [$actor, $session] = $this->fixture();
        config(['estimate-generation.universal_enabled' => true]);
        $session->update(['input_payload' => ['evaluation_mode' => 'universal', 'description' => 'Площадь 12 м². Высота 3 м.']]);
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\InitialProjectFactsWriter::class)->write($session, $actor);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        self::assertCount(1, $models->snapshotForPlanning(...[...$scope, 10001])['snapshot']->facts);
        $get = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetDescriptionNumericProposals::class);
        $list = $get->handle($actor, $scope[1], $scope[2]);
        self::assertSame('0', $list['cost_units']);
        self::assertSame(['area', 'height'], array_column($list['proposals'], 'parameter'));
        $proposal = $list['proposals'][0];
        $input = ['entities' => [['key' => 'chosen-room', 'type' => 'room', 'name' => 'Подтверждённое помещение', 'parameters' => ['area' => [
            'value' => '12', 'unit' => 'm2', 'basis' => 'input', 'description_proposal' => array_intersect_key($proposal,
                array_flip(['proposal_id', 'source_fact_id', 'source_fact_version', 'source_value_hash'])),
        ]]]]];
        $save = app(SaveManualProjectFacts::class);
        foreach (['changed_value', 'measurement', 'wrong_source', 'changed_description', 'disabled_gate'] as $case) {
            $invalid = $input;
            if ($case === 'changed_value') {
                $invalid['entities'][0]['parameters']['area']['value'] = '15';
            } elseif ($case === 'measurement') {
                $invalid['entities'][0]['parameters']['area']['basis'] = 'measurement';
            } elseif ($case === 'wrong_source') {
                $invalid['entities'][0]['parameters']['area']['description_proposal']['source_fact_version'] = 2;
            } elseif ($case === 'changed_description') {
                $session->update(['input_payload' => [...$session->input_payload, 'description' => 'Площадь 16 м².']]);
            } else {
                config(['estimate-generation.universal_enabled' => false]);
            }
            try {
                $save->handle($actor, $scope[1], $scope[2], 0, (string) Str::uuid(), $invalid);
                self::fail('Unverified proposal must be rejected: '.$case);
            } catch (ValidationException) {
                self::assertSame(0, $session->fresh()->state_version);
                self::assertCount(1, $models->snapshotForPlanning(...[...$scope, 10001])['snapshot']->facts);
            }
            config(['estimate-generation.universal_enabled' => true]);
            $session->update(['input_payload' => ['evaluation_mode' => 'universal', 'description' => 'Площадь 12 м². Высота 3 м.']]);
        }
        $requestId = (string) Str::uuid();
        $result = $save->handle($actor, $scope[1], $scope[2], 0, $requestId, $input);
        self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($result),
            \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($save->handle($actor, $scope[1], $scope[2], 0, $requestId, $input)));
        self::assertSame('0', $result['cost_units']);
        self::assertSame('12', $result['quantities']['floor_area']['amount']);
        $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $fact = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === 'area'))[0];
        self::assertSame('user_input', $fact->origin);
        $proof = array_column($snapshot->evidence, null, 'id')[$fact->evidenceIds[0]];
        self::assertSame('input_payload.description_'.hash('sha256', $fact->entityId.'|area'), $proof->nativeReference);
        $decision = $models->decisionsForSelectedFacts(...[...$scope, [$fact->id]])[0];
        self::assertStringContainsString($proposal['proposal_id'], $decision->reason);
        self::assertStringContainsString($proposal['source_fact_id'], $decision->reason);
        self::assertStringContainsString($proposal['source_value_hash'], $decision->reason);
        self::assertStringContainsString($proposal['quote'], $decision->reason);
        self::assertStringContainsString($fact->entityId, $decision->reason);
        $assumptionInput = $input;
        unset($assumptionInput['entities'][0]['name']);
        $assumptionInput['entities'][0]['existing_entity_id'] = $fact->entityId;
        $assumptionInput['entities'][0]['parameters']['area']['basis'] = 'assumption';
        $assumed = $save->handle($actor, $scope[1], $scope[2], 1, (string) Str::uuid(), $assumptionInput);
        self::assertSame('estimated', $assumed['quantities']['floor_area']['source']);
        $current = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        self::assertSame('user_assumption', array_values(array_filter($current->facts, static fn ($fact): bool => $fact->type === 'area'))[0]->origin);
        self::assertLessThanOrEqual(1000, strlen($models->decisionsForSelectedFacts(...[...$scope, $assumed['fact_ids']])[0]->reason));
        self::assertCount(1, array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === 'evaluation_answer'));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->count());
    }

    public function test_long_whitespace_description_is_not_an_unconfirmable_proposal_and_manual_input_still_works(): void
    {
        [$actor, $session] = $this->fixture();
        config(['estimate-generation.universal_enabled' => true]);
        $session->update(['input_payload' => ['evaluation_mode' => 'universal', 'description' => 'Площадь'.str_repeat(' ', 900).'12 м²']]);
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\InitialProjectFactsWriter::class)->write($session, $actor);
        $list = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetDescriptionNumericProposals::class)->handle($actor, (int) $session->project_id, (int) $session->id);
        self::assertSame([], $list['proposals']);
        $result = app(SaveManualProjectFacts::class)->handle($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid(),
            ['entities' => [$this->entity('room', 'room-1', ['area' => '12'], 'm2')]]);
        self::assertSame('12', $result['quantities']['floor_area']['amount']);
        self::assertSame('0', $result['cost_units']);
    }

    public function test_reparenting_invalidates_both_coverages_and_only_changes_relation_facts(): void
    {
        [$actor, $session] = $this->fixture();
        config(['estimate-generation.universal_enabled' => true]);
        $session->update(['input_payload' => ['evaluation_mode' => 'universal']]);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $save = app(SaveManualProjectFacts::class);
        $save->handle($actor, $scope[1], $scope[2], 0, (string) Str::uuid(), ['entities' => [
            [...$this->entity('wall', 'wall-1', ['length' => '3', 'height' => '2']), 'coverage' => ['wall_openings' => 'incomplete']],
            [...$this->entity('wall', 'wall-2', ['length' => '4', 'height' => '2']), 'coverage' => ['wall_openings' => 'incomplete']],
            [...$this->entity('opening', 'opening-1', ['width' => '1', 'height' => '2']), 'parent_key' => 'wall-1'],
        ]]);
        $models = app(ProjectModelRepository::class);
        $entities = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot']->entities;
        $byKey = [];
        foreach ($entities as $entity) {
            $byKey[$entity->attributes['identity']['manual_key']] = $entity;
        }
        $get = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetGeometryInventory::class);
        $savedQuantity = null;
        foreach (['wall-1' => [1, '4'], 'wall-2' => [3, '6']] as $key => [$stateVersion, $amount]) {
            $listing = $get->handle($actor, $scope[1], $scope[2], ['parent_entity_id' => $byKey[$key]->id, 'relation' => 'wall_openings']);
            $child = array_values(array_filter($listing['entities'], static fn ($entity): bool => $entity['entity_id'] === $byKey['opening-1']->id))[0];
            if ($key === 'wall-2') {
                self::assertSame($byKey['wall-1']->id, $child['parent_entity_id']);
            }
            $linked = $save->handle($actor, $scope[1], $scope[2], $stateVersion, (string) Str::uuid(), ['entities' => [[
                'key' => 'parent', 'type' => 'opening', 'existing_entity_id' => $child['entity_id'], 'parent_choice' => [
                    'relation' => 'wall_openings', 'parent_entity_id' => $byKey[$key]->id, 'inventory_fingerprint' => $listing['inventory']['fingerprint'],
                    'child_fingerprint' => $child['fingerprint'], 'reason' => 'Проверена принадлежность проёма выбранной стене',
                ],
            ]]]);
            self::assertSame([], $linked['quantities']);
            if ($savedQuantity !== null) {
                self::assertSame([], $models->currentDerivedQuantities(...[...$scope, $savedQuantity->sourceVersion]));
                try {
                    $models->appendDerivedQuantities([$savedQuantity]);
                    self::fail('Stale relation replay was persisted.');
                } catch (\InvalidArgumentException $error) {
                    self::assertMatchesRegularExpression('/Geometry (?:relation|coverage).*stale/', $error->getMessage());
                }
            }
            $current = $get->handle($actor, $scope[1], $scope[2], ['parent_entity_id' => $byKey[$key]->id, 'relation' => 'wall_openings']);
            $result = $save->handle($actor, $scope[1], $scope[2], $stateVersion + 1, (string) Str::uuid(), ['entities' => [[
                'key' => 'coverage', 'type' => 'wall', 'existing_entity_id' => $byKey[$key]->id,
                'coverage' => ['wall_openings' => 'covered_with_entities'], 'coverage_fingerprints' => ['wall_openings' => $current['inventory']['fingerprint']],
            ]]]);
            $quantityKey = 'quantity:wall_net_area:'.$byKey[$key]->id;
            self::assertArrayHasKey($quantityKey, $result['quantities'], json_encode($result['warnings'], JSON_THROW_ON_ERROR));
            self::assertSame($amount, $result['quantities'][$quantityKey]['amount']);
            $savedQuantity = $this->persistWallPreview($scope, $models, $byKey[$key], $byKey['opening-1'], $result['quantities'][$quantityKey]);
        }
        $after = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        self::assertEquals($entities, $after->entities);
        $relations = array_values(array_filter($after->facts, static fn ($fact): bool => $fact->type === 'geometry_parent_wall_openings'));
        self::assertCount(1, $relations);
        self::assertSame(2, $relations[0]->version);
        self::assertNotNull($relations[0]->supersedesFactId);
        self::assertSame($byKey['wall-2']->id, $relations[0]->value['parent_entity_id']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $scope[2])->count());
    }

    public function test_legacy_native_dimensions_reach_net_wall_area_with_confirmed_parent_and_coverage(): void
    {
        [$actor, $session] = $this->fixture();
        config(['estimate-generation.universal_enabled' => true]);
        $session->update(['input_payload' => ['evaluation_mode' => 'universal']]);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $document = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument::query()->create([
            'session_id' => $scope[2], 'organization_id' => $scope[0], 'project_id' => $scope[1], 'user_id' => $actor->id,
            'filename' => 'wall-openings.xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'storage_path' => 'org-'.$scope[0].'/fixture-wall-openings.xlsx', 'file_size_bytes' => 100,
            'checksum_sha256' => str_repeat('d', 64), 'status' => 'ready', 'page_count' => 1,
        ]);
        $version = \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion::fromDocument($document);
        $context = new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext(
            1, ...[...$scope, (int) $document->id, \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType::SpreadsheetSheet,
                1, $version, [], $document->storage_path, $document->mime_type, $document->filename, 'fixture-claim', 1, 0, 'draft']);
        $native = ['sheet' => 'Стены', 'header_cells' => [
            ['address' => 'A1', 'value' => 'Объект'], ['address' => 'B1', 'value' => 'Параметр'],
            ['address' => 'C1', 'value' => 'Значение'], ['address' => 'D1', 'value' => 'Ед. изм.'],
        ], 'cells' => []];
        foreach ([['wall.w1', 'wall_length', '3', 'm'], ['wall.w1', 'wall_height', '2', 'm']] as $index => $row) {
            foreach ($row as $column => $value) {
                $native['cells'][] = ['address' => chr(65 + $column).($index + 2), 'value' => $value, 'raw_value' => $value, 'formula' => null];
            }
        }
        $publication = (new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\NativeNumericFactFactory)->spreadsheet($context, $native);
        app(\App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter::class)
            ->writeArbitration($publication->claims, $publication->decisions, (int) $document->id, 1, $publication->numericSources);
        $openingDocument = $document->replicate();
        $openingDocument->checksum_sha256 = str_repeat('f', 64);
        $openingDocument->filename = 'openings.xlsx';
        $openingDocument->save();
        $context = new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext(
            1, ...[...$scope, (int) $openingDocument->id, \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType::SpreadsheetSheet,
                1, 'sha256:'.str_repeat('f', 64), [], $openingDocument->storage_path, $openingDocument->mime_type, $openingDocument->filename, 'fixture-claim', 1, 0, 'draft']);
        $native['cells'] = [];
        foreach ([['opening.o1', 'opening_width', '1', 'm'], ['opening.o1', 'opening_height', '2', 'm']] as $index => $row) {
            foreach ($row as $column => $value) {
                $native['cells'][] = ['address' => chr(65 + $column).($index + 2), 'value' => $value, 'raw_value' => $value, 'formula' => null];
            }
        }
        $publication = (new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\NativeNumericFactFactory)->spreadsheet($context, $native);
        app(\App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter::class)
            ->writeArbitration($publication->claims, $publication->decisions, (int) $openingDocument->id, 1, $publication->numericSources);
        $models = app(ProjectModelRepository::class);
        $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        self::assertEqualsCanonicalizing(['length', 'height', 'width', 'height'], array_map(static fn ($fact): string => $fact->type, $snapshot->facts));
        $wall = array_values(array_filter($snapshot->entities, static fn ($entity): bool => $entity->type === 'wall'))[0];
        $opening = array_values(array_filter($snapshot->entities, static fn ($entity): bool => $entity->type === 'opening'))[0];
        $get = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetGeometryInventory::class);
        $query = ['parent_entity_id' => $wall->id, 'relation' => 'wall_openings'];
        $listing = $get->handle($actor, $scope[1], $scope[2], $query);
        self::assertSame([$opening->id], $listing['inventory']['unassigned_ids']);
        $child = array_values(array_filter($listing['entities'], static fn ($entity): bool => $entity['entity_id'] === $opening->id))[0];
        $coverage = ['entities' => [['key' => 'wall-review', 'type' => 'wall', 'existing_entity_id' => $wall->id,
            'coverage' => ['wall_openings' => 'incomplete'], 'coverage_fingerprints' => ['wall_openings' => $listing['inventory']['fingerprint']]]]];
        $save = app(SaveManualProjectFacts::class);
        $save->handle($actor, $scope[1], $scope[2], 0, (string) Str::uuid(), $coverage);
        $input = ['entities' => [['key' => 'opening-parent', 'type' => 'opening', 'existing_entity_id' => $opening->id,
            'parent_choice' => ['relation' => 'wall_openings', 'parent_entity_id' => $wall->id,
                'inventory_fingerprint' => $listing['inventory']['fingerprint'], 'child_fingerprint' => $child['fingerprint'],
                'reason' => 'Принадлежность проёма проверена по исходному листу']]]];
        $requestId = (string) Str::uuid();
        $result = $save->handle($actor, $scope[1], $scope[2], 1, $requestId, $input);
        self::assertSame('0', $result['cost_units']);
        self::assertSame([], $result['quantities']);
        self::assertEquals($result, $save->handle($actor, $scope[1], $scope[2], 1, $requestId, $input));
        $after = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        self::assertEquals($opening, array_values(array_filter($after->entities, static fn ($entity): bool => $entity->id === $opening->id))[0]);
        $link = array_values(array_filter($after->facts, static fn ($fact): bool => $fact->type === 'geometry_parent_wall_openings'))[0];
        self::assertSame($opening->sourceVersion, $link->sourceVersion);
        self::assertNotSame($wall->sourceVersion, $link->sourceVersion);
        self::assertSame($wall->id, $link->value['parent_entity_id']);
        self::assertCount(1, $models->decisionsForSelectedFacts(...[...$scope, [$link->id]]));
        $updated = $get->handle($actor, $scope[1], $scope[2], $query);
        self::assertSame([$opening->id], $updated['inventory']['member_ids']);
        self::assertSame([], $updated['inventory']['unassigned_ids']);
        self::assertNotSame($listing['inventory']['fingerprint'], $updated['inventory']['fingerprint']);
        $coverage['entities'][0]['coverage']['wall_openings'] = 'covered_with_entities';
        try {
            $save->handle($actor, $scope[1], $scope[2], 2, (string) Str::uuid(), $coverage);
            self::fail('Old coverage fingerprint survived a parent decision.');
        } catch (ValidationException) {
            self::assertSame(2, $session->fresh()->state_version);
        }
        $coverage['entities'][0]['coverage_fingerprints']['wall_openings'] = $updated['inventory']['fingerprint'];
        $confirmed = $save->handle($actor, $scope[1], $scope[2], 2, (string) Str::uuid(), $coverage);
        self::assertArrayHasKey('net_wall_area', $confirmed['quantities'], json_encode($confirmed['warnings'], JSON_THROW_ON_ERROR));
        self::assertSame('4', $confirmed['quantities']['net_wall_area']['amount']);
        self::assertContains($link->evidenceIds[0], $confirmed['quantities']['net_wall_area']['evidence_ids']);
        self::assertStringContainsString($link->id, json_encode($confirmed['quantities']['net_wall_area']['formula_inputs'], JSON_THROW_ON_ERROR));
        $draft = ['local_estimates' => [['sections' => [['work_items' => [[
            'key' => 'wall:1', 'quantity' => '4', 'unit' => 'm2', 'metadata' => ['quantity_key' => 'net_wall_area'],
            'quantity_evidence' => $confirmed['quantities']['net_wall_area'],
        ]]]]]]];
        $applyGuard = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Apply\CurrentDraftQuantityProofGuard::class);
        DB::transaction(fn () => $applyGuard->assertCurrent(...[...$scope, $draft]));
        self::addToAssertionCount(1);
        $derived = $this->persistWallPreview($scope, $models, $wall, $opening, $confirmed['quantities']['net_wall_area']);
        self::assertSame('4', $derived->value);
        $models->replaceDerivedQuantityProjection(...[...$scope, $wall->sourceVersion, [$derived], []]);
        self::assertSame(1, DB::table('estimate_generation_project_model_derived_quantities')->where('session_id', $scope[2])->count());
        self::assertSame(2, DB::table('estimate_generation_project_model_derived_operands')->where('session_id', $scope[2])->distinct()->count('source_version'));
        self::assertSame('4', $models->currentDerivedQuantities(...[...$scope, $wall->sourceVersion])[0]->value);
        foreach (['child_fingerprint', 'inventory_fingerprint', 'parent_entity_id'] as $field) {
            $invalid = $input;
            $invalid['entities'][0]['parent_choice'][$field] = str_repeat('f', 64);
            try {
                $save->handle($actor, $scope[1], $scope[2], 3, (string) Str::uuid(), $invalid);
                self::fail('Invalid parent decision was accepted.');
            } catch (ValidationException) {
                self::assertSame(3, $session->fresh()->state_version);
            }
        }
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $scope[2])->count());
        config(['estimate-generation.universal_enabled' => false]);
        try {
            $save->handle($actor, $scope[1], $scope[2], 3, (string) Str::uuid(), $input);
            self::fail('Disabled universal mode accepted a parent decision.');
        } catch (ValidationException) {
            self::assertSame(3, $session->fresh()->state_version);
        }
        config(['estimate-generation.universal_enabled' => true]);
        $coverage['entities'][0]['coverage']['wall_openings'] = 'incomplete';
        $save->handle($actor, $scope[1], $scope[2], 3, (string) Str::uuid(), $coverage);
        try {
            DB::transaction(fn () => $applyGuard->assertCurrent(...[...$scope, $draft]));
            self::fail('Incomplete coverage reused the old apply basis.');
        } catch (ValidationException) {
            self::addToAssertionCount(1);
        }
        self::assertSame([], $models->currentDerivedQuantities(...[...$scope, $wall->sourceVersion]));
        self::assertSame('4', $models->derivedQuantityHistory(...[...$scope, $wall->sourceVersion, $derived->logicalId])[0]->value);
        try {
            $models->appendDerivedQuantities([$derived]);
            self::fail('A result with outdated coverage was persisted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('coverage', $error->getMessage());
        }
        $coverage['entities'][0]['coverage']['wall_openings'] = 'covered_with_entities';
        $reconfirmed = $save->handle($actor, $scope[1], $scope[2], 4, (string) Str::uuid(), $coverage);
        $this->persistWallPreview($scope, $models, $wall, $opening, $reconfirmed['quantities']['net_wall_area']);
        self::assertSame('4', $models->currentDerivedQuantities(...[...$scope, $wall->sourceVersion])[0]->value);
        $openingDocument->update(['ignored_at' => now(), 'status' => 'ignored']);
        self::assertSame([], app(\App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService::class)->previewInput(...$scope)['quantities']);
        self::assertSame([], $models->currentDerivedQuantities(...[...$scope, $wall->sourceVersion]));
        self::assertSame([], $models->currentDerivedQuantitiesForFormulaVersion(...[...$scope, '1']));
        self::assertSame('4', $models->derivedQuantityHistory(...[...$scope, $wall->sourceVersion, $derived->logicalId])[0]->value);
    }

    public function test_geometry_inventory_keeps_native_document_versions_and_locators_separate(): void
    {
        [$actor, $session] = $this->fixture();
        config(['estimate-generation.universal_enabled' => true]);
        $session->update(['input_payload' => ['evaluation_mode' => 'universal']]);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $documents = [];
        foreach (['d', 'f'] as $checksum) {
            $document = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument::query()->create([
                'session_id' => $scope[2], 'organization_id' => $scope[0], 'project_id' => $scope[1], 'user_id' => $actor->id,
                'filename' => 'wall-'.$checksum.'.xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'storage_path' => 'org-'.$scope[0].'/wall-'.$checksum.'.xlsx', 'file_size_bytes' => 100,
                'checksum_sha256' => str_repeat($checksum, 64), 'status' => 'ready', 'page_count' => 1,
            ]);
            $documents[] = $document;
            $context = new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext(
                1, ...[...$scope, (int) $document->id, \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType::SpreadsheetSheet,
                    1, 'sha256:'.str_repeat($checksum, 64), [], $document->storage_path, $document->mime_type, $document->filename, 'fixture-claim', 1, 0, 'draft']);
            $native = ['sheet' => 'Стены', 'header_cells' => [
                ['address' => 'A1', 'value' => 'Объект'], ['address' => 'B1', 'value' => 'Параметр'],
                ['address' => 'C1', 'value' => 'Значение'], ['address' => 'D1', 'value' => 'Ед. изм.'],
            ], 'cells' => []];
            foreach ([['wall.w1', 'length', '3', 'm'], ['wall.w1', 'height', '2', 'm']] as $index => $row) {
                foreach ($row as $column => $value) {
                    $native['cells'][] = ['address' => chr(65 + $column).($index + 2), 'value' => $value, 'raw_value' => $value, 'formula' => null];
                }
            }
            $publication = (new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\NativeNumericFactFactory)->spreadsheet($context, $native);
            app(\App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter::class)
                ->writeArbitration($publication->claims, $publication->decisions, (int) $document->id, 1, $publication->numericSources);
        }
        $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        self::assertCount(2, $snapshot->entities);
        foreach ($snapshot->entities as $wall) {
            $document = array_values(array_filter($documents, static fn ($source): bool => 'sha256:'.$source->checksum_sha256 === $wall->sourceVersion))[0];
            $listing = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetGeometryInventory::class)
                ->handle($actor, $scope[1], $scope[2], ['parent_entity_id' => $wall->id, 'relation' => 'wall_openings']);
            self::assertCount(1, $listing['entities']);
            self::assertCount(2, $listing['entities'][0]['evidence']);
            foreach ($listing['entities'][0]['evidence'] as $proof) {
                self::assertSame($wall->sourceVersion, $proof['source_version']);
                self::assertSame((int) $document->id, $proof['document_id']);
            }
        }
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $scope[2])->count());
    }

    public function test_native_wall_inventory_can_be_confirmed_without_recreating_the_document_entity(): void
    {
        [$actor, $session] = $this->fixture();
        config(['estimate-generation.universal_enabled' => true]);
        $session->update(['input_payload' => ['evaluation_mode' => 'universal']]);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $document = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument::query()->create([
            'session_id' => $scope[2], 'organization_id' => $scope[0], 'project_id' => $scope[1], 'user_id' => $actor->id,
            'filename' => 'walls.xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'storage_path' => 'org-'.$scope[0].'/fixture-walls.xlsx', 'file_size_bytes' => 100,
            'checksum_sha256' => str_repeat('d', 64), 'status' => 'ready', 'page_count' => 1,
        ]);
        $version = \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion::fromDocument($document);
        $context = new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext(
            1, ...[...$scope, (int) $document->id, \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType::SpreadsheetSheet,
                1, $version, [], $document->storage_path, $document->mime_type, $document->filename, 'fixture-claim', 1, 0, 'draft']);
        $native = ['sheet' => 'Стены', 'header_cells' => [
            ['address' => 'A1', 'value' => 'Объект'], ['address' => 'B1', 'value' => 'Параметр'],
            ['address' => 'C1', 'value' => 'Значение'], ['address' => 'D1', 'value' => 'Ед. изм.'],
        ], 'cells' => []];
        foreach ([['wall.w1', 'length', '3', 'm'], ['wall.w1', 'height', '2', 'm']] as $index => $row) {
            foreach ($row as $column => $value) {
                $native['cells'][] = ['address' => chr(65 + $column).($index + 2), 'value' => $value, 'raw_value' => $value, 'formula' => null];
            }
        }
        $publication = (new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\NativeNumericFactFactory)->spreadsheet($context, $native);
        app(\App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter::class)
            ->writeArbitration($publication->claims, $publication->decisions, (int) $document->id, 1, $publication->numericSources);
        $models = app(ProjectModelRepository::class);
        $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        self::assertCount(1, $snapshot->entities);
        self::assertCount(2, $snapshot->facts);
        $wall = $snapshot->entities[0];
        $inventory = \App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory::capture($snapshot, $wall->id, 'wall_openings',
            \App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory::currentDocumentVersions($snapshot));
        $sources = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetGeometryInventory::class);
        $listing = $sources->handle($actor, $scope[1], $scope[2], ['parent_entity_id' => $wall->id, 'relation' => 'wall_openings']);
        self::assertSame($inventory, $listing['inventory']);
        self::assertFalse($listing['inventory']['completeness_confirmed']);
        self::assertSame($wall->id, $listing['entities'][0]['entity_id']);
        self::assertSame($version, $listing['entities'][0]['source_version']);
        self::assertCount(2, $listing['entities'][0]['evidence']);
        self::assertSame((int) $document->id, $listing['entities'][0]['evidence'][0]['document_id']);
        self::assertStringContainsString('xlsx:sheet:Стены!', $listing['entities'][0]['evidence'][0]['native_reference']);
        self::assertSame(1, $listing['pagination']['total']);
        $input = ['entities' => [['key' => 'review-wall', 'type' => 'wall', 'existing_entity_id' => $wall->id,
            'coverage' => ['wall_openings' => 'covered_empty'], 'coverage_fingerprints' => ['wall_openings' => $inventory['fingerprint']]]]];
        $save = app(SaveManualProjectFacts::class);
        foreach ([[], ['wall_openings' => str_repeat('f', 64)]] as $invalidPin) {
            $invalid = $input;
            $invalid['entities'][0]['coverage_fingerprints'] = $invalidPin;
            try {
                $save->handle($actor, $scope[1], $scope[2], 0, (string) Str::uuid(), $invalid);
                self::fail('Unpinned or stale inventory was accepted.');
            } catch (ValidationException) {
                self::assertSame(0, $session->fresh()->state_version);
                self::assertCount(2, $models->snapshot(...$scope)->facts);
            }
        }
        $requestId = (string) Str::uuid();
        $result = $save->handle($actor, $scope[1], $scope[2], 0, $requestId, $input);
        self::assertSame('6', $result['quantities']['net_wall_area']['amount']);
        self::assertSame('0', $result['cost_units']);
        self::assertCount(1, $models->snapshot(...$scope)->entities);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $scope[2])->count());
        self::assertEquals($result, $save->handle($actor, $scope[1], $scope[2], 0, $requestId, $input));
        self::assertSame(1, $session->fresh()->state_version);
        foreach ([['opening.o1', 'width', '1', 'm'], ['opening.o1', 'height', '2', 'm']] as $index => $row) {
            foreach ($row as $column => $value) {
                $native['cells'][] = ['address' => chr(65 + $column).($index + 4), 'value' => $value, 'raw_value' => $value, 'formula' => null];
            }
        }
        $more = (new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\NativeNumericFactFactory)->spreadsheet($context, $native);
        app(\App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter::class)
            ->writeArbitration($more->claims, $more->decisions, (int) $document->id, 1, $more->numericSources);
        $current = $models->snapshot(...$scope);
        $opening = array_values(array_filter($current->entities, static fn ($entity): bool => $entity->type === 'opening'))[0];
        $corrected = $save->handle($actor, $scope[1], $scope[2], 1, (string) Str::uuid(), ['entities' => [
            ['key' => 'wall-correction', 'type' => 'wall', 'existing_entity_id' => $wall->id, 'parameters' => [
                'length' => ['value' => '3', 'unit' => 'm', 'basis' => 'measurement'], 'height' => ['value' => '2', 'unit' => 'm', 'basis' => 'measurement']]],
            ['key' => 'opening-correction', 'type' => 'opening', 'existing_entity_id' => $opening->id, 'parameters' => [
                'width' => ['value' => '1', 'unit' => 'm', 'basis' => 'measurement'], 'height' => ['value' => '2', 'unit' => 'm', 'basis' => 'measurement']]],
        ]]);
        self::assertArrayNotHasKey('net_wall_area', $corrected['quantities']);
        $correctedFacts = $models->snapshot(...$scope)->facts;
        self::assertSame([], array_values(array_filter($correctedFacts, static fn ($fact): bool => $fact->origin === 'document')));
        $ambiguous = $sources->handle($actor, $scope[1], $scope[2], ['parent_entity_id' => $wall->id, 'relation' => 'wall_openings']);
        self::assertCount(1, $ambiguous['inventory']['unassigned_ids']);
        self::assertSame([$opening->id], $ambiguous['inventory']['unassigned_ids']);
        self::assertContains($wall->id, array_column($ambiguous['entities'], 'entity_id'));
        self::assertCount(2, $ambiguous['entities'][0]['evidence'], 'Original document locators survive user corrections.');
        self::assertNotSame($inventory['fingerprint'], $ambiguous['inventory']['fingerprint']);
        $input['entities'][0]['coverage_fingerprints']['wall_openings'] = $ambiguous['inventory']['fingerprint'];
        try {
            $save->handle($actor, $scope[1], $scope[2], 2, (string) Str::uuid(), $input);
            self::fail('Unassigned openings were declared absent.');
        } catch (ValidationException) {
            self::assertSame(2, $session->fresh()->state_version);
        }
        $input['entities'][0]['coverage']['wall_openings'] = 'incomplete';
        $incomplete = $save->handle($actor, $scope[1], $scope[2], 2, (string) Str::uuid(), $input);
        self::assertArrayNotHasKey('net_wall_area', $incomplete['quantities']);
        $save->handle($actor, $scope[1], $scope[2], 3, (string) Str::uuid(), ['entities' => [['key' => 'manual-room', 'type' => 'room', 'name' => 'Помещение ручного ввода']]]);
        $document->update(['checksum_sha256' => str_repeat('e', 64)]);
        $hidden = $sources->handle($actor, $scope[1], $scope[2]);
        self::assertNotContains($wall->id, array_column($hidden['entities'], 'entity_id'));
        self::assertSame(1, $hidden['pagination']['total'], 'Independent manual input must remain available.');
        $preview = app(\App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService::class)->previewInput(...$scope);
        self::assertArrayNotHasKey('net_wall_area', $preview['quantities']);
        $duplicate = $document->replicate();
        $duplicate->status = 'queued';
        $duplicate->checksum_sha256 = str_repeat('d', 64);
        $duplicate->save();
        self::assertNotContains($wall->id, array_column($sources->handle($actor, $scope[1], $scope[2])['entities'], 'entity_id'),
            'An unprocessed duplicate checksum must not revive the replaced document entity.');
        $document->update(['checksum_sha256' => str_repeat('d', 64), 'status' => 'ignored', 'ignored_at' => now()]);
        $excluded = array_column($sources->handle($actor, $scope[1], $scope[2])['entities'], 'entity_id');
        self::assertNotContains($wall->id, $excluded, 'An equal queued document cannot replace the excluded source identity.');
        self::assertNotContains($opening->id, $excluded);
        try {
            $save->handle($actor, $scope[1], $scope[2], 4, (string) Str::uuid(), $input);
            self::fail('Old source inventory was accepted after its checksum changed.');
        } catch (ValidationException) {
            self::assertSame(4, $session->fresh()->state_version);
        }
    }

    public function test_inventory_is_free_and_hidden_for_legacy_and_rejects_foreign_organization_access(): void
    {
        [$actor, $session] = $this->fixture();
        config(['estimate-generation.universal_enabled' => true]);
        $getter = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetGeometryInventory::class);
        $result = $getter->handle($actor, (int) $session->project_id, (int) $session->id);
        self::assertFalse($result['available']);
        self::assertSame([], $result['entities']);
        self::assertSame('0', $result['cost_units']);
        self::assertSame(0, $session->fresh()->state_version);
        $actor->update(['current_organization_id' => Organization::factory()->create()->id]);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $getter->handle($actor, (int) $session->project_id, (int) $session->id);
    }

    public function test_manual_engineering_count_replays_and_rejects_fractional_units_without_ai(): void
    {
        [$actor, $session] = $this->fixture();
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $input = ['entities' => [['key' => 'pump-1', 'type' => 'equipment', 'name' => 'Насос P1',
            'parameters' => ['equipment_count' => ['value' => '3', 'unit' => 'count', 'basis' => 'measurement']]]]];
        $save = app(SaveManualProjectFacts::class);
        $request = (string) Str::uuid();
        $result = $save->handle($actor, $scope[1], $scope[2], 0, $request, $input);
        self::assertSame('3', $result['quantities']['equipment.count']['amount']);
        self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($result),
            \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($save->handle($actor, $scope[1], $scope[2], 0, $request, $input)));
        $snapshot = app(ProjectModelRepository::class)->snapshot(...$scope);
        $entity = $snapshot->entities[0];
        $answer = ['entities' => [['key' => 'answer', 'type' => 'equipment', 'existing_entity_id' => $entity->id,
            'parameters' => ['equipment_count' => ['value' => '4', 'unit' => 'count', 'basis' => 'input']]]]];
        $updated = $save->handle($actor, $scope[1], $scope[2], 1, (string) Str::uuid(), $answer);
        self::assertSame('4', $updated['quantities']['equipment.count']['amount']);
        self::assertCount(1, app(ProjectModelRepository::class)->snapshot(...$scope)->entities);
        $answer['entities'][0]['parameters']['equipment_count']['value'] = '1.5';
        $before = DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count();
        try {
            $save->handle($actor, $scope[1], $scope[2], 2, (string) Str::uuid(), $answer);
            self::fail('Fractional equipment count accepted.');
        } catch (ValidationException) {
            self::assertSame($before, DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count());
            self::assertSame(2, $session->fresh()->state_version);
        }
    }

    public function test_wall_openings_roof_floor_and_excavation_are_calculated_from_manual_facts_without_ai(): void
    {
        [$actor, $session] = $this->fixture();
        $input = ['entities' => [
            [...$this->entity('room', 'room-1', ['length' => '5000', 'width' => '4000'], 'mm'), 'floor' => '1', 'zone' => 'А', 'coverage' => ['room_walls' => 'covered_with_entities']],
            [...$this->entity('wall', 'wall-1', ['length' => '10', 'height' => '3']), 'parent_key' => 'room-1', 'floor' => '1', 'zone' => 'А', 'coverage' => ['wall_openings' => 'covered_with_entities']],
            [...$this->entity('opening', 'door-1', ['width' => '1', 'height' => '2']), 'parent_key' => 'wall-1', 'floor' => '1', 'zone' => 'А'],
            [...$this->entity('opening', 'door-2', ['width' => '1.5', 'height' => '2']), 'parent_key' => 'wall-1', 'floor' => '1', 'zone' => 'А'],
            ['key' => 'roof-1', 'type' => 'roof', 'coverage' => ['roof_facets' => 'covered_with_entities', 'roof_openings' => 'covered_empty']],
            [...$this->entity('roof_facet', 'facet-1', ['plan_area' => '40', 'slope_rise' => '3', 'slope_run' => '4']), 'parent_key' => 'roof-1',
                'parameters' => ['plan_area' => ['value' => '20', 'unit' => 'm2', 'basis' => 'input'], 'slope_rise' => ['value' => '3', 'unit' => 'm', 'basis' => 'input'], 'slope_run' => ['value' => '4', 'unit' => 'm', 'basis' => 'input']]],
            [...$this->entity('roof_facet', 'facet-2', []), 'parent_key' => 'roof-1',
                'parameters' => ['plan_area' => ['value' => '20', 'unit' => 'm2', 'basis' => 'input'], 'slope_rise' => ['value' => '3', 'unit' => 'm', 'basis' => 'input'], 'slope_run' => ['value' => '4', 'unit' => 'm', 'basis' => 'input']]],
            ['key' => 'site-1', 'type' => 'site', 'parameters' => ['area' => ['value' => '100', 'unit' => 'm2', 'basis' => 'input'], 'depth' => ['value' => '0.5', 'unit' => 'm', 'basis' => 'input']]],
        ]];
        $request = (string) Str::uuid();
        $save = app(SaveManualProjectFacts::class);
        $result = $save->handle($actor, (int) $session->project_id, (int) $session->id, 0, $request, $input);
        self::assertSame('20', $result['quantities']['floor_area']['amount']);
        self::assertSame('25', $result['quantities']['net_wall_area']['amount']);
        self::assertSame('50', $result['quantities']['roof_area']['amount']);
        self::assertSame('50', $result['quantities']['earthwork_volume']['amount']);
        self::assertSame('0', $result['cost_units']);
        $replay = $save->handle($actor, (int) $session->project_id, (int) $session->id, 0, $request, $input);
        self::assertEquals($result, $replay);
        self::assertSame(1, $session->fresh()->state_version);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->count());
    }

    public function test_unconfirmed_empty_openings_block_wall_area_and_confirmation_creates_a_new_fact_revision(): void
    {
        [$actor, $session] = $this->fixture();
        $save = app(SaveManualProjectFacts::class);
        $wall = $this->entity('wall', 'wall-1', ['length' => '10', 'height' => '3']);
        $result = $save->handle($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid(), ['entities' => [$wall]]);
        self::assertArrayNotHasKey('net_wall_area', $result['quantities']);
        self::assertSame('geometry_coverage_unknown', $result['warnings'][0]['inputs'][0]['code']);
        $wall['coverage'] = ['wall_openings' => 'covered_empty'];
        $wall['parameters']['length']['value'] = '12';
        $result = $save->handle($actor, (int) $session->project_id, (int) $session->id, 1, (string) Str::uuid(), ['entities' => [$wall]]);
        self::assertSame('36', $result['quantities']['net_wall_area']['amount']);
        $snapshot = app(ProjectModelRepository::class)->snapshot((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
        $length = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === 'length'));
        self::assertCount(1, $length);
        self::assertSame(2, $length[0]->version);
        self::assertNotNull($length[0]->supersedesFactId);
    }

    public function test_stale_request_foreign_scope_and_wrong_unit_cannot_write_manual_facts(): void
    {
        [$actor, $session] = $this->fixture();
        $save = app(SaveManualProjectFacts::class);
        $input = ['entities' => [$this->entity('room', 'room-1', ['length' => '3'], 'm2')]];
        try {
            $save->handle($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid(), $input);
            self::fail('Dimension mismatch accepted.');
        } catch (ValidationException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(0, DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_evidence')->where('session_id', $session->id)->count());
        try {
            $save->handle($actor, (int) $session->project_id, (int) $session->id, 9, (string) Str::uuid(), $input);
            self::fail('Stale mutation accepted.');
        } catch (StaleEstimateGenerationState) {
            self::addToAssertionCount(1);
        }
        $actor->current_organization_id = Organization::factory()->create()->id;
        $actor->save();
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $save->handle($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid(), $input);
    }

    public function test_assumed_size_is_marked_as_estimated_and_flat_roof_has_no_invented_slope(): void
    {
        [$actor, $session] = $this->fixture();
        $room = $this->entity('room', 'room-1', ['length' => '5', 'width' => '4']);
        $room['parameters']['width']['basis'] = 'assumption';
        $result = app(SaveManualProjectFacts::class)->handle($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid(), ['entities' => [
            $room, ['key' => 'roof-1', 'type' => 'roof', 'coverage' => ['roof_facets' => 'covered_with_entities', 'roof_openings' => 'covered_empty']],
            ['key' => 'facet-1', 'type' => 'roof_facet', 'parent_key' => 'roof-1', 'parameters' => [
                'plan_area' => ['value' => '40', 'unit' => 'm2', 'basis' => 'measurement'],
                'slope_rise' => ['value' => '0', 'unit' => 'm', 'basis' => 'measurement'],
                'slope_run' => ['value' => '4', 'unit' => 'm', 'basis' => 'measurement'],
            ]],
        ]]);
        self::assertSame('estimated', $result['quantities']['floor_area']['source']);
        self::assertCount(1, $result['quantities']['floor_area']['assumptions']);
        self::assertSame('40', $result['quantities']['roof_area']['amount']);
        self::assertSame('evidenced', $result['quantities']['roof_area']['source']);
    }

    private function entity(string $type, string $key, array $parameters, string $unit = 'm'): array
    {
        return ['key' => $key, 'type' => $type, 'parameters' => array_map(static fn (string $value): array => ['value' => $value, 'unit' => $unit, 'basis' => 'measurement'], $parameters)];
    }

    public function test_named_rooms_without_dimensions_are_current_confirmed_context_and_can_be_renamed_without_geometry(): void
    {
        [$actor, $session] = $this->fixture();
        $input = ['entities' => [
            ['key' => 'room-a', 'type' => 'room', 'name' => 'Кухня', 'floor' => '1'],
            ['key' => 'room-b', 'type' => 'room', 'name' => 'Кухня', 'floor' => '2'],
        ]];
        $save = app(SaveManualProjectFacts::class);
        $requestId = (string) Str::uuid();
        $result = $save->handle($actor, (int) $session->project_id, (int) $session->id, 0, $requestId, $input);
        self::assertEquals($result, $save->handle($actor, (int) $session->project_id, (int) $session->id, 0, $requestId, $input));
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $snapshot = $models->snapshot(...$scope);
        self::assertCount(2, $snapshot->entities);
        self::assertCount(2, $snapshot->facts);
        foreach ($snapshot->entities as $entity) {
            self::assertArrayNotHasKey('area_m2', $entity->attributes);
            self::assertArrayNotHasKey('polygon', $entity->attributes);
        }
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        foreach ($snapshot->facts as $fact) {
            self::assertSame('entity_identity', $fact->type);
            self::assertNull($fact->unit);
            self::assertSame('Кухня', $fact->value['name']);
            self::assertTrue((new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationChoiceProof)
                ->accepted($snapshot, $fact, array_column($decisions, null, 'selectedFactId')[$fact->id]));
        }
        $input['entities'][0]['name'] = 'Кухня после ремонта';
        $save->handle($actor, $scope[1], $scope[2], 1, (string) Str::uuid(), $input);
        $renamed = $models->snapshot(...$scope);
        self::assertCount(2, $renamed->entities);
        self::assertContains('Кухня после ремонта', array_map(static fn ($fact): string => $fact->value['name'], $renamed->facts));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame('0', $result['cost_units']);
        self::assertArrayNotHasKey('floor_area', $result['quantities']);
    }

    public function test_existing_entity_parameter_answer_preserves_identity_updates_only_that_parameter_and_replays_free(): void
    {
        [$actor, $session] = $this->fixture();
        $save = app(SaveManualProjectFacts::class);
        $save->handle($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid(), ['entities' => [
            [...$this->entity('room', 'room-1', ['length' => '5', 'width' => '4']), 'name' => 'Кухня', 'floor' => '2'],
        ]]);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $before = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $entity = $before->entities[0];
        $length = array_values(array_filter($before->facts, static fn ($fact): bool => $fact->type === 'length'))[0];
        $input = ['entities' => [['key' => 'answer', 'type' => 'room', 'existing_entity_id' => $entity->id,
            'parameters' => ['length' => ['value' => '6', 'unit' => 'm', 'basis' => 'measurement']]]]];
        $request = (string) Str::uuid();
        $result = $save->handle($actor, $scope[1], $scope[2], 1, $request, $input);
        self::assertSame('24', $result['quantities']['floor_area']['amount']);
        self::assertSame('0', $result['cost_units']);
        self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($result),
            \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode(
                $save->handle($actor, $scope[1], $scope[2], 1, $request, $input)));
        $after = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        self::assertCount(1, $after->entities);
        self::assertEquals($entity, $after->entities[0]);
        $updated = array_values(array_filter($after->facts, static fn ($fact): bool => $fact->type === 'length'))[0];
        self::assertSame($length->id, $updated->supersedesFactId);
        self::assertSame($length->version + 1, $updated->version);
        self::assertSame($entity->sourceVersion, $updated->sourceVersion);
        self::assertSame('6', $updated->value);
        self::assertSame('4', array_values(array_filter($after->facts, static fn ($fact): bool => $fact->type === 'width'))[0]->value);
        self::assertSame('Кухня', array_values(array_filter($after->facts, static fn ($fact): bool => $fact->type === 'entity_identity'))[0]->value['name']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_existing_entity_answer_rejects_other_scope_identity_changes_and_duplicate_targets_atomically(): void
    {
        [$actor, $session] = $this->fixture();
        $save = app(SaveManualProjectFacts::class);
        $save->handle($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid(),
            ['entities' => [$this->entity('room', 'room-1', ['length' => '5', 'width' => '4'])]]);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $entity = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001])['snapshot']->entities[0];
        $row = ['key' => 'answer', 'type' => 'room', 'existing_entity_id' => $entity->id,
            'parameters' => ['length' => ['value' => '6', 'unit' => 'm', 'basis' => 'measurement']]];
        $assertions = DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count();
        foreach ([[[...$row, 'existing_entity_id' => 'entity:foreign']], [[...$row, 'type' => 'wall']],
            [[...$row, 'name' => 'Подмена имени']], [$row, [...$row, 'key' => 'again']]] as $rows) {
            try {
                $save->handle($actor, $scope[1], $scope[2], 1, (string) Str::uuid(), ['entities' => $rows]);
                self::fail('An invalid existing-entity answer was accepted.');
            } catch (ValidationException) {
                self::assertSame(1, $session->fresh()->state_version);
                self::assertSame($assertions, DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count());
            }
        }
    }

    public function test_foundation_http_answer_preserves_element_scope_evidence_replay_and_free_preview(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config(['estimate-generation.universal_enabled' => true, 'jwt.secret' => str_repeat('test-key', 8), 'jwt.blacklist_enabled' => false]);
        [$actor, $session] = $this->fixture();
        $session->update(['input_payload' => ['evaluation_mode' => 'universal', 'selected_sections' => ['structures']]]);
        $project = Project::query()->findOrFail($session->project_id);
        app(AuthorizationService::class)->allows('can')->with(Mockery::type(User::class), 'estimate_generation.review',
            Mockery::on(static fn (array $context): bool => $context['project_id'] === (int) $project->id && is_int($context['organization_id'])))
            ->andReturnTrue();
        Route::bind('project', static fn (): Project => $project);
        Route::bind('session', static fn (): EstimateGenerationSession => $session->fresh());
        $route = '/_contract/foundation/projects/{project}/sessions/{session}';
        Route::post($route.'/initial-facts', [EstimateGenerationManualFactsController::class, 'store'])->middleware(['auth:api_admin', SubstituteBindings::class]);
        Route::get($route.'/geometry', [EstimateGenerationManualFactsController::class, 'inventory'])->middleware(['auth:api_admin', SubstituteBindings::class]);
        $url = str_replace(['{project}', '{session}'], [(string) $project->id, (string) $session->id], $route);
        $this->withToken(auth('api_admin')->login($actor));
        $outboundBeforeWrites = count(Http::recorded());
        $initial = ['state_version' => 0, 'request_id' => (string) Str::uuid(), 'entities' => [
            ['key' => 'beam', 'type' => 'foundation_beam', 'name' => 'Балка Б1', 'parameters' => [
                'length' => ['value' => '10', 'unit' => 'm', 'basis' => 'measurement'],
                'width' => ['value' => '250', 'unit' => 'mm', 'basis' => 'input']]],
            ['key' => 'slab', 'type' => 'foundation_slab', 'parameters' => [
                'area' => ['value' => '40', 'unit' => 'm2', 'basis' => 'measurement'],
                'thickness' => ['value' => '80', 'unit' => 'mm', 'basis' => 'input']]],
            $this->entity('site', 'excavation', ['depth' => '0.5']),
        ]];
        $missing = $this->postJson($url.'/initial-facts', $initial)->assertOk()->assertJsonPath('data.cost_units', '0')->json('data');
        self::assertArrayNotHasKey('foundation_volume', $missing['quantities']);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $before = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $beam = array_values(array_filter($before->entities, static fn ($entity): bool => ($entity->attributes['semantic_type'] ?? null) === 'foundation_beam'))[0];
        $questions = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationParameterQuestionBuilder)->candidates(
            [['key' => 'concrete', 'work_key' => 'foundation.concrete', 'entity_id' => $beam->id, 'unit' => 'm3', 'quantity' => null]], $before, $missing['warnings']);
        self::assertSame('height', $questions[0]['answer']['parameter']);
        self::assertSame('foundation_beam', $questions[0]['answer']['entity_type']);
        $this->getJson($url.'/geometry?include_manual=1')->assertOk()->assertJsonPath('data.pagination.total', 2);
        $native = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Review\EstimatePrerequisiteBacklog::class)->current($session->fresh());
        self::assertCount(1, $native['native_questions']['questions']);
        $answer = ['state_version' => 1, 'request_id' => (string) Str::uuid(), 'entities' => [
            ['key' => 'height-answer', 'type' => 'foundation_beam', 'existing_entity_id' => $beam->id,
                'native_question' => $native['native_questions']['questions'][0]['answer']['native_question'],
                'parameters' => ['height' => ['value' => '400', 'unit' => 'mm', 'basis' => 'measurement']]],
        ]];
        $malformed = $answer;
        unset($malformed['entities'][0]['native_question']['input_fingerprint']);
        $this->postJson($url.'/initial-facts', $malformed)->assertUnprocessable();
        self::assertSame(1, $session->fresh()->state_version);
        $result = $this->postJson($url.'/initial-facts', $answer)->assertOk()->assertJsonPath('data.cost_units', '0')->json('data');
        self::assertSame('1', $result['quantities']['quantity:foundation_beam_volume:'.$beam->id]['amount']);
        self::assertArrayNotHasKey('foundation_volume', $result['quantities']);
        $replayed = $this->postJson($url.'/initial-facts', $answer)->assertOk()->json('data');
        self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($result),
            \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($replayed));
        $after = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        self::assertCount(3, $after->entities);
        $height = array_values(array_filter($after->facts, static fn ($fact): bool => $fact->entityId === $beam->id && $fact->type === 'height'))[0];
        self::assertSame('400', $height->value);
        self::assertSame('mm', $height->unit);
        self::assertSame($beam->sourceVersion, $height->sourceVersion);
        $proofs = array_column($after->evidence, null, 'id');
        self::assertSame('user_input', $proofs[$height->evidenceIds[0]]->sourceType);
        $decision = $models->decisionsForSelectedFacts(...[...$scope, [$height->id]])[0];
        self::assertSame($height->id, $decision->selectedFactId);
        self::assertSame('user', $decision->actorType);
        $assertionCount = DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count();
        $wrong = [...$answer, 'state_version' => 2, 'request_id' => (string) Str::uuid()];
        $wrong['entities'][0]['parameters'] = ['depth' => ['value' => '0.5', 'unit' => 'm', 'basis' => 'assumption']];
        $this->postJson($url.'/initial-facts', $wrong)->assertUnprocessable();
        self::assertSame($assertionCount, DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count());
        self::assertSame(2, $session->fresh()->state_version);
        $foreign = User::factory()->create(['current_organization_id' => Organization::factory()->create()->id, 'is_active' => true]);
        $this->withToken(auth('api_admin')->login($foreign))->postJson($url.'/initial-facts', [...$answer, 'state_version' => 2, 'request_id' => (string) Str::uuid()])->assertNotFound();
        self::assertSame(2, $session->fresh()->state_version);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame($outboundBeforeWrites, count(Http::recorded()));
    }

    private function persistWallPreview(array $scope, ProjectModelRepository $models, \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity $wall,
        \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity $opening, array $quantity): \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantity
    {
        $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        $view = \App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryRelationOverlay::apply($snapshot, $decisions,
            \App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory::currentDocumentVersions($snapshot));
        $operands = [];
        foreach ($quantity['formula_inputs']['operands'] as $operand) {
            $operands[$operand['role']][] = $operand['fact_id'];
        }
        $ready = (new \App\BusinessModules\Addons\EstimateGeneration\Quantities\DerivedQuantityFactory)->derive($view, $decisions, [
            'quantity_id' => 'quantity:wall_net_area:'.$wall->id, 'entity_id' => $wall->id,
            'formula_identity' => 'wall_net_area', 'formula_version' => '1', 'output_unit' => 'm2', 'rounding_mode' => 'half_up', 'rounding_scale' => 2,
            'snapshot' => $quantity['formula_inputs']['snapshot_identity'], 'operands' => $operands,
            'geometry_inputs' => [['entity_id' => $opening->id, 'kind' => 'wall_opening', 'operands' => [
                'width' => $operands['opening_widths'][0], 'height' => $operands['opening_heights'][0],
            ]]],
        ]);
        self::assertTrue($ready->isReady(), json_encode($ready->unresolvedInputs, JSON_THROW_ON_ERROR));
        self::assertSame($quantity['amount'], $ready->quantity->value);
        $models->replaceDerivedQuantityProjection(...[...$scope, $wall->sourceVersion, [$ready->quantity], []]);

        return $ready->quantity;
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
            'user_id' => $actor->id, 'status' => 'draft', 'input_payload' => [], 'state_version' => 0]);

        return [$actor, $session];
    }
}
