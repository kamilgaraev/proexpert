<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\IgnoreEstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BindEvaluationWorkEntity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\ConfirmEvaluationScenario;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\ConfirmRoomIdentity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetRoomIdentitySources;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RecalculateUniversalEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationPriceSelection;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScenarioSet;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScopeSelection;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationWorkQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveSessionPricingSource;
use App\BusinessModules\Addons\EstimateGeneration\Application\Review\SelectNormativeCandidate;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\ComposedQuantityProvenance;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\WorkIntentSourceClassifier;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\RunEstimateAudit;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposerCorrection;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\LocalBenchmarkObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\RoomIdentityFactEligibility;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\NativeNumericFactProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationPackageController;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\CataloguePriceSnapshotReader;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\EstimateExportService;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Estimate;
use App\Models\EstimatePositionCatalog;
use App\Models\MeasurementUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;
use Tests\Support\EstimateGeneration\RecordedUniversalPipelineAdapter;

final class RecordedSpreadsheetPipelinePostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_generic_supplementary_equipment_keeps_specification_and_quantity_lineage_through_norm_selection(): void
    {
        $directory = sys_get_temp_dir().'/most-generic-equipment-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $path = $directory.'/equipment.xlsx';
        $recordingPath = $directory.'/recording.json';
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Оборудование')->fromArray([
            ['entity_key', 'parameter', 'value', 'unit'],
            ['equipment:pump-a', 'equipment_specification', 'Насос водяной по ведомости изготовителя', ''],
            ['equipment:pump-a', 'equipment_count', 7, 'шт'],
            ['equipment:pump-b', 'equipment_specification', 'Насос водяной по ведомости изготовителя', ''],
        ]);
        $catalog = $this->genericEquipmentCatalog();
        $source = [];
        $composerFacts = [];
        $quantityBinding = null;
        try {
            (new Xlsx($book))->save($path);
            $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/EstimateGeneration/universal-replay/xlsx-floor-finishing-v1.json')), true, flags: JSON_THROW_ON_ERROR);
            $fixture['description'] = 'Монтаж оборудования по ведомости на промышленной площадке. Технология требует отдельной проверки.';
            $fixture['selected_sections'] = ['equipment'];
            $fixture['initial_input'] = ['price_policy' => 'normative', 'regional_context' => $catalog['context']];
            $fixture['recordings'] = array_slice($fixture['recordings'], 0, 2);
            $intent = ['kind' => 'supplementary', 'work_key' => 'equipment.install', 'name' => 'Монтаж насоса по ведомости',
                'derived_quantity_id' => '__native_equipment_count_quantity_id__',
                'source_fact_ids' => ['__native_specification_fact_id__', '__native_count_fact_id__', '__native_other_specification_fact_id__'],
                'entity_id' => '__native_equipment_entity_id__', 'technology_package_candidate' => null,
                'unit' => 'count', 'work_section' => 'equipment', 'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => []];
            $fixture['recordings'][0]['response']['choices'][0]['message']['content'] = json_encode(['work_intents' => [$intent]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            file_put_contents($recordingPath, json_encode($fixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $adapter = new RecordedUniversalPipelineAdapter($this->app, new LocalBenchmarkObjectReader($directory),
                $recordingPath, hash_file('sha256', $recordingPath),
                static function (EstimateGenerationSession $session) use ($catalog): void {
                    $actor = User::query()->findOrFail($session->user_id);
                    app(SaveSessionPricingSource::class)->handle($actor, (int) $session->project_id, (int) $session->id,
                        ['request_id' => (string) Str::uuid(), 'state_version' => (int) $session->state_version,
                            'estimate_regional_price_version_id' => $catalog['context']['estimate_regional_price_version_id'],
                            'conditions' => ['as_of_date' => '2026-10-01', 'vat_mode' => 'not_applicable',
                                'delivery' => 'outside_scope', 'basis' => 'Синтетический источник для проверки привязки позиции к норме.']]);
                },
                static function (EstimateGenerationSession $session, \Psr\Http\Message\RequestInterface $request) use (&$source, &$composerFacts, &$quantityBinding): array {
                    $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
                    $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
                    $entities = array_column($snapshot->entities, null, 'id');
                    $byKey = [];
                    foreach ($snapshot->facts as $fact) {
                        if ($fact->origin === 'document' && in_array($fact->type, ['equipment_specification', 'equipment_count'], true)) {
                            self::assertSame('confirmed', $fact->status);
                            self::assertSame($scope, [$fact->organizationId, $fact->projectId, $fact->sessionId]);
                            $key = $entities[$fact->entityId]->attributes['properties']['source_entity_key'];
                            $byKey[$key][$fact->type] = $fact;
                        }
                    }
                    $source = ['specification' => $byKey['equipment:pump-a']['equipment_specification'],
                        'count' => $byKey['equipment:pump-a']['equipment_count'],
                        'other' => $byKey['equipment:pump-b']['equipment_specification']];
                    $wire = json_decode((string) $request->getBody(), true, 128, JSON_THROW_ON_ERROR);
                    $project = null;
                    foreach ($wire['messages'] as $message) {
                        if ($message['role'] === 'user') {
                            $project = json_decode($message['content'], true, 128, JSON_THROW_ON_ERROR)['project'] ?? null;
                        }
                    }
                    self::assertIsArray($project);
                    self::assertSame(array_combine(['organization_id', 'project_id', 'session_id'], $scope), $project['scope']);
                    $composerFacts = $project['facts'];
                    self::assertSame([], array_diff(array_column($source, 'id'), array_column($composerFacts, 'id')));
                    $quantities = array_values(array_filter($project['derived_quantities'], static fn (array $quantity): bool =>
                        ($quantity['formula_key'] ?? null) === 'equipment_count' && ($quantity['entity_id'] ?? null) === $source['count']->entityId
                        && ($quantity['operand_fact_ids'] ?? []) === [$source['count']->id] && ($quantity['review_blockers'] ?? []) === []));
                    self::assertCount(1, $quantities);
                    self::assertSame('count', $quantities[0]['unit']);
                    self::assertSame('7', $source['count']->value);
                    $quantityBinding = (new ComposedQuantityProvenance($project['derived_quantities'], $composerFacts))->binding([
                        'work_key' => 'equipment.install', 'derived_quantity_id' => $quantities[0]['id'],
                        'entity_id' => $source['count']->entityId, 'unit' => 'count',
                        'source_fact_ids' => [$source['specification']->id, $source['count']->id, $source['other']->id],
                    ]);
                    return ['__native_specification_fact_id__' => $source['specification']->id,
                        '__native_count_fact_id__' => $source['count']->id, '__native_other_specification_fact_id__' => $source['other']->id,
                        '__native_equipment_entity_id__' => $source['count']->entityId,
                        '__native_equipment_count_quantity_id__' => $quantities[0]['id']];
                });
            $result = $adapter->run(new BenchmarkPredictionCaseData('generic-equipment-source-join', BenchmarkDatasetType::Regression,
                BenchmarkSourceType::Spreadsheet, 'equipment.xlsx', hash_file('sha256', $path), ['synthetic_transport_fixture'], []), 300000);
            self::assertSame('success', $result->status);
            self::assertTrue($quantityBinding['eligible'], json_encode($quantityBinding, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $session = EstimateGenerationSession::query()->findOrFail($adapter->lastRun['session_id']);
            $items = [];
            foreach ($session->draft_payload['local_estimates'] as $local) {
                foreach ($local['sections'] as $section) {
                    foreach ($section['work_items'] as $item) {
                        if (($item['composition_intent']['work_key'] ?? null) === 'equipment.install') {
                            $items[] = $item;
                        }
                    }
                }
            }
            self::assertCount(1, $items, json_encode($session->draft_payload['estimate_composition'], JSON_THROW_ON_ERROR));
            $item = $items[0];
            self::assertSame('supplementary', $item['composition_intent']['kind']);
            self::assertSame('7', $item['quantity']);
            self::assertSame('count', $item['unit']);
            self::assertSame($source['count']->entityId, $item['metadata']['scope_entity_id']);
            self::assertArrayNotHasKey('specialization_scenario', $item);
            self::assertNotSame('residential', $session->draft_payload['object_profile']['object_type'] ?? null);
            $refs = array_column($item['source_refs'], 'role', 'fact_id');
            self::assertSame('context', $refs[$source['specification']->id]);
            self::assertSame('context', $refs[$source['other']->id]);
            self::assertSame('quantity_operand', $refs[$source['count']->id]);
            self::assertNotSame($source['specification']->id, $source['count']->id);
            self::assertSame($source['count']->id, $item['quantity_evidence']['formula_inputs']['operands'][0]['fact_id']);
            $classifier = new WorkIntentSourceClassifier;
            $basis = $classifier->binding($item['composition_intent'], $composerFacts);
            self::assertSame([$source['specification']->id], $basis['basis_fact_ids']);
            self::assertNotContains($source['count']->id, $basis['basis_fact_ids']);
            self::assertNotContains($source['other']->id, $basis['basis_fact_ids']);
            $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning((int) $session->organization_id, (int) $session->project_id, (int) $session->id, 10001)['snapshot'];
            $proofs = array_column($snapshot->evidence, null, 'id');
            self::assertTrue((new NativeNumericFactProof)->matches($source['count'], $proofs[$source['count']->evidenceIds[0]]));
            $actor = User::query()->findOrFail($session->user_id);
            $assignment = UserRoleAssignment::query()->where('user_id', $actor->id)->firstOrFail();
            $role = OrganizationCustomRole::query()->where('organization_id', $session->organization_id)->where('slug', $assignment->role_slug)->firstOrFail();
            $permissions = $role->module_permissions;
            $permissions['ai-estimates'][] = 'estimate_generation.select_normative';
            $role->update(['module_permissions' => $permissions]);
            app(SelectNormativeCandidate::class)->handle((int) $session->id, (int) $session->organization_id, (int) $session->project_id,
                $actor, (int) $session->state_version, $item['key'], $catalog['norm_id'], true);
            $selected = array_values(array_filter(array_merge(...array_map(static fn (array $local): array => array_merge(...array_column($local['sections'], 'work_items')),
                $session->fresh()->draft_payload['local_estimates'])), static fn (array $row): bool => $row['key'] === $item['key']));
            self::assertCount(1, $selected);
            self::assertSame('7', $selected[0]['quantity']);
            self::assertSame('count', $selected[0]['unit']);
            self::assertSame($catalog['norm_id'], $selected[0]['normative_match']['norm_id']);
            self::assertSame($item['source_refs'], $selected[0]['source_refs']);
            self::assertSame($source['count']->id, $selected[0]['quantity_evidence']['formula_inputs']['operands'][0]['fact_id']);
            self::assertSame($adapter->lastRun['provider_requests'], $adapter->lastRun['requests_after_replay']);
            $document = EstimateGenerationDocument::query()->where('session_id', $session->id)->sole();
            app(IgnoreEstimateGenerationDocument::class)->handle($session->fresh(), $document, (int) $session->fresh()->state_version, 'Исходная ведомость исключена пользователем.');
            $currentInput = app(EstimateComposerInputFactory::class)->capture((int) $session->organization_id, (int) $session->project_id,
                (int) $session->id, [], [], [], allowedWorkSections: ['equipment'], evaluationMode: 'universal');
            self::assertNotContains($source['specification']->id, array_column($currentInput->facts, 'id'));
            try {
                $classifier->binding($item['composition_intent'], $currentInput->facts);
                self::fail('An ignored original document cannot remain the current verified work basis.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('estimate_composer_work_source_unrelated', $exception->getMessage());
            }
        } finally {
            $book->disconnectWorksheets();
            foreach ([$path, $recordingPath] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }

    public function test_default_floor_binding_preserves_current_native_proof_without_claiming_complete_work_scope(): void
    {
        $directory = sys_get_temp_dir().'/most-current-floor-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $path = $directory.'/dimensions.xlsx';
        $recording = $directory.'/recording.json';
        $book = $this->floorWorkbook();
        try {
            (new Xlsx($book))->save($path);
            $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/EstimateGeneration/universal-replay/xlsx-floor-finishing-v1.json')), true, flags: JSON_THROW_ON_ERROR);
            $fixture['recordings'] = array_slice($fixture['recordings'], 0, 2);
            file_put_contents($recording, json_encode($fixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $adapter = new RecordedUniversalPipelineAdapter($this->app, new LocalBenchmarkObjectReader($directory), $recording, hash_file('sha256', $recording));
            $result = $adapter->run(new BenchmarkPredictionCaseData('current-default-floor', BenchmarkDatasetType::Regression,
                BenchmarkSourceType::Spreadsheet, 'dimensions.xlsx', hash_file('sha256', $path), ['synthetic_transport_fixture'], []), 300000);
            self::assertSame('success', $result->status);
            $session = EstimateGenerationSession::query()->findOrFail($adapter->lastRun['session_id']);
            self::assertTrue(app(BuildMostEstimateDraft::class)->verifyArtifact($session->draft_payload));
            self::assertCount(count(ProcessingStage::cases()), $adapter->lastRun['completed_stages']);
            self::assertSame(2, $adapter->lastRun['provider_requests']);
            self::assertSame(2, $adapter->lastRun['requests_after_replay']);
            self::assertSame(2, $adapter->lastRun['usage_after_replay']);
            self::assertEqualsCanonicalizing(['estimate_composer', 'estimate_auditor'], DB::table('estimate_generation_ai_role_runs')
                ->where('session_id', $session->id)->where('status', 'completed')->pluck('role')->all());
            $positions = array_values(array_filter($adapter->lastRun['evaluation']['scenarios'][0]['positions'],
                static fn (array $row): bool => $row['work_key'] === 'finish.floor'));
            self::assertCount(1, $positions);
            self::assertSame('50', $positions[0]['quantity']);
            self::assertSame('m2', $positions[0]['unit']);
            self::assertSame('floor_area', $positions[0]['quantity_formula']);
            self::assertSame('supported', $positions[0]['quantity_status']);
            $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning((int) $session->organization_id,
                (int) $session->project_id, (int) $session->id, 10001)['snapshot'];
            $numeric = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->origin === 'document'
                && in_array($fact->type, ['length', 'width'], true)));
            self::assertCount(2, $numeric);
            $proofs = array_column($snapshot->evidence, null, 'id');
            foreach ($numeric as $fact) {
                self::assertSame($positions[0]['entity_id'], $fact->entityId);
                self::assertSame('confirmed', $fact->status);
                self::assertSame([(int) $session->organization_id, (int) $session->project_id, (int) $session->id],
                    [$fact->organizationId, $fact->projectId, $fact->sessionId]);
                self::assertTrue((new NativeNumericFactProof)->matches($fact, $proofs[$fact->evidenceIds[0]]));
            }
            $details = $positions[0]['quantity_evidence_details'];
            self::assertEqualsCanonicalizing(array_column($numeric, 'id'), array_column($details, 'fact_id'));
            self::assertFalse($adapter->lastRun['evaluation']['can_confirm_for_apply']);
            $missing = $adapter->lastRun['evaluation']['coverage']['missing_requirements'];
            self::assertCount(1, $missing);
            self::assertSame('preparation', $missing[0]['requirement']);
            self::assertSame($positions[0]['entity_id'], $missing[0]['entity_id']);
        } finally {
            $book->disconnectWorksheets();
            foreach ([$path, $recording] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }

    public function test_actual_xlsx_upload_and_published_measurements_reach_the_real_composer_and_final_signed_draft(): void
    {
        $this->app->singleton(ApplyComposerCorrectionCycle::class, static fn ($app): ApplyComposerCorrectionCycle => new ApplyComposerCorrectionCycle(
            $app->make(RunEstimateAudit::class), $app->make(RunEstimateComposerCorrection::class), 24576));
        $directory = sys_get_temp_dir().'/most-native-replay-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $input = $directory.'/dimensions.xlsx';
        $book = $this->floorWorkbook();
        try {
            (new Xlsx($book))->save($input);
            $book->disconnectWorksheets();
            $fixture = base_path('tests/Fixtures/EstimateGeneration/universal-replay/xlsx-floor-finishing-v1.json');
            $adapter = new RecordedUniversalPipelineAdapter($this->app, new LocalBenchmarkObjectReader($directory), $fixture, hash_file('sha256', $fixture),
                static function (EstimateGenerationSession $session, ?string $operationId): void {
                    self::assertNull($operationId);
                    $snapshot = app(ProjectModelRepository::class)->snapshot((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
                    $names = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === 'room_name'));
                    $payload = json_decode(DB::table('estimate_generation_document_pages')->where('session_id', $session->id)->value('normalized_payload'), true, 64, JSON_THROW_ON_ERROR);
                    $nameCells = array_values(array_filter($payload['native_structure']['cells'] ?? [], static fn (array $cell): bool => $cell['address'] === 'C4'));
                    self::assertCount(1, $names, json_encode(['name_cells' => $nameCells, 'fact_types' => array_column($snapshot->facts, 'type')], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                    self::assertSame('Офис', $names[0]->value);
                    $numbers = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === 'room_number'));
                    self::assertCount(1, $numbers);
                    $number = $numbers[0];
                    self::assertSame('001', $number->value);
                    self::assertNull($number->unit);
                    self::assertSame('confirmed', $number->status);
                    self::assertSame('candidate', $names[0]->status, 'A printed number cannot confirm the room name.');
                    self::assertSame($names[0]->entityId, $number->entityId);
                    $room = array_values(array_filter($snapshot->entities, static fn ($entity): bool => $entity->id === $number->entityId))[0];
                    self::assertSame('1', $room->attributes['floor_id']);
                    self::assertArrayNotHasKey('room_number', $room->attributes);
                    $evidence = DB::table('estimate_generation_evidence')->where('session_id', $session->id)
                        ->where('id', (int) substr($number->evidenceIds[0], strlen('evidence:')))->first();
                    self::assertNotNull($evidence);
                    self::assertSame(['fact_key' => 'room_number', 'fact_value' => hash('sha256', '001')], json_decode($evidence->value, true, flags: JSON_THROW_ON_ERROR));
                    self::assertSame('xlsx:sheet:Размеры!C5', json_decode($evidence->locator, true, flags: JSON_THROW_ON_ERROR)['native_reference']);
                    self::assertSame($number->sourceVersion, $evidence->source_version);
                    $canonicalProof = array_column($snapshot->evidence, null, 'id')[$number->evidenceIds[0]];
                    self::assertSame('plan', $canonicalProof->documentRole);
                    self::assertSame('room_number', $canonicalProof->literalFact?->factKey);
                    self::assertSame(hash('sha256', '001'), $canonicalProof->literalFact?->valueHash);
                    $document = EstimateGenerationDocument::query()->where('session_id', $session->id)->firstOrFail();
                    $numberNote = $document->facts()->where('label', 'Номер помещения')->first();
                    self::assertNotNull($numberNote);
                    self::assertSame('note', $numberNote->fact_type);
                    self::assertSame('001', $numberNote->value_text);
                    self::assertNull($numberNote->value_number);
                    self::assertNull($numberNote->unit);
                    $migration = require app_path('BusinessModules/Addons/EstimateGeneration/migrations/2026_10_15_000200_allow_room_number_evidence.php');
                    $migration->up();
                    $migration->up();
                    self::assertEquals($evidence, DB::table('estimate_generation_evidence')->where('id', $evidence->id)->first());
                    foreach ([['fact_key' => 'room_number', 'fact_value' => '001'],
                        ['fact_key' => 'room_number', 'fact_value' => 1],
                        ['fact_key' => 'room_number', 'fact_value' => hash('sha256', '001'), 'unit' => 'm2']] as $index => $invalidValue) {
                        $invalid = (array) $evidence;
                        unset($invalid['id']);
                        $invalid['fingerprint'] = hash('sha256', 'invalid-room-number:'.$index);
                        $invalid['value'] = json_encode($invalidValue, JSON_THROW_ON_ERROR);
                        try {
                            DB::transaction(static fn () => DB::table('estimate_generation_evidence')->insert($invalid));
                            self::fail('Invalid room number proof must be rejected by PostgreSQL.');
                        } catch (\Illuminate\Database\QueryException $exception) {
                            self::assertStringContainsString('room_identity_evidence_invalid', $exception->getMessage());
                        }
                    }
                });
            $result = $adapter->run(new BenchmarkPredictionCaseData('xlsx-floor-finishing-v1', BenchmarkDatasetType::Regression,
                BenchmarkSourceType::Spreadsheet, 'dimensions.xlsx', hash_file('sha256', $input), ['native_xlsx_joined_regression'], []), 300000);
            self::assertSame('success', $result->status);
            $session = EstimateGenerationSession::query()->findOrFail($adapter->lastRun['session_id']);
            $automaticSuspendedCeilings = [];
            foreach ($session->draft_payload['local_estimates'] as $estimate) {
                foreach ($estimate['sections'] as $section) {
                    foreach ($section['work_items'] as $item) {
                        if (($item['metadata']['quantity_key'] ?? null) === 'office.ceiling') {
                            $automaticSuspendedCeilings[] = $item;
                        }
                    }
                }
            }
            self::assertSame([], $automaticSuspendedCeilings, 'An office label and floor dimensions do not choose a suspended ceiling.');
            self::assertTrue(app(BuildMostEstimateDraft::class)->verifyArtifact($session->draft_payload));
            self::assertCount(count(ProcessingStage::cases()), $adapter->lastRun['completed_stages']);
            self::assertSame(2, $adapter->lastRun['provider_requests']);
            self::assertSame(2, $adapter->lastRun['requests_after_replay']);
            $auditSubjects = DB::table('estimate_generation_ai_role_runs')->where('session_id', $session->id)
                ->where('role', 'estimate_auditor')->pluck('subject_id')->all();
            self::assertSame([$session->id.':0'], $auditSubjects);
            self::assertSame(0, $adapter->lastRun['document_run']['vision_requests']);
            self::assertSame(0, $adapter->lastRun['document_run']['vision_requests_after_replay']);
            self::assertSame(['completed'], $adapter->lastRun['document_run']['units']);
            self::assertNull($adapter->lastRun['document_run']['operation_id']);
            self::assertNull($adapter->lastRun['document_run']['receipt']);
            $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning((int) $session->organization_id, (int) $session->project_id, (int) $session->id, 10001)['snapshot'];
            $numeric = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->origin === 'document' && in_array($fact->type, ['length', 'width'], true)));
            self::assertCount(2, $numeric);
            $proofs = array_column($snapshot->evidence, null, 'id');
            foreach ($numeric as $fact) {
                self::assertSame('confirmed', $fact->status);
                $proof = $proofs[$fact->evidenceIds[0]];
                self::assertTrue((new NativeNumericFactProof)->matches($fact, $proof));
                self::assertStringStartsWith('xlsx:sheet:Размеры!C', $proof->nativeReference);
            }
            $positions = array_values(array_filter($adapter->lastRun['evaluation']['scenarios'][0]['positions'], static fn (array $item): bool => $item['work_key'] === 'finish.floor'));
            self::assertCount(1, $positions);
            self::assertCount(1, array_unique(array_column($numeric, 'entityId')));
            $roomEntityId = $numeric[0]->entityId;
            self::assertSame('finish.floor', $positions[0]['key']);
            self::assertSame($roomEntityId, $positions[0]['entity_id']);
            self::assertSame('50', $positions[0]['quantity']);
            self::assertSame('m2', $positions[0]['unit']);
            self::assertSame('supported', $positions[0]['quantity_status']);
            self::assertSame('floor_area', $positions[0]['quantity_formula']);
            self::assertNotEmpty($positions[0]['quantity_evidence_details']);
            $roughFloor = array_values(array_filter($adapter->lastRun['evaluation']['scenarios'][0]['positions'], static fn (array $item): bool => $item['work_key'] === 'rough.floor'));
            self::assertSame([], $roughFloor);
            self::assertNull($positions[0]['price_snapshot']);
            $coveredDuplicates = array_values(array_filter($session->draft_payload['estimate_composition']['request_info'],
                static fn (array $notice): bool => $notice['reason'] === 'estimate_composer_physical_duplicate_covered'));
            self::assertSame([], $coveredDuplicates);
            self::assertNotContains('work_source_review', array_column($adapter->lastRun['evaluation']['missing_requirements'], 'requirement'));
            self::assertSame(2, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
            self::assertSame(1, DB::table('estimate_generation_ai_role_runs')->where('session_id', $session->id)
                ->where('role', 'estimate_auditor')->where('status', 'completed')->distinct()->count('subject_id'));
            $repository = app(ProjectModelRepository::class);
            $measurementVersion = $numeric[0]->sourceVersion;
            $currentQuantities = $repository->currentDerivedQuantities((int) $session->organization_id, (int) $session->project_id, (int) $session->id, $measurementVersion);
            self::assertCount(1, $currentQuantities);
            $currentIds = array_column($currentQuantities, 'id');
            $runVersion = $repository->currentCompleteness((int) $session->organization_id, (int) $session->project_id, (int) $session->id)['source_version'];
            self::assertNotSame($runVersion, $currentQuantities[0]->sourceVersion);
            foreach ([null, str_repeat('0', 64)] as $token) {
                try {
                    $repository->replaceDerivedQuantityProjection((int) $session->organization_id, (int) $session->project_id,
                        (int) $session->id, $runVersion, $currentQuantities, [], $token);
                    self::fail('Legacy cross-source or stale planning-token quantity publication was accepted.');
                } catch (\InvalidArgumentException) {
                    self::addToAssertionCount(1);
                }
                self::assertSame($currentIds, array_column($repository->currentDerivedQuantities(
                    (int) $session->organization_id, (int) $session->project_id, (int) $session->id, $measurementVersion), 'id'));
            }
            $this->confirmAndApplyOriginalWorklist($session, $adapter);
        } finally {
            $book->disconnectWorksheets();
            if (is_file($input)) {
                unlink($input);
            }
            rmdir($directory);
        }
    }

    private function floorWorkbook(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Размеры')->fromArray([
            ['entity_key', 'parameter', 'value', 'unit', 'floor', 'document_role'],
            ['room:office', 'length', 5, 'м', '1', 'plan'],
            ['room:office', 'width', 10, 'м', '1', 'plan'],
            ['room:office', 'room_name', 'Офис', '', '1', 'plan'],
            ['room:office', 'room_number', '001', '', '1', 'plan'],
        ]);

        return $book;
    }

    private function genericEquipmentCatalog(): array
    {
        $now = now();
        $key = 'synthetic-generic-provenance-'.strtolower((string) Str::ulid());
        $dataset = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fsnb_2022', 'version_key' => $key,
            'bucket' => 'test', 'prefix' => $key, 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $prices = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fgis_labor_prices', 'version_key' => $key.'-prices',
            'bucket' => 'test', 'prefix' => $key.'-prices', 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $collection = DB::table('estimate_norm_collections')->insertGetId(['dataset_version_id' => $dataset, 'code' => $key,
            'name' => 'Synthetic generic source join', 'norm_type' => 'gesn', 'source_file' => 'synthetic-generic-provenance', 'created_at' => $now, 'updated_at' => $now]);
        $section = DB::table('estimate_norm_sections')->insertGetId(['collection_id' => $collection, 'code' => '20', 'name' => 'Synthetic equipment',
            'path' => '20/synthetic', 'created_at' => $now, 'updated_at' => $now]);
        $norm = DB::table('estimate_norms')->insertGetId(['collection_id' => $collection, 'code' => '20-99-999-01',
            'section_code' => '20', 'section_name' => 'Synthetic equipment', 'section_id' => $section,
            'name' => 'Монтаж оборудования по требованиям изготовителя',
            'work_composition' => json_encode(['Установка и крепление оборудования по согласованной спецификации'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'unit' => '100 шт', 'valid_from' => '2026-10-01', 'valid_to' => '2026-12-31', 'created_at' => $now, 'updated_at' => $now]);
        $resourceCode = 'SYNTHETIC-LABOR-'.$dataset;
        DB::table('estimate_norm_resources')->insert(['estimate_norm_id' => $norm, 'resource_code' => $resourceCode,
            'resource_name' => 'Труд рабочих', 'unit' => 'h', 'quantity' => '10', 'resource_type' => 'labor', 'created_at' => $now, 'updated_at' => $now]);
        $region = DB::table('estimate_regions')->insertGetId(['code' => 'JOIN-'.$dataset, 'name' => 'Synthetic region',
            'fgiscs_subject_id' => random_int(100000, 999999), 'created_at' => $now, 'updated_at' => $now]);
        $zone = DB::table('estimate_price_zones')->insertGetId(['estimate_region_id' => $region, 'name' => 'Synthetic zone',
            'fgiscs_price_zone_id' => random_int(1000000, 1999999), 'created_at' => $now, 'updated_at' => $now]);
        $period = DB::table('estimate_price_periods')->where('year', 2026)->where('quarter', 4)->value('id')
            ?? DB::table('estimate_price_periods')->insertGetId(['fgiscs_period_id' => random_int(2000000, 2999999), 'name' => 'Synthetic 2026 Q4',
                'year' => 2026, 'quarter' => 4, 'created_at' => $now, 'updated_at' => $now]);
        $version = DB::table('estimate_regional_price_versions')->insertGetId(['source' => 'fgiscs', 'region_id' => $region,
            'price_zone_id' => $zone, 'period_id' => $period, 'version_key' => $key, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('estimate_resource_prices')->insert(['dataset_version_id' => $prices, 'regional_price_version_id' => $version,
            'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period, 'resource_code' => $resourceCode,
            'resource_name' => 'Труд рабочих', 'unit' => 'h', 'base_price' => '100', 'price_type' => 'labor', 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('estimate_regional_price_versions')->where('id', $version)->update(['status' => 'active']);

        return ['norm_id' => $norm, 'context' => ['region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period,
            'business_date' => '2026-10-01', 'year' => 2026, 'quarter' => 4, 'version_key' => $key,
            'estimate_regional_price_version_id' => $version, 'normative_dataset_version' => $key]];
    }

    private function confirmAndApplyOriginalWorklist(EstimateGenerationSession $session, RecordedUniversalPipelineAdapter $adapter): void
    {
        $actor = User::query()->findOrFail($session->user_id);
        $projectId = (int) $session->project_id;
        $sessionId = (int) $session->id;
        $organizationId = (int) $session->organization_id;
        $original = $adapter->lastRun['evaluation']['scenarios'][0]['positions'];
        $originalKeys = array_column($original, 'key');
        $nativePosition = array_values(array_filter($original, static fn (array $item): bool => $item['work_key'] === 'finish.floor'))[0];
        self::assertEqualsCanonicalizing(['finish.floor', 'finishing', 'preparation'], $originalKeys);
        $snapshot = app(ProjectModelRepository::class)->snapshot($organizationId, $projectId, $sessionId);
        $nativeFacts = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->origin === 'document'
            && in_array($fact->type, ['length', 'width'], true)));
        self::assertCount(2, $nativeFacts);
        $names = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === 'room_name'));
        self::assertCount(1, $names, 'The actual XLSX name must remain in the canonical snapshot.');
        $name = $names[0];
        $entities = array_column($snapshot->entities, null, 'id');
        $proofIds = array_map(static fn (string $id): int => (int) substr($id, strlen('evidence:')), $name->evidenceIds);
        $nodes = array_column(app(EvidenceRepository::class)->activeNodesForUpdate($organizationId, $projectId, $sessionId, $proofIds), null, 'id');
        $nameProof = (new RoomIdentityFactEligibility)->proof($name, $entities[$name->entityId], $nodes);
        self::assertNotNull($nameProof, json_encode(['status' => $name->status, 'origin' => $name->origin,
            'unit' => $name->unit, 'evidence_count' => count($nodes), 'values' => array_map(static fn ($node): array => $node->value, $nodes)], JSON_THROW_ON_ERROR));
        $document = EstimateGenerationDocument::query()->findOrFail($nameProof->locator['document_id']);
        self::assertSame($name->sourceVersion, DocumentSourceVersion::fromDocument($document));
        $sourceResult = app(GetRoomIdentitySources::class)->handle($actor, $projectId, $sessionId);
        self::assertTrue($sourceResult['available']);
        $sources = $sourceResult['sources'];
        self::assertCount(1, $sources);
        $identity = $sources[0];
        self::assertSame($nativePosition['entity_id'], $identity['entity_id']);
        self::assertSame('candidate', $identity['status']);
        $identityInput = ['request_id' => (string) Str::uuid(), 'state_version' => (int) $session->fresh()->state_version,
            'source_fact_id' => $identity['source_fact_id'], 'source_fact_version' => $identity['source_fact_version'],
            'source_value_hash' => $identity['source_value_hash'], 'reason' => 'Пользователь подтверждает название помещения из исходной ячейки XLSX.'];
        $identityConfirmation = app(ConfirmRoomIdentity::class)->handle($actor, $projectId, $sessionId, $identityInput);
        self::assertTrue($identityConfirmation['identity_confirmed']);
        self::assertFalse($identityConfirmation['dimensions_confirmed']);
        self::assertSame('0', $identityConfirmation['cost_units']);
        self::assertSame(CanonicalPipelineJson::encode($identityConfirmation), CanonicalPipelineJson::encode(
            app(ConfirmRoomIdentity::class)->handle($actor, $projectId, $sessionId, $identityInput)));
        $identity = app(GetRoomIdentitySources::class)->handle($actor, $projectId, $sessionId)['sources'][0];
        self::assertSame('confirmed', $identity['status']);
        self::assertSame($nativePosition['entity_id'], $identity['entity_id']);
        $scopeReceipt = app(SaveEvaluationScopeSelection::class)->handle($actor, $projectId, $sessionId,
            ['request_id' => (string) Str::uuid(), 'state_version' => (int) $session->fresh()->state_version,
                'selected_sections' => ['finishing'], 'profile_id' => 'construction',
                'construction_type' => 'new_construction', 'price_policy' => 'catalog']);
        self::assertSame('0', $scopeReceipt['cost_units']);
        $quantities = [];
        foreach ($original as $position) {
            $native = $position['work_key'] === 'finish.floor';
            $workUnit = $position['unit'] ?? 'm2';
            if (! $native) {
                app(BindEvaluationWorkEntity::class)->handle($actor, $projectId, $sessionId,
                    ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
                        'work_item_key' => $position['key'], 'source_fact_id' => $identity['source_fact_id'],
                        'source_fact_version' => $identity['source_fact_version'], 'source_value_hash' => $identity['source_value_hash'],
                        'reason' => 'Пользователь привязывает работу к помещению из той же таблицы XLSX.']);
                app(SaveEvaluationWorkQuantity::class)->handle($actor, $projectId, $sessionId,
                    ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
                        'work_item_key' => $position['key'], 'value' => '1', 'unit' => $workUnit,
                        'reason' => 'Пользователь задаёт предполагаемый объём дополнительной работы, отдельно от измерений XLSX.']);
            }
            $unit = MeasurementUnit::query()->create(['organization_id' => $organizationId,
                'name' => 'Единица позиции '.$position['key'], 'short_name' => $workUnit,
                'type' => ['m' => 'length', 'm2' => 'area', 'm3' => 'volume', 'count' => 'piece', 'kg' => 'mass', 'h' => 'time'][$workUnit] ?? 'other']);
            $catalog = EstimatePositionCatalog::query()->create(['organization_id' => $organizationId, 'name' => $position['name'],
                'code' => 'XLSX-'.Str::random(6), 'item_type' => 'work', 'measurement_unit_id' => $unit->id,
                'unit_price' => '0.03', 'is_active' => true, 'created_by_user_id' => $actor->id,
                'metadata' => ['currency' => 'RUB', 'price_as_of_date' => '2026-10-01', 'price_region' => 'Москва',
                    'vat_mode' => 'included', 'vat_rate' => '20', 'delivery_included' => true]]);
            $price = app(CataloguePriceSnapshotReader::class)->read($organizationId, 'catalog:'.$catalog->id);
            app(SaveEvaluationPriceSelection::class)->handle($actor, $projectId, $sessionId,
                ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
                    'work_item_key' => $position['key'], 'source_reference' => $price['source_reference'], 'source_hash' => $price['source_hash'],
                    'conditions_confirmation' => ['region' => ['value' => 'Москва', 'basis' => 'Пользователь принимает регион цены для выбранного объекта.']]]);
            if (! $native) {
                $quantities[] = ['work_item_key' => $position['key'], 'value' => '1', 'unit' => $workUnit];
            }
        }
        $recalculated = app(RecalculateUniversalEvaluation::class)->handle($actor, $projectId, $sessionId,
            (int) $session->fresh()->state_version, (string) Str::uuid());
        self::assertSame('0', $recalculated['cost_units']);
        self::assertSame($originalKeys, array_column($recalculated['result']['scenarios'][0]['positions'], 'key'));
        app(SaveEvaluationScenarioSet::class)->handle($actor, $projectId, $sessionId,
            ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
                'definitions' => array_map(static fn (string $id): array => ['id' => $id,
                    'basis' => 'Размеры пола известны из XLSX; дополнительные работы оценены по отдельно принятым пользовательским объёмам.',
                    'quantities' => $quantities], ['minimum', 'base', 'maximum'])]);
        $revision = app(RecalculateUniversalEvaluation::class)->handle($actor, $projectId, $sessionId,
            (int) $session->fresh()->state_version, (string) Str::uuid());
        self::assertTrue($revision['result']['can_confirm_for_apply'], json_encode($revision['result']['missing_requirements'], JSON_UNESCAPED_UNICODE));
        foreach ($revision['result']['scenarios'][0]['positions'] as $position) {
            $native = $position['work_key'] === 'finish.floor';
            self::assertSame($native ? '50' : '1', $position['quantity']);
            self::assertSame('0.03', $position['price_snapshot']['unit_price']);
            self::assertSame('RUB', $position['price_snapshot']['currency']);
            self::assertSame($native ? '1.50' : '0.03', $position['total_cost']);
            self::assertSame($native ? '1.25' : '0.03', $position['money_breakdown']['net_amount']);
            self::assertSame($native ? '0.25' : '0.00', $position['money_breakdown']['vat_amount']);
            self::assertSame($native ? '1.50' : '0.03', $position['money_breakdown']['gross_amount']);
        }
        self::assertSame('1.56', $revision['result']['scenarios'][0]['total_by_currency']['RUB']);
        self::assertSame($originalKeys, array_column($revision['result']['scenarios'][0]['positions'], 'key'));
        self::assertTrue($revision['result']['scope_confirmed']);
        $manualSources = $this->assertCurrentFloorQuantitySources($session, $nativeFacts);
        $this->assertChangedFloorScenario($actor, $session, $nativeFacts, $quantities);
        $confirmation = app(ConfirmEvaluationScenario::class)->handle($actor, $projectId, $sessionId,
            ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
                'revision_id' => $revision['revision_id'], 'content_hash' => $revision['content_hash'],
                'scenario_id' => 'base', 'assumptions_accepted' => true]);
        $this->assertCurrentFloorQuantitySources($session, $nativeFacts, $manualSources);
        $assignment = UserRoleAssignment::query()->where('user_id', $actor->id)->firstOrFail();
        $role = OrganizationCustomRole::query()->where('organization_id', $organizationId)->where('slug', $assignment->role_slug)->firstOrFail();
        $permissions = $role->module_permissions;
        $permissions['ai-estimates'][] = 'estimate_generation.export';
        $permissions['ai-estimates'][] = 'estimate_generation.apply';
        $role->update(['module_permissions' => $permissions]);
        $request = Request::create('/export', 'GET', ['format' => 'excel']);
        $request->setUserResolver(static fn () => $actor);
        $beforeExport = $session->fresh()->getRawOriginal();
        $response = app(EstimateGenerationPackageController::class)->export($request, $session->project, $session->fresh());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        self::assertSame($beforeExport, $session->fresh()->getRawOriginal());
        $quantityReferences = [];
        foreach ($session->fresh()->draft_payload['local_estimates'] as $localEstimate) {
            foreach ($localEstimate['sections'] as $section) {
                foreach ($section['work_items'] as $item) {
                    $quantityReferences[$item['key']] = $item;
                }
            }
        }
        $this->assertPipelineXlsx((string) $response->getContent(), $originalKeys, $quantityReferences);
        $command = new ApplyGeneratedEstimateCommand($sessionId, $organizationId, $projectId, $confirmation['state_version'],
            idempotencyKey: (string) Str::uuid(), artifactHash: $session->fresh()->draft_payload['artifact_hash'], actor: $actor);
        $applied = app(ApplyGeneratedEstimate::class)->handle($command);
        self::assertSame($applied->estimateId, app(ApplyGeneratedEstimate::class)->handle($command)->estimateId);
        $estimate = Estimate::query()->findOrFail($applied->estimateId);
        self::assertSame('1.56', $estimate->total_amount_with_vat);
        self::assertSame('1.31', $estimate->total_amount);
        self::assertSame(count($original), $estimate->items()->whereNull('parent_work_id')->count());
        for ($iteration = 0; $iteration < 2; $iteration++) {
            app(EstimateCalculationService::class)->recalculateAll($estimate->fresh());
            self::assertSame('1.56', $estimate->fresh()->total_amount_with_vat);
            self::assertSame('1.31', $estimate->fresh()->total_amount);
            $this->assertCurrentFloorQuantitySources($session, $nativeFacts, $manualSources);
            foreach ($estimate->fresh()->items()->whereNull('parent_work_id')->get() as $item) {
                $native = $item->metadata['source_work_item_key'] === 'finish.floor';
                self::assertTrue(\Brick\Math\BigDecimal::of((string) $item->quantity_total)->isEqualTo($native ? '50' : '1'));
                self::assertSame($native ? '1.25' : '0.03', $item->metadata['generated_source_money']['money']['net_amount']);
                self::assertSame($native ? '0.25' : '0.00', $item->metadata['generated_source_money']['money']['vat_amount']);
                self::assertSame($native ? '1.50' : '0.03', $item->metadata['generated_source_money']['money']['gross_amount']);
                self::assertSame($native ? 'evidenced' : 'estimated', $item->metadata['quantity_evidence']['source']);
                self::assertSame($native ? [] : [$item->metadata['quantity_evidence']['formula_inputs']['operands'][0]['fact_id']],
                    $item->metadata['quantity_evidence']['assumptions']);
            }
        }
        $totals = (new \ReflectionMethod(EstimateExportService::class, 'prepareTotalsData'))->invoke(app(EstimateExportService::class), $estimate->fresh());
        self::assertSame('1.56', (string) $totals['total_amount_with_vat']);
        self::assertSame('1.31', (string) $totals['total_amount']);
        self::assertTrue(\Brick\Math\BigDecimal::of((string) $totals['vat_amount'])->isEqualTo('0.25'));
        self::assertSame(2, DB::table('estimate_generation_ai_usage')->where('session_id', $sessionId)->count());
    }

    private function assertChangedFloorScenario(User $actor, EstimateGenerationSession $session, array $nativeFacts, array $quantities): void
    {
        DB::beginTransaction();
        try {
            $projectId = (int) $session->project_id;
            $sessionId = (int) $session->id;
            foreach ($quantities as &$quantity) {
                if ($quantity['work_item_key'] === 'preparation') {
                    $quantity['value'] = '2';
                }
            }
            unset($quantity);
            app(SaveEvaluationScenarioSet::class)->handle($actor, $projectId, $sessionId,
                ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
                    'definitions' => array_map(static fn (string $id): array => ['id' => $id,
                        'basis' => 'Отдельное пользовательское допущение: подготовка 2 м², отделка 1 м²; пол 50 м² из XLSX.',
                        'quantities' => $quantities], ['minimum', 'base', 'maximum'])]);
            $revision = app(RecalculateUniversalEvaluation::class)->handle($actor, $projectId, $sessionId,
                (int) $session->fresh()->state_version, (string) Str::uuid());
            self::assertTrue($revision['result']['can_confirm_for_apply']);
            self::assertSame('1.59', $revision['result']['scenarios'][1]['total_by_currency']['RUB']);
            $input = ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
                'revision_id' => $revision['revision_id'], 'content_hash' => $revision['content_hash'],
                'scenario_id' => 'base', 'assumptions_accepted' => true];
            $this->assertInvalidatedNativeFloorCannotConfirm($actor, $session, $nativeFacts[0], $input);
            $confirmation = app(ConfirmEvaluationScenario::class)->handle($actor, $projectId, $sessionId, $input);
            self::assertNotSame($revision['revision_id'], $confirmation['result_revision_id']);
            self::assertSame($revision['revision_id'], $confirmation['revision_id']);
            $session->refresh();
            $accepted = $session->input_payload['confirmed_evaluation'];
            self::assertSame($revision['revision_id'], $accepted['source_revision_id']);
            self::assertSame($revision['content_hash'], $accepted['source_content_hash']);
            self::assertSame($confirmation['result_revision_id'], $accepted['revision_id']);
            $current = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetCurrentEvaluation::class)->handle($actor, $projectId, $sessionId);
            $selected = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\SelectedEvaluationScenario)->select($current['result'], 'base');
            self::assertSame($selected['scenario_hash'], $accepted['scenario_hash']);
            $beforeReplay = $session->getRawOriginal();
            self::assertSame(CanonicalPipelineJson::encode($confirmation), CanonicalPipelineJson::encode(
                app(ConfirmEvaluationScenario::class)->handle($actor, $projectId, $sessionId, $input)));
            self::assertSame($beforeReplay, $session->fresh()->getRawOriginal());
            $manual = $this->assertCurrentFloorQuantitySources($session, $nativeFacts, expectedManual: ['preparation' => '2']);
            $assignment = UserRoleAssignment::query()->where('user_id', $actor->id)->firstOrFail();
            $role = OrganizationCustomRole::query()->where('organization_id', $session->organization_id)->where('slug', $assignment->role_slug)->firstOrFail();
            $permissions = $role->module_permissions;
            $permissions['ai-estimates'][] = 'estimate_generation.export';
            $permissions['ai-estimates'][] = 'estimate_generation.apply';
            $role->update(['module_permissions' => $permissions]);
            $projection = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Export\ReadConfirmedEvaluationExport::class)
                ->read($actor, $projectId, $sessionId);
            $items = [];
            foreach ($projection->draft_payload['local_estimates'] as $estimate) {
                foreach ($estimate['sections'] as $section) {
                    foreach ($section['work_items'] as $item) {
                        $items[$item['key']] = $item;
                    }
                }
            }
            self::assertSame(['preparation' => '2', 'finish.floor' => '50', 'finishing' => '1'], array_column(array_values($items), 'quantity', 'key'));
            self::assertSame(['0.05', '1.25', '0.03'], array_column(array_values($items), 'total_cost'));
            $request = Request::create('/export', 'GET', ['format' => 'excel']);
            $request->setUserResolver(static fn () => $actor);
            self::assertSame(200, app(EstimateGenerationPackageController::class)->export($request, $session->project, $session->fresh())->getStatusCode());
            $command = new ApplyGeneratedEstimateCommand($sessionId, (int) $session->organization_id, $projectId, $confirmation['state_version'],
                idempotencyKey: (string) Str::uuid(), artifactHash: $session->draft_payload['artifact_hash'], actor: $actor);
            $applied = app(ApplyGeneratedEstimate::class)->handle($command);
            self::assertSame($applied->estimateId, app(ApplyGeneratedEstimate::class)->handle($command)->estimateId);
            $estimate = Estimate::query()->findOrFail($applied->estimateId);
            self::assertSame(3, $estimate->items()->whereNull('parent_work_id')->count());
            for ($iteration = 0; $iteration < 2; $iteration++) {
                app(EstimateCalculationService::class)->recalculateAll($estimate->fresh());
                self::assertSame('1.33', $estimate->fresh()->total_amount);
                self::assertSame('1.59', $estimate->fresh()->total_amount_with_vat);
                $this->assertCurrentFloorQuantitySources($session, $nativeFacts, $manual, ['preparation' => '2']);
            }
            $totals = (new \ReflectionMethod(EstimateExportService::class, 'prepareTotalsData'))->invoke(app(EstimateExportService::class), $estimate->fresh());
            self::assertTrue(\Brick\Math\BigDecimal::of((string) $totals['vat_amount'])->isEqualTo('0.26'));
        } finally {
            DB::rollBack();
            $session->refresh();
        }
    }

    private function assertInvalidatedNativeFloorCannotConfirm(User $actor, EstimateGenerationSession $session, \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact $native, array $input): void
    {
        $models = app(ProjectModelRepository::class);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $before = $models->snapshotForPlanning(...[...$scope, 10001, true]);
        $default = $models->snapshotForPlanning(...[...$scope, 10001]);
        self::assertSame(['snapshot', 'token'], array_keys($default));
        self::assertSame($default['token'], $before['token']);
        self::assertSame($before['token'], \App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingInputFingerprint::fromExactState($before['token_state']));
        $beforeSession = $session->fresh()->getRawOriginal();
        $estimates = Estimate::query()->where('project_id', $scope[1])->count();
        DB::beginTransaction();
        try {
            self::assertSame(1, app(EvidenceRepository::class)->invalidate(...[...$scope, [(int) substr($native->evidenceIds[0], 9)], 'Источнику измерения требуется повторная проверка.']));
            $changed = $models->snapshotForPlanning(...[...$scope, 10001, true]);
            self::assertNotSame($before['token'], $changed['token']);
            self::assertNotSame($before['token_state']['bindings'], $changed['token_state']['bindings']);
            try {
                app(ConfirmEvaluationScenario::class)->handle($actor, $scope[1], $scope[2], $input);
                self::fail('An invalidated native operand cannot be refreshed as a scenario self-write.');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                self::assertNotEmpty($exception->errors());
            }
            self::assertSame($beforeSession, $session->fresh()->getRawOriginal());
            self::assertSame($estimates, Estimate::query()->where('project_id', $scope[1])->count());
        } finally {
            DB::rollBack();
            $session->refresh();
        }
        self::assertSame($before['token'], $models->snapshotForPlanning(...[...$scope, 10001])['token']);
    }

    private function assertCurrentFloorQuantitySources(EstimateGenerationSession $session, array $nativeFacts, array $previousManual = [], array $expectedManual = []): array
    {
        $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning((int) $session->organization_id,
            (int) $session->project_id, (int) $session->id, 10001)['snapshot'];
        $facts = array_column($snapshot->facts, null, 'id');
        $evidence = array_column($snapshot->evidence, null, 'id');
        foreach ($nativeFacts as $original) {
            $current = $facts[$original->id];
            self::assertSame($original->entityId, $current->entityId);
            self::assertSame($original->value, $current->value);
            self::assertSame($original->sourceVersion, $current->sourceVersion);
            self::assertSame($original->evidenceIds, $current->evidenceIds);
            self::assertSame('document', $current->origin);
            self::assertTrue((new NativeNumericFactProof)->matches($current, $evidence[$current->evidenceIds[0]]));
        }
        $manual = [];
        foreach ($session->fresh()->draft_payload['local_estimates'] as $estimate) {
            foreach ($estimate['sections'] as $section) {
                foreach ($section['work_items'] as $item) {
                    $native = $item['key'] === 'finish.floor';
                    $basis = $item['quantity_evidence'];
                    self::assertSame($native ? 'evidenced' : 'estimated', $basis['source']);
                    $operands = $basis['formula_inputs']['operands'];
                    if ($native) {
                        self::assertEqualsCanonicalizing(array_column($nativeFacts, 'id'), array_column($operands, 'fact_id'));
                        self::assertSame([], $basis['assumptions']);
                        continue;
                    }
                    self::assertCount(1, $operands);
                    $fact = $facts[$operands[0]['fact_id']];
                    self::assertSame('user_assumption', $fact->origin);
                    self::assertSame($expectedManual[$item['key']] ?? '1', $fact->value);
                    self::assertSame('m2', $fact->unit);
                    self::assertSame($nativeFacts[0]->entityId, $fact->entityId);
                    self::assertSame([], $fact->nativeNumericProofs);
                    self::assertSame([$fact->id], $basis['assumptions']);
                    self::assertSame($fact->evidenceIds, $operands[0]['evidence_ids']);
                    foreach ($fact->evidenceIds as $id) {
                        self::assertSame('user_input', $evidence[$id]->sourceType);
                    }
                    if (isset($previousManual[$item['key']]) && $previousManual[$item['key']]->id !== $fact->id) {
                        self::assertSame($previousManual[$item['key']]->id, $fact->supersedesFactId);
                    }
                    $manual[$item['key']] = $fact;
                }
            }
        }
        self::assertEqualsCanonicalizing(['finishing', 'preparation'], array_keys($manual));

        return $manual;
    }

    private function assertPipelineXlsx(string $content, array $workKeys, array $quantityReferences): void
    {
        $path = tempnam(sys_get_temp_dir(), 'most-recorded-pipeline-export-');
        self::assertNotFalse($path);
        $book = null;
        try {
            file_put_contents($path, $content);
            $book = IOFactory::load($path);
            $sheet = $book->getSheetByName('Смета');
            self::assertNotNull($sheet);
            $gross = [];
            for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
                if ($sheet->getCell('F'.$row)->getValue() === 'ВСЕГО С НДС:') {
                    $gross[] = (string) \Brick\Math\BigDecimal::of(str_replace(' ', '', (string) $sheet->getCell('G'.$row)->getValue()))->toScale(2);
                }
            }
            self::assertSame(['1.56'], $gross);
            $metadataSheet = $book->getSheetByName('_METADATA_');
            self::assertNotNull($metadataSheet);
            $metadata = json_decode((string) $metadataSheet->getCell('A1')->getValue(), true, 64, JSON_THROW_ON_ERROR);
            if (($metadata['encoding'] ?? null) === 'utf8-json-chunks') {
                $json = '';
                for ($row = 2; $row <= $metadata['chunk_count'] + 1; $row++) {
                    $json .= (string) $metadataSheet->getCell('A'.$row)->getValue();
                }
                self::assertSame($metadata['byte_length'], strlen($json));
                self::assertSame($metadata['sha256'], hash('sha256', $json));
                $metadata = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            }
            self::assertCount(count($workKeys), $metadata['items']);
            self::assertEqualsCanonicalizing($workKeys, array_column(array_column($metadata['items'], 'metadata'), 'work_item_key'));
            $exported = [];
            foreach ($metadata['items'] as $item) {
                self::assertNull($item['parent_work_id']);
                self::assertGreaterThan(0, $item['quantity_total']);
                self::assertNotEmpty($item['metadata']['price_snapshot']['source_hash']);
                $native = $item['metadata']['work_item_key'] === 'finish.floor';
                self::assertSame($native ? '1.25' : '0.03', $item['metadata']['source_money']['net_amount']);
                self::assertSame($native ? '0.25' : '0.00', $item['metadata']['source_money']['vat_amount']);
                self::assertSame($native ? '1.50' : '0.03', $item['metadata']['source_money']['gross_amount']);
                self::assertSame('included', $item['metadata']['source_money']['vat_mode']);
                self::assertSame('20', $item['metadata']['source_money']['vat_rate']);
                $source = $quantityReferences[$item['metadata']['work_item_key']];
                foreach (['quantity_evidence_id', 'quantity_evidence_fingerprint', 'quantity_evidence_source_version', 'quantity_formula'] as $field) {
                    if ($field !== 'quantity_formula') {
                        self::assertNotEmpty($source[$field]);
                    }
                    self::assertSame($source[$field] ?? null, $item['metadata'][$field]);
                }
                $exported[] = $item['metadata']['evaluation_confirmation']['scenario_id'];
            }
            self::assertSame(array_fill(0, count($workKeys), 'base'), $exported);
        } finally {
            $book?->disconnectWorksheets();
            unlink($path);
        }
    }
}
