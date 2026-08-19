<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationDecision;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\AtomicDocumentUnitPublicationWriter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitAggregateReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitOutput;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProcessor;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitPublication;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitPublicationWriter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EloquentDocumentProcessingUnitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessDocumentUnit;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\EloquentProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EloquentEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentFact;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorder;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('postgres-contract')]
final class AtomicDocumentUnitPublicationPostgresTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function accepted_consensus_is_atomically_persisted_as_evidence_project_model_and_document_facts(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        self::assertSame('1', getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT'));

        DB::beginTransaction();
        try {
            $organization = Organization::factory()->create();
            $project = Project::factory()->for($organization)->create();
            $user = User::factory()->create();
            $sourceVersion = 'sha256:'.str_repeat('7', 64);
            $session = EstimateGenerationSession::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'status' => 'draft',
                'processing_stage' => 'draft',
                'processing_progress' => 0,
                'input_payload' => [
                    'description' => 'Требуется устройство цементной стяжки пола по подтверждённой площади.',
                ],
                'state_version' => 0,
            ]);
            $document = EstimateGenerationDocument::query()->create([
                'session_id' => $session->id,
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'filename' => 'production-shaped.pdf',
                'mime_type' => 'application/pdf',
                'source_version' => $sourceVersion,
            ]);
            $unit = EstimateGenerationProcessingUnit::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'session_id' => $session->id,
                'document_id' => $document->id,
                'unit_type' => 'pdf_page',
                'unit_index' => 2,
                'source_version' => $sourceVersion,
                'status' => 'pending',
                'locator' => [
                    'source_kind' => 'pdf',
                    'source_version' => $sourceVersion,
                    'coordinate_space' => 'pdf_page_pixels',
                    'artifact_path' => 'org-'.$organization->id.'/estimate-generation/tests/page-2.png',
                    'artifact_source_version' => 'sha256:'.str_repeat('8', 64),
                    'artifact_version_id' => 'atomic-publication-page-2',
                    'artifact_bytes' => 1,
                    'artifact_sha256' => 'sha256:'.str_repeat('8', 64),
                    'content_type' => 'image/png',
                ],
                'metadata' => ['processing_attempt_id' => '217e7395-bdd5-4673-9748-19714ef82d45'],
            ]);
            $writer = new ProjectModelEvidenceWriter(
                new EloquentProjectModelRepository(app('db')),
                new EloquentEvidenceRepository(DB::connection()),
            );
            $processor = new class implements DocumentUnitProcessor
            {
                public function process(DocumentUnitExecutionContext $context): DocumentUnitOutput
                {
                    $claim = new ObservationClaim(
                        'literal:material:page-2',
                        'observer_literal',
                        'material.page-2',
                        'material',
                        ['type' => 'string', 'data' => 'Обезличенный материал'],
                        null,
                        'literal:material:page-2',
                        true,
                        $context->organizationId,
                        $context->projectId,
                        $context->sessionId,
                        $context->sourceVersion,
                        [
                            'page' => $context->index,
                            'unit_type' => $context->type->value,
                            'unit_index' => $context->index,
                            'source_version' => $context->sourceVersion,
                            'explicit' => true,
                        ],
                        confidence: 0.92,
                    );
                    $area = new ObservationClaim(
                        'literal:area:page-2',
                        'observer_literal',
                        'building_area_total',
                        'area',
                        ['type' => 'number', 'data' => '72.19'],
                        'm2',
                        'literal:area:page-2',
                        true,
                        $context->organizationId,
                        $context->projectId,
                        $context->sessionId,
                        $context->sourceVersion,
                        [
                            'page' => $context->index,
                            'unit_type' => $context->type->value,
                            'unit_index' => $context->index,
                            'source_version' => $context->sourceVersion,
                            'explicit' => true,
                        ],
                        confidence: 0.96,
                    );
                    $constructionArea = new ObservationClaim(
                        'construction:area:page-2',
                        'observer_construction',
                        'building.area-total',
                        'area',
                        ['type' => 'number', 'data' => '72.19'],
                        'м²',
                        'construction:area:page-2',
                        true,
                        $context->organizationId,
                        $context->projectId,
                        $context->sessionId,
                        $context->sourceVersion,
                        $area->locator,
                        confidence: 0.91,
                    );
                    $riskArea = new ObservationClaim(
                        'risk:area:page-2',
                        'observer_risk',
                        'building_area_total',
                        'area',
                        ['type' => 'number', 'data' => '72.190'],
                        'm2',
                        'risk:area:page-2',
                        true,
                        $context->organizationId,
                        $context->projectId,
                        $context->sessionId,
                        $context->sourceVersion,
                        $area->locator,
                        confidence: 0.89,
                    );

                    return new DocumentUnitOutput(
                        version: 'production-shaped:v1',
                        text: 'АР',
                        confidence: 0.98,
                        normalizedPayload: ['independent_observations' => []],
                        unitType: $context->type,
                        unitIndex: $context->index,
                        sourceVersion: $context->sourceVersion,
                        publication: new DocumentUnitPublication(
                            [$claim, $area, $constructionArea, $riskArea],
                            [
                                new ArbitrationDecision(
                                    claimId: $claim->id,
                                    status: 'accepted',
                                    supportingClaimIds: [$claim->id],
                                    evidenceRefs: [$claim->evidenceRef],
                                    reasonCode: 'arbiter_consensus',
                                    canonicalClaim: [
                                        'entity_key' => $claim->entityKey,
                                        'fact_type' => $claim->factType,
                                        'value' => $claim->value,
                                        'unit' => $claim->unit,
                                        'source_claim_id' => $claim->id,
                                    ],
                                ),
                                new ArbitrationDecision(
                                    claimId: $constructionArea->id,
                                    status: 'accepted',
                                    supportingClaimIds: [$area->id, $constructionArea->id, $riskArea->id],
                                    evidenceRefs: [$area->evidenceRef, $constructionArea->evidenceRef, $riskArea->evidenceRef],
                                    reasonCode: 'arbiter_area_consensus',
                                    canonicalClaim: [
                                        'entity_key' => 'building_area_total',
                                        'fact_type' => 'area',
                                        'value' => ['type' => 'number', 'data' => '72.19'],
                                        'unit' => 'm2',
                                        'source_claim_id' => $constructionArea->id,
                                    ],
                                ),
                                new ArbitrationDecision(
                                    claimId: $riskArea->id,
                                    status: 'accepted',
                                    supportingClaimIds: [$area->id, $constructionArea->id, $riskArea->id],
                                    evidenceRefs: [$area->evidenceRef, $constructionArea->evidenceRef, $riskArea->evidenceRef],
                                    reasonCode: 'arbiter_area_consensus',
                                    canonicalClaim: [
                                        'entity_key' => 'building_area_total',
                                        'fact_type' => 'area',
                                        'value' => ['type' => 'number', 'data' => '72.19'],
                                        'unit' => 'm2',
                                        'source_claim_id' => $riskArea->id,
                                    ],
                                ),
                                new ArbitrationDecision(
                                    claimId: $area->id,
                                    status: 'accepted',
                                    supportingClaimIds: [$area->id],
                                    evidenceRefs: [$area->evidenceRef],
                                    reasonCode: 'arbiter_consensus',
                                    canonicalClaim: [
                                        'entity_key' => $area->entityKey,
                                        'fact_type' => $area->factType,
                                        'value' => $area->value,
                                        'unit' => $area->unit,
                                        'source_claim_id' => $area->id,
                                    ],
                                ),
                            ],
                        ),
                    );
                }
            };
            $reconciler = new class implements DocumentUnitAggregateReconciler
            {
                public function reconcile(int $documentId, string $sourceVersion): void {}
            };

            (new ProcessDocumentUnit(
                new EloquentDocumentProcessingUnitStore(
                    DB::connection(),
                    new AtomicDocumentUnitPublicationWriter(DB::connection(), $writer),
                ),
                $processor,
                $reconciler,
                app(FailureRecorder::class),
            ))->handle((int) $unit->id, $sourceVersion);

            $persisted = EstimateGenerationProcessingUnit::query()->findOrFail($unit->id);
            self::assertSame('completed', $persisted->status->value);
            self::assertNull($persisted->failure_code);
            self::assertSame(4, DB::table('estimate_generation_evidence')
                ->where('organization_id', $organization->id)
                ->where('project_id', $project->id)
                ->where('session_id', $session->id)
                ->where('source_version', $sourceVersion)
                ->count());
            self::assertDatabaseHas('estimate_generation_document_facts', [
                'document_id' => $document->id,
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'session_id' => $session->id,
                'fact_type' => 'total_area',
                'scope_key' => 'building_area_total',
                'value_number' => 72.1900,
                'unit' => 'm2',
                'confidence' => 0.96,
            ]);
            self::assertSame(2, EstimateGenerationDocumentFact::query()
                ->where('document_id', $document->id)
                ->count());
            $areaFact = EstimateGenerationDocumentFact::query()
                ->where('document_id', $document->id)
                ->where('fact_type', 'total_area')
                ->firstOrFail();
            self::assertSame(
                ['construction:area:page-2', 'literal:area:page-2', 'risk:area:page-2'],
                $areaFact->normalized_payload['claim_ids'],
            );
            self::assertSame(0.96, (float) $areaFact->confidence);
            self::assertDatabaseHas('estimate_generation_document_facts', [
                'document_id' => $document->id,
                'fact_type' => 'material',
                'scope_key' => 'material.page-2',
                'value_text' => 'Обезличенный материал',
            ]);
            self::assertDatabaseHas('estimate_generation_quantity_takeoffs', [
                'document_id' => $document->id,
                'scope_key' => 'rough_floor_area',
                'unit' => 'm2',
                'quantity' => 72.1900,
                'formula' => 'accepted_building_area_total',
                'confidence' => 0.96,
            ]);
            self::assertSame(2, DB::table('estimate_generation_project_model_assertions')
                ->where('organization_id', $organization->id)
                ->where('project_id', $project->id)
                ->where('session_id', $session->id)
                ->where('fact_status', 'confirmed')
                ->count());
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function page_projection_failure_rolls_back_evidence_and_terminal_unit_state_together(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        DB::beginTransaction();
        try {
            $organization = Organization::factory()->create();
            $project = Project::factory()->for($organization)->create();
            $user = User::factory()->create();
            $sourceVersion = 'sha256:'.str_repeat('9', 64);
            $session = EstimateGenerationSession::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'status' => 'draft',
                'processing_stage' => 'draft',
                'processing_progress' => 0,
                'input_payload' => [],
                'state_version' => 0,
            ]);
            $document = EstimateGenerationDocument::query()->create([
                'session_id' => $session->id,
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'filename' => 'rollback.pdf',
                'mime_type' => 'application/pdf',
                'source_version' => $sourceVersion,
            ]);
            $unit = EstimateGenerationProcessingUnit::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'session_id' => $session->id,
                'document_id' => $document->id,
                'unit_type' => 'pdf_page',
                'unit_index' => 1,
                'source_version' => $sourceVersion,
                'status' => 'pending',
                'locator' => [
                    'source_kind' => 'pdf',
                    'source_version' => $sourceVersion,
                    'coordinate_space' => 'pdf_page_pixels',
                    'artifact_path' => 'org-'.$organization->id.'/estimate-generation/tests/rollback.png',
                    'artifact_source_version' => 'sha256:'.str_repeat('a', 64),
                    'artifact_version_id' => 'atomic-publication-rollback',
                    'artifact_bytes' => 1,
                    'artifact_sha256' => 'sha256:'.str_repeat('a', 64),
                    'content_type' => 'image/png',
                ],
                'metadata' => ['processing_attempt_id' => '11d32406-a207-4613-8dbf-9f572fe7be95'],
            ]);
            $writer = new ProjectModelEvidenceWriter(
                new EloquentProjectModelRepository(app('db')),
                new EloquentEvidenceRepository(DB::connection()),
            );
            $atomicWriter = new AtomicDocumentUnitPublicationWriter(DB::connection(), $writer);
            $failingWriter = new class($atomicWriter) implements DocumentUnitPublicationWriter
            {
                public function __construct(private readonly AtomicDocumentUnitPublicationWriter $writer) {}

                public function transaction(int $organizationId, int $sessionId, callable $callback): mixed
                {
                    return $this->writer->transaction($organizationId, $sessionId, $callback);
                }

                public function write(
                    DocumentUnitPublication $publication,
                    int $organizationId,
                    int $projectId,
                    int $sessionId,
                    int $documentId,
                    int $pageNumber,
                    string $sourceVersion,
                ): void {
                    $this->writer->write(
                        $publication,
                        $organizationId,
                        $projectId,
                        $sessionId,
                        $documentId,
                        $pageNumber,
                        $sourceVersion,
                    );

                    throw new \LogicException('injected_after_evidence_write');
                }
            };
            $store = new EloquentDocumentProcessingUnitStore(DB::connection(), $failingWriter);
            $now = now()->toDateTimeImmutable();
            $claim = $store->claim(
                (int) $unit->id,
                $sourceVersion,
                $now,
                $now->modify('+120 seconds'),
                ProcessDocumentUnit::MAX_ATTEMPTS,
            );
            $context = $store->executionContext($claim);
            self::assertInstanceOf(DocumentUnitExecutionContext::class, $context);
            $observation = new ObservationClaim(
                'literal:rollback', 'observer_literal', 'material.rollback', 'material',
                ['type' => 'string', 'data' => 'Обезличенный материал'], null, 'literal:rollback', true,
                $context->organizationId, $context->projectId, $context->sessionId, $context->sourceVersion,
                ['page' => 1, 'unit_type' => 'pdf_page', 'unit_index' => 1, 'source_version' => $sourceVersion, 'explicit' => true],
            );
            $publication = new DocumentUnitPublication([$observation], [new ArbitrationDecision(
                $observation->id,
                'candidate',
                [$observation->id],
                [$observation->evidenceRef],
                'independent_observation_preserved',
                null,
                '',
            )]);

            try {
                $store->publish($claim, new DocumentUnitOutput(
                    version: 'rollback:v1',
                    text: 'АР',
                    confidence: 0.95,
                    unitType: $context->type,
                    unitIndex: $context->index,
                    sourceVersion: $context->sourceVersion,
                    publication: $publication,
                ), $now->modify('+1 second'));
                self::fail('Invalid page projection unexpectedly committed.');
            } catch (\LogicException $exception) {
                self::assertSame('injected_after_evidence_write', $exception->getMessage());
            }

            self::assertSame(0, DB::table('estimate_generation_evidence')
                ->where('organization_id', $organization->id)
                ->where('session_id', $session->id)
                ->count());
            self::assertSame('running', EstimateGenerationProcessingUnit::query()->findOrFail($unit->id)->status->value);
            self::assertSame('processing', DB::table('estimate_generation_document_pages')
                ->where('document_id', $document->id)
                ->where('page_number', 1)
                ->value('status'));
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function thirty_valid_numeric_items_survive_oversized_and_overprecision_items(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        DB::beginTransaction();
        try {
            [$unit, $sourceVersion, , $store] = $this->publicationFixture('e', 6);
            $now = now()->toDateTimeImmutable();
            $claim = $store->claim(
                (int) $unit->id,
                $sourceVersion,
                $now,
                $now->modify('+120 seconds'),
                ProcessDocumentUnit::MAX_ATTEMPTS,
            );
            $context = $store->executionContext($claim);
            self::assertInstanceOf(DocumentUnitExecutionContext::class, $context);

            $claims = [];
            $decisions = [];
            for ($index = 1; $index <= 30; $index++) {
                $observation = $this->numericObservation($context, 'room:'.$index, $index.'.1234');
                $claims[] = $observation;
                $decisions[] = $this->acceptedDecision($observation);
            }
            foreach (['room:oversized' => '1000000000001', 'room:overprecision' => '0.12345'] as $key => $value) {
                $observation = $this->numericObservation($context, $key, $value);
                $claims[] = $observation;
                $decisions[] = $this->acceptedDecision($observation);
            }

            $published = $store->publish($claim, new DocumentUnitOutput(
                version: 'numeric-quarantine:v1',
                text: 'АР',
                confidence: 0.95,
                unitType: $context->type,
                unitIndex: $context->index,
                sourceVersion: $context->sourceVersion,
                publication: new DocumentUnitPublication($claims, $decisions),
            ), $now->modify('+1 second'));

            self::assertTrue($published);
            self::assertSame(30, DB::table('estimate_generation_evidence')
                ->where('organization_id', $context->organizationId)
                ->where('project_id', $context->projectId)
                ->where('session_id', $context->sessionId)
                ->where('source_version', $sourceVersion)
                ->count());
            self::assertSame(30, EstimateGenerationDocumentFact::query()
                ->where('document_id', $context->documentId)
                ->count());
            self::assertSame('1.1234', EstimateGenerationDocumentFact::query()
                ->where('document_id', $context->documentId)
                ->where('scope_key', 'room:1')
                ->firstOrFail()
                ->value_number);
            self::assertFalse(EstimateGenerationDocumentFact::query()
                ->where('document_id', $context->documentId)
                ->whereIn('scope_key', ['room:oversized', 'room:overprecision'])
                ->exists());
            self::assertSame(30, DB::table('estimate_generation_project_model_assertions')
                ->where('organization_id', $context->organizationId)
                ->where('project_id', $context->projectId)
                ->where('session_id', $context->sessionId)
                ->where('fact_status', 'confirmed')
                ->count());
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function signed_elevation_zero_level_and_thirty_neighbor_facts_are_published_atomically(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        DB::beginTransaction();
        try {
            [$unit, $sourceVersion, , $store] = $this->publicationFixture('f', 7);
            $now = now()->toDateTimeImmutable();
            $claim = $store->claim(
                (int) $unit->id,
                $sourceVersion,
                $now,
                $now->modify('+120 seconds'),
                ProcessDocumentUnit::MAX_ATTEMPTS,
            );
            $context = $store->executionContext($claim);
            self::assertInstanceOf(DocumentUnitExecutionContext::class, $context);

            $claims = [];
            $decisions = [];
            for ($index = 1; $index <= 30; $index++) {
                $observation = $this->numericObservation($context, 'room:'.$index, $index.'.1234');
                $claims[] = $observation;
                $decisions[] = $this->acceptedDecision($observation);
            }
            $elevation = $this->numericObservation(
                $context,
                'level:below-grade',
                '-0.3000',
                'elevation',
                'm',
            );
            $claims[] = $elevation;
            $decisions[] = $this->acceptedDecision($elevation);
            $belowGroundStoreys = $this->numericObservation(
                $context,
                'building:below_ground_storeys',
                '0',
                'level',
                'pcs',
            );
            $claims[] = $belowGroundStoreys;
            $decisions[] = $this->acceptedDecision($belowGroundStoreys);
            $negativeArea = $this->numericObservation(
                $context,
                'building:invalid-area',
                '-72.19',
            );
            $claims[] = $negativeArea;
            $decisions[] = $this->acceptedDecision($negativeArea);

            try {
                $published = $store->publish($claim, new DocumentUnitOutput(
                    version: 'signed-elevation:v1',
                    text: 'Отметка -0,300',
                    confidence: 0.95,
                    unitType: $context->type,
                    unitIndex: $context->index,
                    sourceVersion: $context->sourceVersion,
                    publication: new DocumentUnitPublication($claims, $decisions),
                ), $now->modify('+1 second'));
            } catch (\InvalidArgumentException) {
                $published = false;
            }

            self::assertTrue($published, 'A valid signed elevation must not roll back its thirty valid neighbors.');
            $evidenceCount = DB::table('estimate_generation_evidence')
                ->where('organization_id', $context->organizationId)
                ->where('project_id', $context->projectId)
                ->where('session_id', $context->sessionId)
                ->where('source_version', $sourceVersion)
                ->count();
            $elevationDocumentFactValue = EstimateGenerationDocumentFact::query()
                ->where('document_id', $context->documentId)
                ->where('scope_key', 'level:below-grade')
                ->firstOrFail()
                ->value_number;
            $levelDocumentFactValue = EstimateGenerationDocumentFact::query()
                ->where('document_id', $context->documentId)
                ->where('scope_key', 'building:below_ground_storeys')
                ->firstOrFail()
                ->value_number;
            $negativeAreaDocumentFactCount = EstimateGenerationDocumentFact::query()
                ->where('document_id', $context->documentId)
                ->where('scope_key', 'building:invalid-area')
                ->count();
            $projectModelCount = DB::table('estimate_generation_project_model_assertions')
                ->where('organization_id', $context->organizationId)
                ->where('project_id', $context->projectId)
                ->where('session_id', $context->sessionId)
                ->where('fact_status', 'confirmed')
                ->count();
            self::assertSame(
                [32, '-0.3000', '0.0000', 0, 32],
                [
                    $evidenceCount,
                    $elevationDocumentFactValue,
                    $levelDocumentFactValue,
                    $negativeAreaDocumentFactCount,
                    $projectModelCount,
                ],
                'Document facts, evidence, and project model must preserve signed elevations and explicit zero levels.',
            );
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function lineage_created_by_a_concurrent_writer_after_claim_remains_fail_closed(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        DB::beginTransaction();
        try {
            [$unit, $sourceVersion, $writer, $store] = $this->publicationFixture('b', 3);
            $now = now()->toDateTimeImmutable();
            $claim = $store->claim(
                (int) $unit->id,
                $sourceVersion,
                $now,
                $now->modify('+120 seconds'),
                ProcessDocumentUnit::MAX_ATTEMPTS,
            );
            $context = $store->executionContext($claim);
            self::assertInstanceOf(DocumentUnitExecutionContext::class, $context);
            $foreign = $this->observation($context, 'foreign:page-3', 'Материал конкурирующего результата');
            $writer->writeIndependentObservations([$foreign], $context->documentId, $context->index);
            $own = $this->observation($context, 'own:page-3', 'Материал текущего результата');

            $published = $store->publish($claim, new DocumentUnitOutput(
                version: 'stale-writer:v1',
                text: 'АР',
                confidence: 0.95,
                unitType: $context->type,
                unitIndex: $context->index,
                sourceVersion: $context->sourceVersion,
                publication: new DocumentUnitPublication([$own], [new ArbitrationDecision(
                    $own->id,
                    'candidate',
                    [$own->id],
                    [$own->evidenceRef],
                    'independent_observation_preserved',
                    null,
                    '',
                )]),
            ), $now->modify('+1 second'));

            self::assertFalse($published);
            self::assertSame('running', EstimateGenerationProcessingUnit::query()->findOrFail($unit->id)->status->value);
            self::assertSame(0, EstimateGenerationProcessingUnit::query()->findOrFail($unit->id)->output_count);
            self::assertSame(1, DB::table('estimate_generation_evidence')
                ->where('organization_id', $context->organizationId)
                ->where('project_id', $context->projectId)
                ->where('session_id', $context->sessionId)
                ->where('source_version', $sourceVersion)
                ->count());
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function lineage_inserted_in_the_previous_check_to_lock_window_remains_fail_closed(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        DB::beginTransaction();
        try {
            [$unit, $sourceVersion, $writer, $claimStore] = $this->publicationFixture('d', 5);
            $now = now()->toDateTimeImmutable();
            $claim = $claimStore->claim(
                (int) $unit->id,
                $sourceVersion,
                $now,
                $now->modify('+120 seconds'),
                ProcessDocumentUnit::MAX_ATTEMPTS,
            );
            $context = $claimStore->executionContext($claim);
            self::assertInstanceOf(DocumentUnitExecutionContext::class, $context);
            $foreign = $this->observation($context, 'interleaved:page-5', 'Конкурирующий материал');
            $own = $this->observation($context, 'own:page-5', 'Материал текущего результата');
            $atomic = new AtomicDocumentUnitPublicationWriter(DB::connection(), $writer);
            $interleavingWriter = new class($atomic, $writer, $foreign, $context) implements DocumentUnitPublicationWriter
            {
                public function __construct(
                    private readonly AtomicDocumentUnitPublicationWriter $atomic,
                    private readonly ProjectModelEvidenceWriter $foreignWriter,
                    private readonly ObservationClaim $foreign,
                    private readonly DocumentUnitExecutionContext $context,
                ) {}

                public function transaction(int $organizationId, int $sessionId, callable $callback): mixed
                {
                    $this->foreignWriter->writeIndependentObservations(
                        [$this->foreign],
                        $this->context->documentId,
                        $this->context->index,
                    );

                    return $this->atomic->transaction($organizationId, $sessionId, $callback);
                }

                public function write(
                    DocumentUnitPublication $publication,
                    int $organizationId,
                    int $projectId,
                    int $sessionId,
                    int $documentId,
                    int $pageNumber,
                    string $sourceVersion,
                ): void {
                    $this->atomic->write(
                        $publication,
                        $organizationId,
                        $projectId,
                        $sessionId,
                        $documentId,
                        $pageNumber,
                        $sourceVersion,
                    );
                }
            };
            $store = new EloquentDocumentProcessingUnitStore(DB::connection(), $interleavingWriter);

            $published = $store->publish($claim, new DocumentUnitOutput(
                version: 'interleaved:v1',
                text: 'АР',
                confidence: 0.95,
                unitType: $context->type,
                unitIndex: $context->index,
                sourceVersion: $context->sourceVersion,
                publication: new DocumentUnitPublication([$own], [new ArbitrationDecision(
                    $own->id,
                    'candidate',
                    [$own->id],
                    [$own->evidenceRef],
                    'independent_observation_preserved',
                    null,
                    '',
                )]),
            ), $now->modify('+1 second'));

            self::assertFalse($published);
            self::assertSame('running', EstimateGenerationProcessingUnit::query()->findOrFail($unit->id)->status->value);
            self::assertSame(1, DB::table('estimate_generation_evidence')
                ->where('organization_id', $context->organizationId)
                ->where('session_id', $context->sessionId)
                ->count());
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function a_valid_token_cannot_publish_through_a_claim_with_a_foreign_scope(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        DB::beginTransaction();
        try {
            [$unit, $sourceVersion, , $store] = $this->publicationFixture('c', 4);
            $now = now()->toDateTimeImmutable();
            $claim = $store->claim(
                (int) $unit->id,
                $sourceVersion,
                $now,
                $now->modify('+120 seconds'),
                ProcessDocumentUnit::MAX_ATTEMPTS,
            );
            $context = $store->executionContext($claim);
            self::assertInstanceOf(DocumentUnitExecutionContext::class, $context);
            $observation = $this->observation($context, 'foreign-scope:page-4', 'Материал');
            $foreignClaim = new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitClaim(
                unitId: $claim->unitId,
                status: $claim->status,
                token: $claim->token,
                organizationId: $claim->organizationId + 1,
                projectId: $claim->projectId,
                sessionId: $claim->sessionId,
                documentId: $claim->documentId,
                sourceVersion: $claim->sourceVersion,
            );

            $published = $store->publish($foreignClaim, new DocumentUnitOutput(
                version: 'foreign-scope:v1',
                text: 'АР',
                confidence: 0.95,
                unitType: $context->type,
                unitIndex: $context->index,
                sourceVersion: $context->sourceVersion,
                publication: new DocumentUnitPublication([$observation], [new ArbitrationDecision(
                    $observation->id,
                    'candidate',
                    [$observation->id],
                    [$observation->evidenceRef],
                    'independent_observation_preserved',
                    null,
                    '',
                )]),
            ), $now->modify('+1 second'));

            self::assertFalse($published);
            self::assertSame('running', EstimateGenerationProcessingUnit::query()->findOrFail($unit->id)->status->value);
            self::assertSame(0, DB::table('estimate_generation_evidence')->count());
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function repeated_room_facts_keep_one_fact_independent_entity_without_identity_collision(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        DB::beginTransaction();
        try {
            [$unit, $sourceVersion, $writer, $store] = $this->publicationFixture('d', 9);
            $now = now()->toDateTimeImmutable();
            $claimed = $store->claim(
                (int) $unit->id,
                $sourceVersion,
                $now,
                $now->modify('+120 seconds'),
                ProcessDocumentUnit::MAX_ATTEMPTS,
            );
            $context = $store->executionContext($claimed);
            self::assertInstanceOf(DocumentUnitExecutionContext::class, $context);
            $area = $this->numericObservation($context, 'room.kitchen', '22.10');
            $length = $this->numericObservation($context, 'room.kitchen', '5.50', 'length', 'm');

            $writer->writeArbitration([$area], [$this->acceptedDecision($area)], $context->documentId, 9);
            $writer->writeArbitration([$length], [$this->acceptedDecision($length)], $context->documentId, 9);

            self::assertSame(1, DB::table('estimate_generation_project_model_entities')
                ->where('session_id', $context->sessionId)
                ->where('source_version', $sourceVersion)
                ->count());
            self::assertSame(2, DB::table('estimate_generation_project_model_assertions')
                ->where('session_id', $context->sessionId)
                ->where('source_version', $sourceVersion)
                ->count());
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function replay_reuses_a_legacy_fact_dependent_room_entity_without_identity_collision(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        DB::beginTransaction();
        try {
            [$unit, $sourceVersion, $writer, $store] = $this->publicationFixture('e', 9);
            $now = now()->toDateTimeImmutable();
            $claimed = $store->claim(
                (int) $unit->id,
                $sourceVersion,
                $now,
                $now->modify('+120 seconds'),
                ProcessDocumentUnit::MAX_ATTEMPTS,
            );
            $context = $store->executionContext($claimed);
            self::assertInstanceOf(DocumentUnitExecutionContext::class, $context);

            $entityId = 'entity:'.hash('sha256', implode('|', ['room', 'room.kitchen']));
            (new EloquentProjectModelRepository(app('db')))->saveSourceModel([
                new Entity(
                    $entityId,
                    $context->organizationId,
                    $context->projectId,
                    $context->sessionId,
                    $sourceVersion,
                    'room',
                    $entityId,
                    ['area_m2' => '22.10'],
                ),
            ], [], []);

            $length = $this->numericObservation($context, 'room.kitchen', '5.50', 'length', 'm');
            $writer->writeArbitration([$length], [$this->acceptedDecision($length)], $context->documentId, 9);

            $payload = DB::table('estimate_generation_project_model_entities')
                ->where('session_id', $context->sessionId)
                ->where('source_version', $sourceVersion)
                ->where('stable_key', $entityId)
                ->value('payload');
            self::assertSame([
                'area_m2' => '22.10',
                'key' => $entityId,
                'kind' => 'room',
            ], json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR));
            self::assertSame(1, DB::table('estimate_generation_project_model_assertions')
                ->where('session_id', $context->sessionId)
                ->where('source_version', $sourceVersion)
                ->count());
        } finally {
            DB::rollBack();
        }
    }

    /** @return array{EstimateGenerationProcessingUnit, string, ProjectModelEvidenceWriter, EloquentDocumentProcessingUnitStore} */
    private function publicationFixture(string $hashCharacter, int $pageNumber): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create();
        $sourceVersion = 'sha256:'.str_repeat($hashCharacter, 64);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'draft',
            'processing_stage' => 'draft',
            'processing_progress' => 0,
            'input_payload' => [],
            'state_version' => 0,
        ]);
        $document = EstimateGenerationDocument::query()->create([
            'session_id' => $session->id,
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'filename' => 'concurrent-writer.pdf',
            'mime_type' => 'application/pdf',
            'source_version' => $sourceVersion,
        ]);
        $unit = EstimateGenerationProcessingUnit::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'session_id' => $session->id,
            'document_id' => $document->id,
            'unit_type' => 'pdf_page',
            'unit_index' => $pageNumber,
            'source_version' => $sourceVersion,
            'status' => 'pending',
            'locator' => [
                'source_kind' => 'pdf',
                'source_version' => $sourceVersion,
                'coordinate_space' => 'pdf_page_pixels',
                'artifact_path' => 'org-'.$organization->id.'/estimate-generation/tests/concurrent.png',
                'artifact_source_version' => $sourceVersion,
                'artifact_version_id' => 'atomic-publication-concurrent',
                'artifact_bytes' => 1,
                'artifact_sha256' => $sourceVersion,
                'content_type' => 'image/png',
            ],
            'metadata' => ['processing_attempt_id' => '7185da85-316f-4a74-8053-a1167211c60a'],
        ]);
        $writer = new ProjectModelEvidenceWriter(
            new EloquentProjectModelRepository(app('db')),
            new EloquentEvidenceRepository(DB::connection()),
        );

        return [
            $unit,
            $sourceVersion,
            $writer,
            new EloquentDocumentProcessingUnitStore(
                DB::connection(),
                new AtomicDocumentUnitPublicationWriter(DB::connection(), $writer),
            ),
        ];
    }

    private function observation(
        DocumentUnitExecutionContext $context,
        string $id,
        string $value,
    ): ObservationClaim {
        return new ObservationClaim(
            $id,
            'observer_literal',
            'material.page-'.$context->index,
            'material',
            ['type' => 'string', 'data' => $value],
            null,
            $id,
            true,
            $context->organizationId,
            $context->projectId,
            $context->sessionId,
            $context->sourceVersion,
            [
                'page' => $context->index,
                'unit_type' => $context->type->value,
                'unit_index' => $context->index,
                'source_version' => $context->sourceVersion,
                'explicit' => true,
            ],
        );
    }

    private function numericObservation(
        DocumentUnitExecutionContext $context,
        string $entityKey,
        string $value,
        string $factType = 'area',
        string $unit = 'm2',
    ): ObservationClaim {
        $id = 'literal:'.$factType.':'.$entityKey;

        return new ObservationClaim(
            $id,
            'observer_literal',
            $entityKey,
            $factType,
            ['type' => 'number', 'data' => $value],
            $unit,
            $id,
            true,
            $context->organizationId,
            $context->projectId,
            $context->sessionId,
            $context->sourceVersion,
            [
                'page' => $context->index,
                'unit_type' => $context->type->value,
                'unit_index' => $context->index,
                'source_version' => $context->sourceVersion,
                'explicit' => true,
            ],
        );
    }

    private function acceptedDecision(ObservationClaim $claim): ArbitrationDecision
    {
        return new ArbitrationDecision(
            claimId: $claim->id,
            status: 'accepted',
            supportingClaimIds: [$claim->id],
            evidenceRefs: [$claim->evidenceRef],
            reasonCode: 'arbiter_consensus',
            canonicalClaim: [
                'entity_key' => $claim->entityKey,
                'fact_type' => $claim->factType,
                'value' => $claim->value,
                'unit' => $claim->unit,
                'source_claim_id' => $claim->id,
            ],
        );
    }
}
