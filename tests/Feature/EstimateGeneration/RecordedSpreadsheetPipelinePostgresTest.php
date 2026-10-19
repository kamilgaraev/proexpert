<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion;
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
    public function test_actual_xlsx_upload_and_published_measurements_reach_the_real_composer_and_final_signed_draft(): void
    {
        $this->app->singleton(ApplyComposerCorrectionCycle::class, static fn ($app): ApplyComposerCorrectionCycle => new ApplyComposerCorrectionCycle(
            $app->make(RunEstimateAudit::class), $app->make(RunEstimateComposerCorrection::class), 24576));
        $directory = sys_get_temp_dir().'/most-native-replay-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $input = $directory.'/dimensions.xlsx';
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Размеры')->fromArray([
            ['entity_key', 'parameter', 'value', 'unit', 'floor', 'document_role'],
            ['room:office', 'length', 5, 'м', '1', 'plan'],
            ['room:office', 'width', 10, 'м', '1', 'plan'],
            ['room:office', 'room_name', 'Офис', '', '1', 'plan'],
            ['room:office', 'room_number', '001', '', '1', 'plan'],
        ]);
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
            self::assertSame(4, $adapter->lastRun['provider_requests']);
            self::assertSame(4, $adapter->lastRun['requests_after_replay']);
            $auditSubjects = DB::table('estimate_generation_ai_role_runs')->where('session_id', $session->id)
                ->where('role', 'estimate_auditor')->pluck('subject_id')->all();
            self::assertCount(3, $auditSubjects);
            foreach ($auditSubjects as $subject) {
                self::assertStringStartsWith($session->id.':0:part:', $subject, 'Context parts are not extra audit/correction passes.');
            }
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
            self::assertSame('room-floor:'.hash('sha256', "finish_finishing-norm-intent-1\0".$roomEntityId), $positions[0]['key']);
            self::assertSame($roomEntityId, $positions[0]['entity_id']);
            self::assertSame('50', $positions[0]['quantity']);
            self::assertSame('m2', $positions[0]['unit']);
            self::assertSame('supported', $positions[0]['quantity_status']);
            self::assertSame('floor_area', $positions[0]['quantity_formula']);
            self::assertNotEmpty($positions[0]['quantity_evidence_details']);
            $roughFloor = array_values(array_filter($adapter->lastRun['evaluation']['scenarios'][0]['positions'], static fn (array $item): bool => $item['work_key'] === 'rough.floor'));
            self::assertCount(1, $roughFloor);
            self::assertSame('room-floor:'.hash('sha256', "rough_finishing-norm-intent-1\0".$roomEntityId), $roughFloor[0]['key']);
            self::assertSame($roomEntityId, $roughFloor[0]['entity_id']);
            self::assertSame('50', $roughFloor[0]['quantity']);
            self::assertSame('supported', $roughFloor[0]['quantity_status']);
            self::assertNull($positions[0]['price_snapshot']);
            $coveredDuplicates = array_values(array_filter($session->draft_payload['estimate_composition']['request_info'],
                static fn (array $notice): bool => $notice['reason'] === 'estimate_composer_physical_duplicate_covered'));
            self::assertCount(1, $coveredDuplicates);
            self::assertSame($positions[0]['key'], $coveredDuplicates[0]['covered_by_position_key']);
            self::assertNotContains('work_source_review', array_column($adapter->lastRun['evaluation']['missing_requirements'], 'requirement'));
            self::assertSame(4, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
            self::assertSame(3, DB::table('estimate_generation_ai_role_runs')->where('session_id', $session->id)
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
            $this->confirmAndApplyOriginalWorklist($session, $adapter, $roomEntityId);
        } finally {
            $book->disconnectWorksheets();
            if (is_file($input)) {
                unlink($input);
            }
            rmdir($directory);
        }
    }

    private function confirmAndApplyOriginalWorklist(EstimateGenerationSession $session, RecordedUniversalPipelineAdapter $adapter, string $roomEntityId): void
    {
        $actor = User::query()->findOrFail($session->user_id);
        $projectId = (int) $session->project_id;
        $sessionId = (int) $session->id;
        $organizationId = (int) $session->organization_id;
        $original = $adapter->lastRun['evaluation']['scenarios'][0]['positions'];
        $originalKeys = array_column($original, 'key');
        $nativePosition = array_values(array_filter($original, static fn (array $item): bool => $item['work_key'] === 'finish.floor'))[0];
        self::assertEqualsCanonicalizing([
            'room-floor:'.hash('sha256', "rough_finishing-norm-intent-1\0".$roomEntityId), 'rough_finishing-norm-intent-2', 'rough_finishing-norm-intent-3',
            'room-floor:'.hash('sha256', "finish_finishing-norm-intent-1\0".$roomEntityId), 'finish_finishing-norm-intent-2', 'finish_finishing-norm-intent-3',
            'finish_finishing-norm-intent-4', 'finishing', 'preparation',
        ], $originalKeys);
        $snapshot = app(ProjectModelRepository::class)->snapshot($organizationId, $projectId, $sessionId);
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
            $quantities[] = ['work_item_key' => $position['key'], 'value' => $native ? '50' : '1', 'unit' => $workUnit];
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
        }
        self::assertSame('1.74', $revision['result']['scenarios'][0]['total_by_currency']['RUB']);
        self::assertSame($originalKeys, array_column($revision['result']['scenarios'][0]['positions'], 'key'));
        self::assertTrue($revision['result']['scope_confirmed']);
        $confirmation = app(ConfirmEvaluationScenario::class)->handle($actor, $projectId, $sessionId,
            ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
                'revision_id' => $revision['revision_id'], 'content_hash' => $revision['content_hash'],
                'scenario_id' => 'base', 'assumptions_accepted' => true]);
        $request = Request::create('/export', 'GET', ['format' => 'excel']);
        $request->setUserResolver(static fn () => $actor);
        $beforeExport = $session->fresh()->getRawOriginal();
        $response = app(EstimateGenerationPackageController::class)->export($request, $session->project, $session->fresh());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        self::assertSame($beforeExport, $session->fresh()->getRawOriginal());
        $this->assertPipelineXlsx((string) $response->getContent(), $originalKeys);
        $assignment = UserRoleAssignment::query()->where('user_id', $actor->id)->firstOrFail();
        $role = OrganizationCustomRole::query()->where('organization_id', $organizationId)->where('slug', $assignment->role_slug)->firstOrFail();
        $permissions = $role->module_permissions;
        $permissions['ai-estimates'][] = 'estimate_generation.apply';
        $role->update(['module_permissions' => $permissions]);
        $command = new ApplyGeneratedEstimateCommand($sessionId, $organizationId, $projectId, $confirmation['state_version'],
            idempotencyKey: (string) Str::uuid(), artifactHash: $session->fresh()->draft_payload['artifact_hash'], actor: $actor);
        $applied = app(ApplyGeneratedEstimate::class)->handle($command);
        self::assertSame($applied->estimateId, app(ApplyGeneratedEstimate::class)->handle($command)->estimateId);
        $estimate = Estimate::query()->findOrFail($applied->estimateId);
        self::assertSame('1.74', $estimate->total_amount_with_vat);
        self::assertSame(count($original), $estimate->items()->whereNull('parent_work_id')->count());
        for ($iteration = 0; $iteration < 2; $iteration++) {
            app(EstimateCalculationService::class)->recalculateAll($estimate->fresh());
            self::assertSame('1.74', $estimate->fresh()->total_amount_with_vat);
        }
        $totals = (new \ReflectionMethod(EstimateExportService::class, 'prepareTotalsData'))->invoke(app(EstimateExportService::class), $estimate->fresh());
        self::assertSame('1.74', (string) $totals['total_amount_with_vat']);
        self::assertSame(4, DB::table('estimate_generation_ai_usage')->where('session_id', $sessionId)->count());
    }

    private function assertPipelineXlsx(string $content, array $workKeys): void
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
            self::assertSame(['1.74'], $gross);
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
                self::assertNotEmpty($item['metadata']['source_money']);
                $exported[] = $item['metadata']['evaluation_confirmation']['scenario_id'];
            }
            self::assertSame(array_fill(0, count($workKeys), 'base'), $exported);
        } finally {
            $book?->disconnectWorksheets();
            unlink($path);
        }
    }
}
