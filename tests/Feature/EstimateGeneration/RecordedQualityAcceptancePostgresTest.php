<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BindEvaluationWorkEntity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\ConfirmRoomIdentity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetRoomIdentitySources;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RecalculateUniversalEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\LocalBenchmarkObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\NativeNumericFactProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Storage\BoundedVersionedS3ObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\CadGeometryProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\CadConversionRuntime;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\DwgDxfGeometryProvider;
use App\Services\Storage\FileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;
use Tests\Support\EstimateGeneration\RecordedUniversalPipelineAdapter;

final class RecordedQualityAcceptancePostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_the_complete_selected_finishing_composition_has_real_entity_coverage_but_no_invented_prices_or_accuracy(): void
    {
        $directory = sys_get_temp_dir().'/most-full-scope-replay-'.bin2hex(random_bytes(8));
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
            $adapter = $this->adapter('xlsx-complete-selected-finishing-v1.json', new LocalBenchmarkObjectReader($directory));
            $result = $adapter->run(new BenchmarkPredictionCaseData('full-selected-finishing', BenchmarkDatasetType::Regression,
                BenchmarkSourceType::Spreadsheet, 'dimensions.xlsx', hash_file('sha256', $input), ['synthetic_transport_fixture'], []), 300000);
            self::assertSame('success', $result->status);
            $evaluation = $adapter->lastRun['evaluation'];
            foreach ($evaluation['scenarios'][0]['positions'] as $position) {
                if ($position['quantity'] !== null && $position['quantity_formula'] !== 'floor_area') {
                    self::assertSame('assumed', $position['quantity_status']);
                    self::assertNotEmpty($position['quantity_basis']['assumptions']);
                }
            }
            $this->assertReplay($adapter, 3, 50);
            $session = EstimateGenerationSession::query()->findOrFail($adapter->lastRun['session_id']);
            $actor = $session->user;
            $projectId = (int) $session->project_id;
            $sessionId = (int) $session->id;
            $identity = app(GetRoomIdentitySources::class)->handle($actor, $projectId, $sessionId)['sources'][0];
            $confirmation = app(ConfirmRoomIdentity::class)->handle($actor, $projectId, $sessionId,
                ['request_id' => (string) Str::uuid(), 'state_version' => (int) $session->fresh()->state_version,
                    'source_fact_id' => $identity['source_fact_id'], 'source_fact_version' => $identity['source_fact_version'],
                    'source_value_hash' => $identity['source_value_hash'], 'reason' => 'Подтверждено название помещения из ячейки XLSX.']);
            self::assertFalse($confirmation['dimensions_confirmed']);
            $identity = app(GetRoomIdentitySources::class)->handle($actor, $projectId, $sessionId)['sources'][0];
            foreach ($evaluation['scenarios'][0]['positions'] as $position) {
                if ($position['entity_id'] === $identity['entity_id']) {
                    continue;
                }
                $binding = app(BindEvaluationWorkEntity::class)->handle($actor, $projectId, $sessionId,
                    ['request_id' => (string) Str::uuid(), 'state_version' => (int) $session->fresh()->state_version,
                        'work_item_key' => $position['key'], 'source_fact_id' => $identity['source_fact_id'],
                        'source_fact_version' => $identity['source_fact_version'], 'source_value_hash' => $identity['source_value_hash'],
                        'reason' => 'Работа относится к помещению ведомости; отсутствующие размеры не подтверждаются.']);
                self::assertSame('0', $binding['cost_units']);
            }
            $revision = app(RecalculateUniversalEvaluation::class)->handle($actor, $projectId, $sessionId,
                (int) $session->fresh()->state_version, (string) Str::uuid());
            $evaluation = $revision['result'];
            self::assertSame(['finishing'], $evaluation['selected_scope']);
            $scope = $evaluation['coverage']['selected_scope'];
            self::assertSame(['finishing'], array_keys($scope));
            self::assertCount(1, $scope['finishing'], json_encode(['coverage' => $evaluation['coverage'],
                'positions' => array_map(static fn (array $position): array => array_intersect_key($position, array_flip(['key', 'entity_id', 'work_key', 'quantity', 'quantity_status', 'quantity_formula'])), $evaluation['scenarios'][0]['positions'])], JSON_THROW_ON_ERROR));
            self::assertSame(['preparation', 'finishing'], array_column($evaluation['coverage']['covered_requirements'], 'requirement'));
            self::assertSame([], $evaluation['coverage']['missing_requirements']);
            self::assertSame([], $evaluation['coverage']['not_applicable_requirements']);
            self::assertTrue($evaluation['coverage']['scope_complete']);
            self::assertFalse($evaluation['accuracy_calibrated']);
            self::assertFalse($evaluation['can_confirm_for_apply']);
            foreach ($evaluation['scenarios'][0]['positions'] as $position) {
                self::assertSame($scope['finishing'][0], $position['entity_id']);
                self::assertNull($position['price_snapshot']);
                self::assertNull($position['total_cost']);
            }
            $floors = array_values(array_filter($evaluation['scenarios'][0]['positions'], static fn (array $position): bool => $position['quantity_formula'] === 'floor_area'));
            self::assertCount(1, $floors);
            self::assertNotNull($floors[0]['quantity']);
            self::assertTrue(\Brick\Math\BigDecimal::of((string) $floors[0]['quantity'])->isEqualTo('50'));
            self::assertSame('floor_area', $floors[0]['quantity_formula']);
            self::assertNotEmpty($evaluation['priority_questions']);
            self::assertLessThanOrEqual(3, count($evaluation['priority_questions']));
            self::assertSame(count($adapter->lastRun['usage']), DB::table('estimate_generation_ai_usage')->where('session_id', $sessionId)->count());
        } finally {
            $book->disconnectWorksheets();
            if (is_file($input)) {
                unlink($input);
            }
            rmdir($directory);
        }
    }

    public function test_real_dxf_upload_and_native_parser_reach_all_nine_stages_without_promoting_one_dimension_to_area(): void
    {
        $python = getenv('MOST_CAD_TEST_PYTHON');
        self::assertIsString($python);
        self::assertNotSame('', $python);
        $this->app->instance(CadGeometryProvider::class, new DwgDxfGeometryProvider(
            new BoundedVersionedS3ObjectReader(app(FileService::class)),
            new CadConversionRuntime($python, base_path('app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py')),
        ));
        $root = base_path('tests/Fixtures/EstimateGeneration/Vision');
        $adapter = $this->adapter('cad-dimension-partial-v1.json', new LocalBenchmarkObjectReader($root));
        $result = $adapter->run(new BenchmarkPredictionCaseData('native-cad-partial', BenchmarkDatasetType::Regression, BenchmarkSourceType::Dxf,
            'native-dimension-3000mm.dxf', hash_file('sha256', $root.'/native-dimension-3000mm.dxf'), ['synthetic_transport_fixture'], []), 300000);
        self::assertSame('success', $result->status);
        $session = EstimateGenerationSession::query()->findOrFail($adapter->lastRun['session_id']);
        $snapshot = app(ProjectModelRepository::class)->snapshot((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
        $dimensions = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === 'dimension_chain'));
        self::assertCount(1, $dimensions);
        self::assertSame('3000', $dimensions[0]->value);
        self::assertSame('mm', $dimensions[0]->unit);
        self::assertSame('confirmed', $dimensions[0]->status);
        $proofs = array_column($snapshot->evidence, null, 'id');
        self::assertTrue((new NativeNumericFactProof)->matches($dimensions[0], $proofs[$dimensions[0]->evidenceIds[0]]));
        self::assertSame([], array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === 'area')));
        self::assertSame(['completed'], $adapter->lastRun['document_run']['units']);
        self::assertSame(0, $adapter->lastRun['document_run']['vision_requests']);
        foreach ($adapter->lastRun['evaluation']['scenarios'][0]['positions'] as $position) {
            self::assertNull($position['quantity']);
            self::assertNull($position['total_cost']);
        }
        self::assertFalse($adapter->lastRun['evaluation']['can_confirm_for_apply']);
        $this->assertReplay($adapter, 2);
    }

    public function test_document_instructions_and_forged_model_proposals_cannot_accept_quantity_price_or_an_industrial_scope(): void
    {
        $image = imagecreatetruecolor(32, 24);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        $objects = new class($bytes) implements BenchmarkObjectReader
        {
            public function __construct(private readonly string $bytes) {}

            public function read(\App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkCaseData|BenchmarkPredictionCaseData $case, string $role, int $maxBytes): string
            {
                if ($role !== 'input') {
                    throw new \LogicException('Expected data is unavailable to the pipeline.');
                }

                return $this->bytes;
            }
        };
        $adapter = $this->adapter('photo-hostile-proposals-v1.json', $objects);
        $result = $adapter->run(new BenchmarkPredictionCaseData('hostile-document-proposals', BenchmarkDatasetType::Regression,
            BenchmarkSourceType::SitePhoto, 'room.png', hash('sha256', $bytes), ['synthetic_transport_fixture'], []), 300000);
        self::assertSame('success', $result->status);
        $evaluation = $adapter->lastRun['evaluation'];
        self::assertSame(['finishing'], $evaluation['selected_scope']);
        self::assertFalse($evaluation['accuracy_calibrated']);
        self::assertFalse($evaluation['can_confirm_for_apply']);
        self::assertSame([], $evaluation['scenarios'][0]['known_subtotals']);
        foreach ($evaluation['scenarios'][0]['positions'] as $position) {
            self::assertNull($position['quantity']);
            self::assertNull($position['price_snapshot']);
            self::assertNull($position['total_cost']);
            self::assertSame('finishing', $position['section']);
        }
        self::assertSame([], $adapter->lastRun['confirmed_numeric_facts']);
        self::assertSame($adapter->lastRun['document_run']['vision_requests'], $adapter->lastRun['document_run']['vision_requests_after_replay']);
        $this->assertReplay($adapter, 2);
    }

    public function test_independent_native_plan_and_schedule_keep_conflicting_areas_visible_through_the_pipeline(): void
    {
        $directory = sys_get_temp_dir().'/most-source-conflict-replay-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        foreach (['plan' => '50', 'room_schedule' => '60'] as $role => $area) {
            $book = new Spreadsheet;
            try {
                $book->getActiveSheet()->setTitle('Помещения')->fromArray([
                    ['entity_key', 'parameter', 'value', 'unit', 'floor', 'zone', 'document_role'],
                    ['room:office', 'area', $area, 'м²', '1', 'A', $role],
                    ['room:office', 'room_number', '001', '', '1', 'A', $role],
                    ['room:office', 'room_name', 'Офис', '', '1', 'A', $role],
                ]);
                (new Xlsx($book))->save($directory.'/'.$role.'.xlsx');
            } finally {
                $book->disconnectWorksheets();
            }
        }
        try {
            $afterDocuments = function (EstimateGenerationSession $session) use ($directory): void {
                $upload = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\UploadEstimateGenerationDocuments::class)
                    ->handle($session, (int) $session->state_version,
                        [new \Illuminate\Http\UploadedFile($directory.'/room_schedule.xlsx', 'schedule.xlsx',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true)], $session->user, (string) Str::uuid());
                $queue = \Illuminate\Support\Facades\Queue::getFacadeRoot();
                self::assertInstanceOf(\Illuminate\Support\Testing\Fakes\QueueFake::class, $queue);
                $manifest = $queue->pushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob::class)->last();
                self::assertInstanceOf(\App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob::class, $manifest);
                $manifest->handle(app(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessEstimateGenerationDocument::class));
                $unit = $queue->pushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationUnitJob::class)->last();
                self::assertInstanceOf(\App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationUnitJob::class, $unit);
                $unit->handle(app(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessDocumentUnit::class));
                self::assertSame('ready', $upload->documents->sole()->fresh()->status);
                $snapshot = app(ProjectModelRepository::class)->snapshot((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
                $linked = (new \App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\CrossDocumentFactLinker(
                    app(\App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\TargetedConflictResolver::class),
                ))->link($snapshot->entities, $snapshot->facts, $snapshot->evidence);
                $conflicts = array_values(array_filter($linked->conflicts, static fn ($conflict): bool => $conflict->facts[0]->type === 'area'));
                self::assertCount(1, $conflicts, json_encode(['facts' => array_map(static fn ($fact): array => [
                    'type' => $fact->type, 'status' => $fact->status, 'entity_id' => $fact->entityId, 'value' => $fact->value,
                ], $snapshot->facts), 'proofs' => array_map(static fn ($proof): array => ['producer' => $proof->producerName,
                    'role' => $proof->documentRole, 'reference' => $proof->sourceReference, 'native' => $proof->nativeReference], $snapshot->evidence)], JSON_THROW_ON_ERROR));
                $areas = array_values(array_unique(array_column($conflicts[0]->facts, 'value')));
                sort($areas, SORT_STRING);
                self::assertSame(['50', '60'], $areas);
                self::assertCount(2, array_unique(array_column($conflicts[0]->facts, 'sourceVersion')));
                $proofs = array_column($snapshot->evidence, null, 'id');
                foreach ($conflicts[0]->facts as $fact) {
                    self::assertTrue((new NativeNumericFactProof)->matches($fact, $proofs[$fact->evidenceIds[0]]));
                }
            };
            $adapter = $this->adapter('xlsx-native-source-conflict-v1.json', new LocalBenchmarkObjectReader($directory), $afterDocuments);
            $input = $directory.'/plan.xlsx';
            $adapter->run(new BenchmarkPredictionCaseData('native-source-conflict', BenchmarkDatasetType::Regression,
                BenchmarkSourceType::Spreadsheet, 'plan.xlsx', hash_file('sha256', $input), ['synthetic_transport_fixture'], []), 300000);
            $session = EstimateGenerationSession::query()->findOrFail($adapter->lastRun['session_id']);
            $snapshot = app(ProjectModelRepository::class)->snapshot((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
            self::assertCount(1, array_filter($snapshot->conflicts, static fn ($conflict): bool => $conflict->facts[0]->type === 'area'));
            $evaluation = $adapter->lastRun['evaluation'];
            self::assertContains('source_conflict', array_column($evaluation['missing_requirements'], 'requirement'));
            self::assertContains('review_source_questions', array_column($evaluation['priority_questions'], 'next_action'));
            self::assertFalse($evaluation['can_confirm_for_apply']);
            $this->assertReplay($adapter, 3);
        } finally {
            foreach (['plan', 'room_schedule'] as $role) {
                unlink($directory.'/'.$role.'.xlsx');
            }
            rmdir($directory);
        }
    }

    private function adapter(string $filename, BenchmarkObjectReader $objects, ?\Closure $afterDocuments = null): RecordedUniversalPipelineAdapter
    {
        $fixture = base_path('tests/Fixtures/EstimateGeneration/universal-replay/'.$filename);

        return new RecordedUniversalPipelineAdapter($this->app, $objects, $fixture, hash_file('sha256', $fixture), $afterDocuments);
    }

    private function assertReplay(RecordedUniversalPipelineAdapter $adapter, int $requests, int $consumed = 0): void
    {
        $stages = array_column(ProcessingStage::cases(), 'value');
        sort($stages);
        self::assertSame($stages, $adapter->lastRun['completed_stages']);
        self::assertSame($requests, $adapter->lastRun['provider_requests']);
        self::assertSame($requests, $adapter->lastRun['requests_after_replay']);
        self::assertSame(count($adapter->lastRun['usage']), $adapter->lastRun['usage_after_replay']);
        $session = EstimateGenerationSession::query()->findOrFail($adapter->lastRun['session_id']);
        self::assertTrue(app(BuildMostEstimateDraft::class)->verifyArtifact($session->draft_payload));
        self::assertSame($consumed, $adapter->lastRun['consumed_units_minor']);
        self::assertSame(1, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count());
    }
}
