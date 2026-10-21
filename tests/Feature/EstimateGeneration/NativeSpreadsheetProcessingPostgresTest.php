<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitClaimStatus;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceManifestStorage;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitContentReader;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitDetector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessDocumentUnit;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SeekableDocumentSource;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\StoredDocumentArtifact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\NativeNumericFactProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class NativeSpreadsheetProcessingPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_real_xlsx_through_detector_production_processor_publication_and_formula_is_free_and_replayable(): void
    {
        $this->processWorkbook(false);
    }

    public function test_xlsx_formula_remains_unconfirmed_and_cannot_be_used_as_a_measured_dimension(): void
    {
        $this->processWorkbook(true);
    }

    public function test_real_xlsx_publishes_a_scoped_native_equipment_count_with_exact_quantity_operands(): void
    {
        $this->processWorkbook(false, true);
    }

    public function test_real_xlsx_equipment_count_formula_cannot_become_a_native_measured_count(): void
    {
        $this->processWorkbook(true, true);
    }

    private function processWorkbook(bool $withFormula, bool $equipment = false): void
    {
        Http::fake([]);
        Http::preventStrayRequests();
        Queue::fake();
        $ocr = \Mockery::mock(OcrClientInterface::class);
        $ocr->shouldNotReceive('recognize');
        $this->app->instance(OcrClientInterface::class, $ocr);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle($equipment ? 'Оборудование' : 'Размеры')->fromArray($equipment ? [
            ['entity_key', 'parameter', 'value', 'unit'],
            ['equipment:boiler', 'equipment_specification', 'Электрический котёл отопления массой до 30 кг.', ''],
            ['equipment:boiler', 'equipment_count', $withFormula ? '=1+2' : 3, 'шт'],
        ] : [
            ['entity_key', 'parameter', 'value', 'unit', 'floor'],
            ['room:office', 'length', $withFormula ? '=2+3' : 5, 'м', '1'],
            ['room:office', 'width', 10, 'м', '1'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'most-native-chain-');
        self::assertIsString($path);
        try {
            (new Xlsx($spreadsheet))->save($path);
            $spreadsheet->disconnectWorksheets();
            $organization = Organization::factory()->create();
            $project = Project::factory()->for($organization)->create();
            $user = User::factory()->create(['current_organization_id' => $organization->id]);
            $session = EstimateGenerationSession::query()->create([
                'organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $user->id,
                'status' => 'draft', 'processing_stage' => 'draft', 'processing_progress' => 0,
                'state_version' => 0, 'input_payload' => [],
            ]);
            $version = 'sha256:'.hash_file('sha256', $path);
            $document = EstimateGenerationDocument::query()->create([
                'organization_id' => $organization->id, 'project_id' => $project->id, 'session_id' => $session->id,
                'user_id' => $user->id, 'filename' => $equipment ? 'equipment.xlsx' : 'dimensions.xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'storage_path' => 'org-'.$organization->id.'/estimate-generation/native-tests/source.xlsx',
                'source_version' => $version, 'checksum_sha256' => substr($version, 7),
            ]);
            $storage = new class($path) implements DocumentSourceManifestStorage, DocumentUnitContentReader
            {
                private array $artifacts = [];

                public function __construct(private readonly string $sourcePath) {}

                public function open(EstimateGenerationDocument $document, string $sourceVersion): SeekableDocumentSource
                {
                    if ($sourceVersion !== 'sha256:'.hash_file('sha256', $this->sourcePath)) {
                        throw new \LogicException('Source digest mismatch.');
                    }

                    return new SeekableDocumentSource(fopen($this->sourcePath, 'rb'), filesize($this->sourcePath));
                }

                public function put(EstimateGenerationDocument $document, string $sourceVersion, DocumentUnitType $type, int $index, string $content, string $contentType = 'text/plain'): StoredDocumentArtifact
                {
                    $hash = 'sha256:'.hash('sha256', $content);
                    $key = 'org-'.$document->organization_id.'/estimate-generation/native-tests/'.substr($hash, 7);
                    $this->artifacts[$key] = $content;

                    return new StoredDocumentArtifact($key, strlen($content), $hash, $contentType);
                }

                public function read(DocumentUnitExecutionContext $context): string
                {
                    $key = $context->locator['artifact_path'];
                    $content = $this->artifacts[$key];
                    if (! str_starts_with($key, 'org-'.$context->organizationId.'/')
                        || 'sha256:'.hash('sha256', $content) !== $context->locator['artifact_sha256']
                        || strlen($content) !== $context->locator['artifact_bytes']) {
                        throw new \LogicException('Artifact scope or integrity mismatch.');
                    }

                    return $content;
                }
            };
            $this->app->instance(DocumentSourceManifestStorage::class, $storage);
            $this->app->instance(DocumentUnitContentReader::class, $storage);
            $units = app(DocumentUnitDetector::class)->detect($document, $version);
            self::assertCount(1, $units);
            self::assertSame(DocumentUnitType::SpreadsheetSheet, $units[0]->type);
            $store = app(DocumentProcessingUnitStore::class);
            $unit = $store->create((int) $organization->id, (int) $project->id, (int) $session->id, (int) $document->id, $units[0]);
            $outcome = app(ProcessDocumentUnit::class)->handle($unit->id, $version);
            self::assertSame(DocumentProcessingUnitClaimStatus::Acquired, $outcome->status);
            self::assertSame('completed', $store->find($unit->id)->status->value);
            $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning((int) $organization->id, (int) $project->id, (int) $session->id, 100)['snapshot'];
            self::assertCount($withFormula ? 1 : 2, $snapshot->facts);
            if (! $equipment) {
                self::assertCount(1, $snapshot->entities);
            }
            foreach ($snapshot->facts as $fact) {
                self::assertSame('document', $fact->origin);
                if ($equipment) {
                    continue;
                }
                self::assertSame('м', $fact->unit);
                $evidence = array_values(array_filter($snapshot->evidence, static fn ($item): bool => in_array($item->id, $fact->evidenceIds, true)))[0];
                self::assertStringStartsWith('xlsx:sheet:Размеры!C', $evidence->nativeReference);
                if (! $withFormula || $fact->type === 'width') {
                    self::assertSame('confirmed', $fact->status);
                    self::assertTrue((new NativeNumericFactProof)->matches($fact, $evidence));
                } else {
                    self::assertNotSame('confirmed', $fact->status);
                }
            }
            $projection = app(CurrentProjectDerivedQuantityService::class)->previewInput((int) $organization->id, (int) $project->id, (int) $session->id);
            if ($equipment) {
                $specifications = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === 'equipment_specification'));
                self::assertCount(1, $specifications);
                $specification = $specifications[0];
                self::assertSame('Электрический котёл отопления массой до 30 кг.', $specification->value);
                self::assertNull($specification->unit);
                self::assertSame([(int) $organization->id, (int) $project->id, (int) $session->id, $version],
                    [$specification->organizationId, $specification->projectId, $specification->sessionId, $specification->sourceVersion]);
                self::assertSame('document', $specification->origin);
                self::assertSame('confirmed', $specification->status);
                self::assertSame([], $specification->nativeNumericProofs);
                $entities = array_values(array_filter($snapshot->entities, static fn ($entity): bool => $entity->id === $specification->entityId));
                self::assertCount(1, $entities);
                self::assertSame('equipment:boiler', $entities[0]->attributes['properties']['source_entity_key']);
                $sources = array_values(array_filter($snapshot->evidence, static fn ($evidence): bool => in_array($evidence->id, $specification->evidenceIds, true)));
                self::assertCount(1, $sources);
                $source = $sources[0];
                self::assertSame($version, $source->sourceVersion);
                self::assertSame('document:'.$document->id, $source->sourceReference);
                self::assertSame('xlsx:sheet:Оборудование!C2', $source->nativeReference);
                self::assertSame('drawing_analyzer', $source->producerName);
                self::assertFalse((new NativeNumericFactProof)->matches($specification, $source));
                $locator = json_decode(DB::table('estimate_generation_evidence')->where('id', (int) substr($source->id, strlen('evidence:')))->value('locator'), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame([(int) $document->id, DocumentUnitType::SpreadsheetSheet->value, 1],
                    [$locator['document_id'], $locator['unit_type'], $locator['unit_index']]);
                $counts = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === 'equipment_count'));
                self::assertCount($withFormula ? 0 : 1, $counts);
                if ($withFormula) {
                    self::assertSame('candidate', DB::table('estimate_generation_project_model_assertions')
                        ->where('session_id', $session->id)->where('assertion_type', 'equipment_count')->value('fact_status'));
                    self::assertSame([], array_values(array_filter($projection['quantities'],
                        static fn ($quantity): bool => $quantity->formulaKey === 'equipment_count')));
                } else {
                    $count = $counts[0];
                    self::assertSame([(int) $organization->id, (int) $project->id, (int) $session->id, $version],
                        [$count->organizationId, $count->projectId, $count->sessionId, $count->sourceVersion]);
                    self::assertSame('confirmed', $count->status);
                    self::assertSame('3', $count->value);
                    self::assertSame('шт', $count->unit);
                    $proofs = array_values(array_filter($snapshot->evidence,
                        static fn ($evidence): bool => in_array($evidence->id, $count->evidenceIds, true)));
                    self::assertCount(1, $proofs);
                    self::assertSame('xlsx:sheet:Оборудование!C3', $proofs[0]->nativeReference);
                    self::assertNotEmpty($proofs[0]->fingerprint);
                    self::assertTrue((new NativeNumericFactProof)->matches($count, $proofs[0]));
                    $quantity = (new WorkItemQuantityMapper)->mapForEntity('equipment.install', $projection['quantities'], $count->entityId);
                    self::assertNotNull($quantity);
                    self::assertSame('3', $quantity->amount);
                    self::assertSame('count', $quantity->unit);
                    self::assertSame('equipment_count', $quantity->formulaKey);
                    self::assertSame($count->entityId, $quantity->formulaInputs['entity_id']);
                    self::assertCount(1, $quantity->formulaInputs['operands']);
                    $operand = $quantity->formulaInputs['operands'][0];
                    self::assertSame($count->id, $operand['fact_id']);
                    self::assertSame($version, $operand['source_version']);
                    self::assertSame($count->evidenceIds, $operand['evidence_ids']);
                    self::assertTrue($operand['current']);
                }
            } elseif ($withFormula) {
                self::assertSame('candidate', DB::table('estimate_generation_project_model_assertions')
                    ->where('session_id', $session->id)->where('assertion_type', 'length')->value('fact_status'));
                self::assertArrayNotHasKey('floor_area', $projection['quantities']);
            } else {
                self::assertSame('50', $projection['quantities']['floor_area']->amount);
                self::assertSame('floor_area', $projection['quantities']['floor_area']->formulaKey);
                self::assertSame('m2', $projection['quantities']['floor_area']->unit);
            }
            $counts = [];
            foreach (['estimate_generation_project_model_assertions', 'estimate_generation_evidence', 'estimate_generation_document_facts'] as $table) {
                $counts[$table] = DB::table($table)->where('session_id', $session->id)->count();
            }
            self::assertSame(DocumentProcessingUnitClaimStatus::AlreadyCompleted, app(ProcessDocumentUnit::class)->handle($unit->id, $version)->status);
            foreach ($counts as $table => $count) {
                self::assertSame($count, DB::table($table)->where('session_id', $session->id)->count());
            }
            self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
            Http::assertNothingSent();
        } finally {
            $spreadsheet->disconnectWorksheets();
            unlink($path);
        }
    }
}
